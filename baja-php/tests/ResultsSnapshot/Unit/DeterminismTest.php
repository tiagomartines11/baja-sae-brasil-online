<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsSnapshot\Unit;

use Baja\ResultsSnapshot\Capture\ErrorText;
use Baja\ResultsSnapshot\Files\SnapshotFiles;
use Baja\ResultsSnapshot\Html\ResultsPageReader;
use Baja\ResultsSnapshot\Html\Text;
use Baja\Tests\ResultsSnapshot\Unit\Support\FakePages;
use PHPUnit\Framework\TestCase;

/**
 * Same input, same snapshot: byte for byte, on any machine, every time.
 *
 * WHY THIS MATTERS
 *
 * --check fails on ANY difference. If building the same snapshot twice could
 * give two different files (a clock, an unordered list, a machine-specific
 * path), --check would fail for no reason, and a check that cries wolf gets
 * ignored. "Run --update twice and get zero diff" is a promise this file
 * tests piece by piece.
 */
final class DeterminismTest extends TestCase
{
    public function testTheSamePageGivesTheSameBytes(): void
    {
        $first = SnapshotFiles::encode(['tables' => ResultsPageReader::tables(FakePages::prova())]);
        $second = SnapshotFiles::encode(['tables' => ResultsPageReader::tables(FakePages::prova())]);

        $this->assertSame($first, $second);
    }

    public function testTheOrderInWhichFilesWereProducedDoesNotMatter(): void
    {
        // The pages run side by side and finish in any order. The file list
        // is sorted by path before anything is written or compared.
        $a = SnapshotFiles::encodeAll(['22BR/b.json' => ['n' => 2], '22BR/a.json' => ['n' => 1]]);
        $b = SnapshotFiles::encodeAll(['22BR/a.json' => ['n' => 1], '22BR/b.json' => ['n' => 2]]);

        $this->assertSame($a, $b);
        $this->assertSame(['22BR/a.json', '22BR/b.json'], array_keys($a));
    }

    public function testTheFileFormatIsReadableAndEndsWithANewline(): void
    {
        // Accents and slashes stay as they are, so a cell like
        // "SACI<br><i>UNIFEI</i>" reads the same in the diff as on the page.
        $this->assertSame("{\n    \"cell\": \"<i>São José</i>\"\n}\n", SnapshotFiles::encode(['cell' => '<i>São José</i>']));
    }

    public function testRunningTimesAreMasked(): void
    {
        // A prova still in progress shows how long it has been running,
        // which is different every minute.
        $this->assertSame('Tempo de Prova: [elapsed]<br>Última Atualização: [timestamp]',
            Text::maskClock('Tempo de Prova: 01:25<br>Última Atualização: 2024-03-24 17:02:11'));
    }

    public function testErrorMessagesDoNotDependOnWhereTheCodeIsInstalled(): void
    {
        // The dev container runs the code from /var/www/html, CI from
        // /home/runner/work/... The same warning must read the same.
        $warning = ['type' => 'Warning', 'message' => 'Undefined array key "overlay"', 'file' => '', 'line' => 23];

        $inContainer = (new ErrorText('/var/www/html'))->line(['file' => '/var/www/html/resultados/torneio.php'] + $warning);
        $inCi = (new ErrorText('/home/runner/work/x/baja-php/'))->line(['file' => '/home/runner/work/x/baja-php/resultados/torneio.php'] + $warning);

        $this->assertSame('Warning: Undefined array key "overlay" in resultados/torneio.php:23', $inContainer);
        $this->assertSame($inContainer, $inCi);
    }

    public function testARepeatedWarningIsListedOnceWithoutACount(): void
    {
        // prova_juiz.php warns about "ts" inside its sort comparison, once
        // per comparison. How many comparisons a sort makes depends on the
        // scores, so a count would change whenever any score did, and a
        // result change would drag a meaningless warning change along.
        $warning = ['type' => 'Warning', 'message' => 'Undefined array key "ts"', 'file' => '/app/resultados/prova_juiz.php', 'line' => 157];
        $other = ['line' => 42, 'message' => 'Undefined array key "entries"'] + $warning;

        $lines = (new ErrorText('/app'))->warningLines([$warning, $other, $warning, $warning]);

        $this->assertSame([
            'Warning: Undefined array key "ts" in resultados/prova_juiz.php:157',
            'Warning: Undefined array key "entries" in resultados/prova_juiz.php:42',
        ], $lines, 'Each distinct warning once, in the order they first happened.');
    }

    public function testWritingTheSameSnapshotsTwiceChangesNothing(): void
    {
        $folder = sys_get_temp_dir() . '/results-snapshot-test-' . bin2hex(random_bytes(4));
        $files = SnapshotFiles::encodeAll(['22BR/22BR_GER.json' => ['tables' => ResultsPageReader::tables(FakePages::prova())]]);

        SnapshotFiles::replace($folder, $files);
        $afterFirst = SnapshotFiles::read($folder);
        SnapshotFiles::replace($folder, $files);

        $this->assertSame($afterFirst, SnapshotFiles::read($folder));
        $this->assertSame($files, $afterFirst);

        SnapshotFiles::replace($folder, []);
        @rmdir($folder);
    }
}
