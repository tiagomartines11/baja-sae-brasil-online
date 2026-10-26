<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

/**
 * A request the public results API answers with an error instead of data.
 *
 *   status   the HTTP status (400 for a bad query string, 404 for an id
 *            that names nothing)
 *   code     a short, stable word a program can test ("not_found")
 *   message  the same in Portuguese, for whoever reads the response
 *
 * Endpoint::serve() turns it into {"success": false, "error": message,
 * "code": code}, the shape Baja\Api\Cors::error() gives the rest of the API,
 * plus the code. Errors are never cached (see CachePolicy::forError()).
 */
final class ApiError extends \RuntimeException
{
    public const UNKNOWN_PARAMETER = 'unknown_parameter';
    public const INVALID_PARAMETER = 'invalid_parameter';
    public const MISSING_PARAMETER = 'missing_parameter';
    public const NOT_FOUND = 'not_found';
    public const WRONG_TYPE = 'wrong_type';
    public const INTERNAL_ERROR = 'internal_error';

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, self::NOT_FOUND, $message);
    }

    /** @return array{success: false, error: string, code: string} */
    public function body(): array
    {
        return ['success' => false, 'error' => $this->getMessage(), 'code' => $this->errorCode];
    }
}
