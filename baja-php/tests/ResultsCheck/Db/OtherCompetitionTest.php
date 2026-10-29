<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsCheck\Db;

use Baja\Tests\Support\OtherCompetitionEvent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * check-results-configs.php takes an event of any competition (BAJAAPP-74).
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
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite results-check-db
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

    public function testAnEventOfAnotherCompetitionIsChecked(): void
    {
        $run = OtherCompetitionEvent::runScript('check-results-configs.php', ['--events', OtherCompetitionEvent::EVENT]);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertStringContainsString('Checked 1 events: 1 resultados', $run['output']);
    }

    /** Lowercase is accepted on this tool's command line: it is read as capitals. */
    public function testTheCodeMayBeTypedInLowercase(): void
    {
        $run = OtherCompetitionEvent::runScript('check-results-configs.php', ['--events', strtolower(OtherCompetitionEvent::EVENT)]);

        $this->assertSame(0, $run['exit'], $run['output']);
    }

    public function testAWellFormedCodeOfNoEventIsNoSuchEvent(): void
    {
        $run = OtherCompetitionEvent::runScript('check-results-configs.php', ['--events', '97ZZ']);

        $this->assertSame(2, $run['exit'], $run['output']);
        $this->assertStringContainsString('No such event: 97ZZ', $run['output']);
    }
}
