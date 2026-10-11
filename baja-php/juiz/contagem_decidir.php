<?php
namespace Baja\Juiz;

use Baja\Contagem\Review\DecisionValidator;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\LockBusy;

// Stores one reviewer decision. POST ?p= with a JSON body:
//   {"decisao": {...}, "passagem_ids": [...], "assinatura": "...", "ultima_decisao_id": n}
// 200 with the new state, 409 with the fresh state when what the reviewer
// was looking at changed, 400 for a request that makes no sense, 503 when
// the count is busy. Every answer is JSON, so the screen can always tell
// which of these happened.

Pagina::beginJsonEndpoint();
$prova = Pagina::provaOrRedirect();
Pagina::requireControllerJson($prova);

if (!Pagina::isPost()) {
    header('Allow: POST');
    Pagina::sendJson(405, ['erro' => 'metodo_invalido', 'mensagem' => 'Use POST.']);
}
if (!Pagina::hasValidCsrfHeader($prova)) {
    Pagina::sendJson(403, ['erro' => 'csrf', 'mensagem' => 'Sua sessão expirou. Recarregue a página.']);
}

$request = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($request)) {
    Pagina::sendJson(400, ['erro' => 'json_invalido', 'mensagem' => 'Pedido ilegível.']);
}

try {
    $response = DecisionValidator::decide(ContagemService::forProva($prova), $request, Pagina::username());
} catch (LockBusy $busy) {
    // Nothing was stored: the lock is taken before anything is written.
    Pagina::sendJson(503, [
        'erro' => 'ocupado',
        'mensagem' => 'A contagem está ocupada com outra operação. A decisão NÃO foi registrada: tente de novo.',
    ]);
}
Pagina::sendJson($response['status'], $response['body']);
