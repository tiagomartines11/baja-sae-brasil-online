<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * When does the engine dare to say "this lone entry was meant for car Y"
 * (the "troca_provavel" flag), and which car does it pick?
 *
 * The rule: Y must be a REAL pass at that moment, recorded by at least two
 * counters, that the counter of the lone entry does not have. Among several
 * such cars, the closest in time wins (in whole seconds: typing delays make
 * finer differences meaningless); between cars equally close, the number
 * that looks most like what was typed; and only then the milliseconds.
 *
 * TypoAndLoneEntryTest shows the ordinary cases. These are the edges.
 */
final class SwapSuggestionTest extends EngineTestCase
{
    /**
     * SITUATION: two stray entries a few seconds apart. Counter 1 types 14
     * alone, and counter 2 types 23 alone. Nobody else recorded either car,
     * and no other car crossed.
     *
     * WHAT WENT WRONG: two unrelated slips that happen to be neighbours.
     *
     * EXPECTED: they do NOT suggest each other. "Counter 1 meant 23" would
     * move the entry next to counter 2's, the two would then "agree", and
     * two mistakes would become one counted lap. Each is flagged as a plain
     * lone entry, and nothing counts.
     */
    public function testTwoUnrelatedLoneEntriesNextToEachOtherDoNotSuggestEachOther(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 23]);
        $race->entry(1, 14, '03:00');
        $race->entry(2, 23, '03:04');

        $result = $race->run('05:00');

        $this->assertCount(2, $this->openFlags($result, Flag::SOLITARIA));
        $this->assertSame([], $this->openFlags($result, Flag::TROCA_PROVAVEL));
        $this->assertLapCounts([14 => 0, 23 => 0], $result);
    }

    /**
     * SITUATION: car 41 crosses and only counter 2 records it. At the same
     * moment counter 1 types 14 alone.
     *
     * EXPECTED: tempting as it looks (swapped digits!), car 41's pass rests
     * on one counter, so it is not established that car 41 passed at all.
     * No swap is suggested. Two lone entries, for a person to judge.
     */
    public function testAPassSeenByOneCounterIsNotEnoughToExplainAStrayEntry(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 41]);
        $race->entry(2, 41, '03:00');
        $race->entry(1, 14, '03:01');

        $result = $race->run('05:00');

        $this->assertSame([], $this->openFlags($result, Flag::TROCA_PROVAVEL));
        $this->assertCount(2, $this->openFlags($result, Flag::SOLITARIA));
    }

    /**
     * SITUATION: car 41 crosses and all three counters record it, counter 1
     * included. A second later counter 1 also types 14, alone.
     *
     * EXPECTED: not a swap. Counter 1 HAS car 41, so the 14 cannot be "41
     * mistyped". It is a plain lone entry.
     */
    public function testNoSwapIsSuggestedTowardsACarTheCounterAlreadyHas(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 41]);
        $race->pass(41, '03:00');
        $race->entry(1, 14, '03:01');

        $this->assertSingleOpenFlag($race->run('05:00'), Flag::SOLITARIA);
    }

    /**
     * SITUATION: cars 41 and 7 cross within the same second, both recorded
     * by counters 2 and 3 only. Counter 1 types a lone 47. Car 7's pass is
     * 0.3 s from the stray entry and car 41's is 0.9 s.
     *
     * EXPECTED: both are "less than a second away", which is a tie as far
     * as hand timing goes. The numbers break it: 47 is 41 with one digit
     * wrong, while 7 is 47 with a digit missing, a rarer slip. The
     * suggestion is car 41, and the reason given to the reviewer says "um
     * dígito diferente".
     */
    public function testBetweenCarsEquallyCloseInTimeTheMoreSimilarNumberWins(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7, 41]);
        $race->pass(41, '03:00.1', [2, 3]);
        $race->pass(7, '03:00.7', [2, 3]);
        $race->entry(1, 47, '03:01');

        $flag = $this->assertSingleOpenFlag($race->run('05:00'), Flag::TROCA_PROVAVEL);

        $this->assertSame(41, $flag->sugestao['equipe_numero']);
        $this->assertSame([47, 41], $flag->carros);
        $this->assertStringContainsString('um dígito diferente', $flag->motivo);
    }

    /**
     * SITUATION: the same, but the two cars are 41 and 46: both are "47
     * with one digit wrong". Car 46 is 0.3 s from the stray entry, car 41
     * is 0.9 s.
     *
     * EXPECTED: same whole second, same resemblance; only now do the
     * milliseconds decide. The suggestion is car 46.
     */
    public function testWithTimeAndResemblanceEqualTheMillisecondsDecide(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([41, 46]);
        $race->pass(41, '03:00.1', [2, 3]);
        $race->pass(46, '03:00.7', [2, 3]);
        $race->entry(1, 47, '03:01');

        $flag = $this->assertSingleOpenFlag($race->run('05:00'), Flag::TROCA_PROVAVEL);

        $this->assertSame(46, $flag->sugestao['equipe_numero']);
    }

    /**
     * SITUATION: car 41 crosses, recorded by counters 2 and 3. Counter 1
     * types TWO stray numbers around that moment: 14 and then 17.
     *
     * EXPECTED: one real pass can explain only one stray entry. The first
     * one (14) gets the swap suggestion; the second (17) is a plain lone
     * entry. Otherwise accepting both suggestions would put counter 1 on
     * car 41 twice.
     */
    public function testOneRealPassExplainsOnlyOneStrayEntry(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 17, 41]);
        $race->pass(41, '03:00', [2, 3]);
        $first = $race->entry(1, 14, '03:00');
        $second = $race->entry(1, 17, '03:03');

        $result = $race->run('05:00');

        $swaps = $this->openFlags($result, Flag::TROCA_PROVAVEL);
        $this->assertCount(1, $swaps);
        $this->assertSame([$first], $swaps[0]->passagemIds);
        $lone = $this->openFlags($result, Flag::SOLITARIA);
        $this->assertCount(1, $lone);
        $this->assertSame([$second], $lone[0]->passagemIds);
    }

    /**
     * SITUATION: car 41 is recorded by counters 2 and 3 at 03:00. Counter 1
     * types a lone 14 exactly 20 seconds later in one race, and 20.001
     * seconds later in another.
     *
     * EXPECTED: a swap is only looked for inside the matching window. At
     * 20 s the two are still "the same moment" and the swap is suggested;
     * one millisecond later the 14 is just a lone entry. The same holds
     * when the stray entry comes BEFORE the real pass (02:40 and
     * 02:39.999).
     */
    public function testASwapIsOnlyLookedForInsideTheMatchingWindow(): void
    {
        $expectations = [
            '03:20' => Flag::TROCA_PROVAVEL,
            '03:20.001' => Flag::SOLITARIA,
            '02:40' => Flag::TROCA_PROVAVEL,
            '02:39.999' => Flag::SOLITARIA,
        ];
        foreach ($expectations as $strayAt => $expectedFlag) {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            $race->firstCrossing([14, 41]);
            $race->pass(41, '03:00', [2, 3]);
            $race->entry(1, 14, $strayAt);

            $this->assertSingleOpenFlag($race->run('05:00'), $expectedFlag);
        }
    }

    /**
     * SITUATION: counter 1 alone types 14 at 03:00. Around that moment car
     * 41 has a pass that rests on ONE counter's entry (counter 2) plus a
     * pass a reviewer added for it by hand.
     *
     * EXPECTED: to explain a stray entry as a typo, the other car's pass
     * must have been seen by two COUNTERS. A reviewer's added pass is not a
     * counter. So no swap is suggested towards car 41; the stray 14 is an
     * ordinary lone entry.
     */
    public function testAPassAReviewerAddedDoesNotCountAsASecondCounter(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 41]);
        $race->entry(2, 41, '03:00');
        $race->addPass(41, '03:01');
        $stray = $race->entry(1, 14, '03:00');

        $result = $race->run('05:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::SOLITARIA);
        $this->assertSame([$stray], $flag->passagemIds);
    }
}
