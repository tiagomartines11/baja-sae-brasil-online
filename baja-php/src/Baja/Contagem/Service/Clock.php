<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

/**
 * The only place in the lap counting code that reads the wall clock.
 *
 * Everything here works in integer milliseconds since the Unix epoch, and
 * much of it compares "now" against stored times (is this source offline?
 * is this car late?). Reading the clock in one place lets the tests stop it
 * at a chosen moment and get the same answer on every run.
 */
final class Clock
{
    private static ?int $frozenAtMs = null;

    public static function nowMs(): int
    {
        if (self::$frozenAtMs !== null) {
            return self::$frozenAtMs;
        }
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * Tests only. Pass null to let the clock run again.
     */
    public static function freezeAt(?int $ms): void
    {
        self::$frozenAtMs = $ms;
    }
}
