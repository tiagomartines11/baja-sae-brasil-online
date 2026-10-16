<?php
/**
 * Plays "another request, running at the same time" for a database test.
 *
 * A PHPUnit test is one PHP process doing one thing at a time, so it cannot
 * by itself be interrupted half way by somebody else. This script is started
 * as a SECOND process by DbTestCase::startAnotherRequest(). It opens its own
 * database connection and carries out a list of steps, given as JSON in the
 * first argument:
 *
 *   ["sql", "UPDATE ...", [params]]   run a statement
 *   ["sleep", 0.8]                    wait so many seconds
 *
 * Locks taken with GET_LOCK are released when the script ends.
 */
$steps = json_decode($argv[1] ?? '[]', true);

$connection = new PDO(
    'mysql:host=' . getenv('PHPBB_DB_HOST') . ';dbname=baja_resultados',
    (string) getenv('PHPBB_DB_USER'),
    (string) getenv('PHPBB_DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

foreach ($steps as $step) {
    if ($step[0] === 'sleep') {
        usleep((int) round($step[1] * 1000000));
        continue;
    }
    $statement = $connection->prepare($step[1]);
    $statement->execute($step[2] ?? []);
    $statement->closeCursor();
}
