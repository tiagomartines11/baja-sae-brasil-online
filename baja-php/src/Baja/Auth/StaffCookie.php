<?php
declare(strict_types=1);

namespace Baja\Auth;

use Baja\Model\User;
use Baja\Url;

/**
 * The baja_staff cookie: how a judge's browser skips the results cache.
 *
 * The public results pages are cached by Cloudflare and run without the forum
 * session (docs/results-caching.md). A judge in a resultado's
 * "authorized_users" needs the live page, past the spoilers, on the same URL.
 * This cookie is how Cloudflare and nginx tell the two apart:
 *
 *   - Cloudflare's cache rule skips any request whose cookies contain
 *     "baja_staff=<value>";
 *   - nginx loads the forum session for the results pages only when the
 *     cookie is exactly <value> (STAFF_COOKIE_VALUE in .env).
 *
 * It is NOT a credential. Its only effect is that the page is built for this
 * request instead of coming from the cache. Who sees past spoilers is still
 * decided from the forum session (Baja\Results\ViewerContext). Somebody who
 * copies the cookie without a judge's session gets the public page, uncached.
 *
 * Who gets it: users with judge permissions, i.e. a row here holding "index"
 * (the judge area) or "admin". Team members also log in to the forum, and some
 * have a row for the queue (fila) permissions, but never "index"; they must
 * not get it, or every one of them would skip the cache.
 *
 * When: on every page that loads the forum session (sync(), from
 * src/bootstrap.php). Logging in lands the judge on such a page, so that is
 * where it is first set; a value rotated in .env reaches judges on their next
 * page in the judge area, without logging in again. Logging out clears it
 * (Baja\Session::endSession), and so does any page that finds no judge behind
 * the session.
 */
final class StaffCookie
{
    public const NAME = 'baja_staff';

    /**
     * How long the browser keeps it. phpBB's "remember me" keeps a judge
     * logged in across browser restarts, and the cookie has to last as long,
     * or the judge would get cached pages until they next open the judge area.
     */
    private const LIFETIME_SECONDS = 30 * 24 * 3600;

    public const KEEP = 'keep';
    public const SET = 'set';
    public const CLEAR = 'clear';

    /**
     * The value from .env, or '' when it is unset or not usable.
     *
     * Usable means what `openssl rand -hex 24` makes: letters and digits, long
     * enough not to be guessed. The same value is written into the nginx map
     * and a Cloudflare expression, where anything else could break the syntax.
     */
    public static function secret(): string
    {
        $value = (string)getenv('STAFF_COOKIE_VALUE');
        return preg_match('/^[A-Za-z0-9]{32,}$/', $value) === 1 ? $value : '';
    }

    /** Whether this user has judge permissions. */
    public static function isStaff(?User $user): bool
    {
        return $user !== null && ($user->hasPermission('index') || $user->hasPermission('admin'));
    }

    /**
     * What to do with the cookie on this response. Pure, so it can be tested.
     *
     * @param bool    $isStaff the session's user has judge permissions
     * @param ?string $current the cookie the browser sent, null if none
     * @param string  $secret  secret(); '' means the feature is off
     * @return string KEEP, SET or CLEAR
     */
    public static function decide(bool $isStaff, ?string $current, string $secret): string
    {
        if ($isStaff && $secret !== '') {
            return $current === $secret ? self::KEEP : self::SET;
        }
        return $current === null ? self::KEEP : self::CLEAR;
    }

    /** Set or clear the cookie to match the session's user. Before any output. */
    public static function sync(?User $user): void
    {
        $current = $_COOKIE[self::NAME] ?? null;
        if ($current !== null && !is_string($current)) {
            // baja_staff[]=x arrives as an array: present, and not the value.
            $current = '';
        }
        $action = self::decide(self::isStaff($user), $current, self::secret());
        if ($action === self::SET) {
            self::write(self::secret(), time() + self::LIFETIME_SECONDS);
        } elseif ($action === self::CLEAR) {
            self::clear();
        }
    }

    public static function clear(): void
    {
        self::write('', 1);
    }

    private static function write(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        // The parent domain, so that the resultados host receives it, as with
        // LoginCsrf's cookie. Secure wherever the site is served over https;
        // the dev stack is plain http, where a Secure cookie would be dropped.
        setcookie(self::NAME, $value, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '.' . Url::domain(),
            'secure' => Url::scheme() === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
