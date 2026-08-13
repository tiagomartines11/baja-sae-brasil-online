<?php
declare(strict_types=1);
namespace Baja;

/**
 * Builds public-facing URLs for the baja app from env-driven config.
 * Single source of truth for "what's the public URL for X subdomain."
 */
class Url
{
    public static function scheme(): string
    {
        return getenv('BAJA_PUBLIC_SCHEME') ?: 'http';
    }

    public static function domain(): string
    {
        return getenv('BAJA_PUBLIC_DOMAIN') ?: 'baja.local';
    }

    /**
     * Build a URL on a specific subdomain.
     *   subdomainUrl('certificado', '/c/22BR/123')
     *   → "http://certificado.baja.local/c/22BR/123" in dev
     *   → "https://certificado.bajasaebrasil.net/c/22BR/123" in prod
     */
    public static function subdomain(string $sub, string $path = '/'): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return self::scheme() . '://' . $sub . '.' . self::domain() . $path;
    }

    /**
     * Build a URL on the apex (root) domain.
     *   apex('/about')
     *   → "http://baja.local/about" in dev
     *   → "https://bajasaebrasil.net/about" in prod
     */
    public static function apex(string $path = '/'): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return self::scheme() . '://' . self::domain() . $path;
    }

    /**
     * Convenience: the forum URL (forum.{domain}).
     */
    public static function forum(string $path = '/'): string
    {
        return self::subdomain('forum', $path);
    }
}