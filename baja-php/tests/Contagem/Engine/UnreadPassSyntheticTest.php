<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Engine;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;

/**
 * Whole generated races in which counters press "??" and a timing sensor
 * marks the line, checked against the truth: is what the engine applied by
 * itself right, and does the safe mode still never put a car ahead of what
 * it drove?
 *
 * The races are the usual ones (thirty cars, two hours, three counters with
 * every kind of mistake, a handover, a finish and the race-day trouble),
 * plus: each counter presses "??" on 2 % of the crossings it would have
 * typed right, and a precise sensor that misses 2 % of the crossings and
 * marks one crossing that did not happen every minute.
 */
final class UnreadPassSyntheticTest extends EngineTestCase
{
    private const SEEDS = 16;

    /**
     * EXPECTED: of the "??" the engine gave a car by itself, at least 99 %
     * got the car that really crossed (over these races: every one), and so
     * did the sensor marks it matched to a pass. Most "??" are applied; the
     * rest are flagged, with the right car among the candidates. In the
     * safe mode no official pass of any car ever comes before the car
     * really made it, and no car ends with more laps than it drove.
     */
    public function testAutomaticAssignmentsAreRightAndNoCarIsAheadOfTheTruth(): void
    {
        $tally = self::emptyTally();
        for ($seed = 1; $seed <= self::SEEDS; $seed++) {
            $race = SyntheticRace::generate($this->config($seed));
            $result = Engine::run($race->input());

            $this->assertGreaterThan(900, $this->assertNoCarIsEverAheadOfTheTruth($race, $result, "Seed $seed."));
            foreach ($race->truthLaps('primeira_passagem') as $car => $trueLaps) {
                $this->assertLessThanOrEqual($trueLaps, $result->lapCount($car), "Seed $seed: car $car has more laps than it drove.");
            }
            $tally = self::addUp($tally, self::measure($race, $result));
        }

        $this->assertGreaterThan(100, $tally['ilegiveis'], 'the races must have "??" to judge');
        $this->assertGreaterThanOrEqual(0.99, $tally['certas'] / max(1, $tally['atribuidas']), self::describe($tally));
        $this->assertGreaterThan(0.6, $tally['atribuidas'] / $tally['ilegiveis'], 'most "??" are settled without a person: ' . self::describe($tally));
        $this->assertGreaterThanOrEqual(0.99, $tally['marcas_certas'] / max(1, $tally['marcas_atribuidas']), self::describe($tally));
        $this->assertGreaterThan(0.7, $tally['marcas_atribuidas'] / $tally['marcas_reais'], 'most passes get the sensor time: ' . self::describe($tally));
        $this->assertGreaterThanOrEqual(0.95, $tally['candidato_certo'] / max(1, $tally['sinalizadas_com_carro']), 'a flagged "??" lists the right car: ' . self::describe($tally));
    }

    /**
     * EXPECTED: the same race without "??" and without a sensor is the
     * race the seed always gave: the new random draws leave it alone.
     */
    public function testTheNewKnobsAtZeroLeaveTheRaceAsItWas(): void
    {
        $plain = SyntheticRace::generate(SyntheticRaceConfig::withSeed(5));
        $config = SyntheticRaceConfig::withSeed(5);
        $config->unreadRate = 0.0;
        $config->timingSensor = false;
        $again = SyntheticRace::generate($config);
        $this->assertEquals($plain->input()->entries, $again->input()->entries);
    }

    private function config(int $seed): SyntheticRaceConfig
    {
        $config = SyntheticRaceConfig::withSeed($seed)->withFinish()->withRaceDayTrouble();
        $config->unreadRate = 0.02;
        $config->timingSensor = true;
        $config->sensorMissRate = 0.02;
        $config->sensorFalseTriggersPerMinute = 1.0;
        return $config;
    }

    /**
     * What one race says about the unread passes: how many there were, how
     * many the engine applied and how many of those are right, the same for
     * the sensor's marks, and how the flagged ones fare.
     *
     * @return array<string, int>
     */
    public static function measure(SyntheticRace $race, EngineResult $result): array
    {
        $tally = self::emptyTally();
        $sensorId = $race->sensorSourceId();
        $flagged = [];
        foreach ($result->openFlags() as $flag) {
            if ($flag->tipo === Flag::NUMERO_ILEGIVEL) {
                $flagged[$flag->passagemIds[0]] = $flag;
            }
        }
        $input = $race->input();
        foreach ($input->entries as $entry) {
            if (!$entry->isUnread()) {
                continue;
            }
            $realCar = $race->realCarBehindEntry($entry->id);
            if ($entry->fonteId === $sensorId) {
                $tally['marcas']++;
                $tally['marcas_reais'] += $realCar === null ? 0 : 1;
                if (isset($flagged[$entry->id])) {
                    $tally['marcas_sinalizadas']++;
                }
                continue;
            }
            $tally['ilegiveis']++;
            $effective = $result->effectiveEntries[$entry->id] ?? null;
            if ($effective !== null && $effective->atribuidoAutomaticamente) {
                $tally['atribuidas']++;
                $tally['certas'] += $effective->carro === $realCar ? 1 : 0;
            } elseif (isset($flagged[$entry->id])) {
                $tally['sinalizadas']++;
                $cars = array_column($flagged[$entry->id]->candidatos ?? [], 'carro');
                if ($cars !== []) {
                    $tally['sinalizadas_com_carro']++;
                    $tally['candidato_certo'] += in_array($realCar, $cars, true) ? 1 : 0;
                }
            }
        }
        foreach ($result->groups as $group) {
            $mark = $group->marcaSensor;
            if ($mark === null) {
                continue;
            }
            $tally['marcas_atribuidas']++;
            $tally['marcas_certas'] += $race->realCarBehindEntry($mark->id) === $group->carro ? 1 : 0;
        }
        return $tally;
    }

    /** @return array<string, int> */
    public static function emptyTally(): array
    {
        return array_fill_keys([
            'ilegiveis', 'atribuidas', 'certas', 'sinalizadas', 'sinalizadas_com_carro', 'candidato_certo',
            'marcas', 'marcas_reais', 'marcas_atribuidas', 'marcas_certas', 'marcas_sinalizadas',
        ], 0);
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    public static function addUp(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $a[$key] += $value;
        }
        return $a;
    }

    /** @param array<string, int> $tally */
    public static function describe(array $tally): string
    {
        return json_encode($tally);
    }
}
