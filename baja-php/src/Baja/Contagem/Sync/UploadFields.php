<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

/**
 * Reads single fields out of an upload, which is JSON written by a client
 * we do not control (an old cached copy of the count page, or somebody
 * else's program on an automated source). Every reader returns null for
 * anything unusable rather than guessing.
 *
 * Pure functions only.
 */
final class UploadFields
{
    /**
     * Client uuids are normally 36 characters (a standard UUID); shorter
     * ids are accepted as long as they are plain and fit the column.
     */
    private const UUID_PATTERN = '/^[A-Za-z0-9-]{8,36}$/';

    /** Largest value of a signed 32-bit INT column. */
    public const INT_MAX = 2147483647;

    /**
     * @param mixed $item one element of "entries", "voids" or "pit_events"
     * @return string|null the uuid, or null when the element is unusable
     */
    public static function uuidOf($item): ?string
    {
        $uuid = is_array($item) ? ($item['uuid'] ?? null) : null;
        if (!is_string($uuid) || preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            return null;
        }
        return $uuid;
    }

    /**
     * The car number of an element, or null when it is not a number that
     * fits the column. Whether the car exists is not checked: a typo is
     * still what the counter typed, and it must be stored.
     *
     * @param array<string, mixed> $item
     */
    public static function carOf(array $item): ?int
    {
        $car = self::intOrNull($item['car'] ?? null);
        if ($car === null || $car < 0 || $car > self::INT_MAX) {
            return null;
        }
        return $car;
    }

    /**
     * Is this a moment something could really have been recorded at?
     *
     * A phone whose clock is set to the wrong day, or a client sending
     * seconds instead of milliseconds, would otherwise put entries years
     * away from the race, where no review screen would ever show them.
     * Up to a week old is accepted (a phone found in a bag after the event
     * still uploads), and up to a day ahead (clock offsets are estimates).
     */
    public static function isPlausibleTime(int $tsMs, int $nowMs): bool
    {
        $weekMs = 7 * 24 * 3600 * 1000;
        $dayMs = 24 * 3600 * 1000;
        return $tsMs >= $nowMs - $weekMs && $tsMs <= $nowMs + $dayMs;
    }

    /** @param mixed $value */
    public static function intOrNull($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        // JSON has one number type; a client may send 1760000000000.0.
        if (is_float($value) && is_finite($value)) {
            return (int) round($value);
        }
        // BIGINT columns come back from the database as numeric strings.
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * The form of a uuid (or id) used as a PHP array key. MySQL compares
     * the uuid columns without regard to case and PHP array keys do not, so
     * "ABC" and "abc" must land on the same key or the second would be
     * inserted and hit the unique index. The prefix keeps PHP from turning
     * an all-digit value into an integer key.
     */
    public static function key(string $value): string
    {
        return 'k' . strtolower($value);
    }
}
