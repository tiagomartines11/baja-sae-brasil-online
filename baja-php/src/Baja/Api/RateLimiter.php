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

    /**
     * Public results API (api/resultados/, docs/results-api.md): 120
     * requests/minute per client, counted for each endpoint on its own.
     *
     * An overlay polls each endpoint about once a second (60/minute), so
     * this leaves it room for retries and a second tab, and polling all five
     * at once never adds up to one shared limit. Cloudflare answers most of
     * these requests from its cache, so this only counts the ones that reach
     * PHP: it is a backstop for someone defeating the cache, not the
     * protection itself.
     */
    public const LIMIT_RESULTADOS = 120;
    public const WINDOW_RESULTADOS = 60;

    /** @var string[] The endpoints of the public results API, which each have their own bucket. */
    public const RESULTADOS_ENDPOINTS = ['evento', 'equipes', 'resultado', 'corrida', 'torneio'];

    /** @var string Redis key prefix */
    private const KEY_PREFIX = 'rate_limit:';

    /** @var string[] Every category buildKey() can produce, besides "resultados:<endpoint>"; used by clear(). */
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
     * Who a request counts against: the one place that decides it.
     *
     * Today that is the client's IP, REMOTE_ADDR only, never a request
     * header. Headers like X-Forwarded-For and X-Real-IP are
     * attacker-controlled: nginx maps whatever the client sends into HTTP_*,
     * so trusting them lets anyone mint a fresh rate-limit bucket per
     * request and bypass the limiter entirely.
     *
     * Making REMOTE_ADDR truthful is nginx's job, and is where the only
     * dev/prod difference lives: behind Cloudflare, 00-realip.conf
     * restores the client from CF-Connecting-IP; in dev the peer address is
     * already the client. See baja-infra/nginx/templates/baja-app-api.conf.template.
     *
     * When the API gets keys, the key will replace the IP here, so a client
     * is limited per key rather than per address (an event venue puts
     * hundreds of phones behind one IP).
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function clientIdentifier(array $server): string
    {
        return (string)($server['REMOTE_ADDR'] ?? 'unknown');
    }

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

        // Public results API: every method is a read there (anything else
        // is refused with 405, and still counted here)
        if (self::resultadosEndpoint($endpoint) !== null) {
            return [self::LIMIT_RESULTADOS, self::WINDOW_RESULTADOS];
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

        // Public results API: one bucket per endpoint, so an overlay polling
        // all of them is not limited as if it polled one
        $resultados = self::resultadosEndpoint($endpoint);
        if ($resultados !== null) {
            return 'resultados:' . $resultados;
        }

        // For other endpoints, group by method type
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return 'write';
        }

        return 'read';
    }

    /**
     * The public results endpoint a path names ("corrida" for
     * /api/resultados/corrida.php), or null. Only the known ones: a made-up
     * name must not get a bucket of its own.
     */
    private static function resultadosEndpoint(string $endpoint): ?string
    {
        if (preg_match('#^/api/resultados/([a-z]+)\.php$#', $endpoint, $m) === 1
            && in_array($m[1], self::RESULTADOS_ENDPOINTS, true)) {
            return $m[1];
        }
        return null;
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
        $resultados = array_map(fn(string $name) => 'resultados:' . $name, self::RESULTADOS_ENDPOINTS);
        foreach (array_merge(self::CATEGORIES, $resultados) as $category) {
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
