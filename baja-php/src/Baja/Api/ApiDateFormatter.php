<?php
/**
 * API Date Formatter
 *
 * Handles proper UTC formatting for API responses.
 *
 * The database stores timestamps in UTC, but PHP's default timezone is
 * America/Sao_Paulo. This causes Propel to interpret DB timestamps as
 * Brazil time. This class provides a helper to correctly output timestamps
 * as UTC for API consumers.
 */

namespace Baja\Api;

use DateTime;
use DateTimeZone;

class ApiDateFormatter
{
    private static ?DateTimeZone $utcTimezone = null;

    /**
     * Get cached UTC timezone instance
     */
    private static function getUtcTimezone(): DateTimeZone
    {
        if (self::$utcTimezone === null) {
            self::$utcTimezone = new DateTimeZone('UTC');
        }
        return self::$utcTimezone;
    }

    /**
     * Format a DateTime as UTC ISO 8601 string for API output.
     *
     * Since PHP interprets DB timestamps using the default timezone (America/Sao_Paulo),
     * but the database actually stores UTC, we need to re-interpret the timestamp.
     *
     * Example:
     * - DB stores: 2026-01-15 05:35:00 (UTC)
     * - PHP reads as: 2026-01-15 05:35:00 America/Sao_Paulo (wrong interpretation)
     * - This function outputs: 2026-01-15T05:35:00+00:00 (correct UTC)
     *
     * @param DateTime|null $dt The DateTime from Propel (interpreted in local timezone)
     * @return string|null ISO 8601 formatted string with UTC timezone, or null
     */
    public static function toUtc(?DateTime $dt): ?string
    {
        if ($dt === null) {
            return null;
        }

        // Get the raw date/time components (this is what's actually in the DB)
        $rawDateTime = $dt->format('Y-m-d H:i:s');

        // Create new DateTime with those components explicitly in UTC
        $utc = new DateTime($rawDateTime, self::getUtcTimezone());

        return $utc->format('c');
    }

    /**
     * Shorthand for nullable DateTime formatting.
     * Returns null instead of throwing if input is null.
     *
     * @param DateTime|null $dt
     * @return string|null
     */
    public static function format(?DateTime $dt): ?string
    {
        return self::toUtc($dt);
    }
}
