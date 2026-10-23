<?php
declare(strict_types=1);
namespace Baja;

/**
 * Builds public-facing URLs for the baja app from env-driven config.
 * Single source of truth for "what's the public URL for X subdomain."
 */
class Url
{
    /**
     * Derived from LISTEN_MODE — the same variable that picks nginx's listen
     * block in baja-infra/nginx/templates/. It is the environment's "does
     * nginx terminate TLS" switch, so the scheme follows from it and cannot
     * disagree with what is actually being served. A separate scheme variable
     * could, and that class of drift is what the nginx templating removed.
     */
    public static function scheme(): string
    {
        return getenv('LISTEN_MODE') === 'listen-tls' ? 'https' : 'http';
    }

    /**
     * The same BAJA_DOMAIN the nginx templates render every Baja server_name
     * from, so the links this class builds always point at hostnames nginx is
     * actually configured to answer on.
     */
    public static function domain(): string
    {
        return getenv('BAJA_DOMAIN') ?: 'baja.local';
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