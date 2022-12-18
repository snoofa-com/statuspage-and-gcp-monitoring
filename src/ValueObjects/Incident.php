<?php

namespace Snoofa\StpManager\ValueObjects;

use CloudEvents\V1\CloudEventInterface;
use Nette\Utils\Html;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;

/**
 * GCP doc
 * https://cloud.google.com/monitoring/support/notification-options#schema-pubsub
 */
class Incident
{
	/**
	 * Unique identifier of the incident in GCP. It gets stored in metadata of the Statuspage objected and
	 * is used for statuspage incident resolution once the incident gets resolved in GCP.
	 */
	public readonly string $id;

	/**
	 * open or close
	 */
	public readonly IncidentState $state;

	/**
	 *
	 */
	public readonly string $policyName;

	/**
	 *
	 */
	public readonly string $name;

	/**
	 *
	 */
	public readonly string $startInfo;

	/**
	 *
	 */
	public readonly string $endInfo;

	/**
	 * When null Statuspage tries to determine the incident impact on its own using some heuristic.
	 * https://support.atlassian.com/statuspage/docs/top-level-status-and-incident-impact-calculations/
	 */
	public readonly ?IncidentImpact $impact;

	/**
	 *
	 * @var array<string>
	 */
	public readonly array $affectedComponents;

	/**
	 *
	 */
	public readonly ComponentStatus $setComponentsStatus;

	/**
	 *
	 */
	public readonly bool $sendNotifications;


	/**
	 * @throws JsonException
	 */
	public static function fromGCPEvent(CloudEventInterface $event): self
	{
		$data = Json::decode(base64_decode($event->getData()['message']['data']));

		var_dump($event->getData());

		$incident = $data->incident;
		$labels = $incident->policy_user_labels;
		$incidentImpact = $labels->statuspage_incident_impact ?? null;
		$componentsStatus = $labels->statuspage_components_status ?? null;

		$new = new Incident();
		$new->id = $incident->incident_id;
		$new->state = IncidentState::from($incident->state);
		$new->policyName = $incident->policy_name;
		$new->sendNotifications = $labels->statuspage_send_notification ?? true;
		$new->impact = $incidentImpact !== null ? IncidentImpact::from($incidentImpact) : null;
		$new->affectedComponents = array_filter(explode('__', $labels->statuspage_components_status ?? ''));
		$new->setComponentsStatus = $componentsStatus !== null ? ComponentStatus::from($componentsStatus) : ComponentStatus::MAJOR_OUTAGE;

		$docs = $incident->documentation->content ?? null;
		$new->name = $new->parseFromDocs($docs, 'public-name', 'Default name');
		$new->startInfo = $new->parseFromDocs($docs, 'public-start-info', 'Policy breached.');
		$new->endInfo = $new->parseFromDocs($docs, 'public-end-info', 'Up and running.');

		return $new;
	}

	/**
	 * @return array
	 */
	public function serialiseForStatuspage(): array
	{
		$stpIncident = [
			'name' => 'Service outage',
			'status' => 'investigating',
			'metadata' => [
				'data' => [
					'created_by' => 'statuspage-manager',
					'gcp_incident_id' => $this->id,
					'gcp_policy_name' => $this->policyName,
				],
			],
			'deliver_notifications' => $this->sendNotifications,
			'body' => $this->startInfo,
			'components' => array_fill_keys($this->affectedComponents, $this->setComponentsStatus),
			'component_ids' => $this->affectedComponents,
		];

		if ($this->impact !== null) {
			$stpIncident['impact_override'] = $this->impact;
		}

		return $stpIncident;
	}

	/**
	 *
	 */
	private function parseFromDocs(?string $docs, string $tag, ?string $default = null): ?string
	{
		if (empty($docs)) {
			return Strings::trim($default);
		}

		$matches = Strings::match($docs, "~<{$tag}>(.*)<\/{$tag}>~is");
		$value = $matches[1] ?? $default;

		return Strings::trim($value);
	}
}
