<?php
declare(strict_types=1);

namespace Baja\Auth;

class PasswordVerifier
{
    /**
     * Verify a plaintext password against a phpBB-stored hash.
     *
     * Production hash distribution (per docs/phpbb-coupling-report.md): bcrypt
     * ($2y$) and argon2id ($argon2id$). Anything else — empty hash, legacy
     * phpass ($H$, $P$) — is rejected without invoking password_verify().
     */
    public static function verify(string $plaintext, string $storedHash): bool
    {
        if ($storedHash === '' || $storedHash[0] !== '$') {
            return false;
        }
        return password_verify($plaintext, $storedHash);
    }
}
