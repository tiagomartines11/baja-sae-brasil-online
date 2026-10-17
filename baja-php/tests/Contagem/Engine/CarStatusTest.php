<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\CarState;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Car status: is each car still running?
 *
 *   correndo   passing normally
 *   atrasado   overdue: more than 1.5 times its expected lap since its last pass
 *   parado     stopped: more than 2 times its expected lap
 *   no_box     logged in the pit lane
 *   fora       before the start or after the finish
 *
 * "Expected lap" is not one number for everybody. It is the pace of the
 * whole field in the last 15 minutes, multiplied by how this particular car
 * compares with the field. A slow car is given more time before it is
 * called late than a fast one.
 *
 * A long gap is NOT treated as "the counters missed a pass". In real races
 * about one lap in six is 1.5 times longer than the car's usual lap, for
 * perfectly ordinary reasons. The gap only changes the car's status.
 */
final class CarStatusTest extends EngineTestCase
{
    /** Cars 11 to 20 lap steadily; this is the "field" that defines the pace. */
    private const FIELD = [11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

    /**
     * Builds a race where every field car crosses the line at 03:00 (plus
     * 7 seconds per position behind car 11) and then keeps lapping with the
     * given lap times. Car 11 stops after its 8th crossing, which is what
     * the tests look at. Crossings inside $noPassesBetween are left out
     * (used for a red flag, when nobody is on track).
     *
     * @param int[] $lapSeconds duration of each successive lap
     * @param array{0: int, 1: int}|null $noPassesBetween seconds on the race clock
     * @return array{0: RaceScenario, 1: int} the race and the second at which car 11 last crossed
     */
    private function fieldWhereCar11Stops(array $lapSeconds, ?array $noPassesBetween = null): array
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $lastCrossingOfCar11 = 0;

        foreach (self::FIELD as $position => $car) {
            $crossing = 180 + $position * 7;
            $crossingsDone = 0;
            foreach (array_merge([0], $lapSeconds) as $lap) {
                $crossing += $lap;
                $crossingsDone++;
                if ($car === 11 && $crossingsDone > 8) {
                    break;
                }
                if ($noPassesBetween !== null && $crossing >= $noPassesBetween[0] && $crossing < $noPassesBetween[1]) {
                    continue;
                }
                $race->pass($car, RaceScenario::clock($crossing));
                if ($car === 11) {
                    $lastCrossingOfCar11 = $crossing;
                }
            }
        }
        return [$race, $lastCrossingOfCar11];
    }

    /**
     * SITUATION: the field laps in 180 seconds. Car 1 is quick (150-second
     * laps) and car 2 is slow (240-second laps). Both last crossed the line
     * at 20:00 and have not been seen since.
     *
     * EXPECTED at 24:40 (280 seconds later): car 1 is "atrasado", because
     * 280 s is more than 1.5 of ITS laps (225 s). Car 2 is still "correndo":
     * for a 240-second car, 280 s is barely more than one lap.
     *
     * EXPECTED at 26:30 (390 seconds later): car 1 is "parado" (more than
     * two of its laps, 300 s) and car 2 has become "atrasado" (more than
     * 360 s). The same silence means different things for different cars.
     */
    public function testAFastCarIsCalledLateSoonerThanASlowCar(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField(self::FIELD, 180, 12);
        for ($lap = 0; $lap <= 7; $lap++) {
            $race->pass(1, RaceScenario::clock(150 + $lap * 150));
        }
        for ($lap = 0; $lap <= 4; $lap++) {
            $race->pass(2, RaceScenario::clock(240 + $lap * 240));
        }

        $early = $race->run('24:40');
        $this->assertSame(150000, $early->cars[1]->voltaEsperadaMs, 'Car 1 is expected to lap in 150 s.');
        $this->assertSame(240000, $early->cars[2]->voltaEsperadaMs, 'Car 2 is expected to lap in 240 s.');
        $this->assertSame(CarState::ATRASADO, $early->cars[1]->status);
        $this->assertSame(RaceScenario::ms('23:45'), $early->cars[1]->statusDesdeMs, 'Late since 20:00 + 225 s.');
        $this->assertSame(CarState::CORRENDO, $early->cars[2]->status);
        $this->assertSame(CarState::CORRENDO, $early->cars[15]->status, 'A field car that keeps passing is fine.');

        $later = $race->run('26:30');
        $this->assertSame(CarState::PARADO, $later->cars[1]->status);
        $this->assertSame(RaceScenario::ms('25:00'), $later->cars[1]->statusDesdeMs, 'Stopped since 20:00 + 300 s.');
        $this->assertSame(CarState::ATRASADO, $later->cars[2]->status);
    }

    /**
     * SITUATION: two races. In both, car 11 is an average car that last
     * crossed the line and then went silent for 320 seconds.
     *
     * Race A: the track is dry and everybody laps in 180 seconds all along.
     * Race B: it started to rain; laps went from 180 to 300 seconds.
     *
     * EXPECTED: in race A car 11 is "atrasado" (320 s is more than 1.5 laps
     * of 180 s). In race B it is still "correndo": on a 300-second track,
     * 320 s is one ordinary lap. The threshold follows the pace of the field.
     */
    public function testWhenTheWholeFieldSlowsDownCarsAreGivenMoreTime(): void
    {
        [$dryRace, $lastSeenDry] = $this->fieldWhereCar11Stops([180, 180, 180, 180, 180, 180, 180, 180, 180]);
        $dry = $dryRace->run(RaceScenario::clock($lastSeenDry + 320));
        $this->assertSame(CarState::ATRASADO, $dry->cars[11]->status);

        [$wetRace, $lastSeenWet] = $this->fieldWhereCar11Stops([180, 180, 180, 300, 300, 300, 300, 300, 300]);
        $wet = $wetRace->run(RaceScenario::clock($lastSeenWet + 320));
        $this->assertSame(300000, $wet->cars[11]->voltaEsperadaMs, 'The expected lap followed the field to 300 s.');
        $this->assertSame(CarState::CORRENDO, $wet->cars[11]->status);
    }

    /**
     * SITUATION: car 11 last crossed at 24:00. One minute later race control
     * shows the red flag; the race is stopped for ten minutes (25:00 to
     * 35:00) and then restarted. It is now 36:40.
     *
     * By the wall clock car 11 has been silent for 760 seconds, which would
     * make it "parado". But for 600 of those seconds nobody was racing.
     *
     * EXPECTED: "correndo". Only 160 seconds of racing have passed. The
     * stopwatch behind the status does not run during a red flag.
     */
    public function testARedFlagPausesTheClockBehindTheStatus(): void
    {
        $laps = [180, 180, 180, 180, 180, 180, 180, 180, 180, 180, 180, 180];
        $redFlagFrom = 25 * 60;
        $redFlagTo = 35 * 60;

        [$race, $lastSeen] = $this->fieldWhereCar11Stops($laps, [$redFlagFrom, $redFlagTo]);
        $this->assertSame(24 * 60, $lastSeen);
        $race->ocorrencia(Ocorrencia::BANDEIRA_VERMELHA, '25:00');
        $race->ocorrencia(Ocorrencia::RELARGADA, '35:00');

        $duringTheStop = $race->run('30:00');
        $this->assertSame(CarState::CORRENDO, $duringTheStop->cars[11]->status);

        $afterRestart = $race->run('36:40');
        $this->assertSame(CarState::CORRENDO, $afterRestart->cars[11]->status);

        // The same silence with no red flag is a stopped car.
        [$raceWithoutFlag] = $this->fieldWhereCar11Stops($laps);
        $this->assertSame(CarState::PARADO, $raceWithoutFlag->run('36:40')->cars[11]->status);
    }

    /**
     * SITUATION: car 11 last crossed at 24:00 and it is now 30:40, 400
     * seconds later: by the numbers, "parado". But counter 3's phone has
     * been offline since 25:40. If car 11 did pass again, counter 3 may be
     * the one holding the proof.
     *
     * EXPECTED: the status does not change while a counter has not synced
     * past the moment the car would have become late (24:00 + 270 s =
     * 28:30). Once counter 3 has synced past that moment the car becomes
     * "atrasado"; it becomes "parado" only when everybody has synced past
     * 24:00 + 360 s = 30:00.
     */
    public function testNoStatusChangeWhileACounterHasNotSyncedPastTheThreshold(): void
    {
        [$race, $lastSeen] = $this->fieldWhereCar11Stops([180, 180, 180, 180, 180, 180, 180, 180, 180, 180, 180]);
        $this->assertSame(24 * 60, $lastSeen);

        $race->syncedThrough(3, '25:40');
        $this->assertSame(CarState::CORRENDO, $race->run('30:40')->cars[11]->status);

        $race->syncedThrough(3, '29:00');
        $this->assertSame(CarState::ATRASADO, $race->run('30:40')->cars[11]->status);

        $race->syncedThrough(3, '30:40');
        $this->assertSame(CarState::PARADO, $race->run('30:40')->cars[11]->status);
    }

    /**
     * SITUATION: the pit log records car 11 entering the pit lane at 25:00,
     * and no exit yet.
     *
     * EXPECTED: "no_box" since 25:00, however long it stays. A car in the
     * pit lane is not late; we know where it is.
     */
    public function testACarLoggedInThePitLaneIsNoBoxHoweverLongItStays(): void
    {
        [$race] = $this->fieldWhereCar11Stops([180, 180, 180, 180, 180, 180, 180, 180, 180, 180, 180]);
        $race->pitIn(11, '25:00');

        $result = $race->run('33:00');

        $this->assertSame(CarState::NO_BOX, $result->cars[11]->status);
        $this->assertSame(RaceScenario::ms('25:00'), $result->cars[11]->statusDesdeMs);
    }

    /**
     * EXPECTED: before race control starts the race, and after it ends it,
     * every car is "fora". Nobody is late for a race that is not running.
     */
    public function testBeforeTheStartAndAfterTheFinishEveryCarIsFora(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([1, 2]);
        $this->assertSame(CarState::FORA, $race->run('01:00')->cars[1]->status, 'No start yet.');

        $race->start('02:00');
        $race->pass(1, '05:00');
        $this->assertSame(CarState::CORRENDO, $race->run('06:00')->cars[1]->status);

        $race->ocorrencia(Ocorrencia::ENCERRAMENTO, '10:00');
        $this->assertSame(CarState::FORA, $race->run('11:00')->cars[1]->status, 'The race is over.');
    }

    /**
     * SITUATION: the first minutes of a race. Hardly any laps have been
     * completed, so the engine does not yet know what a normal lap is.
     *
     * EXPECTED: everybody is "correndo". With fewer than eight recent laps
     * to go by, the engine prefers saying nothing to guessing.
     */
    public function testEarlyInTheRaceNobodyIsCalledLate(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(1, '03:00');
        $race->pass(2, '03:05');

        $result = $race->run('15:00');

        $this->assertNull($result->cars[1]->voltaEsperadaMs);
        $this->assertSame(CarState::CORRENDO, $result->cars[1]->status);
    }
}
