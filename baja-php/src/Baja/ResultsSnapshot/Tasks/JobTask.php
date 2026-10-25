<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Tasks;

use Baja\ResultsSnapshot\Capture\ChildProcess;
use Baja\ResultsSnapshot\Capture\ErrorText;

/**
 * A snapshot computed by scripts/results-snapshot/job.php rather than by a
 * page: a premiação, or the formulas of one prova.
 */
final class JobTask extends Task
{
    /**
     * @param list<string> $arguments what job.php is called with, e.g. ["formulas", "22BR", "ACE"]
     */
    private function __construct(
        private string $eventoId,
        private string $fileName,
        private array $arguments,
    ) {
    }

    /** Baja\Premiacao\Resolver::resolve() for one premiação row. */
    public static function premiacao(string $eventoId, string $premiacaoId): self
    {
        return new self($eventoId, 'premiacao.' . $premiacaoId . '.json', ['premiacao', $premiacaoId]);
    }

    /** The vars and pontos of one prova, recomputed in memory. */
    public static function formulas(string $eventoId, string $provaId): self
    {
        return new self($eventoId, 'formulas.' . $provaId . '.json', ['formulas', $eventoId, $provaId]);
    }

    public function path(): string
    {
        return $this->eventoId . '/' . $this->fileName;
    }

    public function child(string $projectRoot, string $resultFile, string $stderrFile): ChildProcess
    {
        return new ChildProcess(
            arguments: array_merge([$projectRoot . '/scripts/results-snapshot/job.php'], $this->arguments),
            environment: ['REDIRECT_EVENT' => $this->eventoId, 'SKIP_AUTH' => '1', 'ENV' => 'prod'],
            workingDirectory: $projectRoot,
            resultFile: $resultFile,
            stderrFile: $stderrFile,
        );
    }

    protected function document(?array $answer, ErrorText $errors): array
    {
        $document = is_array($answer['document'] ?? null) ? $answer['document'] : [];
        return $document + self::problems($answer, $errors);
    }
}
