<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Api\Resultados;

use Baja\Api\Resultados\ApiError;
use Baja\Api\Resultados\Query;
use Baja\Api\Resultados\ResultadoEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * How the public results API reads a query string.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The public results API (api/resultados/*.php, docs/results-api.md) is
 * meant to be polled by thousands of viewers of a live stream at once. They
 * do not reach our server: Cloudflare keeps a copy of each answer for a few
 * seconds and serves it to everyone who asks for the same URL.
 *
 * "The same URL" is the weak point. If the API answered "?evt=26BR&x=1",
 * "?evt=26BR&x=2" and so on, each would be a new URL, Cloudflare would have
 * no copy of it, and every such request would reach PHP. So the API only
 * answers query strings made of exactly the parameters an endpoint
 * documents, each written in exactly one way, and refuses anything else
 * with "400 Bad Request". Those refusals are never cached (see
 * EndpointTest).
 *
 * Here the parameters are those of resultado.php:
 *
 *   evt      the event, e.g. 26BR            required
 *   id       the resultado, e.g. 26BR_GER    required
 *   display  "1" to also get the HTML        optional
 *
 * Whether "26BR" is an event that exists is not checked here; that is a
 * 404, which needs the database (see the DB tests).
 */
final class QueryTest extends TestCase
{
    /** @return array<string, string> */
    private function parse(string $queryString): array
    {
        return Query::parse($queryString, ResultadoEndpoint::PARAMS);
    }

    /** Asserts that parsing fails with 400 and the given code, and returns the message. */
    private function assertRefused(string $queryString, string $code): string
    {
        try {
            $this->parse($queryString);
        } catch (ApiError $error) {
            $this->assertSame(400, $error->status, $queryString);
            $this->assertSame($code, $error->errorCode, $queryString);
            return $error->getMessage();
        }
        $this->fail('Expected a 400 for ?' . $queryString);
    }

    // --- What is accepted ------------------------------------------------

    public function testTheDocumentedParametersAreRead(): void
    {
        $this->assertSame(['evt' => '26BR', 'id' => '26BR_GER'], $this->parse('evt=26BR&id=26BR_GER'));
        $this->assertSame(['evt' => '26BR', 'id' => '26BR_GER', 'display' => '1'], $this->parse('evt=26BR&id=26BR_GER&display=1'));
    }

    /** The order does not matter (it does not change the answer). */
    public function testParametersMayComeInAnyOrder(): void
    {
        $this->assertSame(['id' => '26BR_GER', 'evt' => '26BR'], $this->parse('id=26BR_GER&evt=26BR'));
    }

    // --- Parameters nobody documented ------------------------------------

    /** The case the whole class is about: a parameter only there to miss the cache. */
    public function testAnUnknownParameterIsRefused(): void
    {
        $message = $this->assertRefused('evt=26BR&id=26BR_GER&cachebuster=123', ApiError::UNKNOWN_PARAMETER);

        // The message says what is accepted, for whoever made the mistake.
        $this->assertStringContainsString('cachebuster', $message);
        $this->assertStringContainsString('evt, id, display', $message);
    }

    /** Names are case sensitive: "EVT" is not "evt". */
    public function testANameInAnotherCaseIsUnknown(): void
    {
        $this->assertRefused('EVT=26BR&id=26BR_GER', ApiError::UNKNOWN_PARAMETER);
    }

    /** PHP would read "evt[]=26BR" as an array. Here it is just an unknown name. */
    public function testArraySyntaxIsUnknown(): void
    {
        $this->assertRefused('evt[]=26BR&id=26BR_GER', ApiError::UNKNOWN_PARAMETER);
    }

    /** "&&", a leading "&" or a trailing one make a parameter with no name. */
    public function testAnEmptyParameterIsRefused(): void
    {
        $this->assertRefused('evt=26BR&id=26BR_GER&', ApiError::UNKNOWN_PARAMETER);
        $this->assertRefused('&evt=26BR&id=26BR_GER', ApiError::UNKNOWN_PARAMETER);
        $this->assertRefused('evt=26BR&&id=26BR_GER', ApiError::UNKNOWN_PARAMETER);
    }

    // --- Each value written in exactly one way ---------------------------

    /** Twice the same parameter: PHP would silently keep the last one. */
    public function testARepeatedParameterIsRefused(): void
    {
        $this->assertRefused('evt=26BR&evt=26BR&id=26BR_GER', ApiError::INVALID_PARAMETER);
    }

    /**
     * "%42" is "B" percent-encoded. A decoder would read "26%42R" as "26BR",
     * a second URL for the same answer. The ids never need encoding, so
     * the value is checked as sent, and an encoded one is refused.
     */
    public function testAPercentEncodedValueIsRefused(): void
    {
        $this->assertRefused('evt=26%42R&id=26BR_GER', ApiError::INVALID_PARAMETER);
    }

    public function testValuesThatAreNotShapedLikeIdsAreRefused(): void
    {
        $this->assertRefused('evt=26BRX&id=26BR_GER', ApiError::INVALID_PARAMETER);   // 5 characters
        $this->assertRefused('evt=26B&id=26BR_GER', ApiError::INVALID_PARAMETER);     // 3 characters
        $this->assertRefused('evt=26BR&id=GER', ApiError::INVALID_PARAMETER);         // no event prefix
        $this->assertRefused('evt=26BR&id=26BR_GERAL', ApiError::INVALID_PARAMETER); // prova part too long
        $this->assertRefused("evt=26BR'&id=26BR_GER", ApiError::INVALID_PARAMETER);
        $this->assertRefused('evt=&id=26BR_GER', ApiError::INVALID_PARAMETER);       // empty
        $this->assertRefused('evt&id=26BR_GER', ApiError::INVALID_PARAMETER);        // no "=" at all
    }

    /** "display" is a switch: "=1", or left out. "0", "true" or nothing are other URLs. */
    public function testDisplayOnlyAcceptsOne(): void
    {
        $this->assertRefused('evt=26BR&id=26BR_GER&display=0', ApiError::INVALID_PARAMETER);
        $this->assertRefused('evt=26BR&id=26BR_GER&display=true', ApiError::INVALID_PARAMETER);
        $this->assertRefused('evt=26BR&id=26BR_GER&display=', ApiError::INVALID_PARAMETER);
        $this->assertRefused('evt=26BR&id=26BR_GER&display', ApiError::INVALID_PARAMETER);
    }

    // --- Required parameters ---------------------------------------------

    public function testAMissingRequiredParameterIsRefused(): void
    {
        $this->assertRefused('evt=26BR', ApiError::MISSING_PARAMETER);
        $this->assertRefused('', ApiError::MISSING_PARAMETER);
    }
}
