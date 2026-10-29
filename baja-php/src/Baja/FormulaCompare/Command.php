<?php
declare(strict_types=1);

namespace Baja\FormulaCompare;

use Baja\Evento\EventCode;
use Baja\ResultsCheck\FormulaSlot;
use Baja\ResultsCheck\LiveSource;
use Baja\ResultsSnapshot\Capture\ChildProcess;
use Baja\ResultsSnapshot\Capture\ErrorText;
use Baja\ResultsSnapshot\Capture\ProcessPool;
use RuntimeException;
use Throwable;

/**
 * scripts/formula-engine-compare.php, minus the wiring. For every formula
 * of every prova and resultado of the database (or of --events), for
 * every team, it works the formula out with eval() and with SafeEngine,
 * and prints where they differ, grouped by formula (Report).
 *
 *   1. The list of work: each event's provas and resultados (LiveSource).
 *   2. FatalProbe: which formulas make eval() kill PHP. Those cannot be
 *      run with eval() in a process that must survive.
 *   3. One child process per prova and per resultado
 *      (scripts/formula-engine-compare/child.php), several at a time,
 *      each running the site's own code with the Recorder (Units).
 *   4. The counts added up, and the report.
 *
 * Read-only, like the results snapshots: the children switch their
 * database session to read-only before reading anything.
 *
 * Exit codes: 0 when nothing differs but the deliberate differences
 * (eval() killing PHP); 1 when something else differs, or a child died;
 * 2 when it could not run.
 */
final class Command
{
    public const SAME = 0;
    public const DIFFERENT = 1;
    public const CANNOT_RUN = 2;

    private const USAGE = <<<'TXT'
        Usage: php scripts/formula-engine-compare.php [--events <list>] [--jobs <n>] [--now <seconds>]

          --events <list>   only these events, comma separated, e.g. 19SU,26BR (default: all events)
          --jobs <n>        how many PHP processes to run at once (default: 4)
          --now <seconds>   the "now" both engines see in time() and date(), as a Unix
                            time (default: the time the run starts)

        Read-only: it never writes to the database. See docs/formula-engine.md.

        TXT;

    /** @var callable(): LiveSource */
    private $sourceFactory;

    /** @var callable(string): void */
    private $out;

    /**
     * @param callable(): LiveSource $sourceFactory connects to the database
     * @param callable(string): void $out           where to print
     * @param string                 $projectRoot   the baja-php folder
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
        $now = $options['now'] ?? time();

        $workDir = sys_get_temp_dir() . '/formula-compare-' . getmypid() . '-' . bin2hex(random_bytes(4));
        try {
            $source = ($this->sourceFactory)();
            $events = $source->events($options['events']);
            $missing = array_values(array_diff($options['events'] ?? [], $events));
            if ($missing !== []) {
                ($this->out)('No such event: ' . implode(', ', $missing) . "\n");
                return self::CANNOT_RUN;
            }
            if (!mkdir($workDir, 0700)) {
                throw new RuntimeException('Could not create ' . $workDir);
            }

            $units = self::units($source, $events);
            $places = self::places($units);
            $fatal = (new FatalProbe($this->projectRoot, $workDir, $options['jobs']))->run(array_map(fn($place) => $place['formula'], $places));
            ($this->out)(count($units) . ' provas and resultados to compare, ' . $options['jobs'] . " at a time\n");
            $outcome = $this->compare($workDir, $units, $now, $fatal, $options['jobs']);
            $notRun = array_diff_key($places, array_filter($outcome['formulas'], fn($stats) => $stats['evaluations'] > 0));
            $outcome['formulas'] += $this->alone($workDir, $notRun, $now, $fatal);
        } catch (Throwable $e) {
            ($this->out)('Could not compare: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
            return self::CANNOT_RUN;
        } finally {
            foreach (glob($workDir . '/*') ?: [] as $leftover) {
                unlink($leftover);
            }
            @rmdir($workDir);
        }

        $report = new Report($outcome['formulas'], $places, $outcome['died'], array_keys($notRun), [
            'events' => count($events),
            'provas' => count(array_filter($units, fn($unit) => $unit['kind'] === 'prova')),
            'resultados' => count(array_filter($units, fn($unit) => $unit['kind'] === 'resultado')),
            'now' => $now,
            'seconds' => microtime(true) - $started,
        ]);
        ($this->out)($report->render());
        return $report->hasUnexplainedDifferences() ? self::DIFFERENT : self::SAME;
    }

    /**
     * Every prova and resultado of the events, with the formulas of its
     * config (for the report's "where").
     *
     * @param list<string> $events
     * @return list<array{kind: string, event: string, id: string, name: string, slots: list<FormulaSlot>}>
     */
    private static function units(LiveSource $source, array $events): array
    {
        $units = [];
        foreach ($events as $event) {
            $outline = $source->outline($event);
            foreach ($outline['provas'] as $prova) {
                $params = json_decode($prova['params'] ?? '');
                $units[] = ['kind' => 'prova', 'event' => $event, 'id' => (string)$prova['code'], 'name' => (string)$prova['prova'], 'slots' => self::provaSlots($params)];
            }
            foreach ($outline['resultados'] as $resultado) {
                $colunas = json_decode($resultado['colunas'] ?? '');
                $slots = is_object($colunas) && ($colunas->type ?? null) !== 'tournament' ? self::resultadoSlots($colunas) : [];
                $units[] = ['kind' => 'resultado', 'event' => $event, 'id' => (string)$resultado['resultado'], 'name' => (string)$resultado['resultado'], 'slots' => $slots];
            }
        }
        return $units;
    }

    /** @return list<FormulaSlot> vars, min_max, pontos, and a tournament's seeding formulas */
    private static function provaSlots(mixed $params): array
    {
        $slots = FormulaSlot::ofProva($params);
        $seeding = is_object($params) ? ($params->seeding ?? null) : null;
        if (is_object($seeding)) {
            if (isset($seeding->able->formula)) {
                $slots[] = new FormulaSlot('seeding.able.formula', ['seeding', 'able', 'formula'], $seeding->able->formula);
            }
            foreach ((array)($seeding->classification ?? []) as $index => $classification) {
                if (is_object($classification) && isset($classification->formula) && $classification->formula != 'random') {
                    $slots[] = new FormulaSlot('seeding.classification[' . $index . '].formula', ['seeding', 'classification', $index, 'formula'], $classification->formula);
                }
            }
        }
        return $slots;
    }

    /** @return list<FormulaSlot> */
    private static function resultadoSlots(object $colunas): array
    {
        try {
            return FormulaSlot::ofResultado($colunas);
        } catch (Throwable) {
            return [];  // a config too broken to list; its child reports what happens
        }
    }

    /**
     * Where each formula is written, by formula key.
     *
     * @return array<string, array{formula: mixed, where: list<string>}>
     */
    private static function places(array $units): array
    {
        $places = [];
        foreach ($units as $unit) {
            foreach ($unit['slots'] as $slot) {
                $key = Recorder::key($slot->formula);
                $places[$key] ??= ['formula' => $slot->formula, 'where' => []];
                $places[$key]['where'][] = $unit['name'] . ' ' . $unit['kind'] . ' ' . $slot->where;
            }
        }
        return $places;
    }

    /**
     * @param array<string, string> $fatal
     * @return array{formulas: array<string, array>, died: list<string>}
     */
    private function compare(string $workDir, array $units, int $now, array $fatal, int $jobs): array
    {
        $contextFile = $workDir . '/context.json';
        file_put_contents($contextFile, json_encode(['now' => $now, 'fatal' => $fatal]));

        $children = [];
        foreach ($units as $index => $unit) {
            $name = 'unit' . $index;
            $children[$name] = new ChildProcess(
                arguments: [$this->projectRoot . '/scripts/formula-engine-compare/child.php', $unit['kind'], $contextFile, $unit['event'], $unit['id']],
                environment: [],
                workingDirectory: $this->projectRoot,
                resultFile: $workDir . '/' . $name . '.json',
                stderrFile: $workDir . '/' . $name . '.err',
            );
        }

        $formulas = [];
        $died = [];
        $done = 0;
        (new ProcessPool($jobs))->run($children, function (string $name, int $exitCode) use ($children, $units, &$formulas, &$died, &$done): void {
            $unit = $units[(int)substr($name, 4)];
            $answer = self::answer($children[$name], $exitCode, $this->projectRoot);
            if (is_string($answer)) {
                $died[] = $unit['name'] . ' ' . $unit['kind'] . ': ' . $answer;
            } else {
                Recorder::merge($formulas, $answer['formulas']);
            }
            @unlink($children[$name]->resultFile);
            @unlink($children[$name]->stderrFile);
            if (++$done % 100 === 0) {
                ($this->out)($done . ' / ' . count($children) . "\n");
            }
        });
        return ['formulas' => $formulas, 'died' => $died];
    }

    /**
     * The formulas no team ran, compared once each with no variables, in
     * one child.
     *
     * @param array<string, array{formula: mixed, where: list<string>}> $notRun
     * @param array<string, string> $fatal
     * @return array<string, array> Recorder statistics
     */
    private function alone(string $workDir, array $notRun, int $now, array $fatal): array
    {
        if ($notRun === []) {
            return [];
        }
        $contextFile = $workDir . '/context.json';
        $listFile = $workDir . '/alone.json';
        file_put_contents($listFile, json_encode(array_values(array_map(fn($place) => serialize($place['formula']), $notRun))));
        $child = new ChildProcess(
            arguments: [$this->projectRoot . '/scripts/formula-engine-compare/child.php', 'alone', $contextFile, $listFile],
            environment: [],
            workingDirectory: $this->projectRoot,
            resultFile: $workDir . '/alone.result',
            stderrFile: $workDir . '/alone.err',
        );
        $formulas = [];
        (new ProcessPool(1))->run(['alone' => $child], function (string $name, int $exitCode) use ($child, &$formulas): void {
            $answer = self::answer($child, $exitCode, $this->projectRoot);
            if (is_string($answer)) {
                throw new RuntimeException('Comparing the formulas no team runs: ' . $answer);
            }
            $formulas = $answer['formulas'];
        });
        return $formulas;
    }

    /**
     * What a child answered, or why it died.
     *
     * @return array{teams: int, formulas: array<string, array>}|string
     */
    public static function answer(ChildProcess $child, int $exitCode, string $projectRoot): array|string
    {
        $raw = is_file($child->resultFile) ? file_get_contents($child->resultFile) : false;
        $written = $raw === false ? null : json_decode($raw, true);
        if (is_string($written['answer'] ?? null)) {
            // Written by our own child.php a moment ago: arrays, and the odd
            // JSON object used as a formula (stdClass).
            return unserialize((string)base64_decode($written['answer']), ['allowed_classes' => [\stdClass::class]]);
        }
        $errors = new ErrorText($projectRoot);
        if (is_array($written['fatal'] ?? null)) {
            return (string)preg_replace('/^Fatal error: /', '', $errors->error($written['fatal']));
        }
        if (is_array($written)) {
            return 'PHP stopped without an error (exit code ' . $exitCode . '), as exit() or die() in a formula does';
        }
        $stderr = is_file($child->stderrFile) ? trim((string)file_get_contents($child->stderrFile)) : '';
        return 'the process ended without an answer (exit code ' . $exitCode . ')'
            . ($stderr === '' ? '' : ': ' . $errors->withoutLocation((string)array_slice(explode("\n", $stderr), -1)[0]));
    }

    /**
     * Accepts both "--events list" and "--events=list" (same for the others).
     *
     * @param list<string> $arguments
     * @return array{events: list<string>|null, jobs: int, now: int|null}|string the options, or what is wrong with them
     */
    private static function parse(array $arguments): array|string
    {
        $values = ['events' => null, 'jobs' => null, 'now' => null];
        for ($i = 0; $i < count($arguments); $i++) {
            if (preg_match('/^--(events|jobs|now)(?:=(.*))?$/s', $arguments[$i], $match) !== 1) {
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
                if (!EventCode::isValid($event)) {
                    return 'Not an event code: ' . $event;
                }
            }
        }
        if ($values['jobs'] !== null && preg_match('/^[1-9]\d*$/', $values['jobs']) !== 1) {
            return '--jobs must be a whole number of 1 or more.';
        }
        if ($values['now'] !== null && preg_match('/^\d+$/', $values['now']) !== 1) {
            return '--now must be a Unix time in seconds, e.g. 1760000000.';
        }
        return [
            'events' => $events,
            'jobs' => (int)($values['jobs'] ?? 4),
            'now' => $values['now'] === null ? null : (int)$values['now'],
        ];
    }
}
