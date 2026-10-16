<?php
declare(strict_types=1);

/**
 * Loaded once by PHPUnit before any lap counting test (see phpunit.xml.dist).
 *
 * It only makes classes loadable. It does NOT connect to the database: the
 * engine tests are pure and must run on a machine that has no database at
 * all (CI is one). The tests that do need one extend
 * Baja\Tests\Contagem\Db\DbTestCase, which connects on first use and skips
 * itself unless BAJA_TEST_DB=1.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// The application sets this in src/bootstrap.php. Dates only appear in what
// people read, but a test that prints one should print what the site would.
date_default_timezone_set('America/Sao_Paulo');
