<?php
declare(strict_types=1);

namespace Baja\Auth;

use PDO;
use PDOException;

class SessionStore
{
    private const REQUIRED_ENV = [
        'PHPBB_DB_HOST',
        'PHPBB_DB_PORT',
        'PHPBB_DB_NAME',
        'PHPBB_DB_USER',
        'PHPBB_DB_PASS',
        'REDIS_HOST',
        'REDIS_PORT',
        'SESSION_CACHE_TTL_SECONDS',
    ];

    private const CACHE_KEY_PREFIX = 'baja:phpbb:session:';

    // phpBB anonymous user_id; lookups that resolve to this row should be
    // treated as "no logged-in user" rather than populating the session as
    // user "Anonymous".
    private const PHPBB_ANONYMOUS_USER_ID = 1;

    // 24h is a coarse upper bound on session_time freshness. phpBB's actual
    // session_length lives in phpbb_config; we don't query it because the
    // shim only needs a crude liveness check — anything older than this is
    // certainly stale, anything fresher gets handed off to phpBB cookie
    // rotation logic via the _u check in PhpbbSessionShim.
    private const SESSION_MAX_AGE_SECONDS = 86400;

    private array $config;
    private ?PDO $pdo = null;
    private ?\Redis $redis = null;
    private bool $redisDisabled = false;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? self::loadFromEnv();
    }

    /**
     * @return array{user_id:int, username:string}|null
     */
    public function lookupBySessionId(string $sid): ?array
    {
        if ($sid === '') {
            return null;
        }

        $cached = $this->cacheGet($sid);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $stmt = $this->pdo()->prepare(
                'SELECT s.session_user_id, u.username
                 FROM phpbb_sessions s
                 JOIN phpbb_users u ON u.user_id = s.session_user_id
                 WHERE s.session_id = :sid
                   AND s.session_time > UNIX_TIMESTAMP() - :max_age
                 LIMIT 1'
            );
            $stmt->bindValue(':sid', $sid, PDO::PARAM_STR);
            $stmt->bindValue(':max_age', self::SESSION_MAX_AGE_SECONDS, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('SessionStore::lookupBySessionId PDO error: ' . $e->getMessage());
            return null;
        }

        if ($row === false) {
            return null;
        }

        $userId = (int)$row['session_user_id'];
        if ($userId === self::PHPBB_ANONYMOUS_USER_ID) {
            return null;
        }

        $result = ['user_id' => $userId, 'username' => (string)$row['username']];
        $this->cacheSet($sid, $result);
        return $result;
    }

    public function invalidateSessionId(string $sid): void
    {
        if ($sid === '') {
            return;
        }
        $this->cacheDelete($sid);
    }

    /**
     * @return array{user_id:int, username:string, user_password:string}|null
     */
    public function lookupUserByUsername(string $username): ?array
    {
        if ($username === '') {
            return null;
        }
        try {
            $stmt = $this->pdo()->prepare(
                'SELECT user_id, username, user_password
                 FROM phpbb_users
                 WHERE username_clean = :username_clean
                 LIMIT 1'
            );
            // phpBB's username_clean is utf8_clean_string($username) — for
            // ASCII usernames (all the seeded test accounts), this is just
            // strtolower(). The forum stores both `username` (display) and
            // `username_clean` (lookup key); login lookups should use the
            // clean form so case differences don't matter.
            $stmt->bindValue(':username_clean', strtolower($username), PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('SessionStore::lookupUserByUsername PDO error: ' . $e->getMessage());
            return null;
        }

        if ($row === false) {
            return null;
        }
        $userId = (int)$row['user_id'];
        if ($userId === self::PHPBB_ANONYMOUS_USER_ID) {
            return null;
        }
        return [
            'user_id'       => $userId,
            'username'      => (string)$row['username'],
            'user_password' => (string)$row['user_password'],
        ];
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->config['PHPBB_DB_HOST'],
            $this->config['PHPBB_DB_PORT'],
            $this->config['PHPBB_DB_NAME']
        );
        try {
            $this->pdo = new PDO($dsn, $this->config['PHPBB_DB_USER'], $this->config['PHPBB_DB_PASS'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new SessionStoreException(
                'phpBB DB unreachable: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
        return $this->pdo;
    }

    private function redis(): ?\Redis
    {
        if ($this->redisDisabled) {
            return null;
        }
        if ($this->redis !== null) {
            return $this->redis;
        }
        if (!class_exists(\Redis::class)) {
            error_log('SessionStore: phpredis extension not installed; session cache disabled.');
            $this->redisDisabled = true;
            return null;
        }
        $r = new \Redis();
        try {
            $ok = $r->connect(
                $this->config['REDIS_HOST'],
                (int)$this->config['REDIS_PORT'],
                1.0 // 1s connect timeout — auth path is hot, don't block on it.
            );
            if (!$ok) {
                throw new \RuntimeException('connect() returned false');
            }
        } catch (\Throwable $e) {
            error_log('SessionStore: Redis connect failed; cache disabled: ' . $e->getMessage());
            $this->redisDisabled = true;
            return null;
        }
        $this->redis = $r;
        return $this->redis;
    }

    /**
     * @return array{user_id:int, username:string}|null
     */
    private function cacheGet(string $sid): ?array
    {
        $r = $this->redis();
        if ($r === null) {
            return null;
        }
        try {
            $raw = $r->get(self::CACHE_KEY_PREFIX . $sid);
        } catch (\Throwable $e) {
            error_log('SessionStore::cacheGet error: ' . $e->getMessage());
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['user_id'], $decoded['username'])) {
            return null;
        }
        return ['user_id' => (int)$decoded['user_id'], 'username' => (string)$decoded['username']];
    }

    private function cacheSet(string $sid, array $data): void
    {
        $r = $this->redis();
        if ($r === null) {
            return;
        }
        try {
            $r->setex(
                self::CACHE_KEY_PREFIX . $sid,
                (int)$this->config['SESSION_CACHE_TTL_SECONDS'],
                json_encode($data, JSON_THROW_ON_ERROR)
            );
        } catch (\Throwable $e) {
            error_log('SessionStore::cacheSet error: ' . $e->getMessage());
        }
    }

    private function cacheDelete(string $sid): void
    {
        $r = $this->redis();
        if ($r === null) {
            return;
        }
        try {
            $r->del(self::CACHE_KEY_PREFIX . $sid);
        } catch (\Throwable $e) {
            error_log('SessionStore::cacheDelete error: ' . $e->getMessage());
        }
    }

    /**
     * Read environment variables that the session path cannot run without,
     * failing loudly if any is unset or empty.
     *
     * Public so callers outside this class validate session-related variables
     * the same way. Never substitute a default for one of these: a wrong-but-
     * plausible value (a cookie prefix that doesn't match the forum's, a DB
     * name that exists but is empty) produces a session lookup that misses
     * silently and surfaces only as an unexplained login bounce.
     *
     * @return array<string, string>
     */
    public static function requireEnv(string ...$keys): array
    {
        $config = [];
        $missing = [];
        foreach ($keys as $key) {
            $v = getenv($key);
            if ($v === false || $v === '') {
                $missing[] = $key;
                continue;
            }
            $config[$key] = $v;
        }
        if ($missing) {
            throw new SessionStoreException(
                'Missing required environment variables: ' . implode(', ', $missing)
            );
        }
        return $config;
    }

    private static function loadFromEnv(): array
    {
        return self::requireEnv(...self::REQUIRED_ENV);
    }
}
