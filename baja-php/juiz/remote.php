<?php
// TODO(contagem): remove this endpoint once the new lap counting has been validated at an event.
namespace Baja\Juiz;

use Baja\Contagem\Review\ManualScoreFields;
use Baja\Contagem\Service\LockBusy;
use Baja\Model\EventoQuery;
use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;
use Propel\Runtime\Exception\PropelException;
use Baja\Session;

cors();

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

// The spreadsheet proves itself with REMOTE_KEY (src/config.php). This used
// to be `$input['key'] != $_remoteKey`, which let anybody in: the body is
// JSON, so "key" can be true, and PHP 8 takes true == any non-empty string;
// and with REMOTE_KEY empty or unset, an empty key matched it too. So: both
// must be non-empty strings, compared with hash_equals() (which also takes
// the same time whatever the key's first wrong character is). A server
// without REMOTE_KEY refuses everything.
$key = $input['key'] ?? null;
$authorized = is_string($_remoteKey) && $_remoteKey !== ''
    && is_string($key) && hash_equals($_remoteKey, $key);
if (!is_string($_remoteKey) || $_remoteKey === '') {
    error_log('juiz/remote.php: REMOTE_KEY is not configured, so every request is refused');
}
if (!$authorized) {
    header('HTTP/1.0 403 Forbidden');
    exit();
}

$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId('END');
if (!$prova) { header("Location: index.php"); exit; }

if (array_key_exists("truncate", $input) && $input["truncate"] == "all" && ManualScoreFields::isLapsProva($prova)) {
    // The rows of a laps prova also hold what people typed (DESCLAS, the
    // classification field) and, when the count publishes, the lap count
    // itself. Deleting them took all of that along, with no recount. So the
    // spreadsheet's "truncate" only empties the three fields it owns, and is
    // refused while the lap counting owns them.
    try {
        $emptied = ManualScoreFields::truncateSpreadsheetFields($prova);
    } catch (LockBusy $busy) {
        header('HTTP/1.0 503 Service Unavailable');
        die("BUSY");
    }
    if (!$emptied) {
        header('HTTP/1.0 409 Conflict');
        die("REFUSED: a contagem de voltas publica esta prova; use Zerar corrida no controle de corrida");
    }
    die("OK");
}

if (array_key_exists("truncate", $input) && $input["truncate"] == "all") {
    $allInputs = InputQuery::create()->filterByProvaId('END')->filterByEventoId($currentEventId);
    $allInputs->delete();
    $prova->setTempo(null);
    $prova->setTotals(null);
    $prova->save();
    die("OK");
}

// On a laps prova the race clock and VOLTAS/LAST/BEST have ONE writer at a
// time: the spreadsheet while the count's publish mode is "desligada", the
// lap counting in every other mode. With both writing, each overwrote the
// other's laps on every upload and the results page jumped between the two.
// So "raceTime" and "cars" are refused while the count publishes, and the
// request changes nothing (not even the half of it that could be applied).
$writesTotals = array_key_exists("raceTime", $input) || array_key_exists("cars", $input);
if ($writesTotals && !ManualScoreFields::spreadsheetMayWrite($prova)) {
    header('HTTP/1.0 409 Conflict');
    die("REFUSED: a contagem de voltas publica esta prova; a planilha so grava com a publicacao desligada");
}

if (array_key_exists("raceTime", $input)) {
    $prova->setTempo(time() - $input["raceTime"]);
    $prova->save();
}

if (array_key_exists("cars", $input)) {
    foreach ((array)$input["cars"] as $carObj) {
        if ($carObj["laps"] > 0) {
            $input = InputQuery::create()->filterByEquipeId($carObj["number"])->filterByProvaId('END')->filterByEventoId($currentEventId)->findOne();
            if (!$input) {
                $input = new Input();
                $input->setEquipeId($carObj["number"]);
                $input->setEventoId($currentEventId);
                $input->setProvaId('END');
            }
            // Merged, not replaced: the row also holds fields typed by people
            // (DESCLAS), which the spreadsheet does not send.
            $input->setDados(array_merge((array)$input->getDados(), array("VOLTAS"=> intval($carObj["laps"]), "BEST"=> intval($carObj["bestLapTime"]), "LAST"=> intval($carObj["lastPass"]))));
            try { $input->save(); } catch (PropelException $e) { continue; };
        }
    }
    $prova->refreshVarsAndPontos();
    die("OK");
}

die("NO ACTION");

function cors() {
    // Allow from any origin
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        // Decide if the origin in $_SERVER['HTTP_ORIGIN'] is one
        // you want to allow, and if so:
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');    // cache for 1 day
    }

    // Access-Control headers are received during OPTIONS requests
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
            // may also be using PUT, PATCH, HEAD etc
            header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
            header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

        exit(0);
    }
}