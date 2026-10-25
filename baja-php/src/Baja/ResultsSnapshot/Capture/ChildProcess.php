<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Capture;

/**
 * One PHP process to start: the command, its environment and its folder.
 *
 * The child writes its answer as JSON to $resultFile; the standard error is
 * kept in $stderrFile only to explain a child that died without answering.
 */
final class ChildProcess
{
    /**
     * Settings pinned for every child, whatever the php.ini of the machine
     * says. They decide how a number is printed ("377.64" or
     * "377.63999999999999"), so leaving them to the machine would make the
     * same results look different on a laptop and in CI. These are PHP's
     * own defaults, which production runs with.
     */
    private const PINNED_INI = ['precision' => '14', 'serialize_precision' => '-1', 'memory_limit' => '512M'];

    /**
     * @param list<string>          $arguments script and arguments, after the "php -d ..." part
     * @param array<string, string> $environment variables to add to (or replace in) this process's environment
     * @param array<string, string> $ini extra -d settings
     */
    public function __construct(
        public readonly array $arguments,
        public readonly array $environment,
        public readonly string $workingDirectory,
        public readonly string $resultFile,
        public readonly string $stderrFile,
        public readonly array $ini = [],
    ) {
    }

    /** @return list<string> */
    public function command(): array
    {
        $command = [PHP_BINARY];
        foreach (self::PINNED_INI + $this->ini as $name => $value) {
            $command[] = '-d';
            $command[] = $name . '=' . $value;
        }
        return array_merge($command, $this->arguments);
    }

    /**
     * The full environment of the child: this process's own (so the
     * database settings, PATH and TZ carry over), plus the overrides.
     *
     * @return array<string, string>
     */
    public function fullEnvironment(): array
    {
        return array_merge(getenv(), $this->environment, ['SNAPSHOT_RESULT_FILE' => $this->resultFile]);
    }
}
