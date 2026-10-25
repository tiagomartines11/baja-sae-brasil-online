<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

use Baja\Contagem\Service\PublicFeed;
use Baja\Model\Evento;

/**
 * How long a response of the public results API may be reused, in seconds.
 * The one place these numbers live; docs/results-api.md, "Caching", lists
 * them for consumers.
 *
 * Every successful response is "public" with the same max-age (a browser)
 * and s-maxage (Cloudflare). The audience of a broadcast can be thousands of
 * viewers polling the same URL; with s-maxage = 5 this server sees one
 * request per URL every five seconds, however many there are.
 *
 *   live race        PublicFeed::CACHE_SECONDS (2): the race clock and the
 *                    laps move every few seconds, and the feed behind it is
 *                    itself only rebuilt every 2 s
 *   event running    5: results, brackets and the event change when a judge
 *                    saves, and a few seconds late is what the public pages
 *                    already are
 *   event finished   300: nothing changes any more, except a late
 *                    correction, which then shows within five minutes
 *
 * Errors are never cached (forError()). A cached 400 or 404 could be served
 * for a URL that works: Cloudflare's cache key leaves out unknown parameters
 * (see the cache rule in docs/results-api.md), so "?evt=26BR&junk=1" and
 * "?evt=26BR" share a key, and caching the first one's 400 would break the
 * second.
 */
final class CachePolicy
{
    public const EVENT_RUNNING_SECONDS = 5;
    public const EVENT_FINISHED_SECONDS = 300;

    /** For data that changes when judges save: everything but the live race. */
    public static function forEvent(Evento $evento): int
    {
        return $evento->getFinalizado() ? self::EVENT_FINISHED_SECONDS : self::EVENT_RUNNING_SECONDS;
    }

    /** For the live race (corrida.php). A finished event's race no longer moves. */
    public static function forRace(Evento $evento): int
    {
        return $evento->getFinalizado() ? self::EVENT_FINISHED_SECONDS : PublicFeed::CACHE_SECONDS;
    }

    public static function header(int $seconds): string
    {
        return 'Cache-Control: public, max-age=' . $seconds . ', s-maxage=' . $seconds;
    }

    public static function forError(): string
    {
        return 'Cache-Control: no-store';
    }
}
