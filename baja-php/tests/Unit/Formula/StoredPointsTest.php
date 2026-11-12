<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula;

use Baja\Tests\Unit\Formula\Support\ComparesEngines;
use PHPUnit\Framework\TestCase;

/**
 * The seed's provas whose STORED points differ from what their formulas
 * give today (BAJAAPP-56): what the formulas give today, with both engines,
 * for the teams that differ. Pinned so that any change to it is seen; the
 * stored points are not changed by anything here.
 *
 * HOW TO READ THESE TESTS
 *
 * assertGives(value, formula, variables) and assertFails(error, formula,
 * variables) run the formula with eval() and with the new engine, and check
 * that both agree (Support/ComparesEngines.php). A formula that fails gives
 * null on the site: an empty cell, or a point that is now nothing. Each test
 * says, in its comment, what is stored instead and why.
 *
 * BACKGROUND
 *
 * Results pages show the points as they were stored when an entry of the
 * prova was last saved. Saving any entry of a prova works out the points of
 * EVERY team of it again, with today's formulas and today's PHP. For these
 * provas that gives something else than what is published, so the next save
 * would change published results. Which to keep is decided per prova (the
 * report of BAJAAPP-56); these tests only record today's behaviour.
 *
 * The variables are those of the seed's teams (public results data).
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Formula/StoredPointsTest.php
 */
final class StoredPointsTest extends TestCase
{
    use ComparesEngines;

    /**
     * 22SU_ST1, 23SU_ST1-4, 24SU_ST1-4, 25SU_ST2-3 (suspension stages): the
     * best of the team's runs that count. A team with no run that counts
     * (no "SIM") has an empty list: under PHP 7 max([]) was false, so 0 is
     * stored; PHP 8 throws, and the points are null. 54 teams of the seed.
     * No published cell changes: the resultados read null as 0.
     */
    public function testSuspensionStageWithNoRunThatCounts(): void
    {
        $formula = 'MAX(0,ROUND(max(array_diff([$CORR1_1, $CORR1_2], [null]))*$PESO_1,2))';
        $this->assertFails('ValueError: max(): Argument #1 ($value) must contain at least one element', $formula, ['CORR1_1' => null, 'CORR1_2' => null, 'PESO_1' => 15]);
        $this->assertGives(12.0, $formula, ['CORR1_1' => 0.8, 'CORR1_2' => null, 'PESO_1' => 15], 'a team with a run that counts: as stored');

        $corr = '($P1_1==\'SIM\')?MAX(0,(1-($C1_1*0.2))):null';
        $this->assertGives(null, $corr, ['P1_1' => 'NÃO', 'C1_1' => 0], 'a run that does not count is null...');
        $this->assertGives(0.8, $corr, ['P1_1' => 'SIM', 'C1_1' => 1], '...one that counts loses 0.2 per cone');

        $six = 'MAX(0,ROUND(max(array_diff([$CORR2_1, $CORR2_2, $CORR2_3, $CORR2_4, $CORR2_5, $CORR2_6], [null]))*$PESO_2,2))';
        $this->assertFails('ValueError', $six, ['CORR2_1' => null, 'CORR2_2' => null, 'CORR2_3' => null, 'CORR2_4' => null, 'CORR2_5' => null, 'CORR2_6' => null, 'PESO_2' => 15], '25SU: six runs');
    }

    /**
     * 24SU_ST1-5: the average time of a side's runs. With no time on that
     * side, array_sum([]) / count([]) is 0 / 0: PHP 8 throws, so the var is
     * null. Stored is null too: these did not change the stored vars.
     */
    public function testAverageOfNoTimes(): void
    {
        $formula = 'array_sum(array_diff([$T1_2_1, $T1_2_2, $T1_2_3], [NULL]))/count(array_diff([$T1_2_1, $T1_2_2, $T1_2_3], [NULL]))';
        $this->assertFails('DivisionByZeroError: Division by zero', $formula, ['T1_2_1' => null, 'T1_2_2' => null, 'T1_2_3' => null]);
        $this->assertGives(15.0, $formula, ['T1_2_1' => 14.5, 'T1_2_2' => 15.5, 'T1_2_3' => null]);
    }

    /**
     * 22SE_SUS (min_max) and the other SUS/MAN/TRA provas: the best time of
     * the runs. No team of 22SE_SUS completed a run, so TC is null for all
     * 18 (as stored: the points come from $OBS). The difference there is
     * only PENALTS, a pontos added after the last save, which gives 0.
     */
    public function testBestTimeOfNoRun(): void
    {
        $this->assertFails('ValueError: min()', 'min(array_diff([$TC_1,$TC_2],[null]))', ['TC_1' => null, 'TC_2' => null]);
        $this->assertGives(0, '$PENAL?$PENAL:0', ['PENAL' => null], '22SE_SUS PENALTS');
    }

    /**
     * 24NE_SUS: "Ncompletaram" without "$" is an undefined constant. PHP 7
     * read it as the text 'Ncompletaram', and 'Ncompletaram' == 0 was true,
     * so 1 is stored for every team; PHP 8 throws, so Pconclusao is null and
     * SUSPTS, which multiplies by it, is 0 for every team. Published:
     * 7 of 8 teams lose 28.57 to 50 points in 24NE_SUS, _DIN and _GER.
     */
    public function testBareNameInPconclusao(): void
    {
        $vars = ['ARROBA' => 0.8, 'TotalOBS' => 7, 'Ncompletaram' => 0, 'Nparticiparam' => 8];
        $this->assertFails('Error: Undefined constant "Ncompletaram"', 'Ncompletaram == 0 ? 1:($ARROBA*(1-($Ncompletaram/$Nparticiparam)))', $vars);
        $this->assertGives(0, '(50*($Pconclusao)*($OBS/$TotalOBS))', $vars + ['Pconclusao' => null, 'OBS' => 7], 'today, with Pconclusao null');
        $this->assertGives(50, '(50*($Pconclusao)*($OBS/$TotalOBS))', $vars + ['Pconclusao' => 1, 'OBS' => 7], 'as stored, with Pconclusao 1');
    }

    /**
     * 23BR_APR: the formulas are fine, the stored values are stale. Team 14
     * has a final score (ELEF) that was typed after the last save; team 15's
     * stored sum (42.15) has 4.4 that its dados no longer have.
     */
    public function testProjectPresentationSum(): void
    {
        $formula = '($PWTF?$PWTF:$PWT)+($ELEF?$ELEF:$ELE)+($FREF?$FREF:$FRE)+($DESF?$DESF:$DES)+($SUSF?$SUSF:$SUS)+($MKTF?$MKTF:$MKT)+($CALF?$CALF:$CAL)+($GESF?$GESF:$GES)';
        $team14 = ['CAL' => 1.25, 'DES' => 3.5, 'ELE' => 7, 'FRE' => 2, 'GES' => 3.5, 'MKT' => 1, 'PWT' => 2.75, 'SUS' => 1.75, 'ELEF' => 7.9];
        $team15 = ['CAL' => 3.5, 'DES' => 5.75, 'ELE' => 3.5, 'FRE' => 5, 'GES' => 5.25, 'MKT' => 4.75, 'PWT' => 6, 'SUS' => 4];

        $this->assertEqualsWithDelta(23.65, $this->assertSameAsEval($formula, $team14)['value'], 1e-9, 'stored: 22.75, without ELEF');
        $this->assertEqualsWithDelta(37.75, $this->assertSameAsEval($formula, $team15)['value'], 1e-9, 'stored: 42.15');
        $this->assertEqualsWithDelta(71.36256482791137, $this->assertSameAsEval('160*$APR_PARC/$APRMMmax', ['APR_PARC' => 23.65, 'APRMMmax' => 53.025])['value'], 1e-9, 'stored: 68.65');
    }

    /**
     * 23SU_ET1 and 24BR_APR were never worked out (vars and pontos are NULL
     * in the database), and their formulas read fields their entries do not
     * have. No resultado reads either. Worked out today, every point is null.
     */
    public function testProvasNeverWorkedOut(): void
    {
        $this->assertFails('DivisionByZeroError', '($VELO_1>=$VELO_2)?($VELO_1**2/(25.92*$DIST_1)):0', ['VELO_1' => null, 'VELO_2' => null, 'DIST_1' => null], '23SU_ET1');
        $this->assertFails('DivisionByZeroError', '160*$APR_PARC/$APRMMmax', ['APR_PARC' => 0, 'APRMMmax' => 0.0], '24BR_APR');
    }
}
