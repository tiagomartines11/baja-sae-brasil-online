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
     *   very late, so two counters seem to "agree" on a crossing that
     *   never happened;
     * - a counter types a car's number 20 seconds before that car really
     *   passes, so the stray entry sits one matching window away from the
     *   real ones;
     * - a real crossing that two of the three counters got wrong, so it
     *   rests on one counter's word.
     *
     * EXPECTED, in three steps:
     * 1. Before anybody reviews anything, the count is wrong for some car,
     *    but ONLY EVER SHORT: no car is ahead of the truth, at any moment
     *    of the race. (The safe mode must never over-count on its own.)
     * 2. After a reviewer accepts every suggestion, still nobody is ahead.
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
        $this->assertNoCarIsEverAheadOfTheTruth($race, $unreviewed, 'Unreviewed.');
        foreach ($truth as $car => $trueLaps) {
            $this->assertLessThanOrEqual($trueLaps, $beforeReview[$car], "Unreviewed, car $car is ahead of the truth.");
        }
        $this->assertTheRaceDayTroubleIsThereAndFlagged($race, $unreviewed, $passagemInicial);

        $afterSuggestions = SyntheticReviewer::acceptEverySuggestion($input);
        $expectedAfterSuggestions = $truth;
        foreach ($race->mistakes->lapsOnlyAPersonCanRestore as $car => $lapsWaiting) {
            $expectedAfterSuggestions[$car] -= $lapsWaiting;
        }
        $this->assertSame($expectedAfterSuggestions, $this->officialCounts($afterSuggestions, $race));
        $this->assertNoCarIsEverAheadOfTheTruth($race, $afterSuggestions, 'After accepting every suggestion.');
        if ($passagemInicial === Settings::INICIAL_PRIMEIRA_PASSAGEM) {
            // The cars whose first crossing was typed before the recorded
            // start got their lap back.
            $this->assertNotSame($beforeReview, $this->officialCounts($afterSuggestions, $race), 'Accepting the suggestions must have changed the count.');
        }
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

    /** @return array<string, array{0: string, 1: ?string}> kind of mistake, and the flag it leaves for a person (null: suggestions alone repair it) */
    public static function kindsOfMistake(): array
    {
        return [
            'a counter misses a car' => [SyntheticMistakes::MISS, null],
            'a counter types another number' => [SyntheticMistakes::TYPO, null],
            'a double tap' => [SyntheticMistakes::DOUBLE_TAP, null],
            'a repeat 8 to 15 s later' => [SyntheticMistakes::REPEAT, null],
            'a counter types 6 to 12 s late' => [SyntheticMistakes::LATE, null],
            'a counter types 22 to 30 s late' => [SyntheticMistakes::LATE_BEYOND_WINDOW, null],
            'a typo onto a car that is itself passing' => [SyntheticMistakes::TYPO_ONTO_PASSING_CAR, null],
            'a typo onto a car that passes one window away' => [SyntheticMistakes::WINDOW_EDGE_TYPO, null],
            'a repeat plus a very late counter' => [SyntheticMistakes::REPEAT_AND_LATECOMER, Flag::DISPERSAO_ALTA],
            'two counters miss the same crossing' => [SyntheticMistakes::LONE_REAL_PASS, Flag::SOLITARIA],
            'one misses and one mistypes the same crossing' => [SyntheticMistakes::MISS_AND_TYPO, Flag::SOLITARIA],
        ];
    }

    /**
     * SITUATION: races in which the counters make ONE kind of mistake only,
     * about once every hundred crossings, in both "confirmadas" and "todas",
     * and under both rules for the first pass.
     *
     * EXPECTED: for the kinds the engine can judge, accepting the
     * suggestions gives the true count and an empty queue. One exception
     * to "empty": a wrong number typed for a car that was itself due at
     * the line just then (it happens when that car is on a long lap). Two
     * counters say one car and one says another, both could be true, and
     * the engine says so instead of guessing: a "troca_provavel" with no
     * suggestion stays open. The count is right all the same, and the
     * reviewer with the video closes it. (In "confirmadas"; in "todas",
     * where the stray entry counts meanwhile, the video also fixes the
     * count.)
     *
     * For the two kinds that leave a real crossing on one counter's word,
     * suggestions are NOT enough: at least one flag without a suggestion
     * stays open, and the count is only right once a person has settled it.
     *
     * The repeat plus a very late counter needs a person too, for another
     * reason. The repeat and the late entry end up together, ten seconds
     * apart: a held, doubtful pass ("dispersao_alta"). It is not counted,
     * so in "confirmadas" the count is right all along; but which of the
     * two entries to void (here: both) is not something the engine lets a
     * reviewer accept blindly, so each such pair waits for a person to
     * look and void them.
     *
     * In "confirmadas", whatever the kind, no car is ever ahead of the
     * truth before anybody reviews.
     */
    #[DataProvider('kindsOfMistake')]
    public function testEachKindOfMistakeOnItsOwn(string $kind, ?string $flagLeftForAPerson): void
    {
        $suggestionsAreEnough = $flagLeftForAPerson === null;
        $typos = [SyntheticMistakes::TYPO, SyntheticMistakes::TYPO_ONTO_PASSING_CAR, SyntheticMistakes::WINDOW_EDGE_TYPO];
        foreach ([Settings::MODO_CONFIRMADAS, Settings::MODO_TODAS] as $mode) {
            foreach (Settings::PASSAGENS_INICIAIS as $passagemInicial) {
                foreach ([11, 12, 13] as $seed) {
                    $race = SyntheticRace::generate($this->configWithOnly($kind, $seed));
                    $this->assertNotEmpty($race->mistakes->injected);
                    $truth = $race->truthLaps($passagemInicial);
                    $input = $race->input();
                    $input->settings->modoPublicacao = $mode;
                    $input->settings->passagemInicial = $passagemInicial;
                    $context = "Mode $mode, $passagemInicial, seed $seed.";
                    if ($mode === Settings::MODO_CONFIRMADAS) {
                        $this->assertNoCarIsEverAheadOfTheTruth($race, Engine::run($input), $context);
                    }

                    $afterSuggestions = SyntheticReviewer::acceptEverySuggestion($input);
                    if ($suggestionsAreEnough) {
                        // (In "todas" an entry counts until it is moved or
                        // voided, so there an undecided swap is also a lap
                        // in the wrong place until a person decides.)
                        if ($mode === Settings::MODO_CONFIRMADAS || $afterSuggestions->openFlags() === []) {
                            $this->assertSame($truth, $this->officialCounts($afterSuggestions, $race), $context);
                        }
                        foreach ($afterSuggestions->openFlags() as $flag) {
                            $isUndecidedSwap = $flag->tipo === Flag::TROCA_PROVAVEL && $flag->sugestao === null;
                            $this->assertTrue(
                                $isUndecidedSwap && in_array($kind, $typos, true),
                                "$context The review queue should be empty, but for swaps the engine cannot decide: {$flag->id} ({$flag->motivo})"
                            );
                        }
                    } else {
                        $this->assertNotEmpty($this->openFlags($afterSuggestions, $flagLeftForAPerson), $context);
                        foreach ($this->openFlags($afterSuggestions, $flagLeftForAPerson) as $flag) {
                            $this->assertNull($flag->sugestao, $context);
                        }
                        if ($flagLeftForAPerson === Flag::DISPERSAO_ALTA && $mode === Settings::MODO_CONFIRMADAS) {
                            $this->assertSame($truth, $this->officialCounts($afterSuggestions, $race), "$context A held pass does not count.");
                        }
                    }
                    $afterTheVideo = SyntheticReviewer::settleEverythingWithTheVideo($race, $input);
                    $this->assertSame($truth, $this->officialCounts($afterTheVideo, $race), $context);
                    $this->assertNoOpenFlags($afterTheVideo, $context);
                }
            }
        }
    }

    /**
     * Sixty seeds by default. To try many more, set the environment
     * variable CONTAGEM_INVARIANT_SEEDS, e.g. to 600.
     *
     * @return array<string, array{0: int}>
     */
    public static function invariantSeeds(): array
    {
        $howMany = max(1, (int) (getenv('CONTAGEM_INVARIANT_SEEDS') ?: 60));
        $cases = [];
        for ($seed = 1001; $seed <= 1000 + $howMany; $seed++) {
            $cases["seed $seed"] = [$seed];
        }
        return $cases;
    }

    /**
     * THE INVARIANT, for many races: in the safe mode ("confirmadas"), with
     * no reviewer action, a car's official count never exceeds its true
     * count at that moment.
     *
     * SITUATION: whole races with every kind of mistake one counter can
     * make and the three kinds two counters make on one crossing, a
     * handover, a finish and all the race-day trouble, under both rules
     * for the first pass. Nobody reviews anything.
     *
     * EXPECTED: for every official pass of every car, the car had really
     * crossed the line at least that many times by then; and at the end no
     * car has more laps than it drove. (Before the matcher went by
     * closeness and the hold rule existed, a stray entry one window before
     * a real pass, or a repeat plus a late counter, made two passes out of
     * one crossing; with a real-size field that happened in more than a
     * third of the races.)
     */
    #[DataProvider('invariantSeeds')]
    public function testInTheSafeModeNoCarIsEverAheadOfTheTruth(int $seed): void
    {
        $config = SyntheticRaceConfig::withSeed($seed)->withFinish()->withRaceDayTrouble();
        // Three times the usual share of the mistakes that used to split a
        // crossing in two.
        $config->windowEdgeTypoRate = 0.01;
        $config->repeatAndLatecomerRate = 0.01;
        $config->typoOntoPassingCarRate = 0.01;
        $race = SyntheticRace::generate($config);

        foreach (Settings::PASSAGENS_INICIAIS as $passagemInicial) {
            $input = $race->input();
            $input->settings->passagemInicial = $passagemInicial;
            $result = Engine::run($input);

            $checked = $this->assertNoCarIsEverAheadOfTheTruth($race, $result, "Seed $seed, $passagemInicial.");
            $this->assertGreaterThan(900, $checked, 'The race must have been counted at all.');
            foreach ($race->truthLaps($passagemInicial) as $car => $trueLaps) {
                $this->assertLessThanOrEqual($trueLaps, $result->lapCount($car), "Seed $seed, $passagemInicial: car $car has more laps than it drove.");
            }
        }
    }

    /**
     * One race in four of those above, again with every kind of mistake
     * that two counters make on one crossing.
     *
     * @return array<string, array{0: int}>
     */
    public static function stopOftenSeeds(): array
    {
        $howMany = max(15, intdiv((int) (getenv('CONTAGEM_INVARIANT_SEEDS') ?: 60), 4));
        $cases = [];
        for ($seed = 3001; $seed <= 3000 + $howMany; $seed++) {
            $cases["seed $seed"] = [$seed];
        }
        return $cases;
    }

    /**
     * THE INVARIANT, for cars that stop often: the same, with a pit stop on
     * one lap in six and a fueling stop on one in eight, so that many cars
     * stop in their first laps, before they have three laps without a stop.
     *
     * WHY: the laps with a stop count towards a car's normal lap until it
     * has three without one, shortened by the stay (HoldRule); the races
     * above stop too rarely to try that much (a pit stop on one lap in
     * fifty).
     *
     * EXPECTED: as above, no car is ever ahead of the truth.
     */
    #[DataProvider('stopOftenSeeds')]
    public function testInTheSafeModeNoCarThatStopsOftenIsEverAheadOfTheTruth(int $seed): void
    {
        $config = SyntheticRaceConfig::withSeed($seed)->withFinish()->withRaceDayTrouble();
        $config->windowEdgeTypoRate = 0.01;
        $config->repeatAndLatecomerRate = 0.01;
        $config->typoOntoPassingCarRate = 0.01;
        $config->pitStopRate = 0.16;
        $config->fuelStopRate = 0.12;
        $race = SyntheticRace::generate($config);

        foreach (Settings::PASSAGENS_INICIAIS as $passagemInicial) {
            $input = $race->input();
            $input->settings->passagemInicial = $passagemInicial;
            $result = Engine::run($input);

            $this->assertNoCarIsEverAheadOfTheTruth($race, $result, "Seed $seed, $passagemInicial.");
            foreach ($race->truthLaps($passagemInicial) as $car => $trueLaps) {
                $this->assertLessThanOrEqual($trueLaps, $result->lapCount($car), "Seed $seed, $passagemInicial: car $car has more laps than it drove.");
            }
        }
    }

    /**
     * SITUATION: the one mistake no two-of-three count can always catch.
     * Two counters type the SAME wrong number for a crossing: 14 when car
     * 41 passed. The third types 41. So two counters "agree" on a pass of
     * car 14, which is somewhere else on the track, and car 41's real pass
     * rests on one counter's word. One-hour races with nothing but this
     * mistake, about once every hundred crossings.
     *
     * WHAT WENT WRONG (before): car 14 got the phantom pass, the engine
     * suggested moving the third counter's CORRECT entry onto car 14 as
     * well, and accepting every suggestion left car 41 a lap short and car
     * 14 a lap over with an empty queue.
     *
     * EXPECTED:
     * - most phantoms are caught by the hold rule: they land where car 14
     *   cannot be (too soon after its previous pass), are held, and the
     *   suggestion is the reverse one: move the two entries to car 41;
     * - whatever the reviewer who only accepts suggestions ends up with,
     *   each shared typo is either RIGHT for both cars or has an OPEN FLAG
     *   on one of them. Never "wrong and nothing to see";
     * - across these eight races both things happen: some shared typos are
     *   put right by the suggestions alone, and some are left for a person
     *   (the limit of two-of-three agreement is real, and visible);
     * - the reviewer with the video gets every car's count right and
     *   empties the queue.
     */
    public function testASharedTypoEndsRightOrWithAnOpenFlagOnACarItTouched(): void
    {
        $outcomes = [];
        foreach ([21, 22, 23, 24, 25, 26, 27, 28] as $seed) {
            $outcomes = array_merge($outcomes, $this->outcomesOfTheSharedTyposOfOneRace($seed));
        }
        $this->assertContains('right', $outcomes, 'No shared typo was repaired by the suggestions alone.');
        $this->assertContains('flag', $outcomes, 'No shared typo was left to a person.');
    }

    /**
     * @return string[] for each shared typo of the race: 'right' (both cars' counts are right after
     *   accepting every suggestion) or 'flag' (an open flag is left on one of the two cars)
     */
    private function outcomesOfTheSharedTyposOfOneRace(int $seed): array
    {
        $config = SyntheticRaceConfig::withSeed($seed)->withOnly(SyntheticMistakes::SHARED_TYPO, 0.01);
        $config->raceMinutes = 60;
        $race = SyntheticRace::generate($config);
        $this->assertNotEmpty($race->mistakes->sharedTypos);
        $truth = $race->truthLaps(Settings::INICIAL_PRIMEIRA_PASSAGEM);
        $input = $race->input();

        $afterSuggestions = SyntheticReviewer::acceptEverySuggestion($input);
        $counts = $this->officialCounts($afterSuggestions, $race);
        $outcomes = [];
        foreach ($race->mistakes->sharedTypos as $typo) {
            $bothRight = $counts[$typo['realCar']] === $truth[$typo['realCar']] && $counts[$typo['wrongCar']] === $truth[$typo['wrongCar']];
            $hasOpenFlag = false;
            foreach ($afterSuggestions->openFlags() as $flag) {
                if (array_intersect($flag->carros, [$typo['realCar'], $typo['wrongCar']]) !== []) {
                    $hasOpenFlag = true;
                }
            }
            $this->assertTrue(
                $bothRight || $hasOpenFlag,
                sprintf('Seed %d: cars %d and %d are wrong after a shared typo and no flag is open on either.', $seed, $typo['realCar'], $typo['wrongCar'])
            );
            $outcomes[] = $hasOpenFlag ? 'flag' : 'right';
        }

        $afterTheVideo = SyntheticReviewer::settleEverythingWithTheVideo($race, $input);
        $this->assertSame($truth, $this->officialCounts($afterTheVideo, $race), "Seed $seed.");
        $this->assertNoOpenFlags($afterTheVideo, "Seed $seed.");
        return $outcomes;
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
        $config = SyntheticRaceConfig::withSeed($seed)->withOnly($kind, 0.01);
        $config->raceMinutes = 60;
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
