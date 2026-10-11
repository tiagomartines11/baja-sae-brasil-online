<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Review\ReviewerPresence;
use Baja\Contagem\Review\StateSnapshot;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;

// Poll endpoint of the review screen: GET ?p=&versao=&alerta=
// Answers the full state, or "inalterado" when the browser already has the
// current version. Read-only apart from the reviewer's presence mark.

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

$service = ContagemService::forProva($prova);
$username = Pagina::username();

$openFlagId = $_GET['alerta'] ?? null;
if (!is_string($openFlagId) || $openFlagId === '' || strlen($openFlagId) > 200) {
    $openFlagId = null;
}
// Refreshed on every poll, changed or not: this is what keeps "X está olhando" alive.
ReviewerPresence::mark($prova, $username, $openFlagId);

$knownVersion = filter_var($_GET['versao'] ?? null, FILTER_VALIDATE_INT);
$currentVersion = $service->currentVersion();

if ($knownVersion !== false && $knownVersion === $currentVersion) {
    Pagina::sendJson(200, [
        'inalterado' => true,
        'versao' => $currentVersion,
        'servidor_agora_ms' => Clock::nowMs(),
        // Presence changes without the version changing, so it rides along.
        'olhando' => (object) ReviewerPresence::current($prova),
    ]);
}

Pagina::sendJson(200, StateSnapshot::build($service, $username));
