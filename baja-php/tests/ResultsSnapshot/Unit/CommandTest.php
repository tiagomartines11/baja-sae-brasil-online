<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsSnapshot\Unit;

use Baja\ResultsSnapshot\Command;
use Baja\ResultsSnapshot\Files\SnapshotFiles;
use Baja\ResultsSnapshot\SnapshotSource;
use PHPUnit\Framework\TestCase;

/**
 * --check and --update, end to end, without a database.
 *
 * HOW
 *
 * Command does not care where fresh snapshots come from: it asks a
 * SnapshotSource. The real one runs the pages; here a fake one hands back
 * two fixed documents. That leaves exactly what these tests are about: what
 * is compared with what, what gets printed, what exit code comes out, and
 * what ends up on disk.
 *
 * WHY THIS MATTERS
 *
 * A check that passes no matter what is worse than no check, because people
 * trust it. The first two tests are the important ones: an untouched folder
 * passes, a folder with one value changed fails, and says where.
 */
final class CommandTest extends TestCase
{
    private string $folder;

    /** @var list<string> everything Command printed */
    private array $printed = [];

    /** @var list<list<string>|null> the events each build was asked for */
    private array $builds = [];

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/results-snapshot-command-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->folder));
    }

    public function testCheckPassesOnAnUntouchedSnapshotFolder(): void
    {
        $this->assertSame(Command::OK, $this->runCommand('--update'));
        $this->assertSame(Command::OK, $this->runCommand('--check'), 'Nothing changed between --update and --check.');
        $this->assertStringContainsString('Results snapshots match', $this->printedText());
    }

    public function testCheckFailsOnATamperedSnapshotAndSaysWhere(): void
    {
        $this->runCommand('--update');
        // Someone (or something) changed one team's total in the committed file.
        $file = $this->folder . '/22BR/22BR_GER.json';
        file_put_contents($file, str_replace('"377.64"', '"377.65"', (string)file_get_contents($file)));

        $exitCode = $this->runCommand('--check');

        $this->assertSame(Command::DIFFERENT, $exitCode, '--check must fail, so CI goes red.');
        $this->assertStringContainsString('22BR/22BR_GER.json', $this->printedText(), 'The report names the file...');
        $this->assertStringContainsString('tables[0].rows[0] (# 12).Total: "377.65" -> "377.64"', $this->printedText(),
            '...the row, the team, the column, and both values (committed -> now).');
        $this->assertStringNotContainsString('22BR/formulas.ACE.json', $this->printedText(), 'Untouched files are not listed.');
    }

    public function testCheckFailsWhenASnapshotIsMissingOrExtra(): void
    {
        $this->runCommand('--update');
        unlink($this->folder . '/22BR/formulas.ACE.json');
        file_put_contents($this->folder . '/22BR/22BR_OLD.json', "{}\n");

        $this->assertSame(Command::DIFFERENT, $this->runCommand('--check'));
        $this->assertStringContainsString("22BR/formulas.ACE.json\n    new: produced now, but not committed", $this->printedText());
        $this->assertStringContainsString("22BR/22BR_OLD.json\n    committed, but no longer produced", $this->printedText());
    }

    public function testLineEndingsAloneAreNotADifference(): void
    {
        // A Windows checkout may turn every \n into \r\n. Same JSON, same results.
        $this->runCommand('--update');
        $file = $this->folder . '/22BR/22BR_GER.json';
        file_put_contents($file, str_replace("\n", "\r\n", (string)file_get_contents($file)));

        $this->assertSame(Command::OK, $this->runCommand('--check'));
    }

    public function testUpdateWritesTheFilesAndRemovesOnesNoLongerProduced(): void
    {
        mkdir($this->folder . '/22BR', 0777, true);
        file_put_contents($this->folder . '/22BR/22BR_OLD.json', "{}\n");

        $this->assertSame(Command::OK, $this->runCommand('--update'));

        $this->assertSame(['22BR/22BR_GER.json', '22BR/formulas.ACE.json'], array_keys(SnapshotFiles::read($this->folder)));
    }

    public function testEventsLimitWhatIsBuiltAndWhatIsCompared(): void
    {
        $this->runCommand('--update');
        // A snapshot of another event that this run is not about.
        mkdir($this->folder . '/23SU');
        file_put_contents($this->folder . '/23SU/23SU_GER.json', "{}\n");

        $this->assertSame(Command::OK, $this->runCommand('--check', '--events', '22BR'),
            '23SU is not built, so its file must not count as "no longer produced".');
        $this->assertSame(['22BR'], end($this->builds), 'Only the chosen events were asked for.');
        $this->assertFileExists($this->folder . '/23SU/23SU_GER.json');
    }

    public function testDirAcceptsBothSpellings(): void
    {
        // --dir is how the pre-release check on staging keeps its "before"
        // copy outside the repository.
        $other = $this->folder . '/elsewhere';
        $this->assertSame(Command::OK, $this->runCommand('--update', '--dir=' . $other));
        $this->assertSame(Command::OK, $this->runCommand('--check', '--dir', $other));
        $this->assertFileExists($other . '/22BR/22BR_GER.json');
    }

    public function testCheckAgainstAFolderThatDoesNotExistCannotRun(): void
    {
        $this->assertSame(Command::CANNOT_RUN, $this->runCommand('--check', '--dir', $this->folder . '/nothing-here'));
        $this->assertStringContainsString('Create one with --update first', $this->printedText());
    }

    public function testBadOptionsStopBeforeAnythingIsBuilt(): void
    {
        foreach ([[], ['--check', '--update'], ['--check', '--events', '22XX'], ['--check', '--jobs', '0'], ['--chek']] as $arguments) {
            $this->assertSame(Command::CANNOT_RUN, $this->runCommand(...$arguments), 'Options: ' . implode(' ', $arguments));
        }
        $this->assertSame([], $this->builds, 'With bad options the database is never touched.');
        $this->assertStringContainsString('Usage:', $this->printedText());
    }

    /** Runs Command with the fake source and returns its exit code. */
    private function runCommand(string ...$arguments): int
    {
        $source = new class ($this->builds) implements SnapshotSource {
            /** @param list<list<string>|null> $builds */
            public function __construct(private array &$builds)
            {
            }

            public function build(?array $onlyEvents, callable $progress): array
            {
                $this->builds[] = $onlyEvents;
                return [
                    '22BR/22BR_GER.json' => ['tables' => [['columns' => ['#', 'Total'], 'rows' => [['#' => '12', 'Total' => '377.64']]]]],
                    '22BR/formulas.ACE.json' => ['inputs' => [['equipe' => 12, 'vars' => ['T' => 4.51]]]],
                ];
            }
        };
        $command = new Command(fn(int $parallel) => $source, $this->folder, function (string $text): void {
            $this->printed[] = $text;
        });
        return $command->run($arguments);
    }

    private function printedText(): string
    {
        return implode('', $this->printed);
    }
}
