<?php
declare(strict_types=1);

namespace Baja\Tests\FormulaCompare\Db;

use Baja\Tests\Support\OtherCompetitionEvent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * formula-engine-compare.php takes an event of any competition (BAJAAPP-74).
 *
 * An event code is two digits for the year and two capitals or digits for
 * the competition: 26BR, 25AR, 24H2. Every tool now checks codes with the
 * same rule, Baja\Evento\EventCode, so they cannot disagree about which
 * events exist. This runs the real script on 97AR, a made-up event of
 * "another competition" (tests/Support/other-competition-event.sql), and on
 * a code that is well formed but no event.
 *
 * It WRITES the event before the run and deletes it after (the tool itself
 * only reads), so it runs only when BAJA_TEST_DB=1:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite formula-compare-db
 */
final class OtherCompetitionTest extends TestCase
{
    private static PDO|string $database = 'not loaded';

    public static function setUpBeforeClass(): void
    {
        self::$database = OtherCompetitionEvent::load();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$database instanceof PDO) {
            OtherCompetitionEvent::remove(self::$database);
        }
    }

    protected function setUp(): void
    {
        if (is_string(self::$database)) {
            $this->markTestSkipped(self::$database);
        }
    }

    /** Its one formula is ordinary, so both engines agree on it: exit code 0. */
    public function testAnEventOfAnotherCompetitionIsCompared(): void
    {
        $run = OtherCompetitionEvent::runScript('formula-engine-compare.php', ['--events', OtherCompetitionEvent::EVENT, '--now', '1800000000']);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertStringContainsString('1 event, 1 prova, 1 resultado', $run['output']);
    }

    public function testAWellFormedCodeOfNoEventIsNoSuchEvent(): void
    {
        $run = OtherCompetitionEvent::runScript('formula-engine-compare.php', ['--events', '97ZZ']);

        $this->assertSame(2, $run['exit'], $run['output']);
        $this->assertStringContainsString('No such event: 97ZZ', $run['output']);
    }
}
