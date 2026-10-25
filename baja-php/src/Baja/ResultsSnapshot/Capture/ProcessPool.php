<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Capture;

use RuntimeException;

/**
 * Runs many short PHP processes, a few at a time.
 *
 * A full run starts about a thousand of them (one per page); one after the
 * other that takes minutes, side by side it takes well under one. Each
 * child writes its answer to a file, so nothing has to be read from pipes
 * while it runs.
 */
final class ProcessPool
{
    public function __construct(private int $parallel)
    {
        if ($parallel < 1) {
            throw new RuntimeException('At least one process must be allowed to run.');
        }
    }

    /**
     * Runs every child and calls $whenDone(key, exit code) as each one ends.
     *
     * @param array<string, ChildProcess> $children
     * @param callable(string, int): void $whenDone
     */
    public function run(array $children, callable $whenDone): void
    {
        $waiting = $children;
        /** @var array<string, resource> $running */
        $running = [];

        while ($waiting !== [] || $running !== []) {
            while ($waiting !== [] && count($running) < $this->parallel) {
                $key = (string)array_key_first($waiting);
                $running[$key] = $this->start($waiting[$key]);
                unset($waiting[$key]);
            }

            $finishedAny = false;
            foreach ($running as $key => $process) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    continue;
                }
                proc_close($process);
                unset($running[$key]);
                $finishedAny = true;
                $whenDone($key, $status['exitcode']);
            }
            if (!$finishedAny) {
                usleep(5000);
            }
        }
    }

    /** @return resource */
    private function start(ChildProcess $child)
    {
        @unlink($child->resultFile);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', $child->stderrFile, 'w'],
        ];
        $process = proc_open($child->command(), $descriptors, $pipes, $child->workingDirectory, $child->fullEnvironment());
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start: ' . implode(' ', $child->command()));
        }
        return $process;
    }
}
