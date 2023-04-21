<?php

/**
 * This file is part of the Snoofa project (https://snoofa.com)
 * Copyright (c) 2023 Snoofa Limited
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use CloudEvents\V1\CloudEventInterface;
use Google\CloudFunctions\FunctionsFramework;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Snoofa\StpManager\ValueObjects\Incident;
use Snoofa\StpManager\ValueObjects\IncidentState;
use Superbalist\Monolog\Formatter\GoogleCloudJsonFormatter;


FunctionsFramework::cloudEvent('main', 'main');

$client = registerClient(getenv('PAGE_ID'), getenv('AUTH_TOKEN'));
$log = registerLogger();

function main(CloudEventInterface $event): never
{
	global $log;

	try {
		$incident = Incident::fromGCPEvent($event);
	} catch (JsonException $e) {
		$log->addError('Unable to parse Cloud Event message', ['exception' => $e]);
		exit(1);
	}

	$log->addDebug('Received status change signal', [
		'incident_id' => $incident->id,
		'incident_status' => $incident->state
	]);

	if ($incident->state === IncidentState::OPEN) {
		start_incident($incident);
	} else {
		try {
			resolve_incidents($incident);
		} catch (GuzzleException|JsonException $e) {
			$log->addError('Failed to resolve incident(s)', ['message' => $e->getMessage(), 'exception' => $e]);
			exit(1);
		}
	}

	exit(0);
}

/**
 * An alerting policy fired in GCP, this function starts a new incident in Statuspage. Based on the
 * configuration this might change the status of some components and send notification to the Statuspage
 * subscribers.
 *
 * @see Incident::$affectedComponents, Incident::$sendNotifications
 */
function start_incident(Incident $incident): void {
	global $client;
	global $log;

	$body = ['incident' => $incident->serialiseForStatuspage()];

	try {
		$client->post('incidents', ['json' => $body]);
	} catch (GuzzleException $e) {
		$log->addError('POST call to statuspage failed', ['message' => $e->getMessage(), 'exception' => $e]);
	}
}

/**
 * An alerting policy in GCP indicates that the incident has stopped, this function now resolves all incidents
 * that have been created in Statuspage. It relies on the GCP indent id stored in the metadata of Statuspage
 * incidents. All affected components are returned to the `operational` state.
 *
 * @see Incident::$id
 *
 * @throws GuzzleException
 * @throws JsonException
 */
function resolve_incidents(Incident $gcpIncident): void {
	global $client;
	global $log;

	$response = $client->get('incidents/unresolved');
	$unresolved = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

	// Filter out only incidents that match the GCP incident id. (In most cases this should be just one)
	$unresolved = array_filter(
		$unresolved,
		fn(array $incident): bool => $incident['metadata']['data']['gcp_incident_id'] === $gcpIncident->id
	);

	// Mark all incidents as resolved
	foreach ($unresolved as $incident) {
		$components = array_map(fn (array $component) => $component['id'], $incident['components']);
		$body = [
			'incident' => [
				'status' => 'resolved',
				'body' => $gcpIncident->endInfo,
				'components' => array_fill_keys($components, 'operational'),
			],
		];

		try {
			$client->patch("incidents/{$incident['id']}", ['json' => $body]);
		} catch (GuzzleException $e) {
			$log->addError('PATCH call to statuspage failed', ['message' => $e->getMessage(), 'exception' => $e]);
		}
	}

	$log->addDebug(count($unresolved) . ' incident(s) marked as resolved');
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
	$handler = new StreamHandler('php://stderr', Logger::DEBUG);
	$handler->setFormatter(new GoogleCloudJsonFormatter());

	$log = new Logger('default');
	$log->pushHandler($handler);

	return $log;
}
