<?php
if (getenv('SKIP_AUTH')) {
    ini_set('display_errors', 'Off');
    error_reporting(0);
}

$_REQUEST = array_merge($_GET, $_POST);
require_once(__DIR__ . "/../vendor/autoload.php");
require_once(__DIR__ . "/config.php");
error_reporting(E_ALL);
ini_set('display_errors', 'On');

// Rate-limit API endpoints. Deliberately ahead of phpbb_login.php so a
// throttled request is rejected before paying for session setup.
// BAJA_API_VHOST is set by the API vhost (baja-infra/nginx/conf.d*/baja-app-api.conf).
// It is needed because on that vhost the URI carries no /api/ prefix to detect —
// api.example/auth/login.php, not example/api/auth/login.php.
$isApiVhost = !empty($_SERVER['BAJA_API_VHOST']);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

if ($isApiVhost || strpos($requestPath, '/api/') === 0) {
    // REMOTE_ADDR only, never a request header. Headers like X-Forwarded-For
    // and X-Real-IP are attacker-controlled: nginx maps whatever the client
    // sends into HTTP_*, so trusting them lets anyone mint a fresh rate-limit
    // bucket per request and bypass the limiter entirely.
    //
    // Making REMOTE_ADDR truthful is nginx's job, and is where the only
    // dev/prod difference lives: in prod the API vhost runs realip against
    // Cloudflare's ranges with CF-Connecting-IP; in dev the peer address is
    // already the client. See baja-infra/nginx/conf.d*/baja-app-api.conf.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Canonicalise to a /api/... path before handing it over. RateLimiter
    // picks the limit by pattern-matching the endpoint (/api/auth/login.php
    // gets 3/min, /api/auth/ 5/min), so on the API vhost an unprefixed path
    // would silently fall through to the generic read/write limits and give
    // the login endpoint 30/min.
    $endpoint = $isApiVhost ? '/api' . $requestPath : $requestPath;

    // Enforce rate limits (returns 429 and exits if exceeded)
    \Baja\Api\RateLimiter::enforce(
        $clientIp,
        $endpoint,
        $_SERVER['REQUEST_METHOD'] ?? 'GET'
    );
}

if (!getenv('SKIP_AUTH')) {
    require_once(__DIR__ . "/phpbb_login.php");
    $_DEV_MODE = $user->data["username"] == "Tiago" || $user->data["username"] == "jbresolin";
} else {
    $_DEV_MODE = false;
    ini_set('display_errors', 'Off');
    error_reporting(0);
}
date_default_timezone_set('America/Sao_Paulo');

// Ajuste para que solveFormula não gere erro com php > 7
define('Pos', 'Pos'); define('EquipeNum', 'EquipeNum'); define('Equipe', 'Equipe');

use Baja\Model\EventoQuery;

// Lógica para seleção de evento se não houver request de um eveno específico (substituição ao setup manual em .htaccess)
if (empty($_SERVER['REDIRECT_EVENT'])) {
    try {
        // Tenatativa 1: Evento marcado como "em andamento"
        $event = EventoQuery::create()
            ->filterByEmAndamento(true)
            ->orderByAno('desc')
            ->orderByTipo('asc')
            ->findOne();

        // Tenatativa 2: Baja Nacional (tipo=0) com maior ano
        if (!$event) {
            $event = EventoQuery::create()
                ->filterByTipo(0)
                ->orderByAno('desc')
                ->findOne();
        }

        // Fallback: primeiro nacional com registro
        if ($event) {
            $_SERVER['REDIRECT_EVENT'] = $event->getEventoId();
        } else {
            $_SERVER['REDIRECT_EVENT'] = '11BR';
        }

    } catch (Exception $e) {
        // Fallback para catástrofes
        $_SERVER['REDIRECT_EVENT'] = '11BR';
    }
}