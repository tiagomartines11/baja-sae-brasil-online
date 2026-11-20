<?php
declare(strict_types=1);

namespace Baja\Evento;

/**
 * What an event code (evento.evento_id) looks like: two digits for the year
 * and two capitals or digits for the competition, e.g. 26BR, 25AR, 24H2.
 *
 * The tools check a code before using it, because it ends up in folder
 * names (snapshots, archives on the Shared Drive). Once they each had their
 * own copy of the rule, and every copy listed BR, SE, SU and NE only; the
 * events with other letters (AR, FA, FO, EQ, H2...) were refused
 * (BAJAAPP-74). Hence one rule, here, that does not list the letters.
 *
 * The shell scripts have the same rule in baja-infra/scripts/backup-lib.sh
 * (backup_is_event_code). Change both together.
 *
 * Whether such an event EXISTS is a question for the database: a code can
 * be well formed and still be no event.
 */
final class EventCode
{
    /** D: "$" is the very end, so "26BR\n" is not a code. */
    private const PATTERN = '/^\d{2}[A-Z0-9]{2}$/D';

    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }
}
