<?php
namespace Baja\Juiz;

use Baja\Auth\Csrf;
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
$_team = is_string($_REQUEST['team'] ?? null) ? $_REQUEST['team'] : '';

Session::permissionCheck($_page);
// Before anything is saved: a request without the form's token (another
// site submitting it with the judge's session) changes nothing.
Csrf::enforce();

$currentEventId = EventoQuery::getCurrentEvent()->getEventoId();
$equipe = $_team === '' ? null : EquipeQuery::create()->filterByEventoId($currentEventId)->findOneByEquipeId($_team);

$prova = ProvaQuery::create()->filterByEventoId($currentEventId)->findOneByProvaId($_page);
if (!$prova) { header("Location: index.php"); exit; }
$provaInput = [];
$paramsInputs = $prova->getParamsInputs();
array_walk($paramsInputs, function($v) use (&$provaInput) { $provaInput[$v->getCode()] = $v; });

if (!array_key_exists("submit", $_POST)) { header("Location: index.php"); exit; }

// The form's own rules, checked again here (see save_entry.php). The team
// list only offers teams marked present, and its empty first option used to
// send the judge back to the index with everything typed lost.
$entradaRecusada = EntryValidator::forRolling($paramsInputs)->errors($_POST);
if (!$equipe || !$equipe->getPresente()) {
    $entradaRecusada = ['team' => $_team === '' ? 'Escolha a equipe.' : 'A equipe #' . $_team . ' não está na lista de equipes presentes. Recarregue a página.'] + $entradaRecusada;
}
if ($entradaRecusada) {
    http_response_code(422);
    require __DIR__ . '/rolling_entry.php';
    exit();
}

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
    if ($input->getType() == "number" && ($_POST[$k] ?? null) !== null)
        $new_array[$k] = doubleval($_POST[$k]);
    else
        $new_array[$k] = $_POST[$k] ?? null;
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

if ($_POST['submit'] == 'Salvar e Avançar')
    header("Location: rolling_entry.php?p=$_page");
else
    header("Location: dashboard.php?p=$_page");
