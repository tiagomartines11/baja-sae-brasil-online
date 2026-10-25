<?php
// No declare(strict_types=1) here, on purpose: time() and date() below hand
// the formula's own arguments to PHP's, which must check them as they would
// when the formula calls PHP's directly (date(1.5) works, date([]) fails).

/*
 * A fixed "now", so that the corpus comparison
 * (scripts/formula-engine-compare.php) gets the same answer from both
 * engines for a formula that reads the clock (the 19SU "Status?" columns
 * compare time() with a deadline).
 *
 * EvalEngine, given a fixed now, runs the formula's code in the namespace
 * Baja\Formula\PinnedClock. An unqualified time() or date() there is the
 * function below; every other function falls back to PHP's own. Classes do
 * not fall back, so DateTime and DateTimeZone are aliased in. SafeEngine
 * calls the same two static methods.
 *
 * Not pinned: \time() written with its backslash, and new DateTime('now')
 * without a setTimestamp(). Both engines then read the real clock.
 */

namespace Baja\Formula {
    final class PinnedClock
    {
        /** Set by EvalEngine around one evaluation; null otherwise. */
        public static ?int $now = null;

        /** time(), with $now when it is set. Arguments are passed on, so that time(1) fails as PHP's does. */
        public static function time(?int $now, array $arguments): int
        {
            return ($now === null || $arguments !== []) ? \time(...$arguments) : $now;
        }

        /** date($format) and date($format, null) read $now when it is set; anything else is PHP's date() as it is. */
        public static function date(?int $now, array $arguments): string
        {
            $readsClock = count($arguments) === 1 || (count($arguments) === 2 && array_key_exists(1, $arguments) && $arguments[1] === null);
            if ($now !== null && $readsClock) {
                $arguments[1] = $now;
            }
            return \date(...$arguments);
        }
    }
}

namespace Baja\Formula\PinnedClock {
    use Baja\Formula\PinnedClock;

    function time(...$arguments)
    {
        return PinnedClock::time(PinnedClock::$now, $arguments);
    }

    function date(...$arguments)
    {
        return PinnedClock::date(PinnedClock::$now, $arguments);
    }

    if (!class_exists(__NAMESPACE__ . '\\DateTime', false)) {
        class_alias(\DateTime::class, __NAMESPACE__ . '\\DateTime');
        class_alias(\DateTimeZone::class, __NAMESPACE__ . '\\DateTimeZone');
    }
}
