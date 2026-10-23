<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Util\Env;

/**
 * One shared Redis connection for the lap counting code.
 *
 * Fail-soft, like Baja\Auth\SessionStore and Baja\Api\RateLimiter: Redis
 * only holds things that make the screens nicer (the state version, the
 * feed cache, who is looking at which flag). Counting itself lives in
 * MySQL, so a Redis outage must degrade the review screen, never stop an
 * upload. Every caller therefore has to cope with null.
 */
final class RedisClient
{
    private static ?\Redis $connection = null;

    /** Set after a failed attempt so one request does not pay the connect timeout repeatedly. */
    private static bool $unavailable = false;

    /**
     * @return \Redis|null null when the extension is missing or the server cannot be reached
     */
    public static function get(): ?\Redis
    {
        if (self::$unavailable) {
            return null;
        }
        if (self::$connection !== null) {
            return self::$connection;
        }
        if (!class_exists(\Redis::class)) {
            self::$unavailable = true;
            return null;
        }

        $redis = new \Redis();
        try {
            $connected = $redis->connect(
                (string) Env::get('REDIS_HOST', 'redis'),
                (int) Env::getInt('REDIS_PORT', 6379),
                1.0
            );
            if (!$connected) {
                throw new \RuntimeException('connect() returned false');
            }
        } catch (\Throwable $error) {
            error_log('Contagem\RedisClient: Redis unavailable: ' . $error->getMessage());
            self::$unavailable = true;
            return null;
        }

        self::$connection = $redis;
        return self::$connection;
    }

    /**
     * Forget the connection and the "unavailable" verdict. For tests and
     * long-running scripts; a web request never needs it.
     */
    public static function reset(): void
    {
        self::$connection = null;
        self::$unavailable = false;
    }
}
