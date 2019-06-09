<?php
namespace Baja\Juiz;

use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\Log;
use Baja\Model\ProvaQuery;
use Baja\Site\OneSignalClient;

if (!isset($_REQUEST['p'])) header("Location: index.php");

$_page = $_REQUEST['p'];
$_team = $_REQUEST['team'];

Session::permissionCheck($_page);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$equipe = EquipeQuery::create()->filterByEventoId($currentEventId)->findOneByEquipeId($_team);
if (!$equipe) header("Location: index.php");

$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);
if (!$prova) header("Location: index.php");
$provaInput = [];
array_walk($prova->getParamsInputs(), function($v) use (&$provaInput) { $provaInput[$v->getCode()] = $v; });

if (!array_key_exists("submit", $_POST)) header("Location: index.php");

$nota = InputQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($prova->getProvaId())->findOneByEquipeId($equipe->getEquipeId());
if (!$nota) {
    $nota = new Input();
    $nota->setDados(["entries"=>[]]);
    $nota->setProvaId($prova->getProvaId());
    $nota->setEquipeId($equipe->getEquipeId());
    $nota->setEventoId($currentEventId);
}

foreach ($_POST as &$v) {
    if ($v === "") $v = null;
    if (is_array($v)) $v = implode(',', $v);
}
unset($v);

$new_array = [];
$i = 0;
foreach ($provaInput as $k=>$v) {
    $input = $prova->getParamsInputs()[$i++];
    if ($input->getType() == "number" && $_POST[$k] !== null)
        $new_array[$k] = doubleval($_POST[$k]);
    else
        $new_array[$k] = $_POST[$k];
}
$new_array['ts'] = time();
unset($v);

$diff = $nota->getDados();
($diff->entries)[] = $new_array;

$nota->setDados($diff);
$nota->save();

$prova->refreshVarsAndPontos();

$log = new Log();
$log->setUser(Session::getCurrentUser()->getUsername());
$log->setPagina($prova->getEventoId()."_".$prova->getProvaId());
$log->setEquipe($equipe->getEquipeId());
$log->setDados(json_encode($new_array));
$log->save();

header("Location: index.php");
