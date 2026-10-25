<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Baja\ResultsSnapshot\Capture\ChildProcess;
use Baja\ResultsSnapshot\Capture\ErrorText;
use Baja\ResultsSnapshot\Capture\ProcessPool;
use RuntimeException;
use Throwable;

/**
 * Checks every resultado and every prova in its own PHP process
 * (scripts/check-results-configs/child.php), several at a time, so that
 * a formula that kills PHP ends only the check of its own resultado or
 * prova.
 *
 * WHY
 *
 * Input::solveFormula() catches every error a formula RAISES. It cannot
 * catch a FATAL error: one PHP stops on outright, such as a formula it
 * cannot compile ("Unparenthesized `a ? b : c ? d : e` is not supported").
 * Run in the command's own process, the first such formula ended the whole
 * check (BAJAAPP-63).
 *
 * WHEN A CHILD DIES
 *
 * The resultado (or prova) gets one Finding::FATAL. To name the formula,
 * each of its formulas is then run alone, again one process each: the
 * formulas that die alone are the culprits. When none does (the fatal
 * error needs the teams' data, say), the finding names the resultado or
 * prova as a whole. Its other checks are not run: its child died before
 * finishing them.
 */
final class IsolatedCheck
{
    /**
     * @param string $projectRoot the baja-php folder
     * @param int    $parallel    how many processes may run at once
     */
    public function __construct(private string $projectRoot, private int $parallel)
    {
    }

    /**
     * @param array<string, array> $outlines event => LiveSource::outline() of it
     * @return array{events: list<array{event: string, resultados: list<array>, provas: list<array>}>, fixes: list<array>}
     *         the same as Command checked in one process, and the fixes for SqlFix::script()
     */
    public function run(array $outlines): array
    {
        $workDir = sys_get_temp_dir() . '/results-check-run-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($workDir, 0700)) {
            throw new RuntimeException('Could not create ' . $workDir);
        }
        try {
            // 1. One child per resultado and per prova.
            $jobs = [];
            foreach ($outlines as $event => $outline) {
                foreach ($outline['resultados'] as $resultado) {
                    $jobs[] = ['kind' => 'resultado', 'event' => $event, 'id' => $resultado['resultado'], 'outline' => $resultado];
                }
                foreach ($outline['provas'] as $prova) {
                    $jobs[] = ['kind' => 'prova', 'event' => $event, 'id' => $prova['code'], 'outline' => $prova];
                }
            }
            $outcomes = $this->runAll($workDir, array_map(
                fn(array $job) => [$job['kind'], (string)$job['event'], $job['id']],
                $jobs,
            ));

            // 2. For each one that died, each of its formulas alone.
            $slots = [];
            $formulas = [];
            foreach ($jobs as $index => $job) {
                if (isset($outcomes[$index]['fatal'])) {
                    $slots[$index] = self::slots($job);
                    foreach ($slots[$index] as $slot) {
                        $formulas[self::formulaKey($slot)] = $slot->formula;
                    }
                }
            }
            $alone = $this->runAll($workDir, array_map(
                fn($formula) => ['formula', (string)json_encode($formula)],
                $formulas,
            ));

            // 3. Back in the order of the events, as the report wants them.
            $events = [];
            $fixes = [];
            foreach ($outlines as $event => $outline) {
                $events[$event] = ['event' => (string)$event, 'resultados' => [], 'provas' => []];
            }
            foreach ($jobs as $index => $job) {
                $outcome = $outcomes[$index];
                $list = $job['kind'] === 'resultado' ? 'resultados' : 'provas';
                if (isset($outcome['answer'])) {
                    $events[$job['event']][$list][] = $outcome['answer']['entry'];
                    if ($outcome['answer']['fix'] !== null) {
                        $fixes[] = $outcome['answer']['fix'];
                    }
                    continue;
                }
                $findings = self::fatalFindings($job, $outcome['fatal'], $slots[$index], $alone);
                $events[$job['event']][$list][] = $job['kind'] === 'resultado'
                    ? ['resultado' => $job['id'], 'nome' => (string)$job['outline']['nome'], 'teams' => null, 'findings' => $findings, 'fix' => null, 'skipped' => null]
                    : ['prova' => $job['outline']['prova'], 'nome' => (string)$job['outline']['nome'], 'teams' => null, 'findings' => $findings];
            }
            return ['events' => array_values($events), 'fixes' => $fixes];
        } finally {
            foreach (glob($workDir . '/*') ?: [] as $leftover) {
                unlink($leftover);
            }
            rmdir($workDir);
        }
    }

    /**
     * Runs child.php once per entry, with those arguments, and reads back
     * each one's answer, or why it has none.
     *
     * @param array<array-key, list<string>> $arguments
     * @return array<array-key, array{answer: mixed}|array{fatal: string}> with the same keys
     */
    private function runAll(string $workDir, array $arguments): array
    {
        $children = [];
        $keys = [];
        foreach ($arguments as $key => $childArguments) {
            // Not the bare key: PHP would turn a numeric one back into an int.
            $name = 'child' . count($children);
            $keys[$name] = $key;
            $children[$name] = new ChildProcess(
                arguments: array_merge([$this->projectRoot . '/scripts/check-results-configs/child.php'], $childArguments),
                environment: [],
                workingDirectory: $this->projectRoot,
                resultFile: $workDir . '/' . $name . '.json',
                stderrFile: $workDir . '/' . $name . '.err',
            );
        }

        $outcomes = [];
        (new ProcessPool($this->parallel))->run($children, function (string $name, int $exitCode) use ($children, $keys, &$outcomes): void {
            $outcomes[$keys[$name]] = $this->outcome($children[$name], $exitCode);
            @unlink($children[$name]->resultFile);
            @unlink($children[$name]->stderrFile);
        });
        return $outcomes;
    }

    /** @return array{answer: mixed}|array{fatal: string} */
    private function outcome(ChildProcess $child, int $exitCode): array
    {
        $raw = is_file($child->resultFile) ? file_get_contents($child->resultFile) : false;
        $written = $raw === false ? null : json_decode($raw, true);
        if (is_string($written['answer'] ?? null)) {
            // Written by our own child.php a moment ago; only our classes are let in.
            $answer = unserialize((string)base64_decode($written['answer']), ['allowed_classes' => [
                Finding::class, FormulaSlot::class, FixProposal::class, \stdClass::class,
            ]]);
            return ['answer' => $answer];
        }

        $errors = new ErrorText($this->projectRoot);
        if (is_array($written['fatal'] ?? null)) {
            // "Fatal error: Unparenthesized ..." -> "Unparenthesized ...": the report says "fatal" already.
            return ['fatal' => (string)preg_replace('/^Fatal error: /', '', $errors->error($written['fatal']))];
        }
        if (is_array($written)) {
            // The shutdown function ran, with no error: something called exit().
            return ['fatal' => 'PHP stopped without an error (exit code ' . $exitCode . '), as exit() or die() in a formula does'];
        }
        $stderr = is_file($child->stderrFile) ? trim((string)file_get_contents($child->stderrFile)) : '';
        $lastLine = $stderr === '' ? '' : ': ' . $errors->withoutLocation((string)array_slice(explode("\n", $stderr), -1)[0]);
        return ['fatal' => 'the process ended without an answer (exit code ' . $exitCode . ')' . $lastLine];
    }

    /**
     * The formulas of the resultado or prova, from the config as stored.
     *
     * @return list<FormulaSlot>
     */
    private static function slots(array $job): array
    {
        try {
            $slots = $job['kind'] === 'resultado'
                ? (is_object($colunas = json_decode($job['outline']['colunas'] ?? '')) ? FormulaSlot::ofResultado($colunas) : [])
                : FormulaSlot::ofProva(json_decode($job['outline']['params'] ?? ''));
        } catch (Throwable) {
            return [];  // a config too broken to list: the finding names the whole resultado
        }
        // Only text can be run as a formula; anything else is not one.
        return array_values(array_filter($slots, fn(FormulaSlot $slot) => is_string($slot->formula) && trim($slot->formula) !== ''));
    }

    /**
     * One finding for each formula that dies alone, or one for the whole
     * resultado or prova when none does.
     *
     * @param list<FormulaSlot> $slots
     * @param array<string, array{answer: mixed}|array{fatal: string}> $alone formula key => outcome
     * @return list<Finding>
     */
    private static function fatalFindings(array $job, string $fatal, array $slots, array $alone): array
    {
        $findings = [];
        foreach ($slots as $slot) {
            $outcome = $alone[self::formulaKey($slot)] ?? null;
            if (isset($outcome['fatal'])) {
                $findings[] = new Finding(Finding::FATAL, $slot->where, $outcome['fatal'], $slot->formula, slot: $slot);
            }
        }
        if ($findings !== []) {
            return $findings;
        }
        return [new Finding(
            Finding::FATAL,
            'the ' . $job['kind'] . ' as a whole',
            $fatal . '  (none of its formulas dies when run alone, so which one is to blame is not known)',
        )];
    }

    private static function formulaKey(FormulaSlot $slot): string
    {
        return 'formula:' . $slot->formula;
    }
}
