<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Model\ContagemFonteQuery;
use Baja\Model\UserQuery;
use Baja\Session;

// Hands a logged-in browser its upload token for one source:
//   POST contagem_dispositivo.php?p={prova}&f={fonte_id}   count page
//   POST contagem_dispositivo.php?p={prova}&box=1          pit log
//
// This is the only moment of the count flow that needs a forum session.
// The page stores the token and from then on talks to contagem_sync.php,
// which needs nothing else.

header('Content-Type: application/json; charset=utf-8');
// The token is a credential; no proxy or browser cache may keep a copy.
header('Cache-Control: no-store');

$respond = function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

// POST only: issuing a token creates a row, and a GET could be triggered by
// a link or a prefetch.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['erro' => 'metodo_invalido']);
}

// Checked by hand instead of through Session, which answers a missing login
// with a redirect to an HTML page. The caller here is a script that expects
// JSON and needs to tell "log in again" apart from "not allowed".
$forumUsername = (string) ($GLOBALS['user']->data['username'] ?? '');
if ($forumUsername === '') {
    $respond(401, ['erro' => 'sem_sessao']);
}
if (UserQuery::create()->findOneByUsername($forumUsername) === null) {
    $respond(403, ['erro' => 'sem_permissao']);
}

$prova = ContagemAccess::findLapsProva((string) ($_GET['p'] ?? ''));
if ($prova === null) {
    $respond(403, ['erro' => 'sem_permissao']);
}

if (!empty($_GET['box'])) {
    if (!ContagemAccess::canUsePitLog($prova)) {
        $respond(403, ['erro' => 'sem_permissao']);
    }
    $fonte = DeviceTokens::boxSourceFor($prova);
} else {
    $fonte = ContagemFonteQuery::create()->findPk((int) ($_GET['f'] ?? 0));
    // A source of another prova is refused with the same answer as a
    // missing permission, so the reply does not reveal which ids exist.
    $belongsToProva = $fonte !== null
        && $fonte->getEventoId() === $prova->getEventoId()
        && $fonte->getProvaId() === $prova->getProvaId();
    if (!$belongsToProva || !ContagemAccess::canCountOn($fonte)) {
        $respond(403, ['erro' => 'sem_permissao']);
    }
}

$username = Session::getCurrentUser()->getUsername();
$token = DeviceTokens::issue($fonte, $username, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

$respond(200, [
    'token' => $token,
    'fonte' => [
        'id' => (int) $fonte->getFonteId(),
        'nome' => (string) $fonte->getNome(),
        'tipo' => (string) $fonte->getTipo(),
    ],
    'evento_id' => $prova->getEventoId(),
    'prova_id' => $prova->getProvaId(),
]);
