<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Service\ScorePublisher;
use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\Log;
use Baja\Model\ProvaQuery;
use Baja\Site\OneSignalClient;
use Baja\Session;

if (!isset($_REQUEST['p'])) { header("Location: index.php"); exit; }

$_page = $_REQUEST['p'];
$_team = $_REQUEST['t'];

Session::permissionCheck($_page);

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$equipe = EquipeQuery::create()->filterByEventoId($currentEventId)->findOneByEquipeId($_team);
if (!$equipe) { header("Location: dashboard.php?p=$_page"); exit; }

$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);
if (!$prova) { header("Location: index.php"); exit; }
$provaInput = [];
$paramsInputs = $prova->getParamsInputs();
array_walk($paramsInputs, function($v) use (&$provaInput) { $provaInput[$v->getCode()] = $v; });

// In a laps prova, VOLTAS, LAST and BEST belong to the lap counting engine.
// They are left out of everything below, so this page can neither change
// them nor record them as edited; what a person saves here is merged into
// the row beside them.
$isLapsProva = $prova->getType() == ContagemAccess::PROVA_TYPE;
if ($isLapsProva) {
    $provaInput = array_diff_key($provaInput, array_flip(ScorePublisher::ENGINE_FIELDS));
}

if (!array_key_exists("submit", $_POST) && !array_key_exists("delete", $_POST)) { header("Location: dashboard.php?p=$_page"); exit; }

$nota = InputQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($prova->getProvaId())->findOneByEquipeId($equipe->getEquipeId());
if (!$nota) {
    $nota = new Input();
    $nota->setDados(array_fill_keys(array_keys($provaInput), null));
    $nota->setProvaId($prova->getProvaId());
    $nota->setEquipeId($equipe->getEquipeId());
    $nota->setEventoId($currentEventId);
}

if (($_POST['delete'] ?? '') == 'Deletar Nota') {
    $log = new Log();
    $log->setUser(Session::getCurrentUser()->getUsername());
    $log->setPagina($prova->getEventoId()."_".$prova->getProvaId());
    $log->setEquipe($equipe->getEquipeId());
    $log->setDados('{"DELETED": "---"}');
    $log->save();

    $nota->delete();
    header("Location: dashboard.php?p=$_page");
    exit();
}

foreach ($_POST as &$v) {
    if ($v === "") $v = null;
    if (is_array($v)) $v = implode(',', $v);
}
unset($v);

$old_array = (array)$nota->getDados();
$new_array = [];
foreach ($provaInput as $k=>$v) {
    if ($v->getType() == "number" && ($_POST[$k] ?? null) !== null)
        $new_array[$k] = doubleval($_POST[$k]);
    else
        $new_array[$k] = $_POST[$k] ?? null;
}
unset($v);

$sensitive = false;

$diff = [];
foreach ($provaInput as $k=>$v) {
    $hasOld = array_key_exists($k, $new_array);
    $hasNew = array_key_exists($k, $new_array);
    $changed = false;
    // A row created by the lap counting engine has no key yet for the fields people type.
    if ((!$hasOld && $hasNew) || ($hasOld && $hasNew && $new_array[$k] !== ($old_array[$k] ?? null))) {
        $diff[$k] = $new_array[$k];
        $changed = true;
    } else if (!$hasNew && $hasOld) {
        $diff[$k] = 'removed';
        $changed = true;
    }

    if ($changed) {
        if ($v->getSensitive()) {
            $sensitive = true;   
        }
    }
}

if (count($diff) > 0) {
    $nota->setDados($isLapsProva ? array_merge($old_array, $new_array) : $new_array);
    $nota->save();

    $prova->refreshVarsAndPontos();

    $log = new Log();
    $log->setUser(Session::getCurrentUser()->getUsername());
    $log->setPagina($prova->getEventoId()."_".$prova->getProvaId());
    $log->setEquipe($equipe->getEquipeId());
    $log->setDados(json_encode($diff));
    $log->save();

    if (!$sensitive) {
        OneSignalClient::sendMessage('Pontuação Publicada', '#' . $equipe->getEquipeId() . ' - ' . $prova->getNome() . (count(array_values((array)$nota->getPontos())) > 0 ? ': '. array_values((array)$nota->getPontos())[0] : ''), 'prova.php?id='.$prova->getProvaId(), $equipe->getEquipeId());
    }
}

if ($_POST['submit'] == 'Salvar e Avançar')
    header("Location: entry.php?p=$_page&t=".($_team+1));
else
    header("Location: dashboard.php?p=$_page");
