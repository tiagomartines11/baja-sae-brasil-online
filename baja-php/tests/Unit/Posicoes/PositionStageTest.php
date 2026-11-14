<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Posicoes;

use Baja\Formula\EvalEngine;
use Baja\Formula\SafeEngine;
use Baja\Model\Equipe;
use Baja\Model\Input;
use Baja\Model\Prova;
use Baja\Posicoes\PositionStage;
use PHPUnit\Framework\TestCase;

/**
 * Positions inside a prova: the "posicoes" stage (BAJAAPP-102).
 *
 * BACKGROUND
 *
 * A "prova" is one test of the competition. For each team, judges type
 * some data ("dados"), and the prova's formulas, in its "params", turn it
 * into points, in this order:
 *
 *     inputs → vars → min_max → Totais → posicoes → pontos
 *
 * "posicoes" is new. Each entry gives every team a variable holding its
 * POSITION among the teams of this prova: 1 for the best, 2, 3...
 *
 *     "posicoes": {"COL": {"valor": "$NOTA", "ordem": "desc", "desempate": "$NOTA_FINAL"}}
 *
 *   valor      the formula to rank by, worked out for each team
 *   ordem      "desc" (the default): highest first; "asc": lowest first
 *   desempate  optional: separates teams with the same valor
 *
 * The "pontos" formulas can then use $COL, e.g. "$COL == 1 ? 10 : 0".
 *
 * The ranking rules are the results pages' own (the same code ranks both):
 *   - equal values share a position, and the next one is skipped: 1, 1, 3;
 *   - a team with no value has no position (null);
 *   - a disqualified team has no position either.
 *
 * HOW THESE TESTS WORK
 *
 * The prova and its inputs are made in memory, as PHP objects that are
 * never saved: no database is needed. Each test runs the stage and reads
 * the variable it wrote into every team's vars.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Posicoes/PositionStageTest.php
 */
final class PositionStageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Linking objects (an input to its prova) needs Propel's description
        // of the tables. Loading it reads PHP files only; it does not connect.
        require_once dirname(__DIR__, 3) . '/src/loadDatabase.php';
    }

    protected function tearDown(): void
    {
        Input::setFormulaEngine(null);  // back to the configured engine
    }

    /** The simplest case: the highest NOTA is 1st. */
    public function testTeamsAreRankedHighestFirst(): void
    {
        $positions = self::positions('{"COL": {"valor": "$NOTA"}}', [
            1 => ['NOTA' => 7],
            2 => ['NOTA' => 9],
            3 => ['NOTA' => 8],
        ]);

        $this->assertSame([1 => 3, 2 => 1, 3 => 2], $positions);
    }

    /**
     * Cars 1 and 2 both have 9: both are 1st, and the next car is 3rd,
     * not 2nd. Exactly as the results pages count.
     */
    public function testTiesShareThePositionAndTheNextIsSkipped(): void
    {
        $positions = self::positions('{"COL": {"valor": "$NOTA"}}', [
            1 => ['NOTA' => 9],
            2 => ['NOTA' => 9],
            3 => ['NOTA' => 8],
        ]);

        $this->assertSame([1 => 1, 2 => 1, 3 => 3], $positions);
    }

    /**
     * Cars 1 and 2 tie on NOTA; the "desempate" (NOTA_FINAL, also highest
     * first) separates them. Cars 3 and 4 tie on both, so they still share.
     */
    public function testDesempateBreaksATie(): void
    {
        $positions = self::positions('{"COL": {"valor": "$NOTA", "desempate": "$NOTA_FINAL"}}', [
            1 => ['NOTA' => 9, 'NOTA_FINAL' => 5],
            2 => ['NOTA' => 9, 'NOTA_FINAL' => 6],
            3 => ['NOTA' => 8, 'NOTA_FINAL' => 1],
            4 => ['NOTA' => 8, 'NOTA_FINAL' => 1],
        ]);

        $this->assertSame([1 => 2, 2 => 1, 3 => 3, 4 => 3], $positions);
    }

    /** With "ordem": "asc" the LOWEST is 1st, as for a time. The desempate follows. */
    public function testAscRanksTheLowestFirst(): void
    {
        $positions = self::positions('{"T": {"valor": "$TEMPO", "ordem": "asc", "desempate": "$TEMPO_2"}}', [
            1 => ['TEMPO' => 61.2, 'TEMPO_2' => 70],
            2 => ['TEMPO' => 58.4, 'TEMPO_2' => 70],
            3 => ['TEMPO' => 61.2, 'TEMPO_2' => 65],
        ]);

        $this->assertSame([1 => 3, 2 => 1, 3 => 2], $positions);
    }

    /**
     * A team with no value gets null, never a position: here car 2 has no
     * NOTA, car 3's is empty text. The others are ranked without them.
     */
    public function testTeamsWithNoValueGetNull(): void
    {
        $positions = self::positions('{"COL": {"valor": "$NOTA"}}', [
            1 => ['NOTA' => 5],
            2 => [],
            3 => ['NOTA' => ''],
            4 => ['NOTA' => 8],
        ]);

        $this->assertSame([1 => 2, 2 => null, 3 => null, 4 => 1], $positions);
    }

    /**
     * Car 2 is disqualified: it gets null, and the others are ranked as if
     * it were not there, although its NOTA is the highest.
     */
    public function testDisqualifiedTeamsGetNull(): void
    {
        $prova = self::prova('{"posicoes": {"COL": {"valor": "$NOTA"}}}');
        $inputs = [
            self::input($prova, 1, ['NOTA' => 5]),
            self::input($prova, 2, ['NOTA' => 99], disqualified: true),
            self::input($prova, 3, ['NOTA' => 8]),
        ];

        PositionStage::apply($prova, $inputs);

        $this->assertSame([1 => 2, 2 => null, 3 => 1], self::read($inputs, 'COL'));
    }

    /**
     * "valor" sees the input fields, the vars and the min_max, like
     * min_max does. Here it ranks by a var, M, made from two inputs.
     */
    public function testValorSeesTheVars(): void
    {
        $prova = self::prova('{"vars": {"M": "$A + $B"}, "posicoes": {"COL": {"valor": "$M"}}}');
        $inputs = [self::input($prova, 1, ['A' => 1, 'B' => 1]), self::input($prova, 2, ['A' => 5, 'B' => 0])];
        foreach ($inputs as $input) {
            $input->updateVars();
        }

        PositionStage::apply($prova, $inputs);

        $this->assertSame([1 => 2, 2 => 1], self::read($inputs, 'COL'));
        $this->assertSame(2, $inputs[0]->getVars()->M, 'the vars are kept');
    }

    /**
     * The point of it all: a "pontos" formula reads the position. Both
     * formula engines (eval, the default, and the safe one) see it, also
     * when it is null.
     */
    public function testPontosReadThePositionInBothEngines(): void
    {
        foreach (['eval' => new EvalEngine(), 'safe' => new SafeEngine()] as $name => $engine) {
            Input::setFormulaEngine($engine);
            $prova = self::prova('{"posicoes": {"COL": {"valor": "$NOTA"}}, "pontos": {"PTS": "$COL !== null && $COL <= 2 ? 20 : 0"}}');
            $inputs = [
                self::input($prova, 1, ['NOTA' => 9]),
                self::input($prova, 2, ['NOTA' => 8]),
                self::input($prova, 3, ['NOTA' => 7]),
                self::input($prova, 4, []),
            ];

            PositionStage::apply($prova, $inputs);
            foreach ($inputs as $input) {
                $input->updatePontos();
            }

            $points = array_map(fn(Input $input) => $input->getPontos()->PTS, $inputs);
            $this->assertSame([20, 20, 0, 0], $points, $name);
        }
    }

    /** A prova with no "posicoes" is not touched at all: its vars stay exactly as they were. */
    public function testAProvaWithoutPosicoesIsNotTouched(): void
    {
        $prova = self::prova('{"pontos": {"PTS": "$NOTA"}}');
        $input = self::input($prova, 1, ['NOTA' => 9]);
        $input->setVars(['X' => 1]);
        $before = $input->getVars();

        PositionStage::apply($prova, [$input]);

        $this->assertEquals($before, $input->getVars());
        $this->assertSame(['X'], array_keys((array)$input->getVars()));
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * Runs the stage for a prova with these "posicoes" and these teams, and
     * gives the first position variable of each team.
     *
     * @param array<int, array<string, mixed>> $teams car => what the judges typed
     * @return array<int, ?int> car => position
     */
    private static function positions(string $posicoes, array $teams): array
    {
        $prova = self::prova('{"posicoes": ' . $posicoes . '}');
        $inputs = [];
        foreach ($teams as $car => $dados) {
            $inputs[] = self::input($prova, $car, $dados);
        }
        PositionStage::apply($prova, $inputs);
        return self::read($inputs, (string)array_key_first((array)json_decode($posicoes)));
    }

    /**
     * @param list<Input> $inputs
     * @return array<int, mixed> car => the variable, as stored in its vars
     */
    private static function read(array $inputs, string $name): array
    {
        $values = [];
        foreach ($inputs as $input) {
            $vars = (array)$input->getVars();
            self::assertArrayHasKey($name, $vars, 'every team gets the variable, null or not');
            $values[$input->getEquipeId()] = $vars[$name];
        }
        return $values;
    }

    private static function prova(string $params): Prova
    {
        $prova = new Prova();
        $prova->setEventoId('99XX');
        $prova->setProvaId('TST');
        $prova->setParams(json_decode($params));
        return $prova;
    }

    /** @param array<string, mixed> $dados what the judges typed */
    private static function input(Prova $prova, int $team, array $dados, bool $disqualified = false): Input
    {
        $equipe = new Equipe();
        $equipe->setEventoId('99XX');
        $equipe->setEquipeId($team);
        $equipe->setDesclassificado($disqualified);

        $input = new Input();
        $input->setEventoId('99XX');
        $input->setEquipeId($team);
        $input->setProva($prova);
        $input->setEquipe($equipe);
        $input->setDados((object)$dados);
        return $input;
    }
}
