<?php

namespace Baja\Certificado;

use Baja\Util\Env;

/**
 * Per-document backoff for /buscar.
 *
 * Rate limiting means something different here than it did before. The CPF is
 * out of the URL, so there is nothing left to enumerate; what remains worth
 * defending is the name. Somebody holding a CPF — and CPF-plus-name pairs leak
 * together in Brazilian breaches constantly — could otherwise sit and guess
 * names against it until one matches.
 *
 * So this counts failures against the document, not the IP. nginx's limit_req
 * covers the IP dimension, and the two are complementary: an attacker with a
 * pool of addresses defeats the first and not this one.
 *
 * Fail-open, matching Baja\Api\RateLimiter and Baja\Auth\SessionStore. A Redis
 * outage must not take certificate lookups down with it.
 */
final class Backoff
{
    /** Failures against one document before it stops answering. */
    private const MAX_FAILURES = 5;

    /** How long the failure count lives, and how long a lockout lasts. */
    private const WINDOW_SECONDS = 900;

    private const KEY_PREFIX = 'cert_backoff:';

    private static ?\Redis $redis = null;
    private static bool $disabled = false;

    /**
     * Whether this document may be tried again.
     */
    public static function allows(string $documento): bool
    {
        return self::retryAfterSeconds($documento) === null;
    }

    /**
     * Seconds until this document may be tried again, or null if it may now.
     *
     * Exists so the page can say why it is refusing. That does not leak
     * whether the document is on file: failures are counted for any value
     * submitted, real or invented, so a made-up CPF reaches this state after
     * five attempts exactly as a real one does. What it reveals is the
     * caller's own recent history, which they already know.
     */
    public static function retryAfterSeconds(string $documento): ?int
    {
        $redis = self::redis();
        if ($redis === null) {
            return null;
        }

        try {
            $key = self::key($documento);
            if ((int) $redis->get($key) < self::MAX_FAILURES) {
                return null;
            }

            $ttl = (int) $redis->ttl($key);

            // A key with no expiry set yet counts as a full window.
            return $ttl > 0 ? $ttl : self::WINDOW_SECONDS;
        } catch (\Throwable $e) {
            error_log('Certificado\Backoff: read failed; allowing: ' . $e->getMessage());

            return null;
        }
    }

    public static function recordFailure(string $documento): void
    {
        $redis = self::redis();
        if ($redis === null) {
            return;
        }

        try {
            $key = self::key($documento);
            // Refresh the expiry on every failure, so a slow guessing run does
            // not get a free reset by pacing itself just under the window.
            $redis->incr($key);
            $redis->expire($key, self::WINDOW_SECONDS);
        } catch (\Throwable $e) {
            error_log('Certificado\Backoff: write failed: ' . $e->getMessage());
        }
    }

    /**
     * Forget the failures for a document that has just been used successfully.
     *
     * Without this, somebody who mistypes their name five times is locked out
     * of their own certificate for fifteen minutes after finally getting it
     * right, which is the failure mode most likely to actually happen.
     */
    public static function clear(string $documento): void
    {
        $redis = self::redis();
        if ($redis === null) {
            return;
        }

        try {
            $redis->del(self::key($documento));
        } catch (\Throwable $e) {
            error_log('Certificado\Backoff: clear failed: ' . $e->getMessage());
        }
    }

    /**
     * The Redis key for a document. Never the document itself.
     *
     * Keyed, not a plain digest. A bare sha256 of a CPF is not a protection:
     * there are only 10^11 of them, so the whole space can be hashed on a
     * laptop and any leaked key reversed by lookup. HMAC with a per-deployment
     * secret makes that useless without the secret.
     *
     * With no secret configured this degrades to an unkeyed hash rather than
     * turning the backoff off, which is the lesser of the two problems: Redis
     * is bound to localhost, the values are counters with a fifteen-minute
     * life, and the alternative is no defence against name guessing at all.
     * Setting CERT_BACKOFF_SECRET removes the caveat.
     */
    private static function key(string $documento): string
    {
        /*
         * The same canonical form the lookup compares on, or the counter is
         * bypassable by rewriting the same document. Stripping punctuation
         * alone was not enough: leading zeros are insignificant to the search
         * — an integer column dropped them years ago — so 123456, 0123456 and
         * 00123456 all reach the same rows while hashing to different keys,
         * and each spelling handed out another five attempts. There is no
         * limit to how many zeros somebody can prepend.
         *
         * Letters come off for the same reason: AB123456 and 123456 are one
         * document as far as /buscar is concerned, so they are one bucket.
         */
        $normalized = Documento::comparableEstrangeiro($documento);
        if ($normalized === '') {
            $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $documento));
        }

        $secret = Env::get('CERT_BACKOFF_SECRET');

        $digest = $secret
            ? hash_hmac('sha256', $normalized, (string) $secret)
            : hash('sha256', $normalized);

        return self::KEY_PREFIX . $digest;
    }

    private static function redis(): ?\Redis
    {
        if (self::$disabled) {
            return null;
        }
        if (self::$redis !== null) {
            return self::$redis;
        }
        if (!class_exists(\Redis::class)) {
            error_log('Certificado\Backoff: phpredis not installed; per-document backoff disabled.');
            self::$disabled = true;

            return null;
        }

        $redis = new \Redis();

        try {
            if (!$redis->connect((string) Env::get('REDIS_HOST', 'redis'), Env::getInt('REDIS_PORT', 6379), 1.0)) {
                throw new \RuntimeException('connect() returned false');
            }
        } catch (\Throwable $e) {
            error_log('Certificado\Backoff: Redis connect failed; backoff disabled: ' . $e->getMessage());
            self::$disabled = true;

            return null;
        }

        self::$redis = $redis;

        return self::$redis;
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$redis = null;
        self::$disabled = false;
    }
}
