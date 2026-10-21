<?php
/**
 * Renders one juiz page for a database test, as a logged-in user sees it.
 *
 * The HTTP tests go through the real web server, but they need a forum
 * login and are skipped where there is none. This script is the other way
 * in: it is started as a SECOND PHP process by
 * DbTestCase::renderJuizPage(), does what src/bootstrap.php does for the
 * page (minus the forum login), forces the session to the given user and
 * runs the page. What the page prints goes to the standard output; a page
 * that ends with die() or exit simply ends this process.
 *
 * Arguments: the page file under juiz/ (e.g. "contagem.php"), the username,
 * and the query string parameters as JSON.
 *
 * A page that redirects prints nothing: the PHP command line sends no
 * headers, and the page exits right after the Location header.
 */
$page = $argv[1] ?? '';
$username = $argv[2] ?? '';
$query = json_decode($argv[3] ?? '{}', true);

$projectRoot = dirname(__DIR__, 4);
$juiz = $projectRoot . '/juiz';
if (preg_match('/^[a-z_]+\.php$/', $page) !== 1 || !is_file($juiz . '/' . $page)) {
    fwrite(STDERR, "Página desconhecida: $page\n");
    exit(2);
}

require_once $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/src/config.php';
date_default_timezone_set('America/Sao_Paulo');
define('Pos', 'Pos'); define('EquipeNum', 'EquipeNum'); define('Equipe', 'Equipe');

$_SERVER['REDIRECT_EVENT'] = \Baja\Tests\Contagem\Db\TestWorld::EVENTO_ID;
$_SERVER['SCRIPT_NAME'] = '/' . $page;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = is_array($query) ? $query : [];
$_REQUEST = $_GET;

\Baja\Session::setForcedSession($username);

chdir($juiz);
require $juiz . '/' . $page;
