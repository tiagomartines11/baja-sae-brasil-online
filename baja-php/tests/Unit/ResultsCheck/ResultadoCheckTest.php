<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\ResultsCheck;

use Baja\ResultsCheck\Finding;
use Baja\ResultsCheck\ResultadoCheck;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * What the check reports about one resultado.
 *
 * HOW TO READ THESE TESTS
 *
 * Each test:
 *
 *   1. writes a resultado's settings ("colunas") as the organisers type
 *      them in the judge area, as JSON;
 *   2. makes a few teams with made-up scores (Fixtures::team(car number,
 *      scores));
 *   3. runs the check (self::check), which gives back a list of short
 *      lines, one per problem: "<kind> | <where> | <detail>";
 *   4. asserts that exactly those problems are found.
 *
 * Settings used here, in short (docs/results-rules.md has them all):
 *
 *   colunas    the columns. "val" is a formula, e.g. "$PONTOS * 2"; or
 *              "Pos" (the position), "EquipeNum" (car number), "Equipe".
 *   pos        the header of the column to rank teams by
 *   pos_order  "desc" (highest first, the default) or "asc"
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/ResultsCheck/ResultadoCheckTest.php
 */
final class ResultadoCheckTest extends TestCase
{
    /** A config with nothing wrong gives no problems at all. */
    public function testAGoodConfigHasNoProblems(): void
    {
        $problems = self::check('{"pos": "Pontos", "colunas": [
            {"header": "Pos", "val": "Pos"},
            {"header": "#", "val": "EquipeNum"},
            {"header": "Pontos", "val": "\'<b>\' . ROUND($PONTOS, 2) . \'</b>\'"}
        ]}', [
            Fixtures::team(1, ['PONTOS' => 10]),
            Fixtures::team(2, ['PONTOS' => 7.5]),
        ]);

        $this->assertSame([], $problems);
    }

    // ---- 1. Formulas that raise an error -------------------------------

    /**
     * The case this tool was made for: text joined with "+". Every team's
     * cell is empty, and so (since BAJAAPP-61) nobody gets a position.
     * Both are reported.
     */
    public function testAFormulaThatFailsForEveryTeamIsReported(): void
    {
        $problems = self::check('{"pos": "Pontos", "colunas": [
            {"header": "Pos", "val": "Pos"},
            {"header": "Pontos", "val": "\'<strong>\' + ROUND($PONTOS, 2) + \'<strong>\'"}
        ]}', [
            Fixtures::team(1, ['PONTOS' => 10.5]),
            Fixtures::team(2, ['PONTOS' => 7.5]),
        ]);

        $this->assertSame([
            'Formula raises an error | column "Pontos" val | TypeError: Unsupported operand types: string + float | 2 of 2 teams',
            'Ranking config | pos | pos "Pontos" has no number for any team, so every team shows "–" (it is empty for every team)',
        ], $problems);
    }

    /** The report says how many teams an error hits: here only the team with a time of 0. */
    public function testItCountsTheTeamsAnErrorHits(): void
    {
        $problems = self::check('{"colunas": [{"header": "Velocidade", "val": "100 / $TEMPO"}]}', [
            Fixtures::team(1, ['TEMPO' => 4]),
            Fixtures::team(2, ['TEMPO' => 0]),
            Fixtures::team(3, ['TEMPO' => 5]),
        ]);

        $this->assertSame(['Formula raises an error | column "Velocidade" val | DivisionByZeroError: Division by zero | 1 of 3 teams'], $problems);
    }

    /**
     * Not only the columns' "val": every formula the results page runs is
     * checked. Here a list column's lines and its "highlight" (which line
     * is bold), the "tiebreak", and a "val_spoilers" (what the column shows
     * while spoilers are on during an event).
     */
    public function testEveryKindOfFormulaIsChecked(): void
    {
        $problems = self::check('{"pos": "Total", "tiebreak": "max([])", "colunas": [
            {"header": "Voltas", "val": ["$V1", "\'x\' + $V2"], "highlight": "1 / 0"},
            {"header": "Total", "val": "$V1 + $V2", "val_spoilers": "\'?\' + 1"}
        ]}', [Fixtures::team(1, ['V1' => 3, 'V2' => 4])]);

        $this->assertSame([
            'Formula raises an error | column "Voltas" val[1] | TypeError: Unsupported operand types: string + int | 1 of 1 teams',
            'Formula raises an error | column "Voltas" highlight | DivisionByZeroError: Division by zero | 1 of 1 teams',
            'Formula raises an error | column "Total" val_spoilers | TypeError: Unsupported operand types: string + int | 1 of 1 teams',
            'Formula raises an error | tiebreak | ValueError: max(): Argument #1 ($value) must contain at least one element | 1 of 1 teams',
        ], $problems);
    }

    // ---- 2. Ranking settings --------------------------------------------

    /**
     * "pos" with a typo, or naming a column that was removed: nobody can be
     * ranked. (24NE_4X4 in the seed ranks by "Total", which it does not have.)
     */
    public function testPosNamingNoColumnAndNoVariableIsReported(): void
    {
        $problems = self::check('{"pos": "Total", "colunas": [
            {"header": "Pos", "val": "Pos"},
            {"header": "Pontos", "val": "$PONTOS"}
        ]}', [Fixtures::team(1, ['PONTOS' => 10])]);

        $this->assertSame(['Ranking config | pos | pos "Total" names no column and no variable, so nobody is ranked and every team shows "–"'], $problems);
    }

    /** "pos" may also name a formula variable instead of a column (R5). That is fine. */
    public function testPosNamingAVariableIsFine(): void
    {
        $problems = self::check('{"pos": "PONTOS", "colunas": [{"header": "Pos", "val": "Pos"}]}', [
            Fixtures::team(1, ['PONTOS' => 10]),
        ]);

        $this->assertSame([], $problems);
    }

    /** A "Pos" column, but no "pos" to rank by: every team shows "–". (22SE_SUS in the seed.) */
    public function testPosColumnWithoutPosIsReported(): void
    {
        $problems = self::check('{"colunas": [
            {"header": "Pos", "val": "Pos"},
            {"header": "Pontos", "val": "$PONTOS"}
        ]}', [Fixtures::team(1, ['PONTOS' => 10])]);

        $this->assertSame(['Ranking config | pos | column "Pos" shows positions but there is no "pos", so nobody is ranked and every team shows "–"'], $problems);
    }

    /** No "Pos" column and no "pos" is a list without a ranking, which is fine. */
    public function testNoPosAndNoPosColumnIsFine(): void
    {
        $problems = self::check('{"colunas": [{"header": "Pontos", "val": "$PONTOS"}]}', [Fixtures::team(1, ['PONTOS' => 10])]);

        $this->assertSame([], $problems);
    }

    /** pos_order must be "asc" or "desc" (any capitals). Anything else silently ranks as "desc". */
    public function testPosOrderOtherThanAscOrDescIsReported(): void
    {
        $teams = [Fixtures::team(1, ['T' => 10])];
        $config = '{"pos": "T", "pos_order": %s, "colunas": [{"header": "Pos", "val": "Pos"}]}';

        $this->assertSame([], self::check(sprintf($config, '"ASC"'), $teams));
        $this->assertSame([], self::check(sprintf($config, '"desc"'), $teams));
        $this->assertSame(
            ['Ranking config | pos_order | pos_order "crescente" is neither "asc" nor "desc", so it ranks as "desc" (highest first)'],
            self::check(sprintf($config, '"crescente"'), $teams),
        );
    }

    /** The column exists but shows no number for anyone (here a word): nobody is ranked. */
    public function testPosWithNoNumberForAnyTeamIsReported(): void
    {
        $problems = self::check('{"pos": "Pontos", "colunas": [
            {"header": "Pos", "val": "Pos"},
            {"header": "Pontos", "val": "\'DNF\'"}
        ]}', [Fixtures::team(1, []), Fixtures::team(2, [])]);

        $this->assertSame(['Ranking config | pos | pos "Pontos" has no number for any team, so every team shows "–" (it shows e.g. "DNF")'], $problems);
    }

    // ---- 3. Columns empty for every team --------------------------------

    /**
     * No error, yet nobody has a value: likely a variable that is spelled
     * differently in the prova. Only HTML with no text counts as empty too.
     * The variables themselves are listed too, as unknown (see
     * UnknownVariablesTest).
     */
    public function testAColumnEmptyForEveryTeamIsReported(): void
    {
        $problems = self::check('{"colunas": [
            {"header": "Nota", "val": "$NOTA_FINAL"},
            {"header": "Status", "val": "\'<b>\' . $STATUS . \'</b>\'"}
        ]}', [
            Fixtures::team(1, ['NOTA' => 8]),
            Fixtures::team(2, ['NOTA' => 9]),
        ]);

        $this->assertSame([
            'Column empty for every team | column "Nota" val | no error, but the cell is empty for all 2 rows (no team has a value for $NOTA_FINAL, which may be right)',
            'Column empty for every team | column "Status" val | no error, but the cell is empty for all 2 rows',
            'Unknown variables | column "Nota" val | $NOTA_FINAL is given by nothing here, so it is empty for every team',
            'Unknown variables | column "Status" val | $STATUS is given by nothing here, so it is empty for every team',
        ], $problems);
    }

    /** One team with a value is enough: the column works. 0 is a value. */
    public function testAColumnWithOneValueIsFine(): void
    {
        $problems = self::check('{"colunas": [{"header": "Penalidade", "val": "$PEN"}]}', [
            Fixtures::team(1, []),
            Fixtures::team(2, ['PEN' => 0]),
        ]);

        $this->assertSame([], $problems);
    }

    /** A column whose formula raises an error is reported once, as the error, not again as empty. */
    public function testAFailingColumnIsNotAlsoReportedAsEmpty(): void
    {
        $problems = self::check('{"colunas": [{"header": "X", "val": "\'a\' + 1"}]}', [Fixtures::team(1, [])]);

        $this->assertCount(1, $problems);
        $this->assertStringStartsWith('Formula raises an error', $problems[0]);
    }

    /** With no teams at all, no formula runs, so only the settings themselves can be checked. */
    public function testWithNoTeamsOnlyTheSettingsAreChecked(): void
    {
        $result = ResultadoCheck::run(json_decode('{"pos": "Typo", "pos_order": "x", "colunas": [{"header": "X", "val": "\'a\' + 1"}]}'), []);

        $this->assertSame(0, $result['teams']);
        $this->assertSame([Finding::RANKING], array_map(fn(Finding $finding) => $finding->problem, $result['findings']), 'Only the pos_order.');
    }

    /**
     * Runs the check and writes each problem as one line, so a test can
     * compare all of them at once.
     *
     * @param list<\Baja\Results\TeamInput> $teams
     * @return list<string>
     */
    private static function check(string $colunas, array $teams): array
    {
        $decoded = json_decode($colunas);
        self::assertIsObject($decoded, 'The test config is valid JSON.');
        return array_map(
            fn(Finding $finding) => implode(' | ', array_filter([
                $finding->problem,
                $finding->where,
                $finding->detail,
                $finding->teams === null ? null : $finding->teams . ' of ' . $finding->ofTeams . ' teams',
            ], fn($part) => $part !== null)),
            ResultadoCheck::run($decoded, $teams)['findings'],
        );
    }
}
