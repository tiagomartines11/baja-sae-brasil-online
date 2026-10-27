<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot;

use Baja\Model\EventoQuery;
use Baja\ResultsSnapshot\Capture\ErrorText;
use Baja\ResultsSnapshot\Capture\ProcessPool;
use Baja\ResultsSnapshot\Tasks\Catalogue;
use Baja\ResultsSnapshot\Tasks\Task;
use RuntimeException;

/**
 * Builds the snapshots for real: lists the tasks from the database and runs
 * each one in its own PHP process, several at a time.
 */
final class LiveSnapshotSource implements SnapshotSource
{
    /**
     * @param string $projectRoot the baja-php folder
     * @param int    $parallel    how many processes may run at once
     */
    public function __construct(private string $projectRoot, private int $parallel)
    {
    }

    public function build(?array $onlyEvents, callable $progress): Build
    {
        $tasks = Catalogue::tasks($onlyEvents);
        $progress(count($tasks) . ' snapshots to build, ' . $this->parallel . ' at a time');

        $workDir = sys_get_temp_dir() . '/results-snapshot-run-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($workDir, 0700)) {
            throw new RuntimeException('Could not create ' . $workDir);
        }

        try {
            $byKey = [];
            $children = [];
            foreach ($tasks as $index => $task) {
                // Not the bare number: PHP would turn the key back into an int.
                $key = 'task' . $index;
                $byKey[$key] = $task;
                $children[$key] = $task->child($this->projectRoot, $workDir . '/' . $key . '.json', $workDir . '/' . $key . '.err');
            }

            $errors = new ErrorText($this->projectRoot);
            $documents = [];
            $warnings = [];
            $done = 0;
            (new ProcessPool($this->parallel))->run(
                $children,
                function (string $key, int $exitCode) use ($byKey, $children, $errors, &$documents, &$warnings, &$done, $progress, $tasks): void {
                    /** @var Task $task */
                    $task = $byKey[$key];
                    $finished = $task->finish($children[$key], $exitCode, $errors);
                    $documents[$task->path()] = $finished['document'];
                    $warnings[$task->path()] = $finished['warnings'];
                    @unlink($children[$key]->resultFile);
                    @unlink($children[$key]->stderrFile);
                    if (++$done % 100 === 0) {
                        $progress($done . ' / ' . count($tasks));
                    }
                }
            );
        } finally {
            foreach (glob($workDir . '/*') ?: [] as $leftover) {
                unlink($leftover);
            }
            rmdir($workDir);
        }

        ksort($documents, SORT_STRING);
        return new Build($documents, $warnings);
    }

    public function missingEvents(array $events): array
    {
        $found = [];
        foreach (EventoQuery::create()->filterByEventoId($events)->find() as $evento) {
            $found[] = (string)$evento->getEventoId();
        }
        return array_values(array_diff($events, $found));
    }
}
