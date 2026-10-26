<?php
declare(strict_types=1);

namespace Baja\Auth;

/**
 * CSRF protection for the judge and admin areas (BAJAAPP-41).
 *
 * The problem
 * -----------
 * The browser sends the forum session cookie with every request to the judge
 * area, whichever site the request comes from. So a page on another site can
 * hold a hidden form pointing at, say, prova.php?act=Deletar+Prova and submit
 * it: if an admin with a session opens that page, the prova is deleted with
 * the admin's own rights.
 *
 * The fix
 * -------
 * Every form that changes something carries a token, and the page checks it
 * before changing anything. The other site cannot get the token: it is not in
 * a cookie the browser sends on its own, and the other site cannot read our
 * pages (the browser forbids it). So its forged form has no token, or a wrong
 * one, and is refused.
 *
 * The token
 * ---------
 * HMAC-SHA256 of a form name, keyed on the phpBB session id. This application
 * has no PHP session of its own to store a random token in, only the phpBB
 * session cookie that the shim reads, so the token is derived instead:
 *
 *  - it needs no storage and no new secret to deploy or rotate;
 *  - it is per session: a token from one login is worthless in another, and
 *    logging in again (a new session id) is what makes old tokens expire.
 *    There is no expiry by time; a form left open all day still saves, which
 *    is what judges at a long event need;
 *  - the session id itself is never printed; an HMAC cannot be turned back
 *    into its key.
 *
 * The judge area uses one form name, DEFAULT_FORM, for all its forms: a
 * cross-site attacker can read none of our pages, so a token per form would
 * stop nothing more. The certificate and lap-count review pages keep the
 * per-form names they had (Baja\Certificado\Insercao\Csrf).
 *
 * In a page
 * ---------
 * In the form:      <?= Csrf::field() ?>
 * Before a change:  Csrf::enforce();
 * From JavaScript:  the token is in <meta name="csrf-token"> on every judge
 *                   page (Baja\Juiz\Template), sent as the X-CSRF-Token
 *                   header; jQuery's $.post/$.ajax do it on their own.
 *
 * Not for login and logout: there is no session yet to key on. Those use
 * LoginCsrf, which explains why.
 */
final class Csrf
{
    /** The hidden input's name. Also what the certificate pages always used. */
    public const FIELD = '_csrf';

    /** The header JavaScript sends it in. PHP sees it as HTTP_X_CSRF_TOKEN. */
    public const HEADER = 'X-CSRF-Token';

    public const DEFAULT_FORM = 'juiz';

    /** The token for $form in the current session; '' without a session. */
    public static function token(string $form = self::DEFAULT_FORM): string
    {
        $sid = self::sessionId();

        if ($sid === '') {
            // No session means the request is not authenticated, which the
            // page's own permission check has already refused. An unusable
            // token rather than a guessable one keeps this honest if the
            // order ever changes.
            return '';
        }

        return hash_hmac('sha256', $form, $sid);
    }

    /** Whether $sent is the token for $form in the current session. */
    public static function isValid(string $form, mixed $sent): bool
    {
        $expected = self::token($form);

        // is_string: ?_csrf[]=x arrives as an array, which must be a plain
        // "no", not a warning.
        if ($expected === '' || !is_string($sent) || $sent === '') {
            return false;
        }

        // hash_equals takes the same time however much of $sent matches, so
        // timing the answer does not reveal the token a character at a time.
        return hash_equals($expected, $sent);
    }

    /** The hidden input, ready to drop into a form. */
    public static function field(string $form = self::DEFAULT_FORM): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token($form), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * What the request now being handled sent as its token: the form field,
     * or the header for a JSON call. null when it sent neither.
     */
    public static function sent(): mixed
    {
        if (isset($_POST[self::FIELD])) {
            return $_POST[self::FIELD];
        }
        return $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }

    /** Whether the request now being handled carries a valid token. */
    public static function requestIsValid(string $form = self::DEFAULT_FORM): bool
    {
        return self::isValid($form, self::sent());
    }

    /**
     * Stops the request, before anything is changed, unless it carries a
     * valid token.
     *
     * The refusal is a 403 that says what happened and that nothing was
     * saved, in JSON when the caller is a script, so the page can show it.
     * Call this first in every branch that changes something, after the
     * permission check: someone without permission should hear that, not
     * this.
     */
    public static function enforce(string $form = self::DEFAULT_FORM): void
    {
        if (self::requestIsValid($form)) {
            return;
        }

        [$status, $contentType, $body] = self::refusal(self::wantsJson());
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . $contentType);
            header('Cache-Control: private, no-store');
        }
        echo $body;
        exit;
    }

    /**
     * The refusal enforce() sends: [status, content type, body]. Apart so the
     * tests can read it without ending the test process.
     *
     * @return array{int, string, string}
     */
    public static function refusal(bool $json): array
    {
        $message = 'Nada foi alterado: o formulário veio sem o código de segurança, '
            . 'ou com um código de outra sessão. Volte, recarregue a página e tente de novo. '
            . 'Se você não enviou este formulário, outra página tentou enviá-lo em seu nome.';

        if ($json) {
            return [403, 'application/json; charset=utf-8',
                (string) json_encode(['ok' => false, 'erro' => 'csrf', 'mensagem' => $message], JSON_UNESCAPED_UNICODE)];
        }

        return [403, 'text/html; charset=utf-8',
            '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Nada foi alterado</title></head>'
            . '<body><p style="font: 18px sans-serif; color: #900">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="javascript:history.back()">Voltar</a></p></body></html>'];
    }

    /** A script calling with JSON or the header gets its answer in JSON. */
    private static function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        return isset($_SERVER['HTTP_X_CSRF_TOKEN'])
            || str_contains($accept, 'application/json')
            || str_contains($type, 'application/json');
    }

    private static function sessionId(): string
    {
        $prefix = (string) getenv('PHPBB_COOKIE_PREFIX');

        if ($prefix === '') {
            return '';
        }

        $sid = $_COOKIE[$prefix . '_sid'] ?? '';
        return is_string($sid) ? $sid : '';
    }
}
