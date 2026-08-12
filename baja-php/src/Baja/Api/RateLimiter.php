<?php

namespace Baja\Api;

use Baja\Util\Env;

/**
 * Rate limiter using a sliding window backed by Redis.
 *
 * Provides protection against API abuse with configurable limits per endpoint
 * category.
 *
 * Storage note: this was APCu on the VM. APCu is per-process memory, so each
 * php-fpm worker kept its own counters and the effective limit was multiplied
 * by the worker count (and reset on every deploy). Redis is already part of
 * the compose stack and shared by every worker, so the limit it enforces is
 * the real one. See Baja\Auth\SessionStore for the same connection convention.
 *
 * Fail-open: if Redis is unreachable, requests are allowed. A rate limiter
 * outage should not take the API down with it — same posture as the VM's
 * behaviour when APCu was missing.
 */
class RateLimiter
{
    /** @var int Auth endpoints: 5 requests/minute */
    private const LIMIT_AUTH = 5;
    private const WINDOW_AUTH = 60;

    /** @var int Write endpoints (POST/PUT/DELETE): 30 requests/minute */
    private const LIMIT_WRITE = 30;
    private const WINDOW_WRITE = 60;

    /** @var int Read endpoints (GET): 100 requests/minute */
    private const LIMIT_READ = 100;
    private const WINDOW_READ = 60;

    /** @var int Login endpoint: stricter limit of 3 requests/minute */
    private const LIMIT_LOGIN = 3;
    private const WINDOW_LOGIN = 60;

    /** @var string Redis key prefix */
    private const KEY_PREFIX = 'rate_limit:';

    /** @var string[] Every category buildKey() can produce; used by clear(). */
    private const CATEGORIES = ['auth:login', 'auth:general', 'write', 'read'];

    /**
     * Atomic sliding-window check.
     *
     * Doing this as a single script matters: the read-filter-write sequence
     * this replaces let two concurrent workers both observe count < limit and
     * both admit a request, so the limit leaked under exactly the concurrent
     * load it exists to stop.
     *
     * KEYS[1] = key, ARGV = [now, window, limit, member]
     * Returns [allowed, count, reset].
     */
    private const SCRIPT_CHECK = <<<'LUA'
        local key    = KEYS[1]
        local now    = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        local limit  = tonumber(ARGV[3])
        local member = ARGV[4]

        redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)
        local count = redis.call('ZCARD', key)

        if count >= limit then
            local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
            local reset = now + window
            if oldest[2] then
                reset = tonumber(oldest[2]) + window + 1
            end
            return {0, count, math.ceil(reset)}
        end

        redis.call('ZADD', key, now, member)
        redis.call('EXPIRE', key, math.ceil(window) + 10)
        return {1, count + 1, math.ceil(now + window)}
    LUA;

    /** @var \Redis|null Lazily opened connection */
    private static ?\Redis $redis = null;

    /** @var bool Set once a connection attempt has failed */
    private static bool $redisDisabled = false;

    /**
     * Check if the current request should be rate limited.
     * Returns true if request is allowed, false if rate limited.
     *
     * @param string $identifier Unique identifier (IP address or user ID)
     * @param string $endpoint The API endpoint path
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @return array{allowed: bool, limit: int, remaining: int, reset: int}
     */
    public static function check(string $identifier, string $endpoint, string $method): array
    {
        $redis = self::redis();

        if ($redis === null) {
            // Storage unavailable — allow the request, advertise no limit.
            return [
                'allowed' => true,
                'limit' => 0,
                'remaining' => 0,
                'reset' => 0
            ];
        }

        [$limit, $window] = self::getLimitsForEndpoint($endpoint, $method);
        $key = self::buildKey($identifier, $endpoint, $method);

        return self::slidingWindowCheck($redis, $key, $limit, $window);
    }

    /**
     * Check rate limit and send 429 response if exceeded.
     *
     * @param string $identifier Unique identifier (IP address or user ID)
     * @param string $endpoint The API endpoint path
     * @param string $method HTTP method
     * @return void Exits with 429 if rate limited
     */
    public static function enforce(string $identifier, string $endpoint, string $method): void
    {
        $result = self::check($identifier, $endpoint, $method);

        // Set rate limit headers
        if ($result['limit'] > 0) {
            header('X-RateLimit-Limit: ' . $result['limit']);
            header('X-RateLimit-Remaining: ' . max(0, $result['remaining']));
            header('X-RateLimit-Reset: ' . $result['reset']);
        }

        if (!$result['allowed']) {
            $retryAfter = $result['reset'] - time();
            header('Retry-After: ' . max(1, $retryAfter));
            http_response_code(429);
            echo json_encode([
                'error' => 'Too Many Requests',
                'message' => 'Limite de requisições excedido. Tente novamente em ' . max(1, $retryAfter) . ' segundos.',
                'retry_after' => max(1, $retryAfter)
            ]);
            exit;
        }
    }

    /**
     * Get rate limits based on endpoint and HTTP method.
     *
     * @param string $endpoint
     * @param string $method
     * @return array{0: int, 1: int} [limit, window_seconds]
     */
    private static function getLimitsForEndpoint(string $endpoint, string $method): array
    {
        // Stricter limits for login endpoint
        if (preg_match('#/api/auth/login\.php#', $endpoint)) {
            return [self::LIMIT_LOGIN, self::WINDOW_LOGIN];
        }

        // Auth endpoints (registration, password reset, etc.)
        if (preg_match('#/api/auth/#', $endpoint)) {
            return [self::LIMIT_AUTH, self::WINDOW_AUTH];
        }

        // Write operations
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return [self::LIMIT_WRITE, self::WINDOW_WRITE];
        }

        // Read operations (GET, OPTIONS, HEAD)
        return [self::LIMIT_READ, self::WINDOW_READ];
    }

    /**
     * Build a unique cache key for rate limiting.
     *
     * @param string $identifier
     * @param string $endpoint
     * @param string $method
     * @return string
     */
    private static function buildKey(string $identifier, string $endpoint, string $method): string
    {
        // Normalize endpoint to category level for broader rate limiting
        $category = self::getEndpointCategory($endpoint, $method);
        return self::KEY_PREFIX . hash('xxh64', $identifier . ':' . $category);
    }

    /**
     * Get the category for an endpoint (for grouping rate limits).
     *
     * @param string $endpoint
     * @param string $method
     * @return string
     */
    private static function getEndpointCategory(string $endpoint, string $method): string
    {
        // Special handling for auth endpoints - rate limit per specific endpoint
        if (preg_match('#/api/auth/login\.php#', $endpoint)) {
            return 'auth:login';
        }
        if (preg_match('#/api/auth/#', $endpoint)) {
            return 'auth:general';
        }

        // For other endpoints, group by method type
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return 'write';
        }

        return 'read';
    }

    /**
     * Sliding window rate limit check using a Redis sorted set.
     *
     * @return array{allowed: bool, limit: int, remaining: int, reset: int}
     */
    private static function slidingWindowCheck(\Redis $redis, string $key, int $limit, int $window): array
    {
        $now = microtime(true);

        // %.6F, not plain float-to-string: the implicit cast honours the
        // `precision` ini (14 by default), which truncates a microtime value's
        // significant digits. %F is also locale-independent, so a locale using
        // ',' as the decimal separator can't produce a score Lua won't parse.
        $nowArg = sprintf('%.6F', $now);

        // Members must be unique, or requests landing on the same timestamp
        // would collapse into a single sorted-set entry and be undercounted.
        $member = $nowArg . ':' . bin2hex(random_bytes(6));

        try {
            $result = $redis->eval(self::SCRIPT_CHECK, [$key, $nowArg, $window, $limit, $member], 1);

            if (!is_array($result) || count($result) < 3) {
                throw new \RuntimeException('unexpected script result');
            }
        } catch (\Throwable $e) {
            error_log('RateLimiter: Redis check failed; allowing request: ' . $e->getMessage());
            return [
                'allowed' => true,
                'limit' => 0,
                'remaining' => 0,
                'reset' => 0
            ];
        }

        [$allowed, $count, $reset] = $result;

        return [
            'allowed' => (bool) $allowed,
            'limit' => $limit,
            'remaining' => max(0, $limit - (int) $count),
            'reset' => (int) $reset
        ];
    }

    /**
     * Whether rate limiting is actually being enforced.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return self::redis() !== null;
    }

    /**
     * Clear rate limit for an identifier (useful for testing or admin override).
     *
     * @param string $identifier
     * @return void
     */
    public static function clear(string $identifier): void
    {
        $redis = self::redis();

        if ($redis === null) {
            return;
        }

        // Clear all categories for this identifier
        foreach (self::CATEGORIES as $category) {
            $key = self::KEY_PREFIX . hash('xxh64', $identifier . ':' . $category);

            try {
                $redis->del($key);
            } catch (\Throwable $e) {
                error_log('RateLimiter: Redis del failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get current rate limit status without incrementing counter.
     *
     * @param string $identifier
     * @param string $endpoint
     * @param string $method
     * @return array{count: int, limit: int, remaining: int, window: int}
     */
    public static function getStatus(string $identifier, string $endpoint, string $method): array
    {
        $redis = self::redis();

        if ($redis === null) {
            return [
                'count' => 0,
                'limit' => 0,
                'remaining' => 0,
                'window' => 0
            ];
        }

        [$limit, $window] = self::getLimitsForEndpoint($endpoint, $method);
        $key = self::buildKey($identifier, $endpoint, $method);

        try {
            // Counts entries inside the current window. Expired ones are swept
            // by the next check(), so this stays a read-only operation.
            $count = (int) $redis->zCount($key, sprintf('%.6F', microtime(true) - $window), '+inf');
        } catch (\Throwable $e) {
            error_log('RateLimiter: Redis zCount failed: ' . $e->getMessage());
            $count = 0;
        }

        return [
            'count' => $count,
            'limit' => $limit,
            'remaining' => max(0, $limit - $count),
            'window' => $window
        ];
    }

    /**
     * Lazily open the shared Redis connection. Mirrors the degradation
     * behaviour of Baja\Auth\SessionStore: one attempt, then stay disabled
     * for the rest of the request.
     */
    private static function redis(): ?\Redis
    {
        if (self::$redisDisabled) {
            return null;
        }
        if (self::$redis !== null) {
            return self::$redis;
        }
        if (!class_exists(\Redis::class)) {
            error_log('RateLimiter: phpredis extension not installed; rate limiting disabled.');
            self::$redisDisabled = true;
            return null;
        }

        $r = new \Redis();

        try {
            $ok = $r->connect(
                (string) Env::get('REDIS_HOST', 'redis'),
                Env::getInt('REDIS_PORT', 6379),
                1.0 // 1s connect timeout — this runs on every API request.
            );
            if (!$ok) {
                throw new \RuntimeException('connect() returned false');
            }
        } catch (\Throwable $e) {
            error_log('RateLimiter: Redis connect failed; rate limiting disabled: ' . $e->getMessage());
            self::$redisDisabled = true;
            return null;
        }

        self::$redis = $r;
        return self::$redis;
    }

    /**
     * Drop the cached connection (test seam).
     */
    public static function reset(): void
    {
        self::$redis = null;
        self::$redisDisabled = false;
    }
}
