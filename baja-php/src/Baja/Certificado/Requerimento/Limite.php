<?php

namespace Baja\Certificado\Requerimento;

use Baja\Util\Env;

/**
 * How often one caller may submit /requerimento.
 *
 * Turnstile stops a bot; this stops a person, or a bot that solved one
 * challenge, from turning the form into a mail generator. Two things need
 * protecting and they are not the same thing:
 *
 *  - The staff queue, which is keyed by address. Fifty reports in a minute is
 *    not fifty problems.
 *  - Somebody else's inbox. Every submission sends a copy to the address
 *    typed into the form, and nothing proves that address belongs to whoever
 *    is typing. Without a per-address cap this is a mail bomb with SAE
 *    BRASIL's name on the return path, which is worse than the spam: it burns
 *    the sending domain's reputation.
 *
 * Redis, and fail-open, like Baja\Certificado\Backoff and Baja\Api\RateLimiter
 * — an outage must not take the correction channel down with it. nginx's
 * limit_req on POST /requerimento is the other half, and covers the case where
 * Redis is the thing that is down.
 */
final class Limite
{
    /** Submissions from one address before it has to wait. */
    private const MAX_POR_ORIGEM = 5;

    /** Submissions naming one email address before it has to wait. */
    private const MAX_POR_EMAIL = 3;

    /** How long both counters live. */
    private const JANELA_SEGUNDOS = 3600;

    private const KEY_PREFIX = 'cert_requerimento:';

    private static ?\Redis $redis = null;
    private static bool $disabled = false;

    /**
     * Seconds until this caller may submit again, or null if they may now.
     *
     * A read, and only a hint. It runs before the captcha so that an address
     * already over the limit does not also spend a Turnstile verification,
     * but it takes no slot: two requests that arrive together both see the
     * same count here. reservar() is the check that holds.
     */
    public static function esperaSegundos(string $origem, string $email): ?int
    {
        $espera = null;

        foreach (self::chaves($origem, $email) as $chave => $maximo) {
            $restante = self::esperaDaChave($chave, $maximo);

            if ($restante !== null && ($espera === null || $restante > $espera)) {
                $espera = $restante;
            }
        }

        return $espera;
    }

    /**
     * Take one slot for this submission, or say how long to wait.
     *
     * One INCR per counter, and the answer comes from what INCR returns
     * rather than from a read made earlier. That is the whole fix: the old
     * pair — read the count at the top of the request, add one at the bottom,
     * after two SMTP conversations — let six requests that arrived within the
     * same few seconds all read the same count and all pass (BAJAAPP-89).
     * Redis runs INCR one at a time, so six concurrent requests get 1 to 6
     * back and only the first three are under the email ceiling.
     *
     * Called only for a submission that passed the captcha and the form's
     * validation. Counting rejections too would mean somebody who mistypes
     * their email three times is locked out of reporting a problem with their
     * own certificate, which is the failure most likely to actually happen —
     * the same reasoning that put Backoff::clear() in /buscar.
     *
     * A refusal hands back what it took, so that being turned away by one
     * counter does not use up a slot on the other.
     *
     * @return int|null null when the slot is taken and the caller may go on;
     *                  otherwise the seconds until it may try again
     */
    public static function reservar(string $origem, string $email): ?int
    {
        $redis = self::redis();

        if ($redis === null) {
            return null;
        }

        $incrementadas = [];
        $espera        = null;

        try {
            foreach (self::chaves($origem, $email) as $chave => $maximo) {
                $contagem        = (int) $redis->incr($chave);
                $incrementadas[] = $chave;

                // The first submission in the window starts the clock. Only
                // the first: renewing it on every INCR would let somebody who
                // keeps trying hold their own counter open forever.
                if ($contagem === 1) {
                    $redis->expire($chave, self::JANELA_SEGUNDOS);
                }

                if ($contagem > $maximo) {
                    $ttl    = (int) $redis->ttl($chave);
                    $espera = max($espera ?? 0, $ttl > 0 ? $ttl : self::JANELA_SEGUNDOS);
                }
            }

            if ($espera !== null) {
                foreach ($incrementadas as $chave) {
                    $redis->decr($chave);
                }
            }
        } catch (\Throwable $e) {
            // Fail open, like every read here: an outage must not take the
            // correction channel down with it.
            error_log('Certificado\Requerimento\Limite: reservation failed; allowing: ' . $e->getMessage());

            return null;
        }

        return $espera;
    }

    /**
     * The two counters, each with its ceiling.
     *
     * @return array<string, int> key => max
     */
    private static function chaves(string $origem, string $email): array
    {
        $chaves = [];

        if ($origem !== '') {
            $chaves[self::chave('origem', $origem)] = self::MAX_POR_ORIGEM;
        }

        if ($email !== '') {
            $chaves[self::chave('email', mb_strtolower($email, 'UTF-8'))] = self::MAX_POR_EMAIL;
        }

        return $chaves;
    }

    private static function esperaDaChave(string $chave, int $maximo): ?int
    {
        $redis = self::redis();

        if ($redis === null) {
            return null;
        }

        try {
            if ((int) $redis->get($chave) < $maximo) {
                return null;
            }

            $ttl = (int) $redis->ttl($chave);

            return $ttl > 0 ? $ttl : self::JANELA_SEGUNDOS;
        } catch (\Throwable $e) {
            error_log('Certificado\Requerimento\Limite: read failed; allowing: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The Redis key. Never the address or the email itself.
     *
     * Same reasoning as Backoff::key(): an email address and an IP are both
     * personal data, and Redis has no business holding either in a form
     * anybody can read back. Keyed on CERT_BACKOFF_SECRET rather than a
     * second secret — it is the same deployment, the same store, and the same
     * threat, and one more secret to configure is one more to be left unset.
     */
    private static function chave(string $tipo, string $valor): string
    {
        $secret = Env::get('CERT_BACKOFF_SECRET');

        $digest = $secret
            ? hash_hmac('sha256', $tipo . ':' . $valor, (string) $secret)
            : hash('sha256', $tipo . ':' . $valor);

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
            error_log('Certificado\Requerimento\Limite: phpredis not installed; submission limit disabled.');
            self::$disabled = true;

            return null;
        }

        $redis = new \Redis();

        try {
            if (!$redis->connect((string) Env::get('REDIS_HOST', 'redis'), Env::getInt('REDIS_PORT', 6379), 1.0)) {
                throw new \RuntimeException('connect() returned false');
            }
        } catch (\Throwable $e) {
            error_log('Certificado\Requerimento\Limite: Redis connect failed; limit disabled: ' . $e->getMessage());
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
