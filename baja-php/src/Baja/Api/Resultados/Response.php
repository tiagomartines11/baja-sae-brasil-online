<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

/**
 * A successful answer of an endpoint, before it is sent: the JSON body as
 * an array, and how long it may be cached (CachePolicy). Errors are thrown
 * as ApiError instead.
 */
final class Response
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly array $body,
        public readonly int $cacheSeconds,
    ) {
    }
}
