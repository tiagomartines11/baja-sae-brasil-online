<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Certificado\Insercao\Csrf;
use Baja\Contagem\Access\ContagemAccess;
use Baja\Juiz\Template;
use Baja\Model\Prova;
use Baja\Session;

/**
 * What every controller page of the lap counting area does the same way:
 * find the prova named in the URL, protect its forms, draw the same header.
 *
 * Kept here so the files under juiz/ stay thin and none of them can forget
 * one of these steps.
 */
final class Pagina
{
    /** Header the review screen's script sends its CSRF token in. */
    public const CSRF_HEADER = 'HTTP_X_CONTAGEM_CSRF';

    /**
     * The laps prova named by ?p=, or a redirect to the start page when the
     * id is missing, malformed or not a laps prova of the current event.
     */
    public static function provaOrRedirect(): Prova
    {
        $provaId = $_GET['p'] ?? '';
        $prova = is_string($provaId) && preg_match('/^[A-Za-z0-9]{1,3}$/', $provaId) === 1
            ? ContagemAccess::findLapsProva($provaId)
            : null;
        if ($prova === null) {
            header('Location: index.php');
            exit;
        }
        return $prova;
    }

    /** Escapes a value for HTML text or an attribute. */
    public static function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function username(): string
    {
        return (string) Session::getCurrentUser()->getUsername();
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * Name the CSRF token is derived from. One per prova, so a token taken
     * from one race's page is useless against another's.
     */
    public static function csrfFormName(Prova $prova): string
    {
        return 'contagem_' . $prova->getFullCode();
    }

    public static function csrfField(Prova $prova): string
    {
        return Csrf::campo(self::csrfFormName($prova));
    }

    public static function csrfToken(Prova $prova): string
    {
        return Csrf::token(self::csrfFormName($prova));
    }

    /** Stops a form POST that does not carry this session's token. */
    public static function requireCsrfPost(Prova $prova): void
    {
        if (!Csrf::postValido(self::csrfFormName($prova))) {
            http_response_code(403);
            die('Sua sessão expirou ou o formulário é inválido. Volte, recarregue a página e tente de novo.');
        }
    }

    /** Same check for the JSON endpoints, which carry the token in a header. */
    public static function hasValidCsrfHeader(Prova $prova): bool
    {
        $sent = $_SERVER[self::CSRF_HEADER] ?? null;
        return Csrf::valido(self::csrfFormName($prova), is_string($sent) ? $sent : null);
    }

    /**
     * @param array<string, mixed> $body
     * @return never
     */
    public static function sendJson(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Race state must never be served from a cache.
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Redirect after a successful POST, so reloading the page cannot repeat
     * the action (reloading after "Largar agora" must not start the race again).
     *
     * @param array<string, string> $extra more query parameters
     * @return never
     */
    public static function redirectAfterPost(string $page, Prova $prova, array $extra = []): void
    {
        $query = http_build_query(['p' => $prova->getProvaId()] + $extra);
        header('Location: ' . $page . '?' . $query);
        exit;
    }

    /**
     * A query-string value that changes whenever the file changes. /js/ and
     * /css/ are cached by browsers for 30 days; without this a fix would not
     * reach a reviewer's browser during the event.
     *
     * @param string $pathUnderDefault for example "css/contagem-revisao.css"
     */
    public static function assetVersion(string $pathUnderDefault): string
    {
        $file = dirname(__DIR__, 4) . '/default/' . $pathUnderDefault;
        $modified = @filemtime($file);
        return $modified === false ? '1' : (string) $modified;
    }

    public static function stylesheetTag(): string
    {
        $path = 'css/contagem-revisao.css';
        return '<link rel="stylesheet" href="/' . $path . '?v=' . self::assetVersion($path) . '">';
    }

    /** Standard juiz header plus the lap counting style sheet and navigation. */
    public static function printHeader(Prova $prova, string $title): void
    {
        Template::printHeader($title . ' - ' . $prova->getNome(), false);
        echo self::stylesheetTag();
        echo '<div class="contagem-pagina">';
        echo self::navigation($prova, $title);
    }

    public static function printFooter(): void
    {
        echo '</div>';
        Template::printFooter();
    }

    /** Links between the controller pages; the current one is marked. */
    public static function navigation(Prova $prova, string $currentTitle): string
    {
        $query = '?p=' . rawurlencode($prova->getProvaId());
        $links = [
            'Contagem' => 'contagem.php',
            'Revisão' => 'contagem_revisao.php',
            'Controle de corrida' => 'contagem_controle.php',
            'Contadores' => 'contagem_contadores.php',
            'Fontes e configurações' => 'contagem_fontes.php',
        ];

        $html = '<nav class="contagem-nav"><strong>' . self::h($prova->getNome()) . '</strong>';
        foreach ($links as $label => $page) {
            $class = $label === $currentTitle ? ' class="atual"' : '';
            $html .= '<a' . $class . ' href="' . $page . self::h($query) . '">' . self::h($label) . '</a>';
        }
        $html .= '<a href="index.php">Início</a></nav>';
        return $html;
    }

    /**
     * Wall-clock time for server-rendered pages, in the site's time zone.
     */
    public static function formatClock(?int $tsMs): string
    {
        if ($tsMs === null) {
            return '—';
        }
        return date('d/m H:i:s', intdiv($tsMs, 1000));
    }
}
