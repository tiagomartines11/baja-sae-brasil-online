<?php

namespace Baja\Certificado\Insercao;

use Baja\Auth\Csrf as SessionCsrf;

/**
 * A CSRF token for the insertion forms.
 *
 * /buscar needs none — it changes nothing, and a forged search is a search.
 * These pages create certificates, which is exactly the thing a forged
 * request would be worth making.
 *
 * Now a thin name for Baja\Auth\Csrf, which grew out of this class and
 * explains the token (BAJAAPP-41). Kept so the certificate and lap-count
 * review pages need no change: the tokens are the same HMAC of the same form
 * name keyed on the same session id, byte for byte. Keying per form name
 * means a token lifted from one page cannot be replayed against the other.
 */
final class Csrf
{
    public const CAMPO = SessionCsrf::FIELD;

    public static function token(string $formulario): string
    {
        return SessionCsrf::token($formulario);
    }

    public static function valido(string $formulario, ?string $enviado): bool
    {
        return SessionCsrf::isValid($formulario, $enviado);
    }

    /** The hidden input, ready to drop into a form. */
    public static function campo(string $formulario): string
    {
        return SessionCsrf::field($formulario);
    }

    /** Whether the POST now being handled carries a valid token. */
    public static function postValido(string $formulario): bool
    {
        return SessionCsrf::isValid($formulario, $_POST[self::CAMPO] ?? null);
    }
}
