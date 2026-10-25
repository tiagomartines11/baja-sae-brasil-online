<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\ResultsCheck;

use Baja\Model\Input;
use Baja\Model\Prova;
use Baja\ResultsCheck\Finding;
use Baja\ResultsCheck\UnknownVariables;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The config check's "Unknown variables": a formula that reads a variable
 * nothing gives it, which is almost always a typo.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment: a
 * made-up config and teams, then the list of what the check says, one
 * line per variable as "where | what". assertSame(expected, actual) fails,
 * showing both, when they differ.
 *
 * BACKGROUND
 *
 * A formula reads variables: "$P1 + $P2". When a name is wrong ($ACEmin
 * for $ACE_MIN), PHP does not complain: the variable is just empty, the
 * cell shows nothing or the points come out wrong. The new formula engine
 * reads a formula without running it, and so can list its variables
 * (Formula\Syntax\ParsedFormula). This check compares that list with what
 * exists at that place in the config. Nothing is run, nothing is fixed.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/ResultsCheck/UnknownVariablesTest.php
 */
final class UnknownVariablesTest extends TestCase
{
    // ---- Resultados ----

    /** A name no team has and no prova names is reported, with a hint when only its case differs. */
    public function testATypoInAResultado(): void
    {
        $this->assertSame(
            [
                'column "Total" val | $Ace_pts is given by nothing here, so it is empty for every team (there is $ACE_PTS; names care about case)',
                'column "Total" val | $VELPTS is given by nothing here, so it is empty for every team',
            ],
            self::resultado('{"colunas": [{"header": "Total", "val": "$ACE_PTS + $Ace_pts + $VELPTS"}]}', [Fixtures::team(1, ['ACE_PTS' => 10])]),
        );
    }

    /** Team variables (R7), the inputs' fields, a run's fields, and the columns to the left are all known. */
    public function testWhatAResultadoKnows(): void
    {
        $colunas = '{"colunas": [
            {"header": "Pontos", "val": "$P"},
            {"header": "Total", "val": "$Pontos + $NUM . $EQUIPE . $ESCOLA . $ESTADO . $TEAM_ID . $DESCLASSIFICADO + $T"}
        ], "tiebreak": "$Total"}';
        $teams = [Fixtures::team(1, ['P' => 1, 'entries' => [['T' => 5]]])];

        $this->assertSame([], self::resultado($colunas, $teams));
    }

    /** A column to the right is still empty when this one is worked out (R1). */
    public function testAColumnToTheRight(): void
    {
        $this->assertSame(
            ['column "Pontos" val | $Bonus is a column at or to the right of this one: columns are worked out left to right, so here it is still empty'],
            self::resultado('{"colunas": [{"header": "Pontos", "val": "$P + $Bonus"}, {"header": "Bonus", "val": "1"}]}', [Fixtures::team(1, ['P' => 1])]),
        );
    }

    /**
     * A prova that names a variable gives it, even when no team has it: a
     * penalty prova where nobody got a penalty has no row at all.
     */
    public function testAVariableAProvaNamesIsKnown(): void
    {
        $this->assertSame([], self::resultado('{"colunas": [{"header": "Pen", "val": "ROUND($PENPTS, 2)"}]}', [Fixtures::team(1, ['P' => 1])], ['PENPTS' => true]));
    }

    /** A formula that cannot be read is left out: its error is reported elsewhere. */
    public function testAnUnreadableFormulaIsLeftOut(): void
    {
        $this->assertSame([], self::resultado('{"colunas": [{"header": "X", "val": "ROUND($TYPO"}]}', [Fixtures::team(1, [])]));
    }

    // ---- Provas ----

    /** vars see the input fields; min_max also the vars; pontos also the totals and earlier pontos. */
    public function testWhatAProvaKnowsAtEachStep(): void
    {
        $params = [
            'inputs' => [['code' => 'T1', 'name' => 'T1', 'type' => 'number'], ['code' => 'T2', 'name' => 'T2', 'type' => 'number']],
            'vars' => ['T' => 'min($T1, $T2)', 'BAD' => '$T + 1'],
            'min_max' => ['TF' => '$T < 9999 ? $T : null', 'BAD2' => '$TF'],
            'pontos' => ['A_PTS' => '$TFmin / $TF * 100 + $Tavg + $B_PTS', 'B_PTS' => '$A_PTS + $TFcount + $T1'],
        ];

        $this->assertSame([
            'vars.BAD | $T is a var or a min_max: a var only sees the input fields, so here it is empty',
            'min_max.BAD2 | $TF is a min_max: they only see the input fields and the vars, so here it is still empty',
            'pontos.A_PTS | $Tavg is given by nothing here, so it is empty for every team',
            'pontos.A_PTS | $B_PTS is a pontos at or after this one in alphabetical order, the order they are worked out, so here it is still empty',
        ], self::prova($params, [['T1' => 10, 'T2' => 12]]));
    }

    /** A rolling prova's entries carry their time ($ts), written by the site, not by an input. */
    public function testRollingEntriesHaveATime(): void
    {
        $params = ['type' => 'rolling', 'inputs' => [['code' => 'PENA', 'name' => 'Pena', 'type' => 'number']], 'vars' => ['H' => 'date("H:i", $ts) . $PENA']];

        $this->assertSame([], self::prova($params, []));
    }

    /** @return list<string> */
    private static function resultado(string $colunas, array $teams, array $declared = []): array
    {
        return self::lines(UnknownVariables::ofResultado(json_decode($colunas), $teams, $declared));
    }

    /** @return list<string> */
    private static function prova(array $params, array $dados): array
    {
        $prova = new Prova();
        $prova->setParams(json_decode((string)json_encode($params)));
        $inputs = array_map(function (array $one): Input {
            $input = new Input();
            $input->setDados($one);
            return $input;
        }, $dados);
        return self::lines(UnknownVariables::ofProva($prova, $inputs));
    }

    /**
     * @param list<Finding> $findings
     * @return list<string>
     */
    private static function lines(array $findings): array
    {
        return array_map(function (Finding $finding): string {
            self::assertSame(Finding::UNKNOWN_VARIABLE, $finding->problem);
            return $finding->where . ' | ' . $finding->detail;
        }, $findings);
    }
}
