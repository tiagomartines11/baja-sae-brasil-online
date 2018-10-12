<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;
use Propel\Runtime\Exception\PropelException;

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();

if (!isset($input) || !isset($input['key']) || $input['key'] != $_remoteKey || $currentEventId != $_remoteValidFor) {
    header('HTTP/1.0 403 Forbidden');
    exit();
}

$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId('END');
if (!$prova) header("Location: index.php");

if (array_key_exists("truncate", $input) && $input["truncate"] == "all") {
    $allInputs = InputQuery::create()->filterByProvaId('END')->filterByEventoId($currentEventId);
    $allInputs->delete();
    $prova->setTempo(null);
    $prova->setTotals(null);
    $prova->save();
    die("OK");
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
            $input->setDados(array("VOLTAS"=> intval($carObj["laps"]), "BEST"=> intval($carObj["bestLapTime"])));
            try { $input->save(); } catch (PropelException $e) { continue; };
        }
    }
    $prova->refreshVarsAndPontos();
    die("OK");
}

die("NO ACTION");