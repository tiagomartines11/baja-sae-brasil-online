<?php
/**
 * Certificate lookup test suite.
 *
 *   docker compose exec --user "$(id -u):$(id -g)" baja-app \
 *       php tests/certificado/run.php
 *
 * Tests that need a database are skipped unless BAJA_TEST_DB=1 is set, and
 * they write synthetic participants into whatever database Propel is
 * configured for. Never set that variable against production: the fixtures
 * insert and delete rows in `participantes`.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/lib.php';

if (test_db_available()) {
    require_once __DIR__ . '/../../src/config.php';
}

$tests = glob(__DIR__ . '/*_test.php');
sort($tests);

foreach ($tests as $test) {
    printf("\n%s\n", basename($test, '_test.php'));
    require $test;
}

echo "\n";
exit(T::report());
