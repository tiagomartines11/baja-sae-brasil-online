<?php
declare(strict_types=1);

namespace Baja\FormulaCompare;

use Baja\ResultsSnapshot\Capture\ChildProcess;
use Baja\ResultsSnapshot\Capture\ErrorText;
use Baja\ResultsSnapshot\Capture\ProcessPool;

/**
 * Finds the formulas on which eval() kills PHP, before the comparison runs
 * them: a formula PHP cannot compile ("Unparenthesized `a ? b : c ? d :
 * e`", "Cannot use isset() on the result of an expression"), or one that
 * calls exit(). Such a formula dies whatever the variables, so it is
 * enough to run each formula once, with none.
 *
 * The formulas are shared among a few children (child.php probe), each
 * running its share one after the other and noting before each one which
 * it is on. When a child dies, the formula it was on is a culprit, and a
 * new child goes on from the next one. Production has a handful of these,
 * so there are a handful of restarts.
 */
final class FatalProbe
{
    public function __construct(private string $projectRoot, private string $workDir, private int $parallel)
    {
    }

    /**
     * @param array<string, mixed> $formulas formula key => formula
     * @return array<string, string> formula key => why eval() dies on it
     */
    public function run(array $formulas): array
    {
        // Only text: a number or a list as a formula cannot kill PHP.
        $texts = array_filter($formulas, 'is_string');
        $keys = array_keys($texts);
        $shares = $keys === [] ? [] : array_chunk($keys, (int)ceil(count($keys) / $this->parallel));

        $fatal = [];
        $pending = [];
        foreach ($shares as $index => $share) {
            $file = $this->workDir . '/probe' . $index . '.json';
            file_put_contents($file, json_encode(array_map(fn($key) => $texts[$key], $share)));
            $pending[] = ['file' => $file, 'keys' => $share, 'first' => 0];
        }
        $round = 0;
        while ($pending !== []) {
            $children = [];
            foreach ($pending as $index => $share) {
                $name = 'probe' . $round . '_' . $index;
                $children[$name] = new ChildProcess(
                    arguments: [$this->projectRoot . '/scripts/formula-engine-compare/child.php', 'probe', $share['file'], (string)$share['first']],
                    environment: [],
                    workingDirectory: $this->projectRoot,
                    resultFile: $this->workDir . '/' . $name . '.result',
                    stderrFile: $this->workDir . '/' . $name . '.err',
                );
            }
            $next = [];
            (new ProcessPool($this->parallel))->run($children, function (string $name, int $exitCode) use ($children, $pending, $round, &$next, &$fatal): void {
                $share = $pending[(int)substr($name, strlen('probe' . $round . '_'))];
                $child = $children[$name];
                $written = is_file($child->resultFile) ? json_decode((string)file_get_contents($child->resultFile), true) : null;
                if (($written['answer'] ?? null) === null) {
                    // It died on the formula it was on (or, with no note at all, on the first).
                    $at = (int)($written['at'] ?? $share['first']);
                    $key = $share['keys'][$at] ?? null;
                    if ($key !== null) {
                        $fatal[$key] = self::why($written, $exitCode, $this->projectRoot);
                        if ($at + 1 < count($share['keys'])) {
                            $next[] = ['first' => $at + 1] + $share;
                        }
                    }
                }
                @unlink($child->resultFile);
                @unlink($child->stderrFile);
            });
            $pending = $next;
            $round++;
        }
        return $fatal;
    }

    private static function why(?array $written, int $exitCode, string $projectRoot): string
    {
        if (is_array($written['fatal'] ?? null)) {
            $text = (new ErrorText($projectRoot))->error($written['fatal']);
            return (string)preg_replace(['/^Fatal error: /', '/ in \S*eval\(\)\'d code on line \d+$/'], '', $text);
        }
        return 'PHP stopped (exit code ' . $exitCode . '), as exit() or die() in a formula does';
    }
}
