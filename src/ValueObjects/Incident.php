<?php

namespace Snoofa\StpManager\ValueObjects;

use CloudEvents\V1\CloudEventInterface;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;

class Incident
{
	/**
	 * @see self::$name
	 */
	public static string $defaultIncidentName = 'Service outage';

	/**
	 * @see self::$startInfo
	 */
	public static string $defaultIncidentStartInfo = 'An issue with the service has been detected.';

	/**
	 * @see self::$endInfo
	 */
	public static string $defaultIncidentEndInfo = 'Back up and running.';


	/**
	 * Unique identifier of the incident in GCP. It gets stored in metadata of the Statuspage objected and
	 * is used for Statuspage incident resolution once the incident gets resolved in GCP.
	 */
	public readonly string $id;

	/**
	 * Either open or close. This determines whether a new incident should be opened or an ongoing incident
	 * marked as resolved in Statuspage.
	 */
	public readonly IncidentState $state;

	/**
	 * Name of the alerting policy in GCP. It gets passed into the Statuspage incident metadata. Only for a
	 * reference at this point.
	 */
	public readonly string $policyName;

	/**
	 * Public name of the incident. This is what the subscribers see on the Statuspage and in all notification
	 * channels.
	 *
	 * Parsed as a tag from the `documentation` field of the GCP alerting policy.
	 * Example:
	 * ```
	 *  <public-name>We are experiencing downtime on service XYZ</public-name>
	 * ```
	 *
	 * Uses self::$defaultIncidentName as a fallback when nothing parsed from the policy docs.
	 */
	public readonly string $name;

	/**
	 * Public description of the incident. This is what the subscribers see on the Statuspage and in all notification
	 * channels when the incident starts.
	 *
	 * Parsed as a tag from the `documentation` field of the GCP alerting policy.
	 * Example:
	 * ```
	 *  <public-start-info>
	 *  We are investigating the outage and will keep you informed with regular updates.
	 *  </public-start-info>
	 * ```
	 *
	 * Uses self::$defaultIncidentStartInfo as a fallback when nothing parsed from the policy docs.
	 */
	public readonly string $startInfo;

	/**
	 * Public note that gets posted when the incident is automatically resolved. The subscribers see this the
	 * Statuspage and in all notification channels when the incident starts.
	 *
	 *Parsed as a tag from the `documentation` field of the GCP alerting policy.
	 * Example:
	 * ```
	 *  <public-end-info>
	 *  The service XYZ is now up and running. Thank you for your patience.
	 *  </public-end-info>
	 * ```
	 *
	 * Uses self::$defaultIncidentEndInfo as a fallback when nothing parsed from the policy docs.
	 */
	public readonly string $endInfo;

	/**
	 * Parsed from GCP alerting policy label: `statuspage_incident_impact`
	 *
	 * When `statuspage_incident_impact` label is attached to the GCP alerting policy (policy_user_labels),
	 * its value is used to overwrite the incident's impact in Statuspage. When this is not specified,
	 * Statuspage determines the impact on its own based on the affected component statuses. For more info see
	 * the link.
	 *
	 * @link https://support.atlassian.com/statuspage/docs/top-level-status-and-incident-impact-calculations/
	 */
	public readonly ?IncidentImpact $impact;

	/**
	 * Parsed from GCP alerting policy label: `statuspage_affected_components`
	 *
	 * One or multiple Statuspage component ids can be specified in this label. When an incident starts the
	 * status of these components changes to self::$setComponentsStatus.
	 *
	 * When specifying multiple components use `__` as a separator.
	 * Example:
	 * ```
	 * ab2tkbv09nzj__cdl8227q4vlf
	 * ```
	 *
	 * @see self::$setComponentsStatus
	 * @var array<string>
	 */
	public readonly array $affectedComponents;

	/**
	 * Parsed from GCP alerting policy label: `statuspage_components_status`
	 *
	 * Determines the status to which the affected components will be set when the incident starts.
	 * `major_outage` is used by default.
	 */
	public readonly ComponentStatus $setComponentsStatus;

	/**
	 * Parsed from GCP alerting policy label: `statuspage_send_notification`
	 *
	 * Determines whether notifications about this incident are sent to the subscribers.
	 * `true` by default.
	 */
	public readonly bool $sendNotifications;


	/**
	 * Based on the content of the message sent into Pub/Sub by the alerting policy it sets up the Incident
	 * object. See the link for details on the GCP alerting message schema.
	 *
	 * @link https://cloud.google.com/monitoring/support/notification-options#schema-pubsub
	 *
	 * @throws JsonException
	 */
	public static function fromGCPEvent(CloudEventInterface $event): self
	{
		//Handle both base64 encoded string and already-parsed array as an input
		$data = $event->getData()['message']['data'];
		$data = is_array($data) ? $data : Json::decode(base64_decode($data), Json::FORCE_ARRAY);

		$incident = $data['incident'];
		$labels = $incident['policy_user_labels'];
		$incidentImpact = $labels['statuspage_incident_impact'] ?? null;
		$componentsStatus = $labels['statuspage_components_status'] ?? null;

		$new = new Incident();
		$new->id = $incident['incident_id'];
		$new->state = IncidentState::from($incident['state']);
		$new->policyName = $incident['policy_name'];
		$new->sendNotifications = $labels['statuspage_send_notification'] ?? true;
		$new->impact = $incidentImpact !== null ? IncidentImpact::from($incidentImpact) : null;
		$new->affectedComponents = array_filter(explode('__', $labels['statuspage_affected_components'] ?? ''));
		$new->setComponentsStatus = $componentsStatus !== null ? ComponentStatus::from($componentsStatus) : ComponentStatus::MAJOR_OUTAGE;

		$docs = $incident['documentation']['content'] ?? null;
		$new->name = $new->parseFromDocs($docs, 'public-name', self::$defaultIncidentName);
		$new->startInfo = $new->parseFromDocs($docs, 'public-start-info', self::$defaultIncidentStartInfo);
		$new->endInfo = $new->parseFromDocs($docs, 'public-end-info', self::$defaultIncidentEndInfo);

		return $new;
	}

	/**
	 * Serialises the Incident object for Statuspage for the incident to be opened. See the link for details.
	 *
	 * @link https://developer.statuspage.io/#tag/incidents
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
	 * Parses additional information from tags embedded in the GCP policy's documentation.
	 *
	 * @see self::$name, self::$startInfo, self::$endInfo
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
