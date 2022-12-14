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
use Nette\Utils\Json;


FunctionsFramework::cloudEvent('main', 'main');

$client = registerClient("qp85tfp89xjk", "51cbd17a-9469-46dd-82e2-3eb44824c764");
$log = registerLogger();

function main(CloudEventInterface $event): void
{
	global $log;

	$data = Json::decode(base64_decode($event->getData()['message']['data']));

	$log->debug('Received message: ', ['data' => $data]);
	$log->debug("Incident ID: {$data->incident->incident_id}");
	$log->debug("Incident State: {$data->incident->state}");

	$incident = $data->incident;
	$labels = $incident->policy_user_labels;

	if ($data->incident->state === 'OPEN') {
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

	echo Json::encode($incident);

	if ($incidentImpact !== null) {
		$incident['impact_override'] = $incidentImpact;
	}

	$body = ['incident' => $incident];

	try {
		$client->post('incidents', ['json' => $body]);
	} catch (GuzzleException $e) {
		echo $e->getMessage();
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

		$client->patch("incidents/{$incident['id']}", ['json' => $body]);
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
	return new Logger('default');
}
