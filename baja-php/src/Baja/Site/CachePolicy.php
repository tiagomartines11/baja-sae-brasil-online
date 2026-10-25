<?php
declare(strict_types=1);

namespace Baja\Site;

use Baja\Model\Evento;

/**
 * The Cache-Control header of every public results page, and the only place
 * that sends a public one. See docs/results-caching.md.
 *
 * A results page runs one of two ways, chosen by nginx from the baja_staff
 * cookie (baja-app-resultados.conf.template):
 *
 *   public path   SKIP_AUTH=1, no forum session. Everybody gets the same
 *                 page, so it may be cached, by the browser and by
 *                 Cloudflare. How long depends on the event (below).
 *
 *   session path  the forum session is loaded, and the page may differ per
 *                 user (a judge sees past spoilers). Never cached anywhere:
 *                 "private, no-store".
 *
 * max-age is for the browser, s-maxage for Cloudflare. The numbers are
 * starting points, to be tuned by watching an event.
 */
final class CachePolicy
{
    /**
     * While an event is on (or not finished), results change every few
     * minutes and people refresh to see them. A visitor sees a result at
     * most BROWSER + EDGE seconds late; at event peaks that is still one
     * request to the server per page every EDGE seconds, whatever the
     * number of visitors.
     */
    public const LIVE_BROWSER_SECONDS = 10;
    public const LIVE_EDGE_SECONDS = 15;

    /**
     * A finished event's results only change when someone corrects one.
     * A correction then takes up to an hour to show, unless the Cloudflare
     * cache is purged (docs/results-caching.md).
     */
    public const FINISHED_BROWSER_SECONDS = 300;
    public const FINISHED_EDGE_SECONDS = 3600;

    /** For every response that was produced with a forum session. */
    public const PRIVATE = 'private, no-store';

    /**
     * The header for the public path.
     *
     * Only an event that is finished and not in progress gets the long
     * cache. Anything else, including both flags on at once (a mistake in
     * the event's settings), gets the short one: showing a result late is
     * worse than serving a few more requests.
     */
    public static function publicHeader(bool $inProgress, bool $finished): string
    {
        if ($finished && !$inProgress) {
            return self::header(self::FINISHED_BROWSER_SECONDS, self::FINISHED_EDGE_SECONDS);
        }
        return self::header(self::LIVE_BROWSER_SECONDS, self::LIVE_EDGE_SECONDS);
    }

    /** The public header for this event; no event (unknown id) is treated as live. */
    public static function publicHeaderFor(?Evento $evento): string
    {
        if ($evento === null) {
            return self::publicHeader(true, false);
        }
        return self::publicHeader((bool)$evento->getEmAndamento(), (bool)$evento->getFinalizado());
    }

    /**
     * The header for a redirect to a page's clean URL (Baja\Site\CleanUrl).
     * Where a messy URL leads never changes, so it gets the long cache.
     */
    public static function redirectHeader(): string
    {
        return self::publicHeader(false, true);
    }

    /**
     * Whether this request was given the forum session.
     *
     * Two checks, either of which is enough: the flag nginx passes, read
     * exactly as src/bootstrap.php reads it to decide whether to load the
     * session, and whether the session was in fact loaded ($user is set by
     * src/phpbb_login.php). So if those two ever disagree, the answer is
     * "session", and nothing personal can end up in a public cache.
     */
    public static function hasSession(): bool
    {
        return !getenv('SKIP_AUTH') || isset($GLOBALS['user']);
    }

    /**
     * Send the header of a public results page: the public one for this
     * event on the public path, "private, no-store" on the session path.
     */
    public static function send(?Evento $evento): void
    {
        self::sendPublicUnlessSession(self::publicHeaderFor($evento));
    }

    /** Like send(), for a redirect to a clean URL. */
    public static function sendForRedirect(): void
    {
        self::sendPublicUnlessSession(self::redirectHeader());
    }

    /** For pages that are only for judges: never cached. */
    public static function sendPrivate(): void
    {
        header('Cache-Control: ' . self::PRIVATE);
    }

    private static function sendPublicUnlessSession(string $public): void
    {
        header('Cache-Control: ' . (self::hasSession() ? self::PRIVATE : $public));
    }

    private static function header(int $browserSeconds, int $edgeSeconds): string
    {
        return "public, max-age={$browserSeconds}, s-maxage={$edgeSeconds}";
    }
}
