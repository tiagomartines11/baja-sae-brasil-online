<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

/**
 * Turns a phone's idea of the time into the server's.
 *
 * Phones' clocks are routinely seconds, sometimes minutes, off. Each device
 * therefore estimates its offset from the server (offset = server time
 * minus device time) and sends every entry as "raw device time + the offset
 * I believed in when I recorded it". The server does the addition. Keeping
 * the two apart in storage means a bad estimate can be examined afterwards
 * instead of being baked into the time.
 *
 * Pure functions only: no database, no clock.
 */
final class ClockCorrection
{
    /**
     * Server-side time of something the device timed, or null when the
     * device did not know its offset yet.
     */
    public static function correctedTime(int $deviceTimeMs, ?int $offsetMs): ?int
    {
        if ($offsetMs === null) {
            return null;
        }
        return $deviceTimeMs + $offsetMs;
    }

    /**
     * The offset to remember as the device's first, used for entries it
     * recorded before it had any. Once set it never changes: those early
     * entries must keep getting the same time on every engine run.
     *
     * @param int|null $storedFirstOffsetMs what the device row already holds
     * @param int|null $requestOffsetMs the offset sent with this upload
     * @param array<int, int|null> $entryOffsetsMs the offsets of the entries in this upload, in order
     */
    public static function firstOffset(?int $storedFirstOffsetMs, ?int $requestOffsetMs, array $entryOffsetsMs): ?int
    {
        if ($storedFirstOffsetMs !== null) {
            return $storedFirstOffsetMs;
        }
        if ($requestOffsetMs !== null) {
            return $requestOffsetMs;
        }
        foreach ($entryOffsetsMs as $offsetMs) {
            if ($offsetMs !== null) {
                return $offsetMs;
            }
        }
        return null;
    }

    /**
     * The device's new "synced through" mark: the moment up to which we
     * know it has uploaded everything it recorded.
     *
     * The mark only moves when the device says its queue is empty, and it
     * moves to the device's own corrected "now", not to the time the
     * request arrived: an entry typed while the request was travelling is
     * newer than the device's "now" and must not be covered by the mark.
     *
     * @param int|null $currentMarkMs the mark stored so far
     * @param bool $queueEmpty what the device reported
     * @param int|null $deviceNowMs the device's raw clock when it sent the request
     * @param int|null $offsetMs the best offset known for the device (this upload's, else its first)
     * @param int $serverNowMs when the request was handled
     */
    public static function syncedThrough(?int $currentMarkMs, bool $queueEmpty, ?int $deviceNowMs, ?int $offsetMs, int $serverNowMs): ?int
    {
        if (!$queueEmpty || $deviceNowMs === null || $offsetMs === null) {
            // Without an offset the device's "now" cannot be placed on the
            // server's timeline. Leaving the mark alone only delays things:
            // the device will have an offset by its next heartbeat.
            return $currentMarkMs;
        }

        // A device cannot have synced through a moment that has not
        // happened yet; a wild offset must not make it look that way.
        $candidateMs = min($deviceNowMs + $offsetMs, $serverNowMs);

        // The mark never moves backwards: what was uploaded stays uploaded.
        if ($currentMarkMs !== null && $candidateMs < $currentMarkMs) {
            return $currentMarkMs;
        }
        return $candidateMs;
    }
}
