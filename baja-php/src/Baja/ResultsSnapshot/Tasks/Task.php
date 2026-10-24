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
     * @return array<string, mixed>
     */
    public function finish(ChildProcess $child, int $exitCode, ErrorText $errors): array
    {
        $raw = is_file($child->resultFile) ? file_get_contents($child->resultFile) : false;
        $answer = $raw === false ? null : json_decode($raw, true);
        $document = $this->document(is_array($answer) ? $answer : null, $errors);

        if (!is_array($answer)) {
            $stderr = is_file($child->stderrFile) ? trim((string)file_get_contents($child->stderrFile)) : '';
            $lastLine = $stderr === '' ? '' : ': ' . $errors->relative((string)array_slice(explode("\n", $stderr), -1)[0]);
            $document['error'] = 'The process ended without an answer (exit code ' . $exitCode . ')' . $lastLine;
        }
        return $document;
    }

    /**
     * The error and warnings of an answer, as the last two keys of every
     * document.
     *
     * @param array<string, mixed>|null $answer
     * @return array{error: ?string, warnings: list<string>}
     */
    protected static function problems(?array $answer, ErrorText $errors): array
    {
        $fatal = $answer['fatal'] ?? null;
        return [
            'error' => is_array($fatal) ? $errors->line($fatal) : null,
            'warnings' => $errors->warningLines($answer['warnings'] ?? []),
        ];
    }
}
