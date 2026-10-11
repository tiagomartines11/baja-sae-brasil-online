<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Whole races, generated from a seed, checked against the truth.
 *
 * The generator (Support/SyntheticRace) invents a two-hour race with thirty
 * cars and three human counters who miss cars, swap digits, double-tap and
 * type late. Because the generator knows what really happened, these tests
 * can ask the only question that matters in the end: is the official count
 * right?
 *
 * Each test runs for several seeds, that is, several different races.
 */
final class FullRaceTest extends EngineTestCase
{
    /** @return array<string, array{0: int}> */
    public static function seeds(): array
    {
        return [
            'seed 1' => [1],
            'seed 2' => [2],
            'seed 3' => [3],
            'seed 7' => [7],
            'seed 2025' => [2025],
            'seed 31337' => [31337],
        ];
    }

    /**
     * Plays the reviewer who trusts the engine: accept every suggestion,
     * look again, and repeat until the queue has no more suggestions.
     */
    private function acceptEverySuggestion(EngineInput $input): EngineResult
    {
        $nextDecisionId = 1;
        for ($round = 0; $round < 10; $round++) {
            $result = Engine::run($input);
            $accepted = 0;
            foreach ($result->openFlags() as $flag) {
                if ($flag->sugestao === null) {
                    continue;
                }
                $decision = new Decision($nextDecisionId++, $flag->sugestao['tipo']);
                $decision->passagemId = $flag->sugestao['passagem_id'] ?? null;
                $decision->carro = $flag->sugestao['equipe_numero'] ?? null;
                $input->decisions[] = $decision;
                $accepted++;
            }
            if ($accepted === 0) {
                return $result;
            }
        }
        $this->fail('Accepting suggestions kept producing new suggestions for ten rounds.');
    }

    /**
     * @param array<int, int> $truthLapsByCar
     * @return array<int, int> car => official laps, for the same cars
     */
    private function officialCounts(EngineResult $result, array $truthLapsByCar): array
    {
        $counts = [];
        foreach (array_keys($truthLapsByCar) as $car) {
            $counts[$car] = $result->lapCount($car);
        }
        return $counts;
    }

    /**
     * SITUATION: a full race with realistic human error, in the default
     * publish mode ("confirmadas": two counters must agree).
     *
     * EXPECTED: once every suggestion has been accepted, the official lap
     * count of every car equals the number of times it really crossed the
     * line, and nothing is left in the review queue.
     */
    #[DataProvider('seeds')]
    public function testAfterAcceptingEverySuggestionTheOfficialCountEqualsTheTruth(int $seed): void
    {
        $race = SyntheticRace::generate(SyntheticRaceConfig::withSeed($seed));
        $this->assertNotEmpty($race->injectedErrors, 'The generator must have injected mistakes, or the test proves nothing.');

        $result = $this->acceptEverySuggestion($race->input());

        $this->assertSame($race->truthLapsByCar, $this->officialCounts($result, $race->truthLapsByCar));
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: the same races in "todas" mode, where every entry counts
     * unless voided. This mode is far more sensitive: a typo immediately
     * gives a lap to the wrong car, a repeat immediately adds a lap.
     *
     * EXPECTED: before review the count is wrong for at least one car (that
     * is what the mode is like); after accepting every suggestion it equals
     * the truth. The suggestions alone are enough to repair the race.
     */
    #[DataProvider('seeds')]
    public function testEvenWhenEveryEntryCountsTheSuggestionsRepairTheRace(int $seed): void
    {
        $race = SyntheticRace::generate(SyntheticRaceConfig::withSeed($seed));
        $input = $race->input();
        $input->settings->modoPublicacao = Settings::MODO_TODAS;

        $beforeReview = Engine::run($input);
        $this->assertNotSame(
            $race->truthLapsByCar,
            $this->officialCounts($beforeReview, $race->truthLapsByCar),
            'Unreviewed, "todas" mode is expected to be wrong somewhere.'
        );

        $afterReview = $this->acceptEverySuggestion($input);
        $this->assertSame($race->truthLapsByCar, $this->officialCounts($afterReview, $race->truthLapsByCar));
    }

    /**
     * SITUATION: counters who make no mistakes at all.
     *
     * EXPECTED: the count is right and the review queue is empty from
     * beginning to end. The engine must not invent problems: breakdowns,
     * pit stops and a slowing track are normal racing, not errors.
     */
    #[DataProvider('seeds')]
    public function testARaceCountedWithoutMistakesRaisesNoFlags(int $seed): void
    {
        $race = SyntheticRace::generate(SyntheticRaceConfig::withSeed($seed)->withoutHumanErrors());

        $result = Engine::run($race->input());

        $this->assertSame($race->truthLapsByCar, $this->officialCounts($result, $race->truthLapsByCar));
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: the race is watched live. Counter 2's phone is offline
     * from minute 40 to minute 55, then uploads everything. We look at the
     * server's view every five minutes.
     *
     * EXPECTED, at every look:
     * - no car ever has MORE official laps than it has really completed by
     *   then (a lap count that goes up and then down again is the worst
     *   thing a results page can do);
     * - while the phone is offline, no typo or lone-entry flag is raised for
     *   moments inside the offline period, since those depend on entries
     *   the phone has not uploaded yet.
     * And at the end, after accepting the suggestions, the count is right.
     */
    #[DataProvider('seeds')]
    public function testWatchedLiveWithAnOfflinePeriodTheCountNeverOvershoots(int $seed): void
    {
        $config = SyntheticRaceConfig::withSeed($seed);
        $config->offlinePeriods = [['source' => 2, 'fromSeconds' => 40 * 60, 'toSeconds' => 55 * 60]];
        $race = SyntheticRace::generate($config);
        $offlineFromMs = SyntheticRace::START_MS + 40 * 60000;

        for ($minute = 5; $minute <= $config->raceMinutes; $minute += 5) {
            $nowMs = SyntheticRace::START_MS + $minute * 60000;
            $result = Engine::run($race->input($nowMs));

            foreach ($race->truthLapsByCar as $car => $finalTruth) {
                $this->assertLessThanOrEqual($finalTruth, $result->lapCount($car), "Car $car at minute $minute.");
            }
            if ($minute >= 40 && $minute < 55) {
                foreach ($result->openFlags() as $flag) {
                    $dependsOnAbsence = in_array($flag->tipo, [Flag::TROCA_PROVAVEL, Flag::SOLITARIA], true);
                    $this->assertFalse(
                        $dependsOnAbsence && $flag->tsMs >= $offlineFromMs,
                        "Flag {$flag->id} at minute $minute was raised while counter 2 was offline."
                    );
                }
            }
        }

        $final = $this->acceptEverySuggestion($race->input());
        $this->assertSame($race->truthLapsByCar, $this->officialCounts($final, $race->truthLapsByCar));
    }
}
