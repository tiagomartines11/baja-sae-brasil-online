<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Api\Resultados;

use Baja\Api\Resultados\ApiError;
use Baja\Api\Resultados\CachePolicy;
use Baja\Api\Resultados\Endpoint;
use Baja\Api\Resultados\EquipesEndpoint;
use Baja\Api\Resultados\Response;
use Baja\Model\Evento;
use PHPUnit\Framework\TestCase;

/**
 * What every endpoint of the public results API answers, around its data:
 * the HTTP status, the Cache-Control header, and the error body.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Each file in api/resultados/ calls Endpoint::serve() with its parameters
 * and a "handler", the function that looks up the data. Endpoint::respond()
 * is the part of serve() that decides what to send; these tests call it
 * with handlers written on the spot, so no database is needed.
 *
 * Cloudflare sits in front of the API and stores every answer that says
 * "Cache-Control: public, s-maxage=N" for N seconds. That is what lets a
 * whole broadcast audience poll the API without reaching this server. Two
 * rules follow, and are tested here:
 *
 *   - a successful answer always has s-maxage;
 *   - an error never does ("no-store"): a cached error could be served to
 *     people whose request was fine (see CachePolicy).
 *
 * Error bodies have the API foundation's shape (Baja\Api\Cors::error()):
 * {"success": false, "error": "<message>"}, plus "code", a word a program
 * can test.
 */
final class EndpointTest extends TestCase
{
    /** A handler that answers {"ok": true}, cacheable for 5 s. */
    private static function okHandler(): callable
    {
        return fn(array $params) => new Response(['ok' => true, 'params' => $params], 5);
    }

    private static function respond(string $queryString, ?callable $handler = null, string $pathInfo = '', ?callable $reportError = null): array
    {
        [$status, $cacheControl, $json] = Endpoint::respond(EquipesEndpoint::PARAMS, $queryString, $pathInfo, $handler ?? self::okHandler(), $reportError);
        return ['status' => $status, 'cache' => $cacheControl, 'body' => json_decode($json, true)];
    }

    // --- Success ---------------------------------------------------------

    public function testASuccessfulAnswerIsPubliclyCacheable(): void
    {
        $answer = self::respond('evt=26BR');

        $this->assertSame(200, $answer['status']);
        $this->assertSame('Cache-Control: public, max-age=5, s-maxage=5', $answer['cache']);
        $this->assertSame(['ok' => true, 'params' => ['evt' => '26BR']], $answer['body']);
    }

    // --- Errors ----------------------------------------------------------

    public function testABadQueryStringIsA400ThatIsNotCached(): void
    {
        $answer = self::respond('evt=26BR&nocache=1');

        $this->assertSame(400, $answer['status']);
        $this->assertSame('Cache-Control: no-store', $answer['cache']);
        $this->assertFalse($answer['body']['success']);
        $this->assertSame(ApiError::UNKNOWN_PARAMETER, $answer['body']['code']);
        $this->assertIsString($answer['body']['error']);
    }

    /** An id that names nothing: the handler throws, the client gets a 404. */
    public function testAnIdThatNamesNothingIsA404ThatIsNotCached(): void
    {
        $answer = self::respond('evt=99XX', fn() => throw ApiError::notFound('Evento não encontrado.'));

        $this->assertSame(404, $answer['status']);
        $this->assertSame('Cache-Control: no-store', $answer['cache']);
        $this->assertSame(['success' => false, 'error' => 'Evento não encontrado.', 'code' => 'not_found'], $answer['body']);
    }

    /**
     * "/resultados/equipes.php/anything" runs equipes.php too (nginx passes
     * "/anything" as PATH_INFO). Answering it would give the same data a
     * second URL, so it is a 404, and the handler is never called.
     */
    public function testAnythingAfterTheScriptNameIsA404(): void
    {
        $called = false;
        $answer = self::respond('evt=26BR', function () use (&$called) {
            $called = true;
            return new Response([], 5);
        }, pathInfo: '/anything');

        $this->assertSame(404, $answer['status']);
        $this->assertFalse($called);
    }

    /**
     * A bug: the client gets a 500 with no details (they would only help an
     * attacker), and the error itself goes to the log.
     */
    public function testAnUnexpectedErrorIsA500AndIsReported(): void
    {
        $reported = [];
        $answer = self::respond(
            'evt=26BR',
            fn() => throw new \RuntimeException('SQLSTATE[HY000] secret detail'),
            reportError: function (\Throwable $error) use (&$reported) {
                $reported[] = $error->getMessage();
            }
        );

        $this->assertSame(500, $answer['status']);
        $this->assertSame('Cache-Control: no-store', $answer['cache']);
        $this->assertSame(['success' => false, 'error' => 'Erro interno.', 'code' => 'internal_error'], $answer['body']);
        $this->assertSame(['SQLSTATE[HY000] secret detail'], $reported);
    }

    /**
     * Results formulas are PHP the organisers write. If one prints
     * something while the table is built, it must not end up in the
     * middle of the JSON. respond() keeps it out of the answer.
     */
    public function testWhatAHandlerPrintsIsNotSent(): void
    {
        $level = ob_get_level();
        ob_start();
        self::respond('evt=26BR', function () {
            echo 'Warning: something in a formula';
            return new Response(['ok' => true], 5);
        });
        $printed = ob_get_clean();

        $this->assertSame('', $printed);
        $this->assertSame($level, ob_get_level());
    }

    // --- How long --------------------------------------------------------

    /**
     * During an event results change whenever a judge saves, so they are
     * kept a few seconds; the live race even less. A finished event no
     * longer changes, so its answers are kept five minutes.
     *
     * "Finished" is the event's finalizado flag, and only that. Its
     * em_andamento flag looks like "running" but only marks the site's
     * default event (bootstrap.php), so it must change nothing here.
     */
    public function testCacheTimesFollowTheEvent(): void
    {
        foreach ([false, true] as $default) {
            $running = (new Evento())->setFinalizado(false)->setEmAndamento($default);
            $finished = (new Evento())->setFinalizado(true)->setEmAndamento($default);

            $this->assertSame(5, CachePolicy::forEvent($running));
            $this->assertSame(2, CachePolicy::forRace($running));
            $this->assertSame(300, CachePolicy::forEvent($finished));
            $this->assertSame(300, CachePolicy::forRace($finished));
        }
    }
}
