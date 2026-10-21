<?php

namespace Baja\Api;

use Baja\Logger;
use Baja\Model\User;

/**
 * API-specific logging utilities.
 * Provides structured logging for API requests with context tracking.
 */
class ApiLogger
{
    private static ?string $requestId = null;
    private static ?float $requestStartTime = null;
    private static ?string $endpoint = null;
    private static ?string $method = null;
    private static ?int $userId = null;
    private static bool $responseLogged = false;
    private static bool $shutdownRegistered = false;

    /** @var float Threshold in seconds for slow operation warnings */
    private const SLOW_THRESHOLD = 2.0;

    /**
     * Initialize request context. Call at the start of each API request.
     */
    public static function initRequest(?User $user = null): string
    {
        self::$requestId = self::generateRequestId();
        self::$requestStartTime = microtime(true);
        self::$endpoint = $_SERVER['REQUEST_URI'] ?? 'unknown';
        self::$method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
        self::$userId = $user?->getUserId();
        self::$responseLogged = false;

        return self::$requestId;
    }

    /**
     * Initialize with automatic response logging via shutdown handler.
     * This is the recommended way to initialize - just call this once at the start.
     */
    public static function init(): void
    {
        self::initRequest();

        // Log request received
        Logger::info('API request', array_merge(
            self::getBaseContext(),
            ['phase' => 'received']
        ));

        // Register shutdown handler to log response (only once)
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'logResponseOnShutdown']);
        }
    }

    /**
     * Shutdown handler to automatically log response.
     * @internal Called by PHP shutdown mechanism
     */
    public static function logResponseOnShutdown(): void
    {
        // Don't log if already logged manually or if no request was initialized
        if (self::$responseLogged || self::$requestId === null) {
            return;
        }

        $statusCode = http_response_code() ?: 200;
        self::requestCompleted($statusCode);
    }

    /**
     * Mark response as already logged (prevents duplicate logging).
     */
    public static function markResponseLogged(): void
    {
        self::$responseLogged = true;
    }

    /**
     * Set user after authentication (if not known at init time).
     */
    public static function setUser(?User $user): void
    {
        self::$userId = $user?->getUserId();
    }

    /**
     * Get the current request ID.
     */
    public static function getRequestId(): ?string
    {
        return self::$requestId;
    }

    /**
     * Get base context for all log entries.
     */
    private static function getBaseContext(): array
    {
        return array_filter([
            'request_id' => self::$requestId,
            'endpoint' => self::$endpoint,
            'method' => self::$method,
            'user_id' => self::$userId,
            'ip' => self::getClientIp(),
        ]);
    }

    /**
     * Generate a unique request ID.
     */
    private static function generateRequestId(): string
    {
        return substr(bin2hex(random_bytes(8)), 0, 16);
    }

    /**
     * Get client IP address.
     * Public for use by AuditService and other components.
     */
    public static function getClientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';
    }

    /**
     * Calculate request duration in milliseconds.
     */
    private static function getDurationMs(): ?float
    {
        if (self::$requestStartTime === null) {
            return null;
        }
        return round((microtime(true) - self::$requestStartTime) * 1000, 2);
    }

    // ========== Request Lifecycle Logging ==========

    /**
     * Log request received (call after initRequest and authentication).
     */
    public static function requestReceived(array $extra = []): void
    {
        Logger::info('API request received', array_merge(
            self::getBaseContext(),
            $extra
        ));
    }

    /**
     * Log request completed successfully.
     */
    public static function requestCompleted(int $statusCode, array $extra = []): void
    {
        // Prevent duplicate logging
        if (self::$responseLogged) {
            return;
        }
        self::$responseLogged = true;

        $duration = self::getDurationMs();
        $context = array_merge(
            self::getBaseContext(),
            [
                'status_code' => $statusCode,
                'duration_ms' => $duration,
                'phase' => 'completed',
            ],
            $extra
        );

        // Warn if slow
        if ($duration !== null && $duration > self::SLOW_THRESHOLD * 1000) {
            Logger::warning('Slow API request', $context);
        } else {
            Logger::info('API request', $context);
        }
    }

    // ========== Authentication & Authorization ==========

    /**
     * Log authentication failure.
     */
    public static function authenticationFailed(string $reason, array $extra = []): void
    {
        Logger::warning('Authentication failed', array_merge(
            self::getBaseContext(),
            ['reason' => $reason],
            $extra
        ));
    }

    /**
     * Log authorization failure.
     */
    public static function authorizationFailed(string $resource, string $action, array $scope = [], array $extra = []): void
    {
        Logger::warning('Authorization failed', array_merge(
            self::getBaseContext(),
            [
                'resource' => $resource,
                'action' => $action,
                'scope' => $scope,
            ],
            $extra
        ));
    }

    // ========== Errors & Exceptions ==========

    /**
     * Log an exception/error.
     */
    public static function error(string $message, \Throwable $e, array $extra = []): void
    {
        Logger::error($message, array_merge(
            self::getBaseContext(),
            [
                'exception' => get_class($e),
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => self::formatTrace($e),
            ],
            $extra
        ));
    }

    /**
     * Log a generic error (without exception).
     */
    public static function errorMessage(string $message, array $extra = []): void
    {
        Logger::error($message, array_merge(
            self::getBaseContext(),
            $extra
        ));
    }

    /**
     * Format stack trace for logging.
     */
    private static function formatTrace(\Throwable $e): string
    {
        $trace = $e->getTraceAsString();
        // Limit trace length for log storage
        if (strlen($trace) > 2000) {
            $trace = substr($trace, 0, 2000) . '... (truncated)';
        }
        return $trace;
    }

    // ========== Validation ==========

    /**
     * Log validation failure.
     */
    public static function validationFailed(string $field, string $reason, $value = null, array $extra = []): void
    {
        $context = array_merge(
            self::getBaseContext(),
            [
                'field' => $field,
                'reason' => $reason,
            ],
            $extra
        );

        // Include value for debugging, but redact sensitive fields
        if ($value !== null && !self::isSensitiveField($field)) {
            $context['value'] = is_string($value) ? substr($value, 0, 100) : $value;
        }

        Logger::debug('Validation failed', $context);
    }

    /**
     * Check if a field name suggests sensitive data.
     */
    private static function isSensitiveField(string $field): bool
    {
        $sensitive = ['password', 'senha', 'token', 'secret', 'key', 'cpf', 'documento'];
        $fieldLower = strtolower($field);
        foreach ($sensitive as $term) {
            if (str_contains($fieldLower, $term)) {
                return true;
            }
        }
        return false;
    }

    // ========== Business Events ==========

    /**
     * Log a business event (significant domain action).
     */
    public static function businessEvent(string $event, array $data = []): void
    {
        Logger::info("Business event: $event", array_merge(
            self::getBaseContext(),
            ['event' => $event],
            $data
        ));
    }

    // ========== External Services ==========

    /**
     * Log an external service call.
     * Returns a callable to log the completion.
     *
     * @return callable Call with (bool $success, ?int $statusCode, array $extra)
     */
    public static function externalCallStart(string $service, string $operation, array $extra = []): callable
    {
        $startTime = microtime(true);

        Logger::debug("External call started: $service", array_merge(
            self::getBaseContext(),
            [
                'service' => $service,
                'operation' => $operation,
            ],
            $extra
        ));

        return function (bool $success, ?int $statusCode = null, array $extra = []) use ($service, $operation, $startTime) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $context = array_merge(
                self::getBaseContext(),
                [
                    'service' => $service,
                    'operation' => $operation,
                    'success' => $success,
                    'status_code' => $statusCode,
                    'duration_ms' => $duration,
                ],
                $extra
            );

            if (!$success) {
                Logger::warning("External call failed: $service", $context);
            } elseif ($duration > self::SLOW_THRESHOLD * 1000) {
                Logger::warning("Slow external call: $service", $context);
            } else {
                Logger::info("External call completed: $service", $context);
            }
        };
    }

    // ========== Performance ==========

    /**
     * Log a slow operation.
     */
    public static function slowOperation(string $operation, float $durationMs, array $extra = []): void
    {
        Logger::warning("Slow operation: $operation", array_merge(
            self::getBaseContext(),
            [
                'operation' => $operation,
                'duration_ms' => $durationMs,
            ],
            $extra
        ));
    }

    /**
     * Time an operation and log if slow.
     * Returns a callable to stop the timer.
     *
     * @return callable Call with () to complete; returns duration in ms
     */
    public static function timeOperation(string $operation): callable
    {
        $startTime = microtime(true);

        return function () use ($operation, $startTime): float {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            if ($duration > self::SLOW_THRESHOLD * 1000) {
                self::slowOperation($operation, $duration);
            }
            return $duration;
        };
    }
}
