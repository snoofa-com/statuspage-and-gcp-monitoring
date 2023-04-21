<?php

/**
 * This file is part of the Snoofa project (https://snoofa.com)
 * Copyright (c) 2023 Snoofa Limited
 */

declare(strict_types=1);

namespace Snoofa\StpManager\ValueObjects;

/**
 * https://developer.statuspage.io/#operation/postPagesPageIdComponents
 */
enum ComponentStatus: string
{
	case OPERATIONAL = 'operational';
	case UNDER_MAINTENANCE = 'under_maintenance';
	case DEGRADED_PERFORMANCE = 'degraded_performance';
	case PARTIAL_OUTAGE = 'partial_outage';
	case MAJOR_OUTAGE = 'major_outage';
}
