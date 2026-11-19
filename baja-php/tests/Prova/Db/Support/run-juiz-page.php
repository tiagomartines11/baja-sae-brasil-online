<?php
/**
 * Runs ONE juiz page for a database test, the way php-fpm runs it behind
 * nginx, minus the web server and the forum login (JuizPages starts it).
 *
 * Why not a real HTTP request: CI has MySQL but no nginx, no forum and no
 * Redis, and a test that needs them is skipped there, i.e. never runs. The
 * page code is the same either way; what nginx and the forum add is done
 * here by hand:
 *
 *   the event in the URL (/03SE/...)  $_SERVER['REDIRECT_EVENT']
 *   the forum login                   Session::setForcedSession($username)
 *   the forum session cookie          $_COOKIE[<prefix>_sid], a fixed made-up
 *                                     id: the form token (Csrf) is derived
 *                                     from it, so a token the page printed
 *                                     is accepted when posted back
 *   the request                       $_GET, $_POST, $_REQUEST, $_SERVER
 *
 * The PHP command line keeps the status code (http_response_code()) but
 * not the headers. The juiz pages are written in the namespace Baja\Juiz,
 * and PHP looks an unqualified header() up there first, so the header()
 * defined below sees every header the page sets (and passes it on to PHP's
 * own). That is how the test reads where a page redirects to.
 *
 * Argument: a JSON object {page, username, event, get, post}.
 * Output: one JSON object {status, location, body} on the standard output,
 * written when the page ends, however it ends (normally, exit or die).
 * PHP's warnings and errors go to the standard error, which JuizPages
 * checks is empty.
 */

namespace Baja\Juiz {
    /** Records the headers the page sets; see above. */
    function header(string $header, bool $replace = true, int $responseCode = 0): void
    {
        $GLOBALS['__juizPageHeaders'][] = $header;
        \header($header, $replace, $responseCode);
    }
}

namespace {
    $request = json_decode($argv[1] ?? '', true);
    $projectRoot = dirname(__DIR__, 4);
    $juiz = $projectRoot . '/juiz';
    $page = (string)($request['page'] ?? '');
    if (preg_match('/^[a-z_]+\.php$/', $page) !== 1 || !is_file($juiz . '/' . $page)) {
        fwrite(STDERR, "Página desconhecida: $page\n");
        exit(2);
    }

    // The forum's cookie names start with this prefix (set in the stack's .env).
    if ((string)getenv('PHPBB_COOKIE_PREFIX') === '') {
        putenv('PHPBB_COOKIE_PREFIX=juizpagetest');
    }
    $_COOKIE = [getenv('PHPBB_COOKIE_PREFIX') . '_sid' => 'juiz-page-test-session'];

    $_GET = (array)($request['get'] ?? []);
    $_POST = (array)($request['post'] ?? []);
    $_REQUEST = array_merge($_GET, $_POST);
    $query = http_build_query($_GET);
    $_SERVER['REDIRECT_EVENT'] = (string)$request['event'];
    $_SERVER['SCRIPT_NAME'] = '/' . $page;
    $_SERVER['QUERY_STRING'] = $query;
    $_SERVER['REQUEST_URI'] = '/' . $request['event'] . '/' . $page . ($query === '' ? '' : '?' . $query);
    $_SERVER['REQUEST_METHOD'] = $_POST === [] ? 'GET' : 'POST';
    $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

    // What src/bootstrap.php does for a page, without the forum login.
    require_once $projectRoot . '/vendor/autoload.php';
    require_once $projectRoot . '/src/config.php';
    date_default_timezone_set('America/Sao_Paulo');
    define('Pos', 'Pos'); define('EquipeNum', 'EquipeNum'); define('Equipe', 'Equipe');

    \Baja\Session::setForcedSession((string)$request['username']);

    $GLOBALS['__juizPageHeaders'] = [];
    ob_start();
    register_shutdown_function(static function (): void {
        $body = '';
        while (ob_get_level() > 0) {
            $body = ob_get_clean() . $body;
        }
        $location = '';
        foreach ($GLOBALS['__juizPageHeaders'] as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }
        $status = http_response_code();
        echo json_encode([
            // header('Location: ...') makes it a 302, as php-fpm would answer.
            'status' => $status === false ? 200 : $status,
            'location' => $location,
            'body' => $body,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    });

    // php-fpm runs a script from its own folder; the pages count on it.
    chdir($juiz);
    require $juiz . '/' . $page;
}
