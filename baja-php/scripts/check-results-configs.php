<?php
/**
 * Finds broken resultado configs in the data: formulas that raise an error,
 * ranking settings that rank nobody, columns that are empty for every team.
 * With --sql, also writes the one mechanical fix ("+" used to join text) as
 * SQL for a person to read and run.
 *
 *     php scripts/check-results-configs.php
 *     php scripts/check-results-configs.php --events 25BR,26BR --sql fixes.sql
 *
 * Each resultado and prova is checked in its own PHP process
 * (scripts/check-results-configs/child.php), so that a formula that kills
 * PHP is reported instead of ending the run.
 *
 * The database comes from the same environment variables the app uses
 * (PHPBB_DB_HOST, PHPBB_DB_USER, PHPBB_DB_PASS; see src/config.php). It
 * only reads: the connection is switched to read-only first, as the
 * results snapshots do. Everything is explained in
 * docs/results-config-check.md; the work is done by the classes under
 * src/Baja/ResultsCheck/.
 */

use Baja\ResultsCheck\Command;
use Baja\ResultsCheck\LiveSource;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$command = new Command(
    // Connecting is left until the options have been read, so mistakes in
    // them need no database.
    sourceFactory: static function () use ($projectRoot): LiveSource {
        require_once $projectRoot . '/src/config.php';
        ReadOnlyDatabase::enforce();
        return new LiveSource();
    },
    out: static function (string $text): void {
        fwrite(STDOUT, $text);
    },
    projectRoot: $projectRoot,
);

exit($command->run(array_slice($argv, 1)));
