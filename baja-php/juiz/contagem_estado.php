<?php
namespace Baja\Juiz;

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
//       &historico_desde=&historico_epoca=
//                                the newest decision of the history the screen
//                                holds, and the epoch it came with: a full
//                                answer then carries only the history after
//                                it (see StateSnapshot::withHistoryAfter).
//
// The decision history is part of the state, whole (see DecisionHistory);
// there are no older pages to ask for.
//
// This never runs the engine: the state comes from the stored snapshot,
// which is recalculated at most every few seconds (see SnapshotService).

Pagina::beginJsonEndpoint();
$prova = Pagina::provaOrRedirect();
Pagina::requireControllerJson($prova);

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

$state = StateSnapshot::live($service, $snapshot, $username);
$historySince = $_GET['historico_desde'] ?? null;
$historyEpoch = $_GET['historico_epoca'] ?? null;
if (is_string($historySince) && ctype_digit($historySince) && strlen($historySince) <= 18
    && is_string($historyEpoch) && $historyEpoch !== '' && strlen($historyEpoch) <= 40) {
    $state = StateSnapshot::withHistoryAfter($state, (int) $historySince, $historyEpoch);
}
Pagina::sendJson(200, $state);
