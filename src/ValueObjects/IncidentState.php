<?php

namespace Snoofa\StpManager\ValueObjects;

/**
 * GCP Incident Status
 *
 * https://cloud.google.com/monitoring/support/notification-options#schema-pubsub
 */
enum IncidentState: string
{
	case OPEN = 'open';
	case CLOSED = 'closed';
}
