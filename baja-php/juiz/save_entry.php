<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Review\ManualScoreFields;
use Baja\Contagem\Service\LockBusy;
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

$writeLog = function (string $dados) use ($prova, $equipe): void {
    $log = new Log();
    $log->setUser(Session::getCurrentUser()->getUsername());
    $log->setPagina($prova->getEventoId()."_".$prova->getProvaId());
    $log->setEquipe($equipe->getEquipeId());
    $log->setDados($dados);
    $log->save();
};

$wantsDelete = array_key_exists('delete', $_POST) && !array_key_exists('submit', $_POST);

if ($isLapsProva && $wantsDelete) {
    // The row also holds the lap count, which is not this page's to delete:
    // only the fields people type are emptied, and the row stays.
    try {
        if (ManualScoreFields::clear($prova, (int) $equipe->getEquipeId(), array_keys($provaInput))) {
            $writeLog('{"DELETED": "campos manuais"}');
        }
    } catch (LockBusy $busy) {
        die('A contagem de voltas está ocupada com outra operação e nada foi alterado. Volte e tente de novo.');
    }
    header("Location: dashboard.php?p=$_page");
    exit();
}

$nota = InputQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($prova->getProvaId())->findOneByEquipeId($equipe->getEquipeId());
if (!$nota) {
    $nota = new Input();
    $nota->setDados(array_fill_keys(array_keys($provaInput), null));
    $nota->setProvaId($prova->getProvaId());
    $nota->setEquipeId($equipe->getEquipeId());
    $nota->setEventoId($currentEventId);
}

if (($_POST['delete'] ?? '') == 'Deletar Nota') {
    $writeLog('{"DELETED": "---"}');

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

if ($isLapsProva) {
    // The lap counting engine writes VOLTAS, LAST and BEST into this same
    // row many times a minute. Writing back the row as it was read above
    // could put an old lap count over a new one, so the save is done under
    // the count lock, from the row as it is at that moment.
    try {
        $diff = ManualScoreFields::save($prova, (int) $equipe->getEquipeId(), $new_array);
    } catch (LockBusy $busy) {
        die('A contagem de voltas está ocupada com outra operação e nada foi salvo. Volte e tente de novo.');
    }
    foreach (array_keys($diff) as $k) {
        if ($provaInput[$k]->getSensitive()) {
            $sensitive = true;
        }
    }
    $saved = count($diff) > 0;
    if ($saved) {
        // Read again for the notification below: the points were just recalculated.
        $nota = InputQuery::create()->filterByEventoId($currentEventId)->filterByProvaId($prova->getProvaId())->findOneByEquipeId($equipe->getEquipeId());
    }
} else {
    $diff = [];
    foreach ($provaInput as $k=>$v) {
        $hasOld = array_key_exists($k, $new_array);
        $hasNew = array_key_exists($k, $new_array);
        $changed = false;
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

    $saved = count($diff) > 0;
    if ($saved) {
        $nota->setDados($new_array);
        $nota->save();

        $prova->refreshVarsAndPontos();
    }
}

if ($saved) {
    $writeLog(json_encode($diff));

    if (!$sensitive) {
        OneSignalClient::sendMessage('Pontuação Publicada', '#' . $equipe->getEquipeId() . ' - ' . $prova->getNome() . (count(array_values((array)$nota->getPontos())) > 0 ? ': '. array_values((array)$nota->getPontos())[0] : ''), 'prova.php?id='.$prova->getProvaId(), $equipe->getEquipeId());
    }
}

if (($_POST['submit'] ?? '') == 'Salvar e Avançar')
    header("Location: entry.php?p=$_page&t=".($_team+1));
else
    header("Location: dashboard.php?p=$_page");
