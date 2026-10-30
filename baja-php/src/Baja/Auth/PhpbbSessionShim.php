<?php
declare(strict_types=1);

namespace Baja\Auth;

/**
 * The forum login, as the baja app sees it. phpBB keeps a login in cookies on
 * the parent domain ({prefix} is PHPBB_COOKIE_PREFIX, phpbb3_baja in dev):
 *
 *   {prefix}_sid  the session: a row in phpbb_sessions, which expires after
 *                 session_length (phpbb_config) without activity;
 *   {prefix}_u    the user id; 1 means anonymous;
 *   {prefix}_k    the "remember me" key: a row in phpbb_sessions_keys. Only
 *                 set when the user ticked "remember me", and empty otherwise.
 *
 * Every forum page starts with phpBB's session_begin(), which turns a valid
 * _k into a new session when the _sid one has expired, so the forum keeps the
 * user logged in. This shim only reads phpbb_sessions (the app's MySQL user
 * may SELECT and nothing else), so it cannot do that itself. What it can do
 * is say when the browser should make a round trip to the forum to have
 * phpBB do it (decideResume), and when the forum should hear about activity
 * here so the session does not expire under a judge who never opens a forum
 * page (keepAliveDue). Baja\Auth\ForumResume acts on both.
 */
class PhpbbSessionShim
{
    /** decideResume(): send the browser to the forum's /baja/resume. */
    public const RESUME = 'resume';
    /** decideResume(): no way to resume, show the login form as before. */
    public const LOGIN_FORM = 'login';

    /**
     * Juiz pages ask the forum to refresh the session row once it is this
     * old. phpBB itself refreshes it at most once a minute, so anything under
     * 60 would only cost requests; 5 minutes keeps a session_length of an
     * hour (the dev seed's, and phpBB's default) at least 55 minutes from
     * expiring for a judge who is using the judge area, while costing at most
     * one forum request per judge per 5 minutes of activity. A cached session
     * entry can carry a session_time up to SESSION_CACHE_TTL_SECONDS (10
     * minutes) old, which only makes the request come sooner, never later.
     */
    public const KEEPALIVE_AFTER_SECONDS = 300;

    /** @var array{username:string, user_id:int} */
    public array $data = ['username' => '', 'user_id' => 0];

    /** The session row's session_time; null without a session, or when unknown. */
    private ?int $sessionTime = null;

    public function __construct(
        private SessionStore $store,
        private string $cookiePrefix
    ) {}

    public function session_begin(): void
    {
        $sid = $this->cookie('_sid');
        $u   = $this->cookie('_u');

        // phpBB rotates _u to '1' (anonymous user_id) on forum logout while
        // leaving _sid populated. Treating _u=1 as anonymous closes the
        // ghost-session window where baja still shows a logged-in user for
        // up to SESSION_CACHE_TTL_SECONDS after a forum logout.
        if ($u === '' || $u === '1') {
            if ($sid !== '') {
                // Drop any cached entry so a subsequent re-login is seen
                // immediately rather than masked by a stale anonymous cache hit.
                $this->store->invalidateSessionId($sid);
            }
            return;
        }

        if ($sid === '') {
            return;
        }

        $row = $this->store->lookupBySessionId($sid);
        if ($row === null) {
            return;
        }
        $this->data['username'] = $row['username'];
        $this->data['user_id']  = $row['user_id'];
        $this->sessionTime      = $row['session_time'] ?? null;
    }

    /**
     * What to do for a page that needs a login and found none. Pure, so it can
     * be tested.
     *
     * Resume only when phpBB could: it needs a non-empty _k and the _u of the
     * user that key belongs to (phpBB reads the key only together with _u, and
     * _u=1 is the anonymous user, what logging out leaves behind).
     *
     * Only for a page the browser is navigating to: a POST would lose its body
     * on the way through two redirects, and a script's fetch cannot follow a
     * redirect to the forum (another origin, without CORS). Both keep getting
     * the login page, as before.
     *
     * $alreadyTried is the loop guard: the browser was sent to the forum a
     * moment ago and came back still without a session (ForumResume explains
     * when that happens). Then the login form, not another round trip.
     *
     * @param bool   $loggedIn     the shim found a session (data['username'] set)
     * @param string $u            the {prefix}_u cookie, '' if none
     * @param string $k            the {prefix}_k cookie, '' if none
     * @param bool   $alreadyTried the loop-guard cookie is present
     * @param bool   $isNavigation a GET for a page, not a POST or a script's request
     * @return string RESUME or LOGIN_FORM
     */
    public static function decideResume(bool $loggedIn, string $u, string $k, bool $alreadyTried, bool $isNavigation): string
    {
        if ($loggedIn || $alreadyTried || !$isNavigation) {
            return self::LOGIN_FORM;
        }
        if ($k === '' || $u === '' || $u === '1' || !ctype_digit($u)) {
            return self::LOGIN_FORM;
        }
        return self::RESUME;
    }

    /**
     * Whether this page should ask the forum to refresh the session row. Pure.
     *
     * @param ?int $sessionTime the row's session_time; null without a session
     *                          or when it is not known, and then never
     * @param int  $now         time()
     */
    public static function keepAliveDue(?int $sessionTime, int $now): bool
    {
        return $sessionTime !== null && $now - $sessionTime >= self::KEEPALIVE_AFTER_SECONDS;
    }

    /** decideResume() for this request. */
    public function resumeDecision(bool $alreadyTried, bool $isNavigation): string
    {
        return self::decideResume(
            $this->data['username'] !== '',
            $this->cookie('_u'),
            $this->cookie('_k'),
            $alreadyTried,
            $isNavigation
        );
    }

    /** keepAliveDue() for this request's session. */
    public function keepAliveNeeded(int $now): bool
    {
        return self::keepAliveDue($this->sessionTime, $now);
    }

    /**
     * Forget the cached copy of this session, so the next page reads the row
     * phpBB is about to refresh instead of asking again for the next ten
     * minutes with the old session_time.
     */
    public function forgetCachedSession(): void
    {
        $sid = $this->cookie('_sid');
        if ($sid !== '') {
            $this->store->invalidateSessionId($sid);
        }
    }

    public function session_kill(): void
    {
        $sid = $this->cookie('_sid');
        if ($sid !== '') {
            $this->store->invalidateSessionId($sid);
        }
        $this->data = ['username' => '', 'user_id' => 0];
        $this->sessionTime = null;
        // TODO: if baja's own logout path needs to clear the phpBB cookies
        // client-side (independent of phpBB UI logout), call setcookie() for
        // {prefix}_sid / _u / _k here. cookie_domain must match
        // phpbb_config.cookie_domain (.baja.local in dev).
    }

    /** One of phpBB's cookies; '' when absent or not a string (name[]=x). */
    private function cookie(string $suffix): string
    {
        $value = $_COOKIE[$this->cookiePrefix . $suffix] ?? '';
        return is_string($value) ? $value : '';
    }

    public function __call(string $name, array $args)
    {
        throw new \BadMethodCallException(
            "PhpbbSessionShim does not implement '{$name}'. " .
            "The phpBB-coupling analysis indicated baja-app uses only " .
            "session_begin / session_kill / data['username']. If a new " .
            "dependency was introduced, extend the shim."
        );
    }
}
