<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\SyntheticMistakes;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;
use Baja\Tests\Contagem\Engine\Support\SyntheticReviewer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Whole races, generated from a seed, checked against the truth.
 *
 * The generator (Support/SyntheticRace) invents a two-hour race with thirty
 * cars and three human counters who miss cars, swap digits, double-tap,
 * type late, hand over to a colleague mid-race, and sometimes get the same
 * crossing wrong two at a time. Because the generator knows what really
 * happened, these tests can ask the only question that matters in the end:
 * is the official count right?
 *
 * The truth about laps is "crossings minus one": a car's first crossing of
 * the counting line is its pass zero and completes no lap.
 *
 * Each test runs for several seeds, that is, several different races, and
 * under both rules for the first pass: by default laps are crossings minus
 * one; with `passagem_inicial` = "largada" every crossing is a lap.
 *
 * The big races also carry "race-day trouble", which is nobody's typing
 * mistake but still work for a reviewer: the start is recorded a moment
 * late (the first cars' first crossing is typed before it), two cars that
 * are not classified go out for three laps, one phone stamps estimated
 * times for four minutes and another has no usable time for two.
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

    /** @return array<string, array{0: int, 1: string}> seed, pass-zero rule */
    public static function seedsAndRules(): array
    {
        $cases = [];
        foreach (self::seeds() as $name => [$seed]) {
            foreach (Settings::PASSAGENS_INICIAIS as $passagemInicial) {
                $cases["$name, $passagemInicial"] = [$seed, $passagemInicial];
            }
        }
        return $cases;
    }

    /**
     * SITUATION: a full race with every kind of human error and all the
     * race-day trouble, in the default publish mode ("confirmadas": two
     * counters must agree).
     *
     * WHAT WENT WRONG: everything the generator knows how to do. Among it:
     * - a counter repeats a car and a second counter types the same car
     *   very late, so two counters "agree" on a crossing that never
     *   happened and the car gets a lap too many;
     * - a real crossing that two of the three counters got wrong, so it
     *   rests on one counter's word.
     *
     * EXPECTED, in three steps:
     * 1. Before anybody reviews anything, the count is wrong for some car.
     * 2. After a reviewer accepts every suggestion, every extra lap is gone.
     *    What is left is exactly what no algorithm can settle: each crossing
     *    that rests on one counter's word still waits, its car is one lap
     *    short, and a flag WITHOUT a suggestion points at it. The engine
     *    does not guess here, and this test does not pretend it does.
     * 3. After a reviewer settles those with the video, the official count
     *    equals the truth for every car and the review queue is empty.
     *
     * The race-day trouble, in the same three steps:
     * 1. Unreviewed, each kind is flagged, and under the default rule the
     *    cars that crossed before the recorded start are one lap short.
     * 2. Accepting the suggestions gives those cars their initial pass
     *    back. Estimated times never changed a count; entries of unknown
     *    time and unclassified cars never counted.
     * 3. The video reviewer confirms the estimated ones, voids the unknown
     *    ones and discards the unclassified cars' passes. Those two cars
     *    end with no official pass at all.
     */
    #[DataProvider('seedsAndRules')]
    public function testSuggestionsRepairWhatTheEngineCanJudgeAndAPersonSettlesTheRest(int $seed, string $passagemInicial): void
    {
        $race = SyntheticRace::generate(SyntheticRaceConfig::withSeed($seed)->withRaceDayTrouble());
        $this->assertEveryKindOfMistakeHappened($race);
        $truth = $race->truthLaps($passagemInicial);
        $input = $race->input();
        $input->settings->passagemInicial = $passagemInicial;

        $unreviewed = Engine::run($input);
        $beforeReview = $this->officialCounts($unreviewed, $race);
        $this->assertNotSame($truth, $beforeReview, 'Unreviewed, the count is expected to be wrong somewhere.');
        $this->assertTheRaceDayTroubleIsThereAndFlagged($race, $unreviewed, $passagemInicial);

        $afterSuggestions = SyntheticReviewer::acceptEverySuggestion($input);
        $expectedAfterSuggestions = $truth;
        foreach ($race->mistakes->lapsOnlyAPersonCanRestore as $car => $lapsWaiting) {
            $expectedAfterSuggestions[$car] -= $lapsWaiting;
        }
        $this->assertSame($expectedAfterSuggestions, $this->officialCounts($afterSuggestions, $race));
        $this->assertNotSame($beforeReview, $this->officialCounts($afterSuggestions, $race), 'Accepting the suggestions must have changed the count.');
        foreach ($race->mistakes->momentsOnlyAPersonCanSettle as $moment) {
            $this->assertTrue(
                $this->hasOpenFlagWithoutSuggestion($afterSuggestions, $moment['car'], $moment['tsMs']),
                sprintf('Car %d has a crossing only a person can settle, and no flag points at it.', $moment['car'])
            );
        }

        $afterTheVideo = SyntheticReviewer::settleEverythingWithTheVideo($race, $input);
        $this->assertSame($truth, $this->officialCounts($afterTheVideo, $race));
        $this->assertNoOpenFlags($afterTheVideo);
        $this->assertNoUnclassifiedCarHasAnOfficialPass($race, $afterTheVideo);
        $this->assertSame([], $afterTheVideo->unknownTimeEntries, 'Every entry of unknown time was dealt with.');
    }

    /**
     * SITUATION: the same races in "todas" mode, where every entry counts
     * unless voided. This mode is far more sensitive: a typo immediately
     * gives a lap to the wrong car, a repeat immediately adds a lap.
     *
     * EXPECTED: before review the count is wrong for at least one car (that
     * is what the mode is like); after both reviewers it equals the truth.
     * Even in this mode an unclassified car and an entry of unknown time
     * never count.
     */
    #[DataProvider('seedsAndRules')]
    public function testEvenWhenEveryEntryCountsReviewRepairsTheRace(int $seed, string $passagemInicial): void
    {
        $race = SyntheticRace::generate(SyntheticRaceConfig::withSeed($seed)->withRaceDayTrouble());
        $truth = $race->truthLaps($passagemInicial);
        $input = $race->input();
        $input->settings->modoPublicacao = Settings::MODO_TODAS;
        $input->settings->passagemInicial = $passagemInicial;

        $beforeReview = Engine::run($input);
        $this->assertNotSame($truth, $this->officialCounts($beforeReview, $race), 'Unreviewed, "todas" mode is expected to be wrong somewhere.');
        $this->assertNoUnclassifiedCarHasAnOfficialPass($race, $beforeReview);

        $afterReview = SyntheticReviewer::settleEverythingWithTheVideo($race, $input);
        $this->assertSame($truth, $this->officialCounts($afterReview, $race));
        $this->assertNoOpenFlags($afterReview);
        $this->assertNoUnclassifiedCarHasAnOfficialPass($race, $afterReview);
    }

    /** @return array<string, array{0: string, 1: bool}> kind of mistake, and whether suggestions alone repair it */
    public static function kindsOfMistake(): array
    {
        return [
            'a counter misses a car' => [SyntheticMistakes::MISS, true],
            'a counter types another number' => [SyntheticMistakes::TYPO, true],
            'a double tap' => [SyntheticMistakes::DOUBLE_TAP, true],
            'a repeat 8 to 15 s later' => [SyntheticMistakes::REPEAT, true],
            'a counter types 6 to 12 s late' => [SyntheticMistakes::LATE, true],
            'a counter types 22 to 30 s late' => [SyntheticMistakes::LATE_BEYOND_WINDOW, true],
            'a typo onto a car that is itself passing' => [SyntheticMistakes::TYPO_ONTO_PASSING_CAR, true],
            'a repeat plus a very late counter' => [SyntheticMistakes::REPEAT_AND_LATECOMER, true],
            'two counters miss the same crossing' => [SyntheticMistakes::LONE_REAL_PASS, false],
            'one misses and one mistypes the same crossing' => [SyntheticMistakes::MISS_AND_TYPO, false],
        ];
    }

    /**
     * SITUATION: races in which the counters make ONE kind of mistake only,
     * about once every hundred crossings, in both "confirmadas" and "todas",
     * and under both rules for the first pass.
     *
     * EXPECTED: for the kinds the engine can judge, accepting the
     * suggestions gives the true count and an empty queue. For the two
     * kinds that leave a real crossing on one counter's word, suggestions
     * are NOT enough: at least one flag without a suggestion stays open,
     * and the count is only right once a person has settled it.
     */
    #[DataProvider('kindsOfMistake')]
    public function testEachKindOfMistakeOnItsOwn(string $kind, bool $suggestionsAreEnough): void
    {
        foreach ([Settings::MODO_CONFIRMADAS, Settings::MODO_TODAS] as $mode) {
            foreach (Settings::PASSAGENS_INICIAIS as $passagemInicial) {
                foreach ([11, 12, 13] as $seed) {
                    $race = SyntheticRace::generate($this->configWithOnly($kind, $seed));
                    $this->assertNotEmpty($race->mistakes->injected);
                    $truth = $race->truthLaps($passagemInicial);
                    $input = $race->input();
                    $input->settings->modoPublicacao = $mode;
                    $input->settings->passagemInicial = $passagemInicial;

                    $afterSuggestions = SyntheticReviewer::acceptEverySuggestion($input);
                    $context = "Mode $mode, $passagemInicial, seed $seed.";
                    if ($suggestionsAreEnough) {
                        $this->assertSame($truth, $this->officialCounts($afterSuggestions, $race), $context);
                        $this->assertNoOpenFlags($afterSuggestions, $context);
                        continue;
                    }
                    $this->assertNotEmpty($this->openFlags($afterSuggestions, Flag::SOLITARIA), $context);
                    $afterTheVideo = SyntheticReviewer::settleEverythingWithTheVideo($race, $input);
                    $this->assertSame($truth, $this->officialCounts($afterTheVideo, $race), $context);
                    $this->assertNoOpenFlags($afterTheVideo, $context);
                }
            }
        }
    }

    /**
     * SITUATION: counters who make no mistakes at all (there is still a
     * handover in the middle of the race), with race control ending the
     * race and every car crossing once or twice more on its way in.
     *
     * EXPECTED: the count is right and the review queue is empty. The
     * engine must not invent problems: breakdowns, pit stops, fueling
     * stops, a slowing track, a handover and the laps after the flag are
     * normal racing.
     * This holds under both rules for the first pass: by default laps are
     * crossings minus one; with `passagem_inicial` = "largada" every
     * crossing is a lap.
     */
    #[DataProvider('seeds')]
    public function testARaceCountedWithoutMistakesRaisesNoFlags(int $seed): void
    {
        $race = SyntheticRace::generate(SyntheticRaceConfig::withSeed($seed)->withoutHumanErrors()->withFinish());
        $this->assertGreaterThan(0, $race->fuelStops(), 'The race must include fueling stops.');

        foreach (Settings::PASSAGENS_INICIAIS as $passagemInicial) {
            $input = $race->input();
            $input->settings->passagemInicial = $passagemInicial;
            $result = Engine::run($input);

            $this->assertSame($race->truthLaps($passagemInicial), $this->officialCounts($result, $race), "With passagem_inicial = $passagemInicial.");
            $this->assertNoOpenFlags($result);
        }
    }

    private function configWithOnly(string $kind, int $seed): SyntheticRaceConfig
    {
        $config = SyntheticRaceConfig::withSeed($seed)->withoutHumanErrors();
        $config->raceMinutes = 60;
        $rateProperty = [
            SyntheticMistakes::MISS => 'missRate',
            SyntheticMistakes::TYPO => 'typoRate',
            SyntheticMistakes::DOUBLE_TAP => 'doubleTapRate',
            SyntheticMistakes::REPEAT => 'repeatRate',
            SyntheticMistakes::LATE => 'lateRate',
            SyntheticMistakes::LATE_BEYOND_WINDOW => 'lateBeyondWindowRate',
            SyntheticMistakes::TYPO_ONTO_PASSING_CAR => 'typoOntoPassingCarRate',
            SyntheticMistakes::REPEAT_AND_LATECOMER => 'repeatAndLatecomerRate',
            SyntheticMistakes::LONE_REAL_PASS => 'loneRealPassRate',
            SyntheticMistakes::MISS_AND_TYPO => 'missAndTypoRate',
        ][$kind];
        $config->$rateProperty = 0.01;
        return $config;
    }

    private function assertEveryKindOfMistakeHappened(SyntheticRace $race): void
    {
        $kindsInjected = array_unique(array_map(fn(array $mistake) => $mistake['tipo'], $race->mistakes->injected));
        foreach (array_keys(self::kindsOfMistake()) as $description) {
            $kind = self::kindsOfMistake()[$description][0];
            $this->assertContains($kind, $kindsInjected, "The race must contain this kind of mistake: $description.");
        }
        $this->assertGreaterThan(0, $race->mistakes->neighbouringMistakes, 'The race must contain two mistakes on neighbouring crossings.');
    }

    /**
     * Checks that the race really contains each kind of race-day trouble,
     * and that the unreviewed result shows each one to the reviewer.
     */
    private function assertTheRaceDayTroubleIsThereAndFlagged(SyntheticRace $race, EngineResult $unreviewed, string $passagemInicial): void
    {
        $earlyCars = $race->carsBeforeTheRecordedStart();
        $this->assertNotEmpty($earlyCars, 'Some cars must have crossed before the recorded start.');
        $this->assertGreaterThan(SyntheticRace::START_MS, $race->recordedStartMs());
        $this->assertGreaterThan(20, $race->entriesWithTimeQuality(Entry::TEMPO_ESTIMADO));
        $this->assertGreaterThan(5, $race->entriesWithTimeQuality(Entry::TEMPO_DESCONHECIDO));
        $this->assertCount(2, $race->unclassifiedCarNumbers());
        $this->assertGreaterThan(0, $race->fuelStops());

        $flaggedBeforeTheStart = [];
        foreach ($this->openFlags($unreviewed, Flag::ANTES_DA_LARGADA) as $flag) {
            $flaggedBeforeTheStart[] = $flag->carros[0];
            $this->assertNotNull($flag->sugestao, 'A real first crossing a lap before the next one is suggested as the initial pass.');
        }
        if ($passagemInicial === Settings::INICIAL_PRIMEIRA_PASSAGEM) {
            // Each early car is flagged and, until somebody accepts, a lap short.
            foreach ($earlyCars as $car) {
                $this->assertContains($car, $flaggedBeforeTheStart, "Car $car crossed before the recorded start and must be flagged.");
            }
        } else {
            $this->assertSame([], $flaggedBeforeTheStart, 'Under the "largada" rule nothing before the start is flagged.');
        }

        $this->assertCount(
            $race->entriesWithTimeQuality(Entry::TEMPO_DESCONHECIDO),
            $this->openFlags($unreviewed, Flag::TEMPO_DESCONHECIDO),
            'One flag per entry of unknown time.'
        );
        $this->assertCount($race->entriesWithTimeQuality(Entry::TEMPO_DESCONHECIDO), $unreviewed->unknownTimeEntries);
        $this->assertNotEmpty($this->openFlags($unreviewed, Flag::TEMPO_ESTIMADO));
        $this->assertCount(6, $this->openFlags($unreviewed, Flag::CARRO_NAO_CLASSIFICADO), 'Two unclassified cars, three crossings each.');
        $this->assertNoUnclassifiedCarHasAnOfficialPass($race, $unreviewed);
    }

    private function assertNoUnclassifiedCarHasAnOfficialPass(SyntheticRace $race, EngineResult $result): void
    {
        foreach ($race->unclassifiedCarNumbers() as $car) {
            $this->assertArrayNotHasKey($car, $result->cars, "Car $car is not classified and must have no official pass.");
        }
    }

    /** True when an open flag with no suggestion is about that car within half a minute of that moment. */
    private function hasOpenFlagWithoutSuggestion(EngineResult $result, int $car, int $tsMs): bool
    {
        foreach ($result->openFlags() as $flag) {
            $isNear = $flag->tsMs !== null && abs($flag->tsMs - $tsMs) <= 30000;
            if ($flag->sugestao === null && $isNear && in_array($car, $flag->carros, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, int> car => official laps, for every car of the race */
    private function officialCounts(EngineResult $result, SyntheticRace $race): array
    {
        $counts = [];
        foreach ($race->carNumbers() as $car) {
            $counts[$car] = $result->lapCount($car);
        }
        return $counts;
    }
}
