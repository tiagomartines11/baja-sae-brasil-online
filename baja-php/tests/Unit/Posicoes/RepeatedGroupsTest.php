<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Posicoes;

use Baja\Formula\EvalEngine;
use Baja\Formula\SafeEngine;
use Baja\Model\Equipe;
use Baja\Model\Input;
use Baja\Model\Prova;
use Baja\Posicoes\DerivedEntries;
use Baja\Posicoes\EventGraph;
use Baja\Posicoes\PosicoesRules;
use Baja\Posicoes\PositionStage;
use Baja\ResultsCheck\Finding;
use Baja\ResultsCheck\ProvaCheck;
use Baja\ResultsCheck\Report;
use PHPUnit\Framework\TestCase;

/**
 * Where two features meet: positions ("posicoes", BAJAAPP-102) and
 * repeated input groups ("repetir", BAJAAPP-103).
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * A "prova" is one test of the competition. Judges type each team's data
 * ("dados"); the prova's formulas, in its "params", turn it into points.
 *
 * A REPEATED INPUT GROUP saves writing the same fields six times. This:
 *
 *     "inputs": [{"repetir": 6, "nome": "Tentativa", "inputs": [{"code": "T", ...}]}]
 *
 * means the fields T_1, T_2 ... T_6 (one per attempt). The formulas also
 * get $T_lista: the list of the attempts that were filled in, in order.
 * MENOR($T_lista) is the smallest of them (the best time), MAIOR() the
 * largest, CONTAR() how many there are. With no attempt at all, MENOR()
 * and MAIOR() give null (no value), never an error.
 *
 * "POSICOES" gives each team a variable holding its POSITION (1, 2, 3...)
 * among the teams of the prova, ranked by a formula ("valor"), with an
 * optional second formula to break ties ("desempate"):
 *
 *     "posicoes": {"POS": {"valor": "MENOR($T_lista)", "ordem": "asc"}}
 *
 * ("asc": the LOWEST value is 1st, as for a time.) The "pontos" formulas
 * then read $POS.
 *
 * What these tests make sure of:
 *   - "valor" and "desempate" can use $T_lista and MENOR/MAIOR/CONTAR, in
 *     both formula engines (the default "eval" one and the "safe" one);
 *   - a prova whose only inputs are a repeated group is a NORMAL prova
 *     (judges type its data), not a "derived" one that makes its own
 *     empty entries;
 *   - a position may not take the name of a field the group makes (T_3)
 *     or of its list (T_lista): it would overwrite the judges' data;
 *   - the config check reports the mistakes of both features in one run.
 *
 * Everything is made in memory, as PHP objects that are never saved: no
 * database is needed. Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Posicoes/RepeatedGroupsTest.php
 */
final class RepeatedGroupsTest extends TestCase
{
    /** The prova's "inputs": one field, T (a time), repeated for six attempts. */
    private const SIX_ATTEMPTS = '[{"repetir": 6, "nome": "Tentativa", "inputs": [{"code": "T", "name": "Tempo [s]", "type": "number"}]}]';

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

    /**
     * THE MAIN CASE: a placement from the best of six attempts.
     *
     * Each car's best time is MENOR($T_lista), and the lowest best time is
     * 1st. When two best times are equal, the lower WORST time
     * (MAIOR($T_lista)) goes first. Empty attempts ("" or missing) are not
     * times at all.
     *
     *   car   attempts typed                     best   worst   position
     *    1    11.2, 12.5 (then nothing)          11.2   12.5    2
     *    2    13.0 12.1 11.9 11.2 12.0 12.4      11.2   13.0    3
     *    3    "", "", 10.8                       10.8   10.8    1
     *    4    nothing at all                     none           none (null)
     *    5    only the 6th: 15.0                 15.0   15.0    4
     *
     * Cars 1 and 2 tie on 11.2; car 1's worst attempt is better, so it is 2nd.
     * Points: 100 for 1st, 10 less for each place after, 0 with no place.
     * Both formula engines must give exactly the same.
     */
    public function testAPlacementFromTheBestOfSixAttempts(): void
    {
        foreach (['eval' => new EvalEngine(), 'safe' => new SafeEngine()] as $engineName => $engine) {
            Input::setFormulaEngine($engine);
            $prova = self::prova('{
                "inputs": ' . self::SIX_ATTEMPTS . ',
                "posicoes": {"POS": {"valor": "MENOR($T_lista)", "ordem": "asc", "desempate": "MAIOR($T_lista)"}},
                "pontos": {"PTS": "$POS !== null ? 100 - 10 * ($POS - 1) : 0"}
            }');
            $inputs = [
                self::input($prova, 1, ['T_1' => 11.2, 'T_2' => 12.5]),
                self::input($prova, 2, ['T_1' => 13.0, 'T_2' => 12.1, 'T_3' => 11.9, 'T_4' => 11.2, 'T_5' => 12.0, 'T_6' => 12.4]),
                self::input($prova, 3, ['T_1' => '', 'T_2' => '', 'T_3' => 10.8]),
                self::input($prova, 4, []),
                self::input($prova, 5, ['T_6' => 15.0]),
            ];

            self::workOut($prova, $inputs);

            $this->assertSame([1 => 2, 2 => 3, 3 => 1, 4 => null, 5 => 4], self::read($inputs, 'vars', 'POS'), $engineName . ': positions');
            $this->assertSame([1 => 90, 2 => 80, 3 => 100, 4 => 0, 5 => 70], self::read($inputs, 'pontos', 'PTS'), $engineName . ': points');
        }
    }

    /**
     * CONTAR() works in a "valor" too: here the car that completed the most
     * attempts is 1st (highest first, the default order). Cars 1 and 3 both
     * did two, so they share 2nd place and nobody is 3rd.
     */
    public function testValorCanCountTheAttempts(): void
    {
        foreach (['eval' => new EvalEngine(), 'safe' => new SafeEngine()] as $engineName => $engine) {
            Input::setFormulaEngine($engine);
            $prova = self::prova('{"inputs": ' . self::SIX_ATTEMPTS . ', "posicoes": {"MAIS": {"valor": "CONTAR($T_lista)"}}}');
            $inputs = [
                self::input($prova, 1, ['T_1' => 11.2, 'T_2' => 12.5]),
                self::input($prova, 2, ['T_1' => 13.0, 'T_2' => 12.1, 'T_3' => 11.9]),
                self::input($prova, 3, ['T_4' => 9.0, 'T_6' => 9.5]),
            ];

            self::workOut($prova, $inputs);

            $this->assertSame([1 => 2, 2 => 1, 3 => 2], self::read($inputs, 'vars', 'MAIS'), $engineName);
        }
    }

    /**
     * A "derived" prova has positions and NO input field (Compliance): its
     * points come only from positions, so it makes an empty entry for every
     * team by itself. A prova whose only "inputs" entry is a repeated group
     * HAS fields (T_1 ... T_6) once the group is written out: judges type
     * its data, so it must not be treated as derived.
     */
    public function testAProvaWhoseOnlyInputsAreARepeatedGroupIsNotDerived(): void
    {
        $withGroup = self::prova('{"inputs": ' . self::SIX_ATTEMPTS . ', "posicoes": {"POS": {"valor": "MENOR($T_lista)", "ordem": "asc"}}}');
        $withoutInputs = self::prova('{"posicoes": {"P": {"resultado": "26SU_TRA"}}}');

        $this->assertFalse(DerivedEntries::isDerived($withGroup), 'a repeated group gives the prova fields');
        $this->assertTrue(DerivedEntries::isDerived($withoutInputs), 'still derived: no field at all');
    }

    /**
     * A position is stored next to the judges' data under its own name.
     * Named T_3 it would overwrite the third attempt, and named T_lista it
     * would hide the list: the prova page refuses both, as it refuses the
     * name of a field written by hand. ("T" alone is fine: with a group,
     * no field is called just T.)
     */
    public function testAPositionMayNotTakeTheNameOfAFieldOrListOfTheGroup(): void
    {
        $graph = new EventGraph('26SU', [], []);
        $params = json_decode('{"inputs": ' . self::SIX_ATTEMPTS . ', "posicoes": {
            "T_3": {"valor": "$T_3"},
            "T_lista": {"valor": "MENOR($T_lista)"},
            "T": {"valor": "MENOR($T_lista)", "ordem": "asc"}
        }}');

        $this->assertSame([
            'posicoes.T_3: já existe um input, var ou min_max com esse nome.',
            'posicoes.T_lista: já existe um input, var ou min_max com esse nome.',
        ], PosicoesRules::errorsForProva($graph, 'TST', $params));
    }

    /**
     * The config check (docs/results-config-check.md) knows $T_lista in a
     * position formula: a good config gives no finding at all (no "unknown
     * variable", no formula error).
     */
    public function testTheConfigCheckAcceptsListsInPositionFormulas(): void
    {
        $prova = self::prova('{
            "inputs": ' . self::SIX_ATTEMPTS . ',
            "posicoes": {"POS": {"valor": "MENOR($T_lista)", "ordem": "asc", "desempate": "CONTAR($T_lista)"}},
            "pontos": {"PTS": "$POS !== null ? 10 : 0"}
        }');
        $inputs = [self::input($prova, 1, ['T_1' => 11.2]), self::input($prova, 2, [])];

        $result = ProvaCheck::run($prova, $inputs, new EventGraph('99XX', ['TST' => $prova->getParams()], []));

        $this->assertSame([], $result['findings']);
    }

    /**
     * ONE run of the config check lists the mistakes of BOTH features:
     * here the group repeats 30 times (at most 20 are allowed) and the
     * position reads a resultado the event does not have. Each mistake is
     * listed under its own heading in the report.
     */
    public function testTheConfigCheckReportsBothFeaturesInOneRun(): void
    {
        $prova = self::prova('{
            "inputs": [{"repetir": 30, "nome": "Tentativa", "inputs": [{"code": "T", "name": "T", "type": "number"}]}],
            "posicoes": {"P": {"resultado": "99XX_NOP"}}
        }');
        $graph = new EventGraph('99XX', ['TST' => $prova->getParams()], []);

        $findings = ProvaCheck::run($prova, [], $graph)['findings'];

        $kinds = array_map(fn(Finding $finding) => $finding->problem, $findings);
        $this->assertSame([Finding::REPEATED, Finding::POSITIONS], $kinds);

        $report = Report::render([[
            'event' => '99XX',
            'resultados' => [],
            'provas' => [['prova' => 'TST', 'nome' => 'Teste', 'teams' => 0, 'findings' => $findings]],
        ]], null);
        $this->assertStringContainsString(Finding::REPEATED, $report);
        $this->assertStringContainsString('recebeu 30', $report);
        $this->assertStringContainsString(Finding::POSITIONS, $report);
        $this->assertStringContainsString('posicoes.P: o resultado 99XX_NOP não existe neste evento.', $report);
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * Works a prova out the way Prova::refreshVarsAndPontos() does, but in
     * memory: vars, then positions, then points.
     *
     * @param list<Input> $inputs
     */
    private static function workOut(Prova $prova, array $inputs): void
    {
        foreach ($inputs as $input) {
            $input->updateVars();
        }
        PositionStage::apply($prova, $inputs);
        foreach ($inputs as $input) {
            $input->updatePontos();
        }
    }

    /**
     * @param list<Input> $inputs
     * @param 'vars'|'pontos' $column
     * @return array<int, mixed> car => the value stored under $name
     */
    private static function read(array $inputs, string $column, string $name): array
    {
        $values = [];
        foreach ($inputs as $input) {
            $stored = (array)($column === 'vars' ? $input->getVars() : $input->getPontos());
            $values[$input->getEquipeId()] = $stored[$name] ?? null;
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
    private static function input(Prova $prova, int $team, array $dados): Input
    {
        $equipe = new Equipe();
        $equipe->setEventoId('99XX');
        $equipe->setEquipeId($team);
        $equipe->setDesclassificado(false);

        $input = new Input();
        $input->setEventoId('99XX');
        $input->setEquipeId($team);
        $input->setProva($prova);
        $input->setEquipe($equipe);
        $input->setDados((object)$dados);
        return $input;
    }
}
