<?php
/**
 * Run before a page by render_page() in lib.php. Not a test.
 *
 * A page here is a script that php-fpm runs once per request, reading the
 * request from $_GET, $_POST and $_SERVER. The command-line php that runs
 * the tests has no request, so this file builds one from what the test put
 * in the environment, then does what nginx does before every page: run
 * src/bootstrap.php.
 *
 * It also reports the HTTP status the page chose. On the command line there
 * is no response to put it in, so it is written to stderr, on a line of its
 * own that render_page() looks for, once the page has finished.
 */

$pedido = json_decode((string) getenv('BAJA_TEST_PEDIDO'), true) ?: [];

$_GET  = $pedido['get'] ?? [];
$_POST = $pedido['post'] ?? [];
foreach ($pedido['server'] ?? [] as $chave => $valor) {
    $_SERVER[$chave] = $valor;
}

register_shutdown_function(static function (): void {
    fwrite(STDERR, "\nBAJA_TEST_STATUS=" . (http_response_code() ?: 200) . "\n");
});

require __DIR__ . '/../../src/bootstrap.php';
