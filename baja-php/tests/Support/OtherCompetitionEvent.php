<?php
declare(strict_types=1);

namespace Baja\Tests\Support;

use PDO;
use PDOException;

/**
 * Event 97AR (other-competition-event.sql next to this file): an event
 * whose code ends in neither BR, SE, SU nor NE, for the tests that run the
 * command-line tools on one (BAJAAPP-74).
 *
 * Loading it WRITES, so, like the other test events, it is only loaded when
 * BAJA_TEST_DB=1; never set that against production. It is loaded as
 * PHPBB_DB_USER, or as BAJA_TEST_DB_USER / BAJA_TEST_DB_PASS when set (CI
 * sets MySQL's root, so that the tools themselves still run as an account
 * that may only SELECT).
 */
final class OtherCompetitionEvent
{
    public const EVENT = '97AR';

    /**
     * Loads the event, after removing any left over from an interrupted run.
     *
     * @return PDO|string the connection to remove it with later, or why the test must be skipped
     */
    public static function load(): PDO|string
    {
        $host = getenv('PHPBB_DB_HOST');
        if (getenv('BAJA_TEST_DB') !== '1' || $host === false || $host === '') {
            return 'Writes a test event: set BAJA_TEST_DB=1 and the database variables (see ' . self::class . ').';
        }
        try {
            $database = new PDO(
                'mysql:host=' . $host . ';dbname=baja_resultados;charset=utf8mb4',
                (string)(getenv('BAJA_TEST_DB_USER') ?: getenv('PHPBB_DB_USER')),
                (string)(getenv('BAJA_TEST_DB_USER') ? getenv('BAJA_TEST_DB_PASS') : getenv('PHPBB_DB_PASS')),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            return 'No database: ' . $e->getMessage();
        }

        self::remove($database);
        // One statement at a time, so that an error in any of them stops here.
        foreach (preg_split('/;\s*$/m', (string)file_get_contents(__DIR__ . '/other-competition-event.sql')) as $statement) {
            if (trim(preg_replace('/^--.*$/m', '', $statement)) !== '') {
                $database->exec($statement);
            }
        }
        return $database;
    }

    public static function remove(PDO $database): void
    {
        // Every other row hangs off the event, ON DELETE CASCADE.
        $database->prepare('DELETE FROM evento WHERE evento_id = ?')->execute([self::EVENT]);
    }

    /**
     * Runs one of baja-php/scripts/ with these arguments.
     *
     * @param list<string> $arguments
     * @return array{exit: int, output: string}
     */
    public static function runScript(string $script, array $arguments): array
    {
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/' . $script)
            . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1',
            $output,
            $exitCode
        );
        return ['exit' => $exitCode, 'output' => implode("\n", $output)];
    }
}
