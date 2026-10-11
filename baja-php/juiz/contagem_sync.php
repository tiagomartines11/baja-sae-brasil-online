<?php
namespace Baja\Juiz;

use Baja\Contagem\Sync\SyncHandler;

// Upload endpoint of the lap counting pages (count page, pit log) and of
// external automated sources. All the logic is in SyncHandler.
//
// Deliberately no Session:: call anywhere in this file. Session would
// redirect a request without a forum login to login.php, and the whole
// point of the device token is that a phone keeps uploading after the
// counter's login has expired.

header('Content-Type: application/json; charset=utf-8');
// A cached answer would tell a phone that entries were stored when they were not.
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['erro' => 'metodo_invalido']);
    exit;
}

// Some FastCGI setups hand the header over under the REDIRECT_ name.
$authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

try {
    $response = SyncHandler::handle($authorizationHeader, (string) file_get_contents('php://input'));
} catch (\Throwable $error) {
    // The client treats anything that is not a 200 as "not stored" and
    // sends the same batch again, so failing loudly here loses nothing.
    error_log('contagem_sync: ' . $error->getMessage());
    $response = ['status' => 503, 'body' => ['erro' => 'indisponivel']];
}

http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_UNICODE);
