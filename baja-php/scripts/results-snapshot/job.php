<?php
/**
 * Child process for the snapshots that are not pages:
 *
 *     php job.php premiacao <premiacao_id>
 *     php job.php formulas  <evento_id> <prova_id>
 *
 * Started by Baja\ResultsSnapshot\Tasks\JobTask, one
 * process per snapshot, for the same reasons as the pages: a fatal error
 * ends only its own snapshot, and nothing one job leaves in memory (Propel
 * objects, Resolver's cache) can leak into the next.
 *
 * It goes through src/bootstrap.php like a page does, with SKIP_AUTH and
 * REDIRECT_EVENT, because the formulas count on what bootstrap sets up (the
 * Pos/EquipeNum/Equipe constants, the time zone, error reporting).
 *
 * The answer is written as JSON to SNAPSHOT_RESULT_FILE:
 * {"document": ..., "fatal": ..., "warnings": [...]}.
 */

use Baja\ResultsSnapshot\Capture\ErrorRecorder;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;
use Baja\ResultsSnapshot\Jobs\FormulasJob;
use Baja\ResultsSnapshot\Jobs\PremiacaoJob;

$resultFile = (string)getenv('SNAPSHOT_RESULT_FILE');
if ($resultFile === '') {
    fwrite(STDERR, "job.php: SNAPSHOT_RESULT_FILE is not set\n");
    exit(2);
}

require_once __DIR__ . '/../../vendor/autoload.php';
$recorder = ErrorRecorder::install();

$document = null;
register_shutdown_function(static function () use ($resultFile, $recorder, &$document): void {
    file_put_contents($resultFile, json_encode([
        'document' => $document,
        'fatal' => ErrorRecorder::fatal(),
        'warnings' => $recorder->warnings(),
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
});

require __DIR__ . '/../../src/bootstrap.php';

// From here on the database refuses every write. See ReadOnlyDatabase.
ReadOnlyDatabase::enforce();

// The jobs print nothing; anything that does get printed is not ours.
ob_start();
$document = match ($argv[1] ?? '') {
    'premiacao' => PremiacaoJob::run((string)($argv[2] ?? '')),
    'formulas' => FormulasJob::run((string)($argv[2] ?? ''), (string)($argv[3] ?? '')),
    default => throw new InvalidArgumentException('Unknown job: ' . ($argv[1] ?? '(none)')),
};
ob_end_clean();
