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
$_team = $_REQUEST['t'];

Session::permissionCheck($_page);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$equipe = EquipeQuery::create()->filterByEventoId($currentEventId)->findOneByEquipeId($_team);
if (!$equipe) header("Location: dashboard.php?p=$_page");

$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);
if (!$prova) header("Location: index.php");
$provaInput = [];
array_walk($prova->getParamsInputs(), function($v) use (&$provaInput) { $provaInput[$v->getCode()] = $v; });

if (!array_key_exists("submit", $_POST)) header("Location: dashboard.php?p=$_page");

$nota = InputQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($prova->getProvaId())->findOneByEquipeId($equipe->getEquipeId());
if (!$nota) {
    $nota = new Input();
    $nota->setDados(array_fill_keys(array_keys($provaInput), null));
    $nota->setProvaId($prova->getProvaId());
    $nota->setEquipeId($equipe->getEquipeId());
    $nota->setEventoId($currentEventId);
}

foreach ($_POST as &$v) {
    if ($v === "") $v = null;
    if (is_array($v)) $v = implode(',', $v);
}
unset($v);

$old_array = (array)$nota->getDados();
$new_array = [];
$i = 0;
foreach ($provaInput as $k=>$v) {
    $input = $prova->getParamsInputs()[$i++];
    if ($input->getType() == "number" && $_POST[$k] !== null)
        $new_array[$k] = doubleval($_POST[$k]);
    else
        $new_array[$k] = $_POST[$k];
}
unset($v);

$diff = [];
foreach ($provaInput as $k=>$v) {
    $hasOld = array_key_exists($k, $new_array);
    $hasNew = array_key_exists($k, $new_array);
    if ((!$hasOld && $hasNew) || ($hasOld && $hasNew && $new_array[$k] != $old_array[$k])) {
        $diff[$k] = $new_array[$k];
    } else if (!$hasNew && $hasOld) {
        $diff[$k] = 'removed';
    }
}

if (count($diff) > 0) {
    $nota->setDados($new_array);
    $nota->save();

    $prova->refreshVarsAndPontos();

    $log = new Log();
    $log->setUser(Session::getCurrentUser()->getUsername());
    $log->setPagina($prova->getEventoId()."_".$prova->getProvaId());
    $log->setEquipe($equipe->getEquipeId());
    $log->setDados(json_encode($diff));
    $log->save();

    OneSignalClient::sendMessage('Pontuação Publicada', '#' . $equipe->getEquipeId() . ' - ' . $prova->getNome() . (count(array_values((array)$nota->getPontos())) > 0 ? ': '. array_values((array)$nota->getPontos())[0] : ''), 'prova.php?id='.$prova->getProvaId(), $equipe->getEquipeId());
}

if ($_POST['submit'] == 'Salvar e Avançar')
    header("Location: entry.php?p=$_page&t=".($_team+1));
else
    header("Location: dashboard.php?p=$_page");
