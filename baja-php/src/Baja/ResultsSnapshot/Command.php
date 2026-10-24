<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot;

use Baja\ResultsSnapshot\Files\Comparison;
use Baja\ResultsSnapshot\Files\SnapshotFiles;
use Throwable;

/**
 * scripts/results-snapshot.php, minus the wiring: reads the options, builds
 * the snapshots and either compares them with a snapshot folder (--check)
 * or writes them into it (--update).
 *
 * Exit codes: 0 all good, 1 snapshots differ (--check only), 2 could not
 * run (bad options, database trouble).
 */
final class Command
{
    public const OK = 0;
    public const DIFFERENT = 1;
    public const CANNOT_RUN = 2;

    private const USAGE = <<<'TXT'
        Usage: php scripts/results-snapshot.php --check|--update [--dir <path>] [--events <list>] [--jobs <n>]

          --check           rebuild every snapshot into a temporary folder, compare it with the
                            snapshot folder, print what differs, exit 1 if anything does
          --update          rewrite the snapshot folder with what the code produces now
                            (the "accept" step: commit the result and let review see it)
          --dir <path>      the snapshot folder (default: tests/results-snapshots)
          --events <list>   only these events, comma separated, e.g. 22BR,23SU
                            (default: every event with finalizado = 1)
          --jobs <n>        how many PHP processes to run at once (default: 4)

        Read-only: it never writes to the database. See docs/results-snapshots.md.

        TXT;

    /** @var callable(int): SnapshotSource */
    private $sourceFactory;

    /** @var callable(string): void */
    private $out;

    /**
     * @param callable(int): SnapshotSource $sourceFactory makes the source, given --jobs
     * @param string                         $defaultDir    the committed folder, tests/results-snapshots
     * @param callable(string): void         $out           where to print
     */
    public function __construct(callable $sourceFactory, private string $defaultDir, callable $out)
    {
        $this->sourceFactory = $sourceFactory;
        $this->out = $out;
    }

    /** @param list<string> $arguments the command line, without the script name */
    public function run(array $arguments): int
    {
        $options = self::parse($arguments, $this->defaultDir);
        if (is_string($options)) {
            $this->say($options . "\n\n" . self::USAGE);
            return self::CANNOT_RUN;
        }

        $started = microtime(true);
        try {
            $source = ($this->sourceFactory)($options['jobs']);
            $fresh = SnapshotFiles::encodeAll($source->build($options['events'], fn(string $line) => $this->say($line . "\n")));
        } catch (Throwable $e) {
            $this->say('Could not build the snapshots: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
            return self::CANNOT_RUN;
        }
        $summary = sprintf(
            "%d snapshot files, %.1f MB, built in %.0f s.\n",
            count($fresh),
            array_sum(array_map('strlen', $fresh)) / 1048576,
            microtime(true) - $started
        );

        if ($options['mode'] === 'update') {
            SnapshotFiles::replace($options['dir'], $fresh, $options['events']);
            $this->say('Updated ' . $options['dir'] . ': ' . $summary);
            return self::OK;
        }
        return $this->check($options['dir'], $fresh, $options['events'], $summary);
    }

    /**
     * @param array<string, string> $fresh
     * @param list<string>|null     $events
     */
    private function check(string $dir, array $fresh, ?array $events, string $summary): int
    {
        if (!is_dir($dir)) {
            $this->say('No snapshot folder at ' . $dir . ". Create one with --update first.\n");
            return self::CANNOT_RUN;
        }

        $comparison = new Comparison(SnapshotFiles::read($dir, $events), $fresh);
        if ($comparison->isClean()) {
            $this->say('Results snapshots match ' . $dir . '. ' . $summary);
            return self::OK;
        }

        // Kept for inspection: diff -r <dir> <this folder> shows every byte.
        $freshDir = sys_get_temp_dir() . '/results-snapshot-check-' . bin2hex(random_bytes(4));
        SnapshotFiles::replace($freshDir, $fresh);
        $this->say(
            'Results snapshots DIFFER from ' . $dir . " (before -> now):\n\n"
            . $comparison->report() . "\n"
            . count($comparison->differences()) . ' of ' . count($fresh) . ' files differ. ' . $summary
            . 'The fresh snapshots are in ' . $freshDir . "\n"
            . "If every change above is intended, accept it with --update and commit the result.\n"
        );
        return self::DIFFERENT;
    }

    /**
     * Accepts both "--dir path" and "--dir=path" (same for --events, --jobs).
     *
     * @param list<string> $arguments
     * @return array{mode: string, dir: string, events: list<string>|null, jobs: int}|string the options, or what is wrong with them
     */
    private static function parse(array $arguments, string $defaultDir): array|string
    {
        $mode = null;
        $values = ['dir' => null, 'events' => null, 'jobs' => null];
        for ($i = 0; $i < count($arguments); $i++) {
            $argument = $arguments[$i];
            if ($argument === '--check' || $argument === '--update') {
                if ($mode !== null) {
                    return 'Choose one of --check and --update.';
                }
                $mode = substr($argument, 2);
                continue;
            }
            if (preg_match('/^--(dir|events|jobs)(?:=(.*))?$/s', $argument, $match) !== 1) {
                return 'Unknown option: ' . $argument;
            }
            $value = $match[2] ?? $arguments[++$i] ?? null;
            if ($value === null || $value === '') {
                return '--' . $match[1] . ' needs a value.';
            }
            $values[$match[1]] = $value;
        }
        if ($mode === null) {
            return 'Choose one of --check and --update.';
        }

        $events = null;
        if ($values['events'] !== null) {
            $events = array_values(array_filter(array_map('trim', explode(',', $values['events'])), 'strlen'));
            foreach ($events as $event) {
                if (preg_match('/^\d{2}(BR|SE|SU|NE)$/', $event) !== 1) {
                    return 'Not an event code: ' . $event;
                }
            }
        }
        if ($values['jobs'] !== null && preg_match('/^[1-9]\d*$/', $values['jobs']) !== 1) {
            return '--jobs must be a whole number of 1 or more.';
        }

        return [
            'mode' => $mode,
            'dir' => rtrim($values['dir'] ?? $defaultDir, '/'),
            'events' => $events,
            'jobs' => (int)($values['jobs'] ?? 4),
        ];
    }

    private function say(string $text): void
    {
        ($this->out)($text);
    }
}
