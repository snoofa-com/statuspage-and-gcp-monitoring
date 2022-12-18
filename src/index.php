<?php

/**
 * This file is part of the Snoofa project (https://snoofa.com)
 * Copyright (c) 2020 Snoofa Limited
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use CloudEvents\V1\CloudEventInterface;
use Google\CloudFunctions\FunctionsFramework;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Superbalist\Monolog\Formatter;
use Nette\Utils\Json;
use Superbalist\Monolog\Formatter\GoogleCloudJsonFormatter;


FunctionsFramework::cloudEvent('main', 'main');

$client = registerClient(getenv('PAGE_ID'), getenv('AUTH_TOKEN'));
$log = registerLogger();

function main(CloudEventInterface $event): void
{
	global $log;

	$data = Json::decode(base64_decode($event->getData()['message']['data']));

	$log->addDebug('Received message: ', ['data' => $data]);
	$log->addDebug("Incident ID: {$data->incident->incident_id}");
	$log->addDebug("Incident State: {$data->incident->state}");

	$incident = $data->incident;
	$labels = $incident->policy_user_labels;

	$log->addDebug('Received status change signal', [
		'incident_id' => $incident->incident_id,
		'incident_state' => $incident->state,
		'policy_labels' => $labels
	]);

	if ($data->incident->state === 'open') {
		start_incident(
			$incident->incident_id,
			$incident->policy_name,
			$labels->statuspage_incident_impact ?? null,
			(bool) ($labels->statuspage_send_notification ?? true),
			parseAffectedComponents($labels->statuspage_affected_components ?? ''),
			$labels->statuspage_components_status ?? 'major_outage',
		);
	} else {
		resolve_incidents($incident->incident_id);
	}
}

function parseAffectedComponents(string $components): array {
	return explode('__', $components);
}

function start_incident(
	string $incidentId,
	string $policyName,
	?string $incidentImpact = null,
	bool $deliverNotifications = true,
	array $components = [],
	?string $componentsStatus = null
): void {
	global $client;
	global $log;

	$incident = [
		'name' => 'Service outage',
		'status' => 'investigating',
		'metadata' => [
			'data' => [
				'created_by' => 'statuspage-manager',
				'gcp_incident_id' => $incidentId,
				'gcp_policy_name' => $policyName,
			],
		],
		'deliver_notifications' => $deliverNotifications,
		'body' => 'Alerting policy breached',
		'components' => array_fill_keys($components, $componentsStatus),
		'component_ids' => $components
	];

	if ($incidentImpact !== null) {
		$incident['impact_override'] = $incidentImpact;
	}

	$body = ['incident' => $incident];

	try {
		$client->post('incidents', ['json' => $body]);
	} catch (GuzzleException $e) {
		$log->addError('POST call to statuspage failed', ['message' => $e->getMessage(), 'exception' => $e]);
	}
}

function resolve_incidents(string $incidentId): void {
	global $client;
	global $log;

	$response = $client->get('incidents/unresolved');
	$incidents = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

	$incidents = array_filter(
		$incidents,
		fn(array $incident): bool => $incident['metadata']['data']['gcp_incident_id'] === $incidentId
	);

	foreach ($incidents as $incident) {
		$components = array_map(fn (array $component) => $component['id'], $incident['components']);
		$body = [
			'incident' => [
				'status' => 'resolved',
				'body' => 'Service back up',
				'components' => array_fill_keys($components, 'operational'),
			],
		];

		try {
			$client->patch("incidents/{$incident['id']}", ['json' => $body]);
		} catch (GuzzleException $e) {
			$log->addError('PATCH call to statuspage failed', ['message' => $e->getMessage(), 'exception' => $e]);
		}
	}

	$log->debug(count($incidents) . ' incident(s) resolved.');
}

function registerClient(string $pageId, string $authToken): Client {
	return new Client([
		'base_uri' => "https://api.statuspage.io/v1/pages/{$pageId}/",
		'headers' => [
			"Authorization" => "OAuth {$authToken}",
		]
	]);
}

/**
 * Register global logger.
 * Formatter ensures correct parsing of output when run as GCF.
 */
function registerLogger(): Logger
{
	$handler = new StreamHandler('php://stdout', Logger::WARNING);
	$handler->setFormatter(new GoogleCloudJsonFormatter());

	$log = new Logger('default');
	$log->pushHandler($handler);

	return $log;
}
