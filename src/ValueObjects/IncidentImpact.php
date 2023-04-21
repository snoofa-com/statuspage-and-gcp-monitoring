<?php

/**
 * This file is part of the Snoofa project (https://snoofa.com)
 * Copyright (c) 2023 Snoofa Limited
 */

declare(strict_types=1);

namespace Snoofa\StpManager\ValueObjects;

/**
 * https://developer.statuspage.io/#operation/postPagesPageIdIncidents
 */
enum IncidentImpact: string
{
	case NONE = 'none';
	case MAINTENANCE = 'maintenance';
	case MINOR = 'minor';
	case MAJOR = 'major';
	case CRITICAL = 'critical';
}
