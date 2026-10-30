<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsSnapshot\Db;

use Baja\Tests\Support\OtherCompetitionEvent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * results-snapshot.php takes an event of any competition (BAJAAPP-74).
 *
 * BACKGROUND
 *
 * An event code is two digits for the year and two capitals or digits for
 * the competition: 26BR, 25AR, 24H2. The tool once accepted only BR, SE, SU
 * and NE after the year, so `--events 25AR` stopped with "Not an event
 * code" and the nightly archive of such an event, which runs this tool,
 * failed every night. The rule now lives in one place, Baja\Evento\
 * EventCode, and does not list the letters.
 *
 * WHAT IS TESTED
 *
 * The real script, on 97AR, a made-up event of "another competition"
 * (tests/Support/other-competition-event.sql), written into a temporary
 * folder as the nightly archive does. And a code that is well formed but no
 * event, which must say "No such event" rather than build nothing.
 *
 * HOW TO RUN
 *
 * This test WRITES the event before the run and deletes it after (the tool
 * itself only reads), so it runs only when BAJA_TEST_DB=1. In the dev
 * container:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite results-snapshot-db
 */
final class OtherCompetitionTest extends TestCase
{
    private static PDO|string $database = 'not loaded';

    private string $folder;

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
        $this->folder = sys_get_temp_dir() . '/results-snapshot-other-competition-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->folder));
    }

    public function testAnEventOfAnotherCompetitionIsSnapshotted(): void
    {
        $run = OtherCompetitionEvent::runScript('results-snapshot.php', ['--update', '--dir', $this->folder, '--events', OtherCompetitionEvent::EVENT]);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertFileExists($this->folder . '/97AR/97AR_REL.json', 'The results page was snapshotted...');
        $this->assertStringContainsString('EQUIPE TESTE 2', (string)file_get_contents($this->folder . '/97AR/97AR_REL.json'), '...with its teams in it.');
        $this->assertFileExists($this->folder . '/97AR/formulas.REL.json', 'And the prova\'s formulas.');
    }

    public function testAWellFormedCodeOfNoEventIsNoSuchEvent(): void
    {
        $run = OtherCompetitionEvent::runScript('results-snapshot.php', ['--update', '--dir', $this->folder, '--events', '97ZZ']);

        $this->assertSame(2, $run['exit'], $run['output']);
        $this->assertStringContainsString('No such event: 97ZZ', $run['output']);
        $this->assertDirectoryDoesNotExist($this->folder, 'Nothing was written.');
    }
}
