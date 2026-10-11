<?php
namespace Baja\Juiz;

use Baja\Contagem\Sync\SyncHandler;

// Upload endpoint of the lap counting pages (count page, pit log) and of
// external automated sources. All the logic is in SyncHandler.
//
// Deliberately no Session:: call anywhere in this file. Session would
// redirect a request without a forum login to login.php, and the whole
// point of the device token is that a phone keeps uploading after the
// counter's login has expired. nginx serves this script with SKIP_AUTH, so
// the forum session code is not even loaded: an outage of the forum
// database cannot stop the counting.

// Everything printed from here on is held back, so that a stray PHP notice
// can never end up in front of the JSON and make it unreadable to the phone.
ob_start();

header('Content-Type: application/json; charset=utf-8');
// A cached answer would tell a phone that entries were stored when they were not.
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    ob_end_clean();
    echo json_encode(['erro' => 'metodo_invalido']);
    exit;
}

// Some FastCGI setups hand the header over under the REDIRECT_ name.
$authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

// When the request reached PHP, in milliseconds. The phone derives its clock
// offset from the time in the answer, so it must be the arrival time, not
// the time after the work was done.
$receivedAtMs = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (int) round($_SERVER['REQUEST_TIME_FLOAT'] * 1000) : null;

try {
    $response = SyncHandler::handle($authorizationHeader, (string) file_get_contents('php://input'), $receivedAtMs);
} catch (\Throwable $error) {
    // The client treats anything that is not a 200 as "not stored" and
    // sends the same batch again, so failing loudly here loses nothing.
    error_log('contagem_sync: ' . $error->getMessage());
    $response = ['status' => 503, 'body' => ['erro' => 'indisponivel']];
}

ob_end_clean();
http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_UNICODE);

// The phone has its answer from here on. What follows (the whole-race
// recalculation, when it is due) happens without anybody waiting for it.
if (isset($response['after'])) {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    try {
        ($response['after'])();
    } catch (\Throwable $error) {
        error_log('contagem_sync (after response): ' . $error->getMessage());
    }
}
