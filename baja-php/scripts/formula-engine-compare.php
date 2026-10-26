<?php
/**
 * Compares the two formula engines on every formula of the database: for
 * every formula of every prova and resultado config, for every team where
 * it applies, eval() and SafeEngine work it out, and the differences are
 * printed, grouped by formula.
 *
 *     php scripts/formula-engine-compare.php
 *     php scripts/formula-engine-compare.php --events 19SU,26BR --jobs 8
 *
 * Exit code 0 when only the deliberate differences remain, 1 when anything
 * else differs, 2 when it could not run.
 *
 * The database comes from the same environment variables the app uses
 * (PHPBB_DB_HOST, PHPBB_DB_USER, PHPBB_DB_PASS; see src/config.php). It
 * only reads: every connection is switched to read-only, as the results
 * snapshots do. How to run it on staging against a copy of production is
 * in docs/formula-engine.md; the work is done by the classes under
 * src/Baja/FormulaCompare/.
 */

use Baja\FormulaCompare\Command;
use Baja\ResultsCheck\LiveSource;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
date_default_timezone_set('America/Sao_Paulo');

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
