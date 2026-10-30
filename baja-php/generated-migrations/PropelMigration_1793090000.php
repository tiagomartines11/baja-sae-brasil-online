<?php
use Propel\Generator\Manager\MigrationManager;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * An event's first and last day as dates (BAJAAPP-12): evento.data_inicio
 * and evento.data_fim, filled in once from the free-text evento.data where
 * it can be read.
 *
 * Written by hand in Propel's format: `./propel migration:diff` needs
 * database credentials in a propel.yaml, and the change is two nullable
 * columns. `./propel sql:build` gives the same column definitions.
 *
 * WHY
 *
 * A role given on an event ends a few days after the event's last day
 * (Baja\Rbac\Expiry\GrantExpiry). Until now that day was only written in
 * evento.data, text for people ("20 a 24 de abril de 2022"), and the RBAC
 * code parsed it on every grant. Now it is a date, set on juiz/evento.php,
 * and the text is only read here, once.
 *
 * WHAT IS READ
 *
 * Only a date and nothing else, in one of these shapes (any case, "º" or
 * "o" after the day, "a", "até", "e" or a dash between two days):
 *
 *     20 a 24 de abril de 2022              one month
 *     30 de abril a 2 de maio de 2025       across months
 *     30 de dezembro de 2025 a 2 de janeiro de 2026
 *     15 de agosto de 2025                  one day
 *     24/04/2022   20/04/2022 a 24/04/2022
 *
 * Anything else (text around the date, "a definir", a list of days, a day
 * that does not exist, a first day after the last, more than 31 days) is
 * left EMPTY, never guessed: a wrong last day would end judges' access
 * during the event, or keep it long after. The migration prints those
 * events with their text, for an admin to set the dates on
 * juiz/evento.php. Until they do, a role on such an event needs an
 * explicit end.
 *
 * The text is read in preUp(), before any SQL runs, and the UPDATEs are
 * part of getUpSQL(): Propel records a migration as applied BEFORE it calls
 * postUp(), so a data step there could fail and never be retried.
 *
 * The same DDL and the seed events' dates are in
 * baja-infra/mysql/init/10-evento-datas.sql for fresh dev databases.
 *
 * Tests: tests/Unit/Rbac/EventDateBackfillTest.php (the reading) and
 * tests/Rbac/Db/EventDateMigrationTest.php (the columns and the UPDATE,
 * on MySQL).
 */
class PropelMigration_1793090000
{
    public $comment = 'evento.data_inicio and data_fim, filled from the evento.data text where it can be read (BAJAAPP-12)';

    /** A range longer than this is more likely a typo than an event. */
    public const MAX_DAYS = 31;

    private const MONTHS = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3, 'abril' => 4,
        'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8, 'setembro' => 9,
        'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    /** @var string[] the UPDATEs preUp() worked out */
    private array $backfill = [];

    /**
     * The first and last day in one evento.data text, as 'Y-m-d', or null
     * when the text is not one of the shapes above.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function datesFromText(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }
        // The text may arrive as latin1 bytes, as evento stores it.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        // Admins type entities ("24&nbsp;de abril"); a non-breaking space is
        // still a space here.
        $text = str_replace("\u{00A0}", ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text), " ."), 'UTF-8');

        $d = '(\d{1,2})(?:º|o)?';
        $m = '(' . implode('|', array_keys(self::MONTHS)) . ')';
        $y = '(\d{4})';
        $to = ' ?(?:a|até|e|-|–) ?';
        $n = '(\d{1,2})\/(\d{1,2})\/(\d{4})';

        if (preg_match("/^$d de $m de $y$to$d de $m de $y$/u", $text, $p)) {
            $first = [$p[3], self::MONTHS[$p[2]], $p[1]];
            $last = [$p[6], self::MONTHS[$p[5]], $p[4]];
        } elseif (preg_match("/^$d de $m$to$d de $m de $y$/u", $text, $p)) {
            // 30 de dezembro a 2 de janeiro de 2026: the first day is in 2025.
            $firstMonth = self::MONTHS[$p[2]];
            $lastMonth = self::MONTHS[$p[4]];
            $first = [(int) $p[5] - ($firstMonth > $lastMonth ? 1 : 0), $firstMonth, $p[1]];
            $last = [$p[5], $lastMonth, $p[3]];
        } elseif (preg_match("/^$d$to$d de $m de $y$/u", $text, $p)) {
            $first = [$p[4], self::MONTHS[$p[3]], $p[1]];
            $last = [$p[4], self::MONTHS[$p[3]], $p[2]];
        } elseif (preg_match("/^$d de $m de $y$/u", $text, $p)) {
            $first = $last = [$p[3], self::MONTHS[$p[2]], $p[1]];
        } elseif (preg_match("/^$n$to$n$/u", $text, $p)) {
            $first = [$p[3], $p[2], $p[1]];
            $last = [$p[6], $p[5], $p[4]];
        } elseif (preg_match("/^$n$/u", $text, $p)) {
            $first = $last = [$p[3], $p[2], $p[1]];
        } else {
            return null;
        }

        $firstDay = self::date(...$first);
        $lastDay = self::date(...$last);
        if ($firstDay === null || $lastDay === null || $firstDay > $lastDay
            || $firstDay->diff($lastDay)->days > self::MAX_DAYS) {
            return null;
        }
        return [$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')];
    }

    /** A real calendar day, or null (31 de fevereiro). */
    private static function date(int|string $year, int|string $month, int|string $day): ?DateTimeImmutable
    {
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * Reads every event's text, keeps an UPDATE for each one that can be
     * read, and prints the others.
     *
     * @param PDO|ConnectionInterface $con a connection to baja_resultados
     * @return array{updates: string[], unread: array<string, ?string>} unread: evento_id => its text
     */
    public static function planBackfill($con): array
    {
        $updates = [];
        $unread = [];
        foreach ($con->query('SELECT evento_id, data FROM evento ORDER BY evento_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dates = self::datesFromText($row['data']);
            if ($dates === null) {
                $unread[(string) $row['evento_id']] = $row['data'];
                continue;
            }
            $updates[] = sprintf(
                'UPDATE `evento` SET `data_inicio` = %s, `data_fim` = %s WHERE `evento_id` = %s AND `data_fim` IS NULL;',
                $con->quote($dates[0]), $con->quote($dates[1]), $con->quote((string) $row['evento_id'])
            );
        }
        return ['updates' => $updates, 'unread' => $unread];
    }

    public function preUp(MigrationManager $manager)
    {
        $plan = self::planBackfill($manager->getAdapterConnection('resultados'));
        $this->backfill = $plan['updates'];

        echo 'BAJAAPP-12: data_inicio and data_fim read from the text of ' . count($plan['updates']) . " event(s).\n";
        if ($plan['unread'] !== []) {
            echo 'BAJAAPP-12: left EMPTY for these ' . count($plan['unread']) . " event(s), whose text is not a date this migration reads.\n"
                . "Set their dates on juiz/evento.php; until then a role given on one of them needs an explicit end.\n";
            foreach ($plan['unread'] as $eventoId => $text) {
                echo "    $eventoId  " . var_export($text, true) . "\n";
            }
        }
    }

    public function postUp(MigrationManager $manager)
    {
    }

    public function preDown(MigrationManager $manager)
    {
    }

    public function postDown(MigrationManager $manager)
    {
    }

    public function getUpSQL(): array
    {
        $connection_resultados = <<< 'EOT'
ALTER TABLE `evento`

  ADD `data_inicio` DATE AFTER `data`,

  ADD `data_fim` DATE AFTER `data_inicio`;

EOT;
        foreach ($this->backfill as $update) {
            $connection_resultados .= "\n" . $update . "\n";
        }

        return [
            'resultados' => $connection_resultados,
        ];
    }

    /** Drops the two columns. Dates typed on juiz/evento.php since are lost. */
    public function getDownSQL(): array
    {
        $connection_resultados = <<< 'EOT'
ALTER TABLE `evento`

  DROP `data_inicio`,

  DROP `data_fim`;
EOT;

        return [
            'resultados' => $connection_resultados,
        ];
    }
}
