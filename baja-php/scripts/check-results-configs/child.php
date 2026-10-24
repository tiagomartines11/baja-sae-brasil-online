<?php
/**
 * Child process of scripts/check-results-configs.php. It checks ONE
 * resultado, or ONE prova, or runs ONE formula on its own:
 *
 *     php child.php resultado <evento_id> <resultado_id>
 *     php child.php prova     <evento_id> <prova_id>
 *     php child.php formula   <the formula, as JSON>
 *
 * Started by Baja\ResultsCheck\IsolatedCheck, one process each. Most broken
 * formulas raise an error, which Input::solveFormula() catches. A few kill
 * PHP instead: a formula PHP 8 cannot even compile (a nested ternary with no
 * parentheses) is a fatal error inside eval(), and no try/catch stops that.
 * In a child it only ends the child; the command sees it and reports it.
 *
 * The answer is written as JSON to SNAPSHOT_RESULT_FILE (the name comes from
 * ResultsSnapshot\Capture\ChildProcess, which is reused here):
 *
 *     {"answer": <the result, serialized and base64-encoded>, "fatal": null}
 *     {"answer": null, "fatal": {type, message, file, line}}   when PHP died
 */

use Baja\Model\Input;
use Baja\ResultsCheck\Command;
use Baja\ResultsCheck\LiveSource;
use Baja\ResultsSnapshot\Capture\ErrorRecorder;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;

$resultFile = (string)getenv('SNAPSHOT_RESULT_FILE');
if ($resultFile === '') {
    fwrite(STDERR, "child.php: SNAPSHOT_RESULT_FILE is not set\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/vendor/autoload.php';

// Runs however the process ends, a fatal error included; $answer is still
// null then, and error_get_last() holds the fatal error.
$answer = null;
register_shutdown_function(static function () use ($resultFile, &$answer): void {
    file_put_contents($resultFile, json_encode([
        'answer' => $answer === null ? null : base64_encode(serialize($answer)),
        'fatal' => ErrorRecorder::fatal(),
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
});

[, $mode, $first, $second] = $argv + [null, '', '', ''];

if ($mode === 'formula') {
    // Only to see whether this formula, alone, kills PHP. No team's data is
    // needed for that: PHP refuses to compile it whatever the variables.
    ob_start();
    Input::solveFormula([], json_decode((string)$first, true));
    ob_end_clean();
    $answer = true;
    return;
}

require_once $projectRoot . '/src/config.php';
// From here on the database refuses every write, as in the command itself.
ReadOnlyDatabase::enforce();
$source = new LiveSource();

// The checks print nothing; anything a formula prints is not ours.
ob_start();
$answer = match ($mode) {
    'resultado' => Command::checkResultado($source, (string)$first, (string)$second),
    'prova' => Command::checkProva($source, (string)$first, (string)$second),
    default => throw new InvalidArgumentException('Unknown mode: ' . $mode),
};
ob_end_clean();
