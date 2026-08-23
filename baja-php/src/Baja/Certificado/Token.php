<?php

namespace Baja\Certificado;

/**
 * The public identifier for one certificate.
 *
 * 16 random bytes, base64url, unpadded — 22 characters. Random rather than
 * derived (an HMAC of the row, say) because the value has to be stored either
 * way in order to be looked up, and storing a random one means there is no
 * secret to manage, rotate, or leak.
 *
 * 128 bits is far past what enumeration resistance needs here; the point is
 * unlinkability. The identifier it replaces was the participant's CPF in
 * base 16, from which the CPF was recoverable with hexdec() and nothing else.
 */
final class Token
{
    /** Length of the generated string. base64 of 16 bytes is 24 chars with '==' padding. */
    public const LENGTH = 22;

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /**
     * Cheap syntactic check, used to reject junk before it reaches a query.
     *
     * Worth having on its own terms: the legacy route fed whatever the path
     * matched straight into hexdec(), which silently discards characters it
     * does not understand, and the deprecation notice that produced was the
     * information disclosure fixed in b158ed6. A token that is not exactly 22
     * base64url characters cannot match a row, so there is no reason to ask
     * the database.
     */
    public static function isWellFormed(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{' . self::LENGTH . '}\z/', $token) === 1;
    }
}
