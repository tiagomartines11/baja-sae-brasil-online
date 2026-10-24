<?php
/**
 * Results regression snapshots: records what every results page, every
 * premiação and every prova formula computes, and checks that it still does.
 *
 *     php scripts/results-snapshot.php --check
 *     php scripts/results-snapshot.php --update
 *     php scripts/results-snapshot.php --update --dir /tmp/before --events 25BR,26BR
 *
 * The database comes from the same environment variables the app uses
 * (PHPBB_DB_HOST, PHPBB_DB_USER, PHPBB_DB_PASS; see src/config.php). The
 * tool only reads: every connection it opens is switched to read-only.
 * Everything is explained in docs/results-snapshots.md; the work is done by
 * the classes under src/Baja/ResultsSnapshot/.
 */

use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;
use Baja\ResultsSnapshot\Command;
use Baja\ResultsSnapshot\LiveSnapshotSource;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$command = new Command(
    // Connecting is left until the options have been read, so --help-like
    // mistakes need no database.
    sourceFactory: static function (int $parallel) use ($projectRoot): LiveSnapshotSource {
        require_once $projectRoot . '/src/config.php';
        ReadOnlyDatabase::enforce();
        return new LiveSnapshotSource($projectRoot, $parallel);
    },
    defaultDir: $projectRoot . '/tests/results-snapshots',
    out: static function (string $text): void {
        fwrite(STDOUT, $text);
    },
);

exit($command->run(array_slice($argv, 1)));
