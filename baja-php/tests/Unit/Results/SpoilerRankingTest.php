<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Results\EventStatus;
use Baja\Results\ResultTable;
use Baja\Results\ViewerContext;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * No ranking for the public while spoilers apply (BAJAAPP-42,
 * docs/results-rules.md R11 and R12).
 *
 * THE PROBLEM
 *
 * During an event the organisers can turn spoilers on: a column with a
 * "val_spoilers" then shows that (here "?") instead of the real points.
 * But the "Pos" column could still give the points away. A resultado can
 * rank by a formula variable instead of a column ("pos": "PONTOS"), and
 * that variable is never hidden: the public saw "?" in every Pontos cell
 * and, next to it, the real 1st, 2nd and 3rd, with medals.
 *
 * THE RULE
 *
 * On the public page (and the API), while spoilers apply (spoilers on and
 * the event not finished), a resultado with at least one "val_spoilers"
 * column ranks NOBODY:
 *
 *   - every team's position is null, and its "Pos" cell shows "–", so
 *     there are no medals either;
 *   - no tiebreak value is kept in the rows;
 *   - the rows stay in the page's usual order (by "ts", then car number).
 *
 * Everything else ranks as before: a resultado with no "val_spoilers"
 * column (it hides nothing), an event without spoilers or finished, the
 * judges' copy and the premiação.
 *
 * (How to read these tests: see the comment at the top of
 * ColumnFormulasTest.)
 */
final class SpoilerRankingTest extends TestCase
{
    /**
     * The leak case: "pos" names the formula variable PONTOS, not a column,
     * and the only column showing the points is hidden by spoilers.
     */
    private const RANKED_BY_VARIABLE = '{
        "pos": "PONTOS",
        "tiebreak": "$TEMPO",
        "authorized_users": ["ana"],
        "colunas": [
            {"header": "Pos",    "val": "Pos"},
            {"header": "#",      "val": "EquipeNum"},
            {"header": "Pontos", "val": "$PONTOS", "val_spoilers": "\"?\""}
        ]
    }';

    /** "pos" names the hidden column itself. */
    private const RANKED_BY_HIDDEN_COLUMN = '{
        "pos": "Pontos",
        "colunas": [
            {"header": "Pos",    "val": "Pos"},
            {"header": "Pontos", "val": "$PONTOS", "val_spoilers": "\"?\""}
        ]
    }';

    /** No column has a "val_spoilers": spoilers hide nothing here. */
    private const NOTHING_HIDDEN = '{
        "pos": "Pontos",
        "colunas": [
            {"header": "Pos",    "val": "Pos"},
            {"header": "Pontos", "val": "$PONTOS"}
        ]
    }';

    /**
     * Three teams. By points, car 2 is 1st (90), car 3 is 2nd (70) and
     * car 1 is 3rd (50). Each has a TEMPO for the tiebreak.
     */
    private static function teams(): array
    {
        return [
            Fixtures::team(1, ['PONTOS' => 50, 'TEMPO' => 61]),
            Fixtures::team(2, ['PONTOS' => 90, 'TEMPO' => 62]),
            Fixtures::team(3, ['PONTOS' => 70, 'TEMPO' => 63]),
        ];
    }

    /** The real ranking, by points: what the public must NOT see during spoilers. */
    private const REAL_POSITIONS = [2 => 1, 3 => 2, 1 => 3];

    /** Nobody ranked: what the public sees during spoilers. */
    private const NO_POSITIONS = [1 => null, 2 => null, 3 => null];

    private static function duringEventWithSpoilers(): EventStatus
    {
        return new EventStatus(spoilers: true, finished: false);
    }

    private static function build(string $config, ViewerContext $viewer): ResultTable
    {
        return Fixtures::build(Fixtures::config($config), self::teams(), $viewer);
    }

    // --- The public page while spoilers apply ------------------------------

    /**
     * The leak case. Before the fix, the public saw "?" for the points but
     * still the real positions (car 2 1st, with a gold medal).
     */
    public function testPublicSeesNoPositionsWhenPosNamesAFormulaVariable(): void
    {
        $table = self::build(self::RANKED_BY_VARIABLE, ViewerContext::publicView(self::duringEventWithSpoilers()));

        $this->assertSame(self::NO_POSITIONS, Fixtures::positions($table->rows));
        $this->assertSame([1 => '?', 2 => '?', 3 => '?'], Fixtures::column($table->rows, 'Pontos'));
        $this->assertTrue($table->rankingHidden);
    }

    /** Ranking by the hidden column itself also shows no positions. */
    public function testPublicSeesNoPositionsWhenPosNamesAHiddenColumn(): void
    {
        $table = self::build(self::RANKED_BY_HIDDEN_COLUMN, ViewerContext::publicView(self::duringEventWithSpoilers()));

        $this->assertSame(self::NO_POSITIONS, Fixtures::positions($table->rows));
        $this->assertTrue($table->rankingHidden);
    }

    /**
     * Every "Pos" cell shows "–", the same as a team with no value (R3).
     * The page only draws a medal for positions 1, 2 and 3, so there are
     * none. The cell's raw value (for the API) is null, not the position.
     */
    public function testEveryPosCellShowsADashAndHasNoRawPosition(): void
    {
        $table = self::build(self::RANKED_BY_VARIABLE, ViewerContext::publicView(self::duringEventWithSpoilers()));

        $this->assertSame([1 => '–', 2 => '–', 3 => '–'], Fixtures::column($table->rows, 'Pos'));
        foreach ($table->rows as $row) {
            $this->assertNull($row->cell('Pos')->raw, 'car ' . $row->team->number);
        }
    }

    /**
     * The tiebreak would also give the order away (here TEMPO), so the rows
     * carry no tiebreak value. The coming API (BAJAAPP-9) sends these rows
     * as they are.
     */
    public function testRowsCarryNoTiebreakValue(): void
    {
        $table = self::build(self::RANKED_BY_VARIABLE, ViewerContext::publicView(self::duringEventWithSpoilers()));

        foreach ($table->rows as $row) {
            $this->assertNull($row->tiebreak, 'car ' . $row->team->number);
        }
    }

    /**
     * The rows are listed as the page always lists them (by "ts", then car
     * number), not best first. Here car 3's run has the earliest ts, so it
     * comes first, whatever its points.
     */
    public function testRowsKeepThePageOrder(): void
    {
        $inputs = [
            Fixtures::team(1, ['PONTOS' => 50, 'ts' => 1700000300]),
            Fixtures::team(2, ['PONTOS' => 90, 'ts' => 1700000200]),
            Fixtures::team(3, ['PONTOS' => 70, 'ts' => 1700000100]),
        ];
        $config = Fixtures::config(self::RANKED_BY_VARIABLE);
        $withSpoilers = Fixtures::build($config, $inputs, ViewerContext::publicView(self::duringEventWithSpoilers()));
        $afterTheEvent = Fixtures::build($config, $inputs);

        $this->assertSame([3, 2, 1], Fixtures::numbers($withSpoilers->pageOrder()));
        $this->assertSame(Fixtures::numbers($afterTheEvent->pageOrder()), Fixtures::numbers($withSpoilers->pageOrder()));
    }

    // --- The public page when spoilers do not apply ------------------------

    /** Spoilers switched off during the event: the real ranking is shown. */
    public function testPublicSeesTheRankingWhenSpoilersAreOff(): void
    {
        $table = self::build(self::RANKED_BY_VARIABLE, ViewerContext::publicView(new EventStatus(spoilers: false, finished: false)));

        $this->assertSame(self::REAL_POSITIONS, Fixtures::positions($table->rows));
        $this->assertFalse($table->rankingHidden);
    }

    /** Once the event is finished, spoilers no longer apply, even if still switched on. */
    public function testPublicSeesTheRankingOnceTheEventIsFinished(): void
    {
        $table = self::build(self::RANKED_BY_VARIABLE, ViewerContext::publicView(new EventStatus(spoilers: true, finished: true)));

        $this->assertSame(self::REAL_POSITIONS, Fixtures::positions($table->rows));
        $this->assertSame(1, $table->rows[0]->display('Pos'), 'car 2 is 1st, so it gets the gold medal');
        $this->assertSame(62, $table->rows[0]->tiebreak, "car 2's TEMPO");
    }

    /**
     * A resultado with no "val_spoilers" column hides nothing, so it ranks
     * normally even while spoilers are on for the event.
     */
    public function testResultadoWithoutSpoilerColumnsRanksDuringSpoilers(): void
    {
        $table = self::build(self::NOTHING_HIDDEN, ViewerContext::publicView(self::duringEventWithSpoilers()));

        $this->assertSame(self::REAL_POSITIONS, Fixtures::positions($table->rows));
        $this->assertFalse($table->rankingHidden);
    }

    // --- The judges' copy and the premiação: unchanged ---------------------

    /** A judge listed in "authorized_users" sees the real values and the real ranking. */
    public function testAuthorizedUserSeesTheRealRanking(): void
    {
        $table = self::build(self::RANKED_BY_VARIABLE, ViewerContext::authorized(self::duringEventWithSpoilers(), 'ana', false));

        $this->assertSame(self::REAL_POSITIONS, Fixtures::positions($table->rows));
        $this->assertSame(90, $table->rows[0]->display('Pontos'));
    }

    /**
     * A judge NOT in the list sees "?" for the points, but the judges' copy
     * ranks as it always did: by "pos", here the PONTOS variable. Only the
     * public page hides the ranking.
     */
    public function testJudgeNotInTheListKeepsTheOldRanking(): void
    {
        $table = self::build(self::RANKED_BY_VARIABLE, ViewerContext::authorized(self::duringEventWithSpoilers(), 'carla', false));

        $this->assertSame('?', $table->rows[0]->display('Pontos'));
        $this->assertSame(self::REAL_POSITIONS, Fixtures::positions($table->rows));
        $this->assertFalse($table->rankingHidden);
    }

    /** The premiação never hides spoilers, so it always ranks. */
    public function testPremiacaoAlwaysRanks(): void
    {
        $table = self::build(self::RANKED_BY_VARIABLE, ViewerContext::premiacao());

        $this->assertSame(self::REAL_POSITIONS, Fixtures::positions($table->rows));
        $this->assertFalse($table->rankingHidden);
    }
}
