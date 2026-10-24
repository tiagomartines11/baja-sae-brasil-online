<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\ResultsCheck;

use Baja\Model\Equipe;
use Baja\Model\Input;
use Baja\Model\Prova;
use Baja\ResultsCheck\Finding;
use Baja\ResultsCheck\ProvaCheck;
use PHPUnit\Framework\TestCase;

/**
 * The provas' own formulas, which the check reports but never fixes.
 *
 * BACKGROUND
 *
 * A prova (a competition test: acceleration, suspension...) has formulas
 * in its "params":
 *
 *   vars     worked out from what the judges typed, e.g. the best of two runs
 *   min_max  more of the same, worked out after vars
 *   pontos   the points, from the vars and the totals over all teams
 *
 * They run when a judge saves an input. The results are STORED, and the
 * results pages show the stored values. So the check only reports these
 * errors: fixing a prova's formula means saving the prova, which works
 * every team's points out again (BAJAAPP-56). That is for a person to do.
 *
 * These tests make the prova and its inputs in memory, as PHP objects that
 * are never saved: no database is needed.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/ResultsCheck/ProvaCheckTest.php
 */
final class ProvaCheckTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Linking objects (an input to its prova) needs Propel's description
        // of the tables. Loading it reads PHP files only; it does not connect.
        require_once dirname(__DIR__, 3) . '/src/loadDatabase.php';
    }

    /**
     * PHP 8's min() of an empty list is an error. Here team 2 has no valid
     * run at all, so only team 2 is hit.
     */
    public function testErrorsInVarsAreReportedWithTheTeamsTheyHit(): void
    {
        $prova = self::prova('{"vars": {"T": "min(array_diff([$T_1, $T_2], [null]))"}, "pontos": {"PTS": "100 - $T"}}');
        $inputs = [
            self::input($prova, 1, ['T_1' => 12.5, 'T_2' => 11.0]),
            self::input($prova, 2, ['T_1' => null, 'T_2' => null]),
        ];

        $result = ProvaCheck::run($prova, $inputs);

        $this->assertSame(2, $result['teams']);
        $this->assertSame(
            ['vars.T | ValueError: min(): Argument #1 ($value) must contain at least one element | 1 of 2'],
            self::lines($result['findings']),
        );
    }

    /** min_max and pontos are run too, in that order, as when an input is saved. */
    public function testMinMaxAndPontosAreChecked(): void
    {
        $prova = self::prova('{"vars": {"V": "$V_1"}, "min_max": {"M": "\'x\' + $V"}, "pontos": {"PTS": "100 / $V"}}');

        $result = ProvaCheck::run($prova, [self::input($prova, 1, ['V_1' => 0])]);

        $this->assertSame([
            'min_max.M | TypeError: Unsupported operand types: string + int | 1 of 1',
            'pontos.PTS | DivisionByZeroError: Division by zero | 1 of 1',
        ], self::lines($result['findings']));
    }

    /**
     * Running the formulas changes the Input objects in memory, as saving
     * would, but nothing is saved: Propel still sees the objects as new,
     * never written to a database.
     */
    public function testNothingIsSaved(): void
    {
        $prova = self::prova('{"vars": {"V": "$V_1 * 2"}}');
        $input = self::input($prova, 1, ['V_1' => 3]);

        ProvaCheck::run($prova, [$input]);

        $this->assertSame(6, $input->getVars()->V, 'Worked out in memory...');
        $this->assertTrue($input->isNew(), '...and never saved.');
        $this->assertTrue($prova->isNew());
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
        $input->setDados($dados);
        return $input;
    }

    /**
     * @param list<Finding> $findings
     * @return list<string>
     */
    private static function lines(array $findings): array
    {
        return array_map(fn(Finding $finding) => $finding->where . ' | ' . $finding->detail . ' | ' . $finding->teams . ' of ' . $finding->ofTeams, $findings);
    }
}
