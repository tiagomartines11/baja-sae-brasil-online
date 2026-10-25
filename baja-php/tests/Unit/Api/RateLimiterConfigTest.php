<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Api;

use Baja\Api\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Which requests the rate limiter counts together, and against whom.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Every request to the API passes through Baja\Api\RateLimiter
 * (bootstrap.php calls it). It counts each client's requests over the last
 * minute in Redis and answers "429 Too Many Requests" past a limit.
 * Requests are counted in "buckets": all the plain reads of the API share
 * one bucket of 100 a minute, the login has its own of 3.
 *
 * The public results API (api/resultados/) is polled by stream overlays,
 * about once a second per endpoint, often all five endpoints at once. So
 * each of its endpoints has a bucket of its own, of 120 a minute. Most of
 * those requests never reach the limiter anyway: Cloudflare answers them
 * from its cache. The limiter is the backstop for whoever gets past it.
 *
 * Counting itself needs Redis, so these tests only look at the decisions
 * that come before it: the bucket and the limit for a path, and who a
 * request is counted against. The bucket and limit are private details of
 * the limiter, read here by reflection so that a test can name them.
 */
final class RateLimiterConfigTest extends TestCase
{
    /** @return array{0: string, 1: int, 2: int} [bucket, limit, window in seconds] */
    private static function bucketOf(string $path, string $method = 'GET'): array
    {
        $category = new \ReflectionMethod(RateLimiter::class, 'getEndpointCategory');
        $limits = new \ReflectionMethod(RateLimiter::class, 'getLimitsForEndpoint');
        return [$category->invoke(null, $path, $method), ...$limits->invoke(null, $path, $method)];
    }

    public function testEachPublicResultsEndpointHasItsOwnBucket(): void
    {
        $this->assertSame(['resultados:evento', 120, 60], self::bucketOf('/api/resultados/evento.php'));
        $this->assertSame(['resultados:equipes', 120, 60], self::bucketOf('/api/resultados/equipes.php'));
        $this->assertSame(['resultados:resultado', 120, 60], self::bucketOf('/api/resultados/resultado.php'));
        $this->assertSame(['resultados:corrida', 120, 60], self::bucketOf('/api/resultados/corrida.php'));
        $this->assertSame(['resultados:torneio', 120, 60], self::bucketOf('/api/resultados/torneio.php'));
    }

    /** About once a second per endpoint fits, with room to spare. */
    public function testTheLimitAllowsPollingOnceASecond(): void
    {
        $this->assertGreaterThanOrEqual(60, RateLimiter::LIMIT_RESULTADOS * 60 / RateLimiter::WINDOW_RESULTADOS);
    }

    /**
     * A name that is not one of the five does not get a bucket of its own
     * (else anyone could make up names to get fresh buckets); it counts as
     * an ordinary read.
     */
    public function testAMadeUpEndpointSharesTheOrdinaryReadBucket(): void
    {
        $this->assertSame(['read', 100, 60], self::bucketOf('/api/resultados/anything.php'));
        $this->assertSame(['read', 100, 60], self::bucketOf('/api/resultados/corrida.php/x'));
    }

    /** The rest of the API is as it was. */
    public function testOtherEndpointsAreUnchanged(): void
    {
        $this->assertSame(['auth:login', 3, 60], self::bucketOf('/api/auth/login.php', 'POST'));
        $this->assertSame(['read', 100, 60], self::bucketOf('/api/eventos/list.php'));
        $this->assertSame(['write', 30, 60], self::bucketOf('/api/eventos/manage.php', 'POST'));
    }

    /**
     * Requests are counted against the client's IP as the server saw it,
     * never against a header the client sent: X-Forwarded-For can say
     * anything, and a new value per request would be a new bucket per
     * request. (Behind Cloudflare, nginx itself puts the real client in
     * REMOTE_ADDR.) An API key would replace the IP in this same function.
     */
    public function testTheClientIsItsAddressNotWhatItsHeadersClaim(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.2'];

        $this->assertSame('203.0.113.7', RateLimiter::clientIdentifier($server));
        $this->assertSame('unknown', RateLimiter::clientIdentifier([]));
    }
}
