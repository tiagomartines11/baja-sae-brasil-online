<?php
/**
 * Child process of scripts/formula-engine-compare.php. Started by
 * Baja\FormulaCompare\Command, several at a time:
 *
 *     php child.php probe     <formulas.json> <first>
 *     php child.php prova     <context.json> <evento_id> <prova_id>
 *     php child.php resultado <context.json> <evento_id> <resultado_id>
 *     php child.php alone     <context.json> <formulas.json>
 *
 * probe: runs each formula of the list, from index <first>, with eval()
 * and no variables, to find those on which eval() kills PHP (FatalProbe).
 * Before each, it notes the index, so that when PHP dies the parent knows
 * which formula did it, and starts again after it.
 *
 * prova, resultado: compares the engines on it (Units, Recorder), with the
 * fixed now and the list of fatal formulas from <context.json>.
 *
 * alone: compares the engines on each formula of the list once, with no
 * variables: the formulas that no team runs, which would otherwise not be
 * compared at all.
 *
 * The answer is written as JSON to SNAPSHOT_RESULT_FILE (the name comes
 * from ResultsSnapshot\Capture\ChildProcess, which is reused here):
 *
 *     {"answer": <the result, serialized and base64-encoded>, "at": <index>, "fatal": null}
 *     {"answer": null, "at": <index>, "fatal": {type, message, file, line}}   when PHP died
 */

use Baja\Formula\EvalEngine;
use Baja\FormulaCompare\Recorder;
use Baja\FormulaCompare\Units;
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
// The site defines these in src/bootstrap.php, so that formulas naming them
// get the text. The comparison must run formulas as the site does.
foreach (['Pos', 'EquipeNum', 'Equipe'] as $constant) {
    if (!defined($constant)) {
        define($constant, $constant);
    }
}
date_default_timezone_set('America/Sao_Paulo');

// Runs however the process ends, a fatal error included.
$answer = null;
$at = null;
register_shutdown_function(static function () use ($resultFile, &$answer, &$at): void {
    file_put_contents($resultFile, json_encode([
        'answer' => $answer === null ? null : base64_encode(serialize($answer)),
        'at' => $at,
        'fatal' => ErrorRecorder::fatal(),
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
});

[, $mode, $file, $first, $second] = $argv + [null, '', '', '', ''];

if ($mode === 'probe') {
    $formulas = json_decode((string)file_get_contents($file), true);
    $engine = new EvalEngine(0);
    for ($at = (int)$first; $at < count($formulas); $at++) {
        // Written before each formula: if PHP dies, this says which one.
        file_put_contents($resultFile, json_encode(['answer' => null, 'at' => $at, 'fatal' => null]));
        ob_start();
        try {
            $engine->evaluate([], $formulas[$at]);
        } catch (Throwable) {
            // A normal error: not what we are looking for.
        }
        ob_end_clean();
    }
    $answer = ['done' => true];
    return;
}

$context = json_decode((string)file_get_contents($file), true);
require_once $projectRoot . '/src/config.php';
// From here on the database refuses every write.
ReadOnlyDatabase::enforce();
$recorder = new Recorder((int)$context['now'], (array)$context['fatal']);
$answer = match ($mode) {
    'prova' => Units::prova(new LiveSource(), $recorder, (string)$first, (string)$second),
    'resultado' => Units::resultado(new LiveSource(), $recorder, (string)$first, (string)$second),
    'alone' => Units::alone($recorder, json_decode((string)file_get_contents($first), true)),
    default => throw new InvalidArgumentException('Unknown mode: ' . $mode),
};
