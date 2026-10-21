<?php
declare(strict_types=1);

namespace Baja\Auth;

use PDO;
use PDOException;

/**
 * Staff email addresses, read out of the forum.
 *
 * This application has no email column of its own. `baja_resultados`.`user`
 * holds a username and a permission list and nothing else, and the forum is
 * where an address is already kept current — somebody who changes their email
 * changes it there, and a second copy here would be a second copy to go
 * stale.
 *
 * No new grant is needed: the `resultados` MySQL account already has SELECT
 * on the whole of `phpbb_baja` for the session shim (see
 * baja-infra/mysql/init/02-create-users.sh). Read-only, which is the whole of
 * this class's access.
 *
 * Separate from SessionStore rather than a method on it. SessionStore is on
 * the request path of every authenticated page and its constructor demands
 * the Redis and session-cache variables; this runs once, on a form
 * submission, and needs four database variables. Reusing its connection would
 * mean a certificate report failing because a session cache variable is
 * unset.
 */
final class PhpbbEmails
{
    private const REQUIRED_ENV = [
        'PHPBB_DB_HOST',
        'PHPBB_DB_PORT',
        'PHPBB_DB_NAME',
        'PHPBB_DB_USER',
        'PHPBB_DB_PASS',
    ];

    /**
     * phpBB user types. 1 is an account that never activated and 2 is a bot;
     * neither is a person who will read a notification.
     */
    private const TIPOS_IGNORADOS = [1, 2];

    /** Null when the environment is incomplete — see the constructor. */
    private ?array $config;
    private ?PDO $pdo = null;

    /**
     * Missing configuration disables this class rather than throwing.
     *
     * SessionStore fails loudly on the same variables, and should: a session
     * lookup that misses silently surfaces as an unexplained login bounce.
     * The consequence here is different. This runs after a participant's
     * report has been written down, and an exception at that point would turn
     * a saved report into a 500 — the person retries, and the queue fills
     * with duplicates of a report that was already safe. The report is the
     * thing that matters; the notification is best-effort, and the env
     * fallback list in Destinatarios exists for exactly this.
     */
    public function __construct(?array $config = null)
    {
        if ($config !== null) {
            $this->config = $config;

            return;
        }

        try {
            $this->config = SessionStore::requireEnv(...self::REQUIRED_ENV);
        } catch (SessionStoreException $e) {
            error_log('PhpbbEmails: disabled, ' . $e->getMessage());
            $this->config = null;
        }
    }

    /**
     * Addresses for the usernames given, lowercased-username keyed.
     *
     * Matched on `username_clean`, the same column the session shim matches
     * on, because `username` preserves the display case and the two systems
     * were never guaranteed to agree on it.
     *
     * A username with no forum row, or with no address, is simply absent from
     * the result. The caller decides what that means — for the report
     * notification it means one fewer recipient, never a failed send.
     *
     * @param array<int, string> $usernames
     *
     * @return array<string, string> username_clean => email
     */
    public function porUsername(array $usernames): array
    {
        $limpos = [];
        foreach ($usernames as $username) {
            $limpo = strtolower(trim($username));
            if ($limpo !== '') {
                $limpos[$limpo] = true;
            }
        }

        if ($limpos === [] || $this->config === null) {
            return [];
        }

        $chaves = array_keys($limpos);
        // Named placeholders rather than interpolation. The list is small
        // (people with the `certificados` permission) so one statement is
        // enough; there is no chunking to get wrong.
        $marcadores = implode(',', array_fill(0, count($chaves), '?'));

        try {
            $stmt = $this->pdo()->prepare(
                'SELECT username_clean, user_email
                   FROM phpbb_users
                  WHERE username_clean IN (' . $marcadores . ')
                    AND user_type NOT IN (' . implode(',', self::TIPOS_IGNORADOS) . ")
                    AND user_email <> ''"
            );
            $stmt->execute($chaves);
            $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException|SessionStoreException $e) {
            error_log('PhpbbEmails: lookup failed: ' . $e->getMessage());

            return [];
        }

        $saida = [];
        foreach ($linhas as $linha) {
            $saida[(string) $linha['username_clean']] = (string) $linha['user_email'];
        }

        return $saida;
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
                (int) $e->getCode(),
                $e
            );
        }

        return $this->pdo;
    }
}
