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
     * Times are milliseconds since 1970 and must stay below 2^53: beyond
     * that, adding two of them (which the engine does to take a median) no
     * longer fits a whole number, PHP silently turns the sum into a
     * fraction, and every later run of the engine fails. 2^53 ms is the
     * year 287,396, so nothing real is lost.
     */
    public const TIME_LIMIT_MS = 9007199254740992;

    /** How many entries, voids or stop events of one request are looked at. The rest wait for the next request. */
    public const MAX_ITEMS_PER_REQUEST = 500;

    /** @param mixed $value */
    public static function intOrNull($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        // BIGINT columns come back from the database as numeric strings.
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        // JSON has one number type; a client may send 1760000000000.0. A
        // number too large for an integer (1e30) is cut to the largest
        // one: converting it directly gives an arbitrary result.
        if (is_float($value) && is_finite($value)) {
            return (int) round(max((float) PHP_INT_MIN, min($value, 9.2e18)));
        }
        return null;
    }

    /**
     * Image references of an automatic source are opaque ids appended to the
     * source's image base URL: plain characters only, so a reference can
     * never climb out of that address ("../") or carry a query of its own.
     * \z, not $: $ also lets a trailing newline through.
     */
    private const IMAGE_REF_PATTERN = '/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,63}\z/';

    /**
     * The image reference of an entry ("imagem"), or null when there is none
     * or it is not a plain id of at most 64 characters. A bad reference is
     * dropped, never a reason to refuse the entry.
     *
     * @param array<string, mixed> $item
     */
    public static function imageRefOf(array $item): ?string
    {
        $ref = $item['imagem'] ?? null;
        return is_string($ref) && preg_match(self::IMAGE_REF_PATTERN, $ref) === 1 ? $ref : null;
    }

    /**
     * How sure the system was ("confianca"), from 0 to 1 with three
     * decimals, or null when it did not say or said something else.
     *
     * @param array<string, mixed> $item
     */
    public static function confidenceOf(array $item): ?float
    {
        $value = $item['confianca'] ?? null;
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $value = (float) $value;
        return is_finite($value) && $value >= 0.0 && $value <= 1.0 ? round($value, 3) : null;
    }

    /** Can this time be computed with? See TIME_LIMIT_MS. */
    public static function isUsableTime(int $tsMs): bool
    {
        return $tsMs >= 0 && $tsMs < self::TIME_LIMIT_MS;
    }

    /** The nearest usable time to an unusable one. */
    public static function clampTime(int $tsMs): int
    {
        return max(0, min($tsMs, self::TIME_LIMIT_MS - 1));
    }

    /**
     * At most MAX_ITEMS_PER_REQUEST elements of a list from the request.
     * A phone that was offline for an hour sends its backlog in batches;
     * a single request with a hundred thousand elements is not a phone.
     *
     * @param mixed $list
     * @return array<int, mixed>
     */
    public static function firstItems($list): array
    {
        return is_array($list) ? array_slice(array_values($list), 0, self::MAX_ITEMS_PER_REQUEST) : [];
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
