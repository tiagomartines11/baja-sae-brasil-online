<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Certificado\Insercao\Csrf;
use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Service\ContagemService;
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

    /** Output-buffer level opened by beginJsonEndpoint(); null when it was not called. */
    private static ?int $jsonBufferLevel = null;

    /**
     * First line of every JSON endpoint.
     *
     * In development PHP prints notices and warnings into the response. One
     * such line in front of the JSON makes the whole answer unreadable for
     * the browser, which then cannot tell "decision stored" from "decision
     * refused". So everything printed from here on is held back, and
     * sendJson() throws it away (into the error log) before answering.
     *
     * An error nobody caught is answered as JSON too, for the same reason.
     */
    public static function beginJsonEndpoint(): void
    {
        ob_start();
        self::$jsonBufferLevel = ob_get_level();
        set_exception_handler(function (\Throwable $error): void {
            error_log('contagem: ' . get_class($error) . ': ' . $error->getMessage() . ' em ' . $error->getFile() . ':' . $error->getLine());
            self::sendJson(500, [
                'erro' => 'interno',
                'mensagem' => 'Erro no servidor. Não se sabe se a ação foi concluída: confira antes de repetir.',
            ]);
        });
    }

    /**
     * Throws away whatever was printed since beginJsonEndpoint() and
     * returns it, so the caller can log it. Empty when nothing was printed.
     */
    public static function discardStrayOutput(): string
    {
        $stray = '';
        while (self::$jsonBufferLevel !== null && ob_get_level() >= self::$jsonBufferLevel) {
            $stray = (string) ob_get_clean() . $stray;
        }
        self::$jsonBufferLevel = null;
        return $stray;
    }

    /**
     * @param array<string, mixed> $body
     * @return never
     */
    public static function sendJson(int $status, array $body): void
    {
        $stray = self::discardStrayOutput();
        if (trim($stray) !== '') {
            error_log('contagem: saída inesperada descartada de uma resposta JSON: ' . mb_substr(trim($stray), 0, 500));
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Race state must never be served from a cache.
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /**
     * For the JSON endpoints: answers 403 as JSON unless the user controls
     * the prova. The HTML refusal of ContagemAccess::requireController()
     * would reach the review screen as an unreadable answer.
     */
    public static function requireControllerJson(Prova $prova): void
    {
        if (!ContagemAccess::isController($prova)) {
            self::sendJson(403, ['erro' => 'sem_acesso', 'mensagem' => 'Você não tem acesso a esta prova.']);
        }
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
        echo self::simulationBanner($prova);
        echo self::testPresetBanner($prova);
    }

    /**
     * The banner of every controller page while the test preset is applied.
     * The preset shrinks the matching window and the other time settings to
     * a lap of a few seconds; a real race started under it would be counted
     * with a window too short for people typing by hand.
     */
    public static function testPresetBanner(Prova $prova): string
    {
        if (!ContagemService::forProva($prova)->hasSavedSettings()) {
            return '';
        }
        return '<p class="contagem-simulacao contagem-predefinicao" role="alert"><strong>PREDEFINIÇÃO DE TESTE APLICADA</strong> '
            . 'Os tempos da contagem estão ajustados para um ensaio e NÃO são os da corrida de verdade. '
            . 'Antes da corrida, use "Restaurar valores salvos" em '
            . '<a href="contagem_fontes.php?p=' . self::h(rawurlencode($prova->getProvaId())) . '">Fontes e configurações</a>.</p>';
    }

    /**
     * The permanent "SIMULAÇÃO" banner of every controller page, shown for
     * as long as the prova accepts simulated entries. A rehearsal left
     * switched on would let made-up passes into a real race; the banner is
     * what makes that impossible to overlook.
     */
    public static function simulationBanner(Prova $prova): string
    {
        if (!Settings::fromArray($prova->getParams()->contagem ?? null)->permitirSimulacao) {
            return '';
        }
        return '<p class="contagem-simulacao" role="alert"><strong>SIMULAÇÃO</strong> '
            . 'Esta prova aceita passagens simuladas. Desligue "Permitir simulação" em Fontes e configurações antes da corrida de verdade.</p>';
    }

    /**
     * The form shown when an action answered "confirm first": the same
     * fields again, plus the one that says yes.
     *
     * @param array<string, mixed> $post the form as it was sent
     */
    public static function confirmationForm(Prova $prova, string $action, array $post, ConfirmationNeeded $question): string
    {
        $fields = '';
        foreach ($post as $name => $value) {
            // The token is added fresh below; anything that is not plain
            // text has no business in these forms.
            if ($name === Csrf::CAMPO || !is_string($value) || isset($question->campos[$name])) {
                continue;
            }
            $fields .= '<input type="hidden" name="' . self::h($name) . '" value="' . self::h($value) . '">';
        }
        foreach ($question->campos as $name => $value) {
            $fields .= '<input type="hidden" name="' . self::h($name) . '" value="' . self::h($value) . '">';
        }
        return '<form method="POST" action="' . self::h($action) . '" class="contagem-acao contagem-confirmacao">'
            . self::csrfField($prova) . $fields
            . '<input type="hidden" name="' . self::h($question->campo) . '" value="1">'
            . '<button type="submit" class="botao-vermelho">' . self::h($question->rotulo) . '</button>'
            . '<a href="' . self::h($action) . '">Não, voltar</a></form>';
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
            // The file keeps its old name so that bookmarks keep working.
            'Usuários' => 'contagem_contadores.php',
            'Fontes e configurações' => 'contagem_fontes.php',
            // Named as on the hub and the chooser.
            ContagemAccess::pitLogLabel($prova) => 'contagem_box.php',
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
