<?php
/**
 * Environment Variable Helper
 *
 * Provides a consistent way to access environment variables that works
 * both in VM deployments (with .env file) and containerized deployments
 * (with injected environment variables).
 *
 * @package Baja\Util
 */

namespace Baja\Util;

class Env
{
    /**
     * Get an environment variable value
     *
     * Checks in order:
     * 1. $_ENV (populated by dotenv or container)
     * 2. getenv() (system environment)
     * 3. Default value
     *
     * An empty value counts as unset in both sources, matching .env.example
     * where a blank means "not configured".
     *
     * This was `$_ENV[$key] ?? getenv($key) ?: $default`. Because ?: treats
     * "0" as falsy, any variable legitimately set to "0" reaching the getenv
     * branch returned the default instead — so LOG_STDOUT=0 read as true.
     * The two sources also disagreed on empty values: $_ENV won with "",
     * while getenv fell through to the default.
     *
     * @param string $key Environment variable name
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        $value = getenv($key);

        if ($value !== false && $value !== '') {
            return $value;
        }

        return $default;
    }

    /**
     * Get a required environment variable
     *
     * @param string $key Environment variable name
     * @return string
     * @throws \RuntimeException if variable is not set
     */
    public static function require(string $key): string
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            throw new \RuntimeException(
                "Required environment variable '{$key}' is not set. " .
                "Check your .env file or container configuration."
            );
        }

        return $value;
    }

    /**
     * Get an environment variable as boolean
     *
     * @param string $key Environment variable name
     * @param bool $default Default value
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get an environment variable as integer
     *
     * @param string $key Environment variable name
     * @param int|null $default Default value
     * @return int|null
     */
    public static function getInt(string $key, ?int $default = null): ?int
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Check if an environment variable is set and not empty
     *
     * @param string $key Environment variable name
     * @return bool
     */
    public static function has(string $key): bool
    {
        $value = self::get($key);
        return $value !== null && $value !== '';
    }

    /**
     * Check if running in production environment
     *
     * @return bool
     */
    public static function isProduction(): bool
    {
        return self::get('ENV', 'dev') === 'prod';
    }

    /**
     * Check if running in development environment
     *
     * @return bool
     */
    public static function isDevelopment(): bool
    {
        return self::get('ENV', 'dev') === 'dev';
    }
}
