<?php
declare(strict_types=1);

namespace Baja\Tests\Support;

use PDO;
use PDOException;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;

/**
 * Connects a test to the real dev database, for tests that WRITE.
 *
 * Writing is only allowed when BAJA_TEST_DB=1 is set, and the tests are
 * skipped otherwise, so that nobody puts test rows into a database by
 * accident. Never set it against production.
 *
 * It connects as PHPBB_DB_USER (the app's own account, as in the dev
 * container), or as BAJA_TEST_DB_USER / BAJA_TEST_DB_PASS when those are
 * set: CI sets them, because its usual account may only read.
 *
 * Usage, in setUpBeforeClass():
 *
 *     self::$skipped = WritableTestDatabase::whyUnavailable();
 *
 * and in setUp(): skip when that is not null. After that,
 * WritableTestDatabase::propel() is the app's own Propel connection and
 * WritableTestDatabase::separatePdo() a second, independent one.
 */
final class WritableTestDatabase
{
    private static ?string $whyUnavailable = null;
    private static bool $checked = false;

    /** Null when the database can be used; otherwise the reason to skip. */
    public static function whyUnavailable(): ?string
    {
        if (self::$checked) {
            return self::$whyUnavailable;
        }
        self::$checked = true;

        $host = getenv('PHPBB_DB_HOST');
        if (getenv('BAJA_TEST_DB') !== '1' || $host === false || $host === '') {
            return self::$whyUnavailable = 'Writes to the database: set BAJA_TEST_DB=1 and the database variables (see tests/Support/WritableTestDatabase.php).';
        }
        if (getenv('BAJA_TEST_DB_USER')) {
            putenv('PHPBB_DB_USER=' . getenv('BAJA_TEST_DB_USER'));
            putenv('PHPBB_DB_PASS=' . getenv('BAJA_TEST_DB_PASS'));
        }
        try {
            self::separatePdo();
        } catch (PDOException $e) {
            return self::$whyUnavailable = 'No database: ' . $e->getMessage();
        }
        require_once dirname(__DIR__, 2) . '/src/config.php';
        // Propel hands back the same object for the same row for as long as
        // the process lives. A test that changes rows with SQL must see them
        // as the next web request would, so every load reads the database.
        Propel::disableInstancePooling();
        return null;
    }

    /** The connection the application code uses. */
    public static function propel(): ConnectionInterface
    {
        return Propel::getWriteConnection('resultados');
    }

    /**
     * A new connection of its own: what it creates for its session only
     * (a TEMPORARY table, say) is invisible to the application's.
     */
    public static function separatePdo(): PDO
    {
        return new PDO(
            'mysql:host=' . getenv('PHPBB_DB_HOST') . ';dbname=baja_resultados;charset=utf8mb4',
            (string) getenv('PHPBB_DB_USER'),
            (string) getenv('PHPBB_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
