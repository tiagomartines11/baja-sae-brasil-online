<?php
declare(strict_types=1);

namespace Baja\Site;

/**
 * One URL per public results page, so that one cache entry serves everybody.
 *
 * Cloudflare keeps a separate copy of a page for every different query
 * string. A link shared on Facebook comes back as
 * prova.php?id=26BR_GER&fbclid=IwAR..., with a new fbclid for every click,
 * and each one would be a cache miss that reaches the server. So each page
 * lists the parameters it really reads, and a request with anything else, or
 * with them in another order or spelling, is sent (301) to the same URL with
 * only those, in the page's order.
 */
final class CleanUrl
{
    /**
     * The clean URL to redirect to, or null when the request already is it.
     *
     * Pure, so it can be tested: enforce() feeds it the request.
     *
     * @param string   $path        the path the visitor asked for, e.g.
     *                              "/26BR/prova.php" (kept, event prefix included)
     * @param string   $queryString the raw query string, without "?"
     * @param string[] $allowed     the parameters the page reads, in the
     *                              order the clean URL lists them
     */
    public static function target(string $path, string $queryString, array $allowed): ?string
    {
        parse_str($queryString, $given);

        $kept = [];
        foreach ($allowed as $name) {
            // A parameter given as a list (id[]=x) is not one the pages read:
            // they would turn it into "Array". Dropped like any unknown one.
            if (array_key_exists($name, $given) && is_string($given[$name])) {
                $kept[$name] = $given[$name];
            }
        }

        $clean = http_build_query($kept, '', '&', PHP_QUERY_RFC3986);
        if ($clean === $queryString) {
            return null;
        }
        return self::safePath($path) . ($clean === '' ? '' : '?' . $clean);
    }

    /**
     * Redirect to the clean URL if this request is not on it. Call at the
     * very top of a public page, before anything is printed.
     *
     * Only GET and HEAD: those are what caches store. A POST (the overlay
     * pages post their options) is left alone.
     *
     * @param string[] $allowed see target()
     */
    public static function enforce(array $allowed): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $target = self::target($path, (string)($_SERVER['QUERY_STRING'] ?? ''), $allowed);
        if ($target === null) {
            return;
        }

        CachePolicy::sendForRedirect();
        header('Location: ' . $target, true, 301);
        exit;
    }

    /**
     * The path for a Location header, which must stay on this site.
     *
     * The path comes from the visitor. "//evil.example/prova.php" would be
     * read by the browser as another host, and so would "/\evil.example"
     * (browsers treat "\" as "/"). Leading slashes are collapsed to one, and
     * a path with anything but plain URL characters falls back to "/".
     */
    public static function safePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if (preg_match('#^/[A-Za-z0-9_./-]*$#', $path) !== 1) {
            return '/';
        }
        return $path;
    }
}
