<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Review\DecisionValidator;
use Baja\Contagem\Review\Pagina;
use Baja\Contagem\Service\ContagemService;

// Stores one reviewer decision. POST ?p= with a JSON body:
//   {"decisao": {...}, "versao": n, "passagem_ids": [...], "assinatura": "...", "ultima_decisao_id": n}
// 200 with the new state, 409 with the fresh state when what the reviewer
// was looking at changed, 400 for a malformed request.

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

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
} catch (\RuntimeException $failure) {
    // Most likely the per-prova lock could not be had in time. Logged, and
    // answered as JSON so the screen can say "try again" instead of
    // mistaking an error page for an expired login.
    error_log('contagem_decidir: ' . $failure->getMessage());
    Pagina::sendJson(503, ['erro' => 'ocupado', 'mensagem' => 'O servidor não conseguiu concluir. A decisão pode não ter sido registrada: confira o histórico antes de repetir.']);
}
Pagina::sendJson($response['status'], $response['body']);
