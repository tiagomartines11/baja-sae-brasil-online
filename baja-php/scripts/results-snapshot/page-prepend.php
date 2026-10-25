<?php
/**
 * Runs ONE results page the way nginx runs it, and records what came out.
 *
 * Used by Baja\ResultsSnapshot\Capture\PageTask, which starts a fresh PHP
 * process per page as
 *
 *     php -d auto_prepend_file=<this file> resultados/prova.php
 *
 * so the page file itself is the main script, exactly as with php-fpm. One
 * process per page, because the pages call exit and declare functions, and
 * because a page that dies must not take the others with it.
 *
 * What nginx does for resultados (baja-infra/nginx/templates/
 * baja-app-resultados.conf.template), and where it happens here:
 *
 *   fastcgi_param REDIRECT_EVENT, SKIP_AUTH   environment of this process,
 *                                             set by PageTask (the PHP
 *                                             command line copies the
 *                                             environment into $_SERVER)
 *   ?id=<resultado>                           $_GET below, from SNAPSHOT_QUERY
 *   auto_prepend_file=src/bootstrap.php       required at the end of this file
 *
 * PageTask also sets ENV=prod, so bootstrap.php turns display_errors off
 * as in production: a page that fails prints what a visitor would get, not
 * the error text. The error is still recorded, into the result file.
 *
 * Environment read here (all set by PageTask):
 *
 *   SNAPSHOT_RESULT_FILE  where to write the JSON result
 *   SNAPSHOT_QUERY        the query string, e.g. "id=22BR_GER"
 *   SNAPSHOT_SCRIPT_NAME  e.g. "/prova.php", for $_SERVER['SCRIPT_NAME']
 *   SNAPSHOT_SPOILERS     "1" to show the event as it looks DURING the event:
 *                         spoilers = 1, finalizado = 0, in memory only (see
 *                         Baja\ResultsSnapshot\Capture\DuringEventView)
 *
 * Nothing here changes what the page computes, and nothing is written to
 * the database: the MySQL session is switched to read-only before the page
 * starts (ReadOnlyDatabase). It fills in the request and listens.
 */

use Baja\ResultsSnapshot\Capture\DuringEventView;
use Baja\ResultsSnapshot\Capture\ErrorRecorder;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;

$snapshotResultFile = getenv('SNAPSHOT_RESULT_FILE');
if ($snapshotResultFile === false || $snapshotResultFile === '') {
    fwrite(STDERR, "page-prepend.php: SNAPSHOT_RESULT_FILE is not set\n");
    exit(2);
}

// bootstrap.php requires it again; require_once makes that a no-op.
require_once __DIR__ . '/../../vendor/autoload.php';

// --- The request ---------------------------------------------------------

$snapshotQuery = (string)getenv('SNAPSHOT_QUERY');
parse_str($snapshotQuery, $_GET);
$_POST = [];
$_SERVER['QUERY_STRING'] = $snapshotQuery;
$_SERVER['SCRIPT_NAME'] = (string)getenv('SNAPSHOT_SCRIPT_NAME');
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'] . '?' . $snapshotQuery;
$_SERVER['REQUEST_METHOD'] = 'GET';

// --- Listening -----------------------------------------------------------

$GLOBALS['__snapshotRecorder'] = ErrorRecorder::install();

// Everything the page prints is kept, not sent: PageTask parses it.
ob_start();

// Runs after the page ends, however it ends: normally, by exit, or by a
// fatal error.
register_shutdown_function(static function () use ($snapshotResultFile): void {
    $html = '';
    while (ob_get_level() > 0) {
        $html = ob_get_clean() . $html;
    }

    // The command line has no headers to read back, but header('Location:')
    // still sets the response code to 302.
    $status = http_response_code();
    file_put_contents($snapshotResultFile, json_encode([
        'html' => $html,
        'fatal' => ErrorRecorder::fatal(),
        'warnings' => $GLOBALS['__snapshotRecorder']->warnings(),
        'status' => $status === false ? null : $status,
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
});

// --- The real bootstrap --------------------------------------------------

require __DIR__ . '/../../src/bootstrap.php';

ReadOnlyDatabase::enforce();

if (getenv('SNAPSHOT_SPOILERS') === '1') {
    DuringEventView::show($_SERVER['REDIRECT_EVENT']);
}

unset($snapshotQuery, $snapshotResultFile);
