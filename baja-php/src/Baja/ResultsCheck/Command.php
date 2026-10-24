<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Throwable;

/**
 * scripts/check-results-configs.php, minus the wiring: reads the options,
 * checks every resultado and prova of the events, prints the report and,
 * with --sql, writes the mechanical fixes to a file.
 *
 * Exit codes: 0 whenever the report could be made, whatever it found (it
 * is a report, not a test); 2 when it could not run (bad options, no
 * database, the --sql file cannot be written).
 */
final class Command
{
    public const OK = 0;
    public const CANNOT_RUN = 2;

    private const USAGE = <<<'TXT'
        Usage: php scripts/check-results-configs.php [--events <list>] [--sql <file>]

          --events <list>   only these events, comma separated, e.g. 22BR,23SE (default: all events)
          --sql <file>      also write the mechanical fixes ("+" used to join text) to this file, as
                            UPDATEs to read and then run by hand

        Read-only: it never writes to the database. See docs/results-config-check.md.

        TXT;

    /** @var callable(): LiveSource */
    private $sourceFactory;

    /** @var callable(string): void */
    private $out;

    /**
     * @param callable(): LiveSource  $sourceFactory connects to the database
     * @param callable(string): void  $out           where to print
     */
    public function __construct(callable $sourceFactory, callable $out)
    {
        $this->sourceFactory = $sourceFactory;
        $this->out = $out;
    }

    /** @param list<string> $arguments the command line, without the script name */
    public function run(array $arguments): int
    {
        $options = self::parse($arguments);
        if (is_string($options)) {
            ($this->out)($options . "\n\n" . self::USAGE);
            return self::CANNOT_RUN;
        }

        try {
            $source = ($this->sourceFactory)();
            $events = $source->events($options['events']);
            $missing = array_values(array_diff($options['events'] ?? [], $events));
            if ($missing !== []) {
                ($this->out)('No such event: ' . implode(', ', $missing) . "\n");
                return self::CANNOT_RUN;
            }
            $checked = [];
            $fixes = [];
            foreach ($events as $event) {
                $checked[] = self::checkEvent($source, $event, $fixes);
            }
        } catch (Throwable $e) {
            ($this->out)('Could not check: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
            return self::CANNOT_RUN;
        }

        if ($options['sql'] !== null) {
            $about = [
                'Made ' . date('Y-m-d H:i') . ' from ' . (count($events) === 1 ? 'event ' : 'events ') . implode(', ', $events) . '.',
            ];
            if (@file_put_contents($options['sql'], SqlFix::script($fixes, $about)) === false) {
                ($this->out)('Could not write ' . $options['sql'] . "\n");
                return self::CANNOT_RUN;
            }
        }
        ($this->out)(Report::render($checked, $options['sql']));
        return self::OK;
    }

    /**
     * @param list<array> $fixes the fixes found so far, for SqlFix::script()
     * @return array{event: string, resultados: list<array>, provas: list<array>}
     */
    private static function checkEvent(LiveSource $source, string $event, array &$fixes): array
    {
        $resultados = [];
        foreach ($source->resultados($event) as $resultado) {
            $colunas = json_decode($resultado['colunas'] ?? '');
            $entry = ['resultado' => $resultado['resultado'], 'nome' => $resultado['nome'], 'teams' => 0, 'findings' => [], 'fix' => null, 'skipped' => null];
            if (!is_object($colunas) || ($colunas->type ?? null) === 'tournament') {
                // A tournament is shown by torneio.php from its own tables; no columns to check.
                $resultados[] = ['skipped' => 'tournament or no columns'] + $entry;
                continue;
            }
            $check = ResultadoCheck::run($colunas, $resultado['inputs']);
            $fix = FixProposal::for($colunas, $resultado['inputs'], $check['findings']);
            if ($fix !== null && $fix->changes !== []) {
                $fixes[] = ['evento' => $event, 'resultado' => $resultado['resultado'], 'nome' => $resultado['nome'], 'colunas' => $resultado['colunas'], 'fix' => $fix];
            }
            $resultados[] = ['teams' => $check['teams'], 'findings' => $check['findings'], 'fix' => $fix] + $entry;
        }

        $provas = [];
        foreach ($source->provas($event) as $prova) {
            $check = ProvaCheck::run($prova['prova'], $prova['inputs']);
            $provas[] = [
                'prova' => $prova['prova']->getFullCode(),
                'nome' => (string)$prova['prova']->getNome(),
                'teams' => $check['teams'],
                'findings' => $check['findings'],
            ];
        }
        return ['event' => $event, 'resultados' => $resultados, 'provas' => $provas];
    }

    /**
     * Accepts both "--events list" and "--events=list" (same for --sql).
     *
     * @param list<string> $arguments
     * @return array{events: list<string>|null, sql: string|null}|string the options, or what is wrong with them
     */
    private static function parse(array $arguments): array|string
    {
        $values = ['events' => null, 'sql' => null];
        for ($i = 0; $i < count($arguments); $i++) {
            if (preg_match('/^--(events|sql)(?:=(.*))?$/s', $arguments[$i], $match) !== 1) {
                return 'Unknown option: ' . $arguments[$i];
            }
            $value = $match[2] ?? $arguments[++$i] ?? null;
            if ($value === null || $value === '') {
                return '--' . $match[1] . ' needs a value.';
            }
            $values[$match[1]] = $value;
        }

        $events = null;
        if ($values['events'] !== null) {
            $events = array_values(array_filter(array_map('trim', explode(',', strtoupper($values['events']))), 'strlen'));
            foreach ($events as $event) {
                if (preg_match('/^[0-9A-Z]{4}$/', $event) !== 1) {
                    return 'Not an event code: ' . $event;
                }
            }
        }
        return ['events' => $events, 'sql' => $values['sql']];
    }
}
