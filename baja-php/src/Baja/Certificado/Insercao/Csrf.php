<?php

namespace Baja\Certificado\Insercao;

/**
 * A CSRF token for the insertion forms.
 *
 * /buscar needs none — it changes nothing, and a forged search is a search.
 * These pages create certificates, which is exactly the thing a forged
 * request would be worth making.
 *
 * Derived from the phpBB session id rather than stored anywhere, because
 * there is nowhere to store it: this application has no PHP session of its
 * own, only the phpBB session cookie the shim reads. HMAC of the form name
 * keyed on the session id gives a token that is per-session and per-form,
 * needs no storage, and needs no new secret to be deployed and rotated.
 *
 * The session id is the secret, and it is a good one for this: an attacker on
 * another origin cannot read the cookie, which is the whole of what CSRF
 * protection rests on. Keying per form name means a token lifted from one
 * page cannot be replayed against the other.
 */
final class Csrf
{
    public const CAMPO = '_csrf';

    public static function token(string $formulario): string
    {
        $sid = self::sessionId();

        if ($sid === '') {
            // No session means the request is not authenticated, which the
            // page's own permission check has already refused. Returning an
            // unusable token rather than a guessable one keeps this honest if
            // the order ever changes.
            return '';
        }

        return hash_hmac('sha256', $formulario, $sid);
    }

    public static function valido(string $formulario, ?string $enviado): bool
    {
        $esperado = self::token($formulario);

        if ($esperado === '' || $enviado === null || $enviado === '') {
            return false;
        }

        return hash_equals($esperado, $enviado);
    }

    /** The hidden input, ready to drop into a form. */
    public static function campo(string $formulario): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::CAMPO,
            htmlspecialchars(self::token($formulario), ENT_QUOTES, 'UTF-8')
        );
    }

    /** Whether the POST now being handled carries a valid token. */
    public static function postValido(string $formulario): bool
    {
        return self::valido($formulario, isset($_POST[self::CAMPO]) ? (string) $_POST[self::CAMPO] : null);
    }

    private static function sessionId(): string
    {
        $prefixo = (string) getenv('PHPBB_COOKIE_PREFIX');

        if ($prefixo === '') {
            return '';
        }

        return (string) ($_COOKIE[$prefixo . '_sid'] ?? '');
    }
}
