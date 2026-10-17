<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * The flag "primeira_passagem_tardia": a car whose pass zero was probably
 * missed.
 *
 * With laps = passes - 1, the pack crossing the line seconds after the
 * start is the hardest moment of the race to count. A car whose first
 * crossing nobody caught silently loses a lap: its second crossing becomes
 * its pass zero. Nothing about that car looks wrong afterwards, except that
 * its "first" pass comes about one lap later than everybody else's.
 *
 * The rule: a car is flagged when its first pass is later than the middle
 * (median) first pass of the field plus half of the field's median first
 * lap.
 */
final class LateFirstPassTest extends EngineTestCase
{
    /**
     * Cars 1 to 5 cross for the first time at 00:30, 00:32, ... 00:38 and
     * then lap every 180 seconds (three crossings each).
     */
    private function raceWithAFieldOfFive(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5], 180, 3, '00:30', 2);
        return $race;
    }

    /**
     * SITUATION: the field of five, plus car 9. Car 9 crossed with the pack
     * at about 00:35, but in the confusion of the start nobody typed it.
     * Its next crossings, at 03:35 and 06:35, were recorded by everybody.
     *
     * WHAT WENT WRONG: all three counters missed car 9's first crossing.
     *
     * EXPECTED:
     * - As far as the entries go, car 9 has one lap (03:35 is taken as its
     *   pass zero). The truth is two.
     * - A "primeira_passagem_tardia" flag on car 9's first recorded pass,
     *   with NO suggestion: the engine cannot know whether the car started
     *   late or was missed. It says how late the pass is (180 s after the
     *   field's).
     * - When the reviewer adds the missing pass at 00:35, the flag
     *   disappears and car 9 has its two laps.
     */
    public function testACarWhoseFirstPassComesALapAfterTheFieldsIsFlaggedUntilTheMissingPassIsAdded(): void
    {
        $race = $this->raceWithAFieldOfFive();
        $firstRecordedPass = $race->pass(9, '03:35');
        $race->pass(9, '06:35');

        $before = $race->run('08:00');

        $flag = $this->assertSingleOpenFlag($before, Flag::PRIMEIRA_PASSAGEM_TARDIA);
        $this->assertSame([9], $flag->carros);
        $this->assertNull($flag->sugestao);
        $this->assertSame(array_values($firstRecordedPass), $flag->passagemIds);
        $this->assertSame(RaceScenario::ms('03:35'), $flag->tsMs);
        $this->assertStringContainsString('180 s', $flag->motivo);
        $this->assertLapCounts([9 => 1], $before);

        $race->addPass(9, '00:35');
        $after = $race->run('08:00');

        $this->assertNoOpenFlags($after);
        $this->assertLapCounts([9 => 2], $after);
    }

    /**
     * SITUATION: the same race, but car 9 really did start late: it left
     * from the pit lane three minutes after everybody.
     *
     * EXPECTED: the reviewer confirms car 9's first pass ("it is fine as it
     * is"). The flag is silenced for good and the count stays at one lap.
     */
    public function testConfirmingTheLatePassSilencesTheFlag(): void
    {
        $race = $this->raceWithAFieldOfFive();
        $firstRecordedPass = $race->pass(9, '03:35');
        $race->pass(9, '06:35');
        $race->confirm($firstRecordedPass[2]);

        $result = $race->run('08:00');

        $this->assertNoOpenFlags($result);
        $this->assertLapCounts([9 => 1], $result);
    }

    /**
     * SITUATION: the field's median first pass is at 00:35 (with car 9
     * counted in) and its median first lap is 180 s, so "too late" starts
     * after 00:35 + 90 s = 02:05.
     *
     * EXPECTED: a car whose first pass is at 02:05 sharp is not flagged
     * (a slow starter, a spin on the first lap). One millisecond later, it
     * is.
     */
    public function testTheLimitIsTheFieldsMedianFirstPassPlusHalfItsMedianFirstLap(): void
    {
        $onTheLimit = $this->raceWithAFieldOfFive();
        $onTheLimit->pass(9, '02:05');
        $onTheLimit->pass(9, '05:05');
        $this->assertNoOpenFlags($onTheLimit->run('08:00'));

        $justOver = $this->raceWithAFieldOfFive();
        $justOver->pass(9, '02:05.001');
        $justOver->pass(9, '05:05.001');
        $this->assertSingleOpenFlag($justOver->run('08:00'), Flag::PRIMEIRA_PASSAGEM_TARDIA);
    }

    /**
     * SITUATION: only two cars are racing. Car 1 crosses at 00:30 and laps
     * every three minutes; car 9's first pass is at 03:35.
     *
     * EXPECTED: no flag. With fewer than three cars there is no "field" to
     * compare a car with.
     */
    public function testWithFewerThanThreeCarsThereIsNoFieldToCompareWith(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1], 180, 3, '00:30');
        $race->pass(9, '03:35');
        $race->pass(9, '06:35');

        $this->assertNoOpenFlags($race->run('08:00'));

        // A third car is enough to make a field.
        $race->steadyField([2], 180, 3, '00:32');
        $this->assertSingleOpenFlag($race->run('08:00'), Flag::PRIMEIRA_PASSAGEM_TARDIA);
    }

    /**
     * SITUATION: the race of the first test, under the other rule
     * (`passagem_inicial` = "largada"), where the start itself is pass zero
     * and the first crossing is not supposed to be typed.
     *
     * EXPECTED: no such flag. There is no pass zero to miss.
     */
    public function testTheFlagDoesNotExistWhenTheStartItselfIsPassZero(): void
    {
        $race = $this->raceWithAFieldOfFive();
        $race->settings->passagemInicial = Settings::INICIAL_LARGADA;
        $race->pass(9, '03:35');
        $race->pass(9, '06:35');

        $this->assertNoOpenFlags($race->run('08:00'));
    }
}
