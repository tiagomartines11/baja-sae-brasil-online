<?php
declare(strict_types=1);

namespace Baja\Auth;

class PhpbbSessionShim
{
    /** @var array{username:string, user_id:int} */
    public array $data = ['username' => '', 'user_id' => 0];

    public function __construct(
        private SessionStore $store,
        private string $cookiePrefix
    ) {}

    public function session_begin(): void
    {
        $sid = (string)($_COOKIE[$this->cookiePrefix . '_sid'] ?? '');
        $u   = (string)($_COOKIE[$this->cookiePrefix . '_u']   ?? '');

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
    }

    public function session_kill(): void
    {
        $sid = (string)($_COOKIE[$this->cookiePrefix . '_sid'] ?? '');
        if ($sid !== '') {
            $this->store->invalidateSessionId($sid);
        }
        $this->data = ['username' => '', 'user_id' => 0];
        // TODO: if baja's own logout path needs to clear the phpBB cookies
        // client-side (independent of phpBB UI logout), call setcookie() for
        // {prefix}_sid / _u / _k here. cookie_domain must match
        // phpbb_config.cookie_domain (.baja.local in dev).
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
