<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\CarStatus;
use Baja\Contagem\Engine\Data\CarState;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;

/**
 * The status rule by itself: `CarStatus::statusAt()`.
 *
 * CarStatusTest shows what the statuses mean in a race. The rule is also
 * available as a plain function of a few numbers (the car's last pass, its
 * expected lap, the race timeline), because the results page asks "and
 * now?" every few seconds from a stored snapshot, without recomputing the
 * race. These tests pin the rule down and prove that the function and the
 * full engine never disagree.
 */
final class CarStatusRuleTest extends EngineTestCase
{
    private const FIELD = [11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

    /**
     * SITUATION: the field laps in 180 seconds. Car 1 last crossed at 21:00,
     * went into the pit lane at 22:00 and left it at 30:00.
     *
     * WHAT COULD GO WRONG: measured from its last pass, car 1 has been
     * "silent" for nine minutes when it leaves the pits, and would be shown
     * as "parado" the moment it rejoins, after every single pit stop.
     *
     * EXPECTED: we know where the car was, so the wait is measured from the
     * later of its last pass and its last pit exit:
     *   29:00  no_box (since 22:00)
     *   33:00  correndo: only 180 s since it left the pits
     *   35:00  atrasado since 34:30 (pit exit + 1.5 laps)
     *   36:30  parado since 36:00 (pit exit + 2 laps)
     */
    public function testAfterAPitStopTheWaitIsMeasuredFromThePitExit(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField(self::FIELD, 180, 12);
        for ($crossing = 1; $crossing <= 7; $crossing++) {
            $race->pass(1, RaceScenario::clock($crossing * 180));
        }
        $race->pitIn(1, '22:00');
        $race->pitOut(1, '30:00');

        $inThePits = $race->run('29:00')->cars[1];
        $this->assertSame([CarState::NO_BOX, RaceScenario::ms('22:00')], [$inThePits->status, $inThePits->statusDesdeMs]);

        $justOut = $race->run('33:00')->cars[1];
        $this->assertSame(180000, $justOut->voltaEsperadaMs);
        $this->assertSame([CarState::CORRENDO, null], [$justOut->status, $justOut->statusDesdeMs]);

        $late = $race->run('35:00')->cars[1];
        $this->assertSame([CarState::ATRASADO, RaceScenario::ms('34:30')], [$late->status, $late->statusDesdeMs]);

        $stopped = $race->run('36:30')->cars[1];
        $this->assertSame([CarState::PARADO, RaceScenario::ms('36:00')], [$stopped->status, $stopped->statusDesdeMs]);
    }

    /**
     * SITUATION: car 1 made a short pit stop early on (10:00 to 11:00) and
     * has passed many times since; its last pass was at 21:00.
     *
     * EXPECTED: the pit exit is older than the last pass, so it plays no
     * part: at 25:40 the car is "atrasado" since 21:00 + 270 s = 25:30.
     */
    public function testAnOldPitExitDoesNotReplaceAMoreRecentPass(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField(self::FIELD, 180, 12);
        foreach ([180, 360, 540, 720, 900, 1080, 1260] as $crossing) {
            $race->pass(1, RaceScenario::clock($crossing));
        }
        $race->pitIn(1, '10:00');
        $race->pitOut(1, '11:00');

        $car = $race->run('25:40')->cars[1];

        $this->assertSame([CarState::ATRASADO, RaceScenario::ms('25:30')], [$car->status, $car->statusDesdeMs]);
    }

    /**
     * The rule, number by number. The race started at 00:00; the car last
     * passed at 10:00 and is expected to lap in 100 seconds; every counter
     * has uploaded everything.
     *
     * EXPECTED:
     *   up to 150 s after the pass (1.5 laps), inclusive   correndo
     *   after that, up to 200 s (2 laps), inclusive        atrasado since 12:30
     *   after that                                         parado since 13:20
     */
    public function testTheThresholdsAreOneAndAHalfAndTwoExpectedLapsInclusive(): void
    {
        $timeline = RaceTimeline::fromOcorrencias([new Ocorrencia(1, Ocorrencia::LARGADA, RaceScenario::ms('00:00'))]);
        $lastPassMs = RaceScenario::ms('10:00');
        $statusAt = fn(string $now) => CarStatus::statusAt(RaceScenario::ms($now), 7, $lastPassMs, 100000, $timeline, new Settings(), fn(int $momentMs) => true);

        $this->assertSame([CarState::CORRENDO, null], $statusAt('12:30'));
        $this->assertSame([CarState::ATRASADO, RaceScenario::ms('12:30')], $statusAt('12:30.001'));
        $this->assertSame([CarState::ATRASADO, RaceScenario::ms('12:30')], $statusAt('13:20'));
        $this->assertSame([CarState::PARADO, RaceScenario::ms('13:20')], $statusAt('13:20.001'));
    }

    /**
     * EXPECTED, for the special cases of the same rule:
     * - before the start the car is "fora", whatever else is known;
     * - with no expected lap (pace unknown) nobody is late: "correndo";
     * - a car that never passed is timed from the start of the race;
     * - the thresholds follow the settings `fator_atrasado`/`fator_parado`.
     */
    public function testTheSpecialCasesOfTheRule(): void
    {
        $timeline = RaceTimeline::fromOcorrencias([new Ocorrencia(1, Ocorrencia::LARGADA, RaceScenario::ms('01:00'))]);
        $everybodySynced = fn(int $momentMs) => true;
        $settings = new Settings();

        $this->assertSame([CarState::FORA, null], CarStatus::statusAt(RaceScenario::ms('00:30'), 7, null, 100000, $timeline, $settings, $everybodySynced));
        $this->assertSame([CarState::CORRENDO, null], CarStatus::statusAt(RaceScenario::ms('50:00'), 7, RaceScenario::ms('02:00'), null, $timeline, $settings, $everybodySynced));
        $this->assertSame(
            [CarState::PARADO, RaceScenario::ms('04:20')],
            CarStatus::statusAt(RaceScenario::ms('05:00'), 7, null, 100000, $timeline, $settings, $everybodySynced),
            'Never passed: two expected laps after the start at 01:00.'
        );

        $patient = new Settings();
        $patient->fatorAtrasado = 3.0;
        $patient->fatorParado = 5.0;
        $this->assertSame([CarState::CORRENDO, null], CarStatus::statusAt(RaceScenario::ms('05:00'), 7, null, 100000, $timeline, $patient, $everybodySynced));
        $this->assertSame(
            [CarState::ATRASADO, RaceScenario::ms('06:00')],
            CarStatus::statusAt(RaceScenario::ms('07:00'), 7, null, 100000, $timeline, $patient, $everybodySynced)
        );
    }

    /**
     * SITUATION: the car is 250 s past its last pass (expected lap 100 s):
     * by the numbers, "parado" since 13:20, having been "atrasado" since
     * 12:30. But not every counter has uploaded that far.
     *
     * EXPECTED: the rule asks "has everybody synced through the moment the
     * car WOULD have changed status?" and believes only what is confirmed:
     * synced through 13:00 -> "atrasado" (12:30 is covered, 13:20 is not);
     * synced through 12:00 -> still "correndo".
     */
    public function testAStatusIsOnlyGivenOnceEverybodyHasSyncedPastItsThreshold(): void
    {
        $timeline = RaceTimeline::fromOcorrencias([new Ocorrencia(1, Ocorrencia::LARGADA, RaceScenario::ms('00:00'))]);
        $lastPassMs = RaceScenario::ms('10:00');
        $statusWhenSyncedThrough = fn(string $syncedThrough) => CarStatus::statusAt(
            RaceScenario::ms('14:10'),
            7,
            $lastPassMs,
            100000,
            $timeline,
            new Settings(),
            fn(int $momentMs) => $momentMs <= RaceScenario::ms($syncedThrough)
        );

        $this->assertSame([CarState::PARADO, RaceScenario::ms('13:20')], $statusWhenSyncedThrough('13:20'));
        $this->assertSame([CarState::ATRASADO, RaceScenario::ms('12:30')], $statusWhenSyncedThrough('13:00'));
        $this->assertSame([CarState::CORRENDO, null], $statusWhenSyncedThrough('12:00'));
    }

    /**
     * SITUATION: whole synthetic races (pit stops, breakdowns, a phone
     * offline for a quarter of an hour, a finish), looked at every four
     * minutes. At each look the engine gives every car a status. We then
     * ask the plain function the same question, giving it only what a
     * stored snapshot holds: the car's last pass and expected lap.
     *
     * EXPECTED: the same status and the same "since when", for every car at
     * every look. And the races are varied enough that all five statuses
     * were seen.
     */
    public function testTheRuleAgreesWithTheEngineForEveryCarAtEveryMoment(): void
    {
        $statusesSeen = [];
        foreach ([1, 2, 3] as $seed) {
            $config = SyntheticRaceConfig::withSeed($seed)->withFinish();
            $config->offlinePeriods = [['source' => 2, 'fromSeconds' => 40 * 60, 'toSeconds' => 55 * 60]];
            $race = SyntheticRace::generate($config);

            for ($minute = 4; $minute <= $config->raceMinutes + 4; $minute += 4) {
                $input = $race->input(SyntheticRace::START_MS + $minute * 60000);
                $result = Engine::run($input);

                foreach ($result->cars as $carro => $car) {
                    $byTheRule = CarStatus::statusAt(
                        $input->nowMs,
                        $carro,
                        $car->ultimaPassagemMs,
                        $car->voltaEsperadaMs,
                        $result->timeline,
                        $input->settings,
                        fn(int $momentMs) => $this->everySourceHasSyncedThrough($input, $momentMs)
                    );
                    $this->assertSame([$car->status, $car->statusDesdeMs], $byTheRule, "Seed $seed, minute $minute, car $carro.");
                    $statusesSeen[$car->status] = true;
                }
            }
        }

        ksort($statusesSeen);
        $this->assertSame(
            [CarState::ATRASADO, CarState::CORRENDO, CarState::FORA, CarState::NO_BOX, CarState::PARADO],
            array_keys($statusesSeen)
        );
    }

    private function everySourceHasSyncedThrough(EngineInput $input, int $momentMs): bool
    {
        foreach ($input->sources as $source) {
            if ($source->sincronizadoAteMs === null || $source->sincronizadoAteMs < $momentMs) {
                return false;
            }
        }
        return true;
    }
}
