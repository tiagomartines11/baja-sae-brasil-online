<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use RuntimeException;
use Throwable;

/**
 * scripts/check-results-configs.php, minus the wiring: reads the options,
 * checks every resultado and prova of the events, each in its own PHP
 * process (IsolatedCheck), prints the report and, with --sql, writes the
 * mechanical fixes to a file.
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
        Usage: php scripts/check-results-configs.php [--events <list>] [--sql <file>] [--jobs <n>]

          --events <list>   only these events, comma separated, e.g. 22BR,23SE (default: all events)
          --sql <file>      also write the mechanical fixes ("+" used to join text) to this file, as
                            UPDATEs to read and then run by hand
          --jobs <n>        how many PHP processes to run at once (default: 4)

        Read-only: it never writes to the database. See docs/results-config-check.md.

        TXT;

    /** @var callable(): LiveSource */
    private $sourceFactory;

    /** @var callable(string): void */
    private $out;

    /**
     * @param callable(): LiveSource  $sourceFactory connects to the database
     * @param callable(string): void  $out           where to print
     * @param string                  $projectRoot   the baja-php folder, where the child script is
     */
    public function __construct(callable $sourceFactory, callable $out, private string $projectRoot)
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

        $started = microtime(true);
        try {
            $source = ($this->sourceFactory)();
            $events = $source->events($options['events']);
            $missing = array_values(array_diff($options['events'] ?? [], $events));
            if ($missing !== []) {
                ($this->out)('No such event: ' . implode(', ', $missing) . "\n");
                return self::CANNOT_RUN;
            }
            $outlines = [];
            foreach ($events as $event) {
                $outlines[$event] = $source->outline($event);
            }
            ['events' => $checked, 'fixes' => $fixes] = (new IsolatedCheck($this->projectRoot, $options['jobs']))->run($outlines);
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
        ($this->out)(Report::render($checked, $options['sql'], microtime(true) - $started));
        return self::OK;
    }

    /**
     * Checks one resultado. Called by scripts/check-results-configs/child.php,
     * in a process of its own (see IsolatedCheck).
     *
     * @return array{entry: array, fix: ?array}
     *         entry: {resultado, nome, teams, findings, fix, skipped}, as Report::render() wants it
     *         fix:   for SqlFix::script(), or null when there is no mechanical fix
     */
    public static function checkResultado(LiveSource $source, string $event, string $id): array
    {
        $resultado = $source->resultados($event, $id)[0] ?? null;
        if ($resultado === null) {
            throw new RuntimeException('No resultado ' . $id . ' in ' . $event);
        }
        $colunas = json_decode($resultado['colunas'] ?? '');
        $entry = ['resultado' => $resultado['resultado'], 'nome' => $resultado['nome'], 'teams' => 0, 'findings' => [], 'fix' => null, 'skipped' => null];
        if (!is_object($colunas) || ($colunas->type ?? null) === 'tournament') {
            // A tournament is shown by torneio.php from its own tables; no columns to check.
            return ['entry' => ['skipped' => 'tournament or no columns'] + $entry, 'fix' => null];
        }
        $check = ResultadoCheck::run($colunas, $resultado['inputs']);
        $fix = FixProposal::for($colunas, $resultado['inputs'], $check['findings']);
        return [
            'entry' => ['teams' => $check['teams'], 'findings' => $check['findings'], 'fix' => $fix] + $entry,
            'fix' => $fix !== null && $fix->changes !== []
                ? ['evento' => $event, 'resultado' => $resultado['resultado'], 'nome' => $resultado['nome'], 'colunas' => $resultado['colunas'], 'fix' => $fix]
                : null,
        ];
    }

    /**
     * Checks one prova ("APR", without the event). Called like checkResultado().
     *
     * @return array{entry: array{prova: string, nome: string, teams: int, findings: list<Finding>}, fix: null}
     */
    public static function checkProva(LiveSource $source, string $event, string $id): array
    {
        $prova = $source->provas($event, $id)[0] ?? null;
        if ($prova === null) {
            throw new RuntimeException('No prova ' . $id . ' in ' . $event);
        }
        $check = ProvaCheck::run($prova['prova'], $prova['inputs']);
        return [
            'entry' => [
                'prova' => $prova['prova']->getFullCode(),
                'nome' => (string)$prova['prova']->getNome(),
                'teams' => $check['teams'],
                'findings' => $check['findings'],
            ],
            // Never a fix for a prova: saving one recomputes its stored points (BAJAAPP-56).
            'fix' => null,
        ];
    }

    /**
     * Accepts both "--events list" and "--events=list" (same for --sql, --jobs).
     *
     * @param list<string> $arguments
     * @return array{events: list<string>|null, sql: string|null, jobs: int}|string the options, or what is wrong with them
     */
    private static function parse(array $arguments): array|string
    {
        $values = ['events' => null, 'sql' => null, 'jobs' => null];
        for ($i = 0; $i < count($arguments); $i++) {
            if (preg_match('/^--(events|sql|jobs)(?:=(.*))?$/s', $arguments[$i], $match) !== 1) {
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
        if ($values['jobs'] !== null && preg_match('/^[1-9]\d*$/', $values['jobs']) !== 1) {
            return '--jobs must be a whole number of 1 or more.';
        }
        return ['events' => $events, 'sql' => $values['sql'], 'jobs' => (int)($values['jobs'] ?? 4)];
    }
}
