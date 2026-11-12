<?php
/**
 * For StoredPointsDifferTest: every prova of one event, recomputed in memory
 * by the results snapshots' FormulasJob (Input::updateVars/updatePontos with
 * the totals as stored, nothing saved), and which teams' stored vars and
 * pontos differ from that. Prints JSON: {"<EV>_<PROVA>": {"vars": n, "pontos": n}}
 * for the provas that differ.
 *
 *     REDIRECT_EVENT=24BR SKIP_AUTH=1 ENV=prod php stored-points-child.php
 *
 * Goes through src/bootstrap.php as the snapshot jobs do (the formulas count
 * on its constants and time zone), then makes the database refuse writes.
 */

use Baja\Model\ProvaQuery;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;
use Baja\ResultsSnapshot\Jobs\FormulasJob;

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';
require $root . '/src/bootstrap.php';
ReadOnlyDatabase::enforce();

$event = (string)getenv('REDIRECT_EVENT');
$differ = [];
ob_start();
foreach (ProvaQuery::create()->filterByEventoId($event)->orderByProvaId()->find() as $prova) {
    $job = FormulasJob::run($event, (string)$prova->getProvaId());
    if ($job['recompute_error'] !== null) {
        $differ[$event . '_' . $prova->getProvaId()] = ['error' => $job['recompute_error']];
        continue;
    }
    $vars = count($job['differs_from_stored']['vars']);
    $pontos = count($job['differs_from_stored']['pontos']);
    if ($vars > 0 || $pontos > 0) {
        $differ[$event . '_' . $prova->getProvaId()] = ['vars' => $vars, 'pontos' => $pontos];
    }
}
ob_end_clean();
echo json_encode($differ);
