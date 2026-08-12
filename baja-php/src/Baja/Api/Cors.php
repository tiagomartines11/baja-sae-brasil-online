<?php

namespace Baja\Api;

use Baja\Util\Env;

/**
 * CORS handling utility for API endpoints.
 *
 * Uses an explicit whitelist from the API_CORS_ORIGINS env var.
 * No regex patterns - only exact origin matches are allowed.
 *
 * Usage:
 *   Cors::handle('GET');           // Allow GET only
 *   Cors::handle('GET, POST');     // Allow GET and POST
 *   Cors::handle(['GET', 'POST']); // Same, using array
 */
class Cors
{
    /** @var string[]|null Cached allowed-origin whitelist */
    private static ?array $allowedOrigins = null;

    /**
     * Handle CORS headers, OPTIONS preflight, and method validation.
     *
     * This method handles everything needed for API CORS in one call:
     * 1. Sets CORS headers if origin is allowed
     * 2. Handles OPTIONS preflight requests (returns 200 and exits)
     * 3. Validates request method is in allowed list (returns 405 if not)
     *
     * @param string|array $methods Allowed HTTP methods (e.g., 'GET', 'POST, PUT', or ['GET', 'POST'])
     * @return void This method exits on OPTIONS preflight or invalid method
     */
    public static function handle(string|array $methods = 'GET'): void
    {
        // Normalize methods to array for validation
        if (\is_string($methods)) {
            $methodsArray = array_map('trim', explode(',', $methods));
        } else {
            $methodsArray = $methods;
        }

        // Build methods string for headers (always include OPTIONS)
        $methodsString = implode(', ', $methodsArray);
        if (stripos($methodsString, 'OPTIONS') === false) {
            $methodsString .= ', OPTIONS';
        }

        // Set CORS origin header if origin is allowed.
        // Vary: Origin is required because the response differs per origin —
        // without it a shared cache (Cloudflare sits in front of this) can
        // serve one origin's Access-Control-Allow-Origin header to another.
        header('Vary: Origin');

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && self::isAllowedOrigin($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }

        // Standard headers.
        //
        // X-Request-ID is required, not optional: src/lib/apiClient.ts sets it
        // on every request for trace correlation. Omitting it here makes the
        // preflight reject the actual request, so the whole API fails
        // cross-origin — and because it is a custom header, even GETs are
        // "non-simple" and get preflighted.
        header('Access-Control-Allow-Methods: ' . $methodsString);
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-ID');
        header('Content-Type: application/json; charset=UTF-8');

        // Handle OPTIONS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            // Without this browsers re-preflight after a few seconds (Chromium
            // defaults to 5s), doubling request count on a cross-origin API.
            // 7200 is Chromium's cap; Firefox allows more but honours less.
            header('Access-Control-Max-Age: 7200');
            header('Allow: ' . $methodsString);
            http_response_code(200);
            exit;
        }

        // Validate request method
        if (!\in_array($_SERVER['REQUEST_METHOD'], $methodsArray, true)) {
            self::error(405, 'Método não permitido');
        }
    }

    /**
     * Check if an origin is allowed based on the explicit whitelist.
     *
     * @param string $origin The origin to check (e.g., 'https://bajasaebrasil.net')
     * @return bool True if origin is in the whitelist
     */
    public static function isAllowedOrigin(string $origin): bool
    {
        return \in_array($origin, self::allowedOrigins(), true);
    }

    /**
     * Send a JSON error response.
     *
     * @param int $code HTTP status code
     * @param string $message Error message
     * @param bool $exit Whether to exit after sending (default: true)
     */
    public static function error(int $code, string $message, bool $exit = true): void
    {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $message]);
        if ($exit) {
            exit;
        }
    }

    /**
     * Check if request method matches expected and send 405 if not.
     *
     * @param string|array $expected Expected method(s)
     * @return bool True if method matches
     */
    public static function requireMethod(string|array $expected): bool
    {
        if (is_string($expected)) {
            $expected = [$expected];
        }

        $method = $_SERVER['REQUEST_METHOD'];

        if (!in_array($method, $expected, true)) {
            self::error(405, 'Método não permitido');
            return false;
        }

        return true;
    }

    /**
     * The allowed-origin whitelist, from API_CORS_ORIGINS.
     *
     * The VM read this from src/config/programas.php. That file doesn't exist
     * here: this branch injects all environment-varying values through the
     * compose environment, so the list is a comma-separated env var instead.
     * Deliberately no fallback default — an unset var yields an empty list and
     * no Access-Control-Allow-Origin header, which fails closed.
     *
     * @return string[]
     */
    private static function allowedOrigins(): array
    {
        if (self::$allowedOrigins === null) {
            $raw = (string) Env::get('API_CORS_ORIGINS', '');

            self::$allowedOrigins = array_values(array_filter(
                array_map('trim', explode(',', $raw)),
                static fn (string $origin): bool => $origin !== ''
            ));
        }

        return self::$allowedOrigins;
    }

    /**
     * Clear cached config (useful for testing).
     */
    public static function clearCache(): void
    {
        self::$allowedOrigins = null;
    }
}
