<?php

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
