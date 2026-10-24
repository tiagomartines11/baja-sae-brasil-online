<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Tasks;

use Baja\ResultsSnapshot\Capture\ChildProcess;
use Baja\ResultsSnapshot\Capture\ErrorText;

/**
 * One snapshot file, and the PHP process that produces it.
 *
 * Two classes: a results page (PageTask), and a premiação or
 * the formulas of one prova (JobTask).
 */
abstract class Task
{
    /** Where the snapshot goes, relative to tests/results-snapshots/, e.g. "22BR/22BR_GER.json". */
    abstract public function path(): string;

    /** The process to start. It must write its answer to the given result file. */
    abstract public function child(string $projectRoot, string $resultFile, string $stderrFile): ChildProcess;

    /**
     * Turns the child's answer into the snapshot document.
     *
     * @param array<string, mixed>|null $answer the decoded result file, or null if the child wrote none
     * @return array<string, mixed>
     */
    abstract protected function document(?array $answer, ErrorText $errors): array;

    /**
     * Reads what the child left behind and builds the document. A child that
     * died without writing an answer (killed, out of memory, a crash before
     * the shutdown function ran) still gives a document, with the reason as
     * its error, so that the run goes on and the snapshot shows it.
     *
     * The PHP warnings come back beside the document, not in it: they are
     * for the --warnings report and are never compared (see WarningReport).
     *
     * @return array{document: array<string, mixed>, warnings: list<string>}
     */
    public function finish(ChildProcess $child, int $exitCode, ErrorText $errors): array
    {
        $raw = is_file($child->resultFile) ? file_get_contents($child->resultFile) : false;
        $answer = $raw === false ? null : json_decode($raw, true);
        $answer = is_array($answer) ? $answer : null;
        $document = $this->document($answer, $errors);

        if ($answer === null) {
            $stderr = is_file($child->stderrFile) ? trim((string)file_get_contents($child->stderrFile)) : '';
            $lastLine = $stderr === '' ? '' : ': ' . $errors->withoutLocation((string)array_slice(explode("\n", $stderr), -1)[0]);
            $document['error'] = 'The process ended without an answer (exit code ' . $exitCode . ')' . $lastLine;
        }
        return ['document' => $document, 'warnings' => $errors->warningLines($answer['warnings'] ?? [])];
    }

    /**
     * The fatal error of an answer, as the last key of every document: the
     * error class and message, or null if the page or job ran to the end.
     *
     * @param array<string, mixed>|null $answer
     * @return array{error: ?string}
     */
    protected static function problems(?array $answer, ErrorText $errors): array
    {
        $fatal = $answer['fatal'] ?? null;
        return ['error' => is_array($fatal) ? $errors->error($fatal) : null];
    }
}
