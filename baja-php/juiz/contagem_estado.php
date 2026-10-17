<?php
namespace Baja\Juiz;

use Baja\Contagem\Review\DecisionHistory;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Review\ReviewerPresence;
use Baja\Contagem\Review\StateSnapshot;
use Baja\Contagem\Service\ContagemService;

// Poll endpoint of the review screen. Read-only apart from the reviewer's
// presence mark.
//
//   GET ?p=&versao=&alerta=      the state. When `versao` is the token of the
//                                current snapshot the answer is "inalterado"
//                                with only the parts that change on their own.
//   GET ?p=&historico_antes=ID   one older page of the decision history.
//
// This never runs the engine: the state comes from the stored snapshot,
// which is recalculated at most every few seconds (see SnapshotService).

Pagina::beginJsonEndpoint();
$prova = Pagina::provaOrRedirect();
Pagina::requireControllerJson($prova);

if (isset($_GET['historico_antes'])) {
    $beforeId = filter_var($_GET['historico_antes'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($beforeId === false) {
        Pagina::sendJson(400, ['erro' => 'pedido_invalido', 'mensagem' => 'Página do histórico inválida.']);
    }
    Pagina::sendJson(200, DecisionHistory::page($prova, DecisionHistory::activeIdsFromDatabase($prova), $beforeId));
}

$service = ContagemService::forProva($prova);
$username = Pagina::username();

$openFlagId = $_GET['alerta'] ?? null;
if (!is_string($openFlagId) || $openFlagId === '' || strlen($openFlagId) > 200) {
    $openFlagId = null;
}
// Refreshed on every poll, changed or not: this is what keeps "X está olhando" alive.
ReviewerPresence::mark($prova, $username, $openFlagId);

// Read once: the comparison below and the answer must be about the same snapshot.
$snapshot = $service->snapshot();

// The token is opaque: equal means "you already have this", and nothing else.
$knownToken = is_string($_GET['versao'] ?? null) ? $_GET['versao'] : '';
if ($knownToken !== '' && hash_equals($snapshot->token, $knownToken)) {
    Pagina::sendJson(200, StateSnapshot::unchanged($service, $snapshot, $username));
}

Pagina::sendJson(200, StateSnapshot::live($service, $snapshot, $username));
