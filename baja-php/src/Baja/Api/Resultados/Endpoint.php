<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

use Baja\Api\ApiLogger;
use Baja\Api\Cors;

/**
 * What every file in api/resultados/ does around its own work, so that each
 * of them is a few lines:
 *
 *     Endpoint::serve(EquipesEndpoint::PARAMS, [EquipesEndpoint::class, 'handle']);
 *
 * In order:
 *
 *   1. CORS for any origin, GET (and HEAD) only: Cors::handlePublic().
 *   2. Nothing after the script name: /resultados/evento.php/x would run
 *      this same script, under another cache key.
 *   3. The query string, strictly (Query::parse()).
 *   4. The endpoint's handler, which returns a Response or throws ApiError.
 *   5. The JSON, with Cache-Control from the Response, or no-store for an
 *      error.
 *
 * Anything a formula prints while the results are built (formulas are PHP,
 * written by the organisers) is thrown away, and PHP errors are logged but
 * never displayed: either would end up inside the JSON. An unexpected
 * exception is logged and answered with a 500.
 *
 * Rate limiting is not here: bootstrap.php applies it to every API request
 * before this code runs (see RateLimiter, "resultados").
 */
final class Endpoint
{
    /**
     * @param array<string, array{0: string, 1: bool}> $spec the endpoint's parameters, for Query::parse()
     * @param callable(array<string, string>): Response $handler
     */
    public static function serve(array $spec, callable $handler): void
    {
        ini_set('display_errors', 'Off');
        ApiLogger::initRequest();
        Cors::handlePublic('GET, HEAD');

        [$status, $cacheHeader, $json] = self::respond(
            $spec,
            (string)($_SERVER['QUERY_STRING'] ?? ''),
            (string)($_SERVER['PATH_INFO'] ?? ''),
            $handler
        );

        http_response_code($status);
        header($cacheHeader);
        echo $json;
    }

    /**
     * Steps 2 to 5, without sending anything, so the tests can call it.
     *
     * @param array<string, array{0: string, 1: bool}> $spec
     * @param callable(array<string, string>): Response $handler
     * @param ?callable(\Throwable): void $reportError where an unexpected exception goes; the log by default
     * @return array{0: int, 1: string, 2: string} [HTTP status, Cache-Control header line, JSON]
     */
    public static function respond(array $spec, string $queryString, string $pathInfo, callable $handler, ?callable $reportError = null): array
    {
        $reportError ??= static fn(\Throwable $error) => ApiLogger::error('Public results API failed', $error);
        ob_start();
        try {
            if ($pathInfo !== '') {
                throw ApiError::notFound('Endereço desconhecido.');
            }
            $response = $handler(Query::parse($queryString, $spec));
            $answer = [200, CachePolicy::header($response->cacheSeconds), self::encode($response->body)];
        } catch (ApiError $error) {
            $answer = [$error->status, CachePolicy::forError(), self::encode($error->body())];
        } catch (\Throwable $error) {
            $reportError($error);
            $internal = new ApiError(500, ApiError::INTERNAL_ERROR, 'Erro interno.');
            $answer = [500, CachePolicy::forError(), self::encode($internal->body())];
        } finally {
            ob_end_clean();
        }
        return $answer;
    }

    /**
     * The JSON text of a body. Shared with the tests, so that what they
     * compare is what a client receives.
     *
     * @param array<string, mixed> $body
     */
    public static function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
