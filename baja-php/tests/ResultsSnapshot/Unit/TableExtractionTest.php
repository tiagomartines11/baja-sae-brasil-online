<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsSnapshot\Unit;

use Baja\ResultsSnapshot\Files\Comparison;
use Baja\ResultsSnapshot\Files\SnapshotFiles;
use Baja\ResultsSnapshot\Html\ResultsPageReader;
use Baja\Tests\ResultsSnapshot\Unit\Support\FakePages;
use PHPUnit\Framework\TestCase;

/**
 * How a results page becomes a snapshot, and which changes to a page the
 * snapshot notices.
 *
 * WHY THIS MATTERS
 *
 * The snapshots exist to catch one thing: a change in what a results page
 * SAYS (who is where, with how many points, with which medal). If they also
 * changed whenever someone touched the menu or a stylesheet, every PR would
 * come with snapshot noise, people would learn to accept it without
 * reading, and the one diff that matters would slip through. So these tests
 * check both directions:
 *
 *   - page chrome changes (menu, header, asset URLs, indentation, the clock)
 *     give NO difference;
 *   - a changed cell, a changed row order, a moved highlight or medal, or a
 *     new column DO give a difference, and the difference says where.
 *
 * Every test works on made-up HTML (tests/ResultsSnapshot/Unit/Support/
 * FakePages.php), so none of them needs a database.
 */
final class TableExtractionTest extends TestCase
{
    public function testATableBecomesRowsKeyedByColumnHeader(): void
    {
        // The page prints a header row and one row per team. The snapshot
        // keeps each row as "column header => what is in the cell", so a
        // reviewer reads "Total of team 12" rather than "cell 4 of row 1".
        $tables = ResultsPageReader::tables(FakePages::prova());

        $this->assertCount(1, $tables, 'The page has exactly one results table.');
        $this->assertSame(['[img trofeu.png]', '#', 'Equipe Escola', 'Total'], $tables[0]['columns'],
            'The trophy header has no text, so it is named after its picture; "Equipe<br>Escola" reads as two words.');
        $this->assertSame([
            '[img trofeu.png]' => '<p style="color:transparent">1º</p>',
            '#' => '12',
            'Equipe Escola' => 'Equipe Alfa<br><i class="nomeEscola">Escola Um</i>',
            'Total' => '<b>377.64</b>',
            'td.class' => ['[img trofeu.png]' => 'ouro'],
        ], $tables[0]['rows'][0], 'The first row, with the gold medal kept from the <td> class.');
    }

    public function testTheLastUpdatedTimeUnderTheTableIsMasked(): void
    {
        // "Última Atualização" changes every time a judge saves anything,
        // even if no result changes. It is replaced by a placeholder; the
        // rest of the footer ("Prova Finalizada") is kept, because it is
        // information the public reads.
        $footer = ResultsPageReader::tables(FakePages::prova())[0]['footer'];

        $this->assertSame('Prova Finalizada<br>Última Atualização: [timestamp]', $footer);
    }

    public function testChangesToThePageAroundTheTableGiveNoDifference(): void
    {
        $before = self::snapshotOf(FakePages::prova());
        $after = self::snapshotOf(FakePages::prova(chrome: [
            'menu' => 'A completely different menu',
            'css' => '/assets/theme-4f3a2b.css',
            'updatedAt' => '2031-01-01 00:00:00',
            'indent' => "\t\t\t\t",
        ]));

        $this->assertSame([], self::differences($before, $after),
            'Menu, stylesheet URL, clock and indentation are not results.');
    }

    public function testAChangedCellIsADifferenceThatNamesTheTeamAndColumn(): void
    {
        $teams = FakePages::TEAMS;
        $teams[1]['total'] = '301.21';

        $differences = self::differences(self::snapshotOf(FakePages::prova()), self::snapshotOf(FakePages::prova($teams)));

        $this->assertSame(
            ['tables[0].rows[1] (# 7).Total: "<b>301.20</b>" -> "<b>301.21</b>"'],
            $differences,
            'One hundredth of a point on one team is exactly one line of difference, and it says which team.'
        );
    }

    public function testAChangedRowOrderIsADifference(): void
    {
        // The same three teams in another order. Nothing in any single cell
        // changed, but the order is the ranking, so it must show.
        $teams = FakePages::TEAMS;
        [$teams[1], $teams[2]] = [$teams[2], $teams[1]];

        $differences = self::differences(self::snapshotOf(FakePages::prova()), self::snapshotOf(FakePages::prova($teams)));

        $this->assertNotSame([], $differences);
        $this->assertContains('tables[0].rows[1] (# 33)["#"]: "7" -> "33"', $differences);
    }

    public function testAMovedHighlightOrMedalIsADifference(): void
    {
        // The bold <b> around a value is inside the cell; the medal is a
        // class on the <td>. Both are something a visitor sees.
        $teams = FakePages::TEAMS;
        $teams[0]['pos'] = 2;

        $differences = self::differences(self::snapshotOf(FakePages::prova()), self::snapshotOf(FakePages::prova($teams)));

        $this->assertContains('tables[0].rows[0] (# 12)["td.class"]["[img trofeu.png]"]: "ouro" -> "prata"', $differences);
    }

    public function testANewColumnIsADifference(): void
    {
        $html = str_replace('<th style=""  >Total</th>', '<th style=""  >Total</th><th>Bônus</th>', FakePages::prova());

        $differences = self::differences(self::snapshotOf(FakePages::prova()), self::snapshotOf($html));

        $this->assertContains('tables[0].columns: 4 items -> 5 items', $differences);
    }

    public function testAPageWithoutAResultsTableGivesNoTables(): void
    {
        // prova.php shows a "not yet" picture instead of a table when there
        // is nothing to show. That is recorded as an empty list, so a page
        // that suddenly loses its table shows up as a difference.
        $this->assertSame([], ResultsPageReader::tables('<html><body><img src="img/aviso2_18.gif"></body></html>'));
    }

    public function testATournamentKeepsItsRoundsAndTheWinner(): void
    {
        $rounds = ResultsPageReader::tournamentRounds(FakePages::torneio());

        $this->assertSame('Final', $rounds[0]['round']);
        $match = $rounds[0]['matches'][0];
        $this->assertSame('Melhor de 3', $match['caption']);
        $this->assertSame('tournament-bracket__team tournament-bracket__team--winner', $match['rows'][0]['tr.class'],
            'The winner is marked by a class on the <tr>, so the row class is kept.');

        $swapped = ResultsPageReader::tournamentRounds(FakePages::torneio(winnerClassOn: 'second'));
        $this->assertNotSame([], self::differences(['rounds' => $rounds], ['rounds' => $swapped]),
            'Giving the match to the other team is a difference.');
    }

    /** @return array<string, mixed> what PageTask would store for this HTML */
    private static function snapshotOf(string $html): array
    {
        return ['tables' => ResultsPageReader::tables($html)];
    }

    /**
     * The lines --check would print, comparing two snapshot documents the
     * way the tool really does: encoded to file contents, then compared.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string>
     */
    private static function differences(array $before, array $after): array
    {
        $comparison = new Comparison(['X/x.json' => SnapshotFiles::encode($before)], ['X/x.json' => SnapshotFiles::encode($after)]);
        return $comparison->differences()['X/x.json'] ?? [];
    }
}
