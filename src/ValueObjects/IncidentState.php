<?php

/**
 * This file is part of the Snoofa project (https://snoofa.com)
 * Copyright (c) 2023 Snoofa Limited
 */

declare(strict_types=1);

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
