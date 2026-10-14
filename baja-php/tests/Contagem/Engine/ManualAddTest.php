<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * "Editar manualmente" on a flag: the reviewer adds a pass by hand.
 *
 * The review screen opens "Adicionar passagem" prefilled with the flag's
 * FIRST car and the flag's time; submitting stores an "adicionar" decision.
 * A reviewer who adds a pass for a car at a moment has said "this car
 * crossed here". These tests say what that settles and what it does not.
 *
 * What it settles: counters' entries for that car at that moment belong to
 * the pass the reviewer added. It is ONE pass (never two), it counts, and
 * nothing about it stays open.
 *
 * What it does not settle: entries for ANOTHER car at that moment. Adding
 * a pass for the car the other two counters already recorded says nothing
 * new about the third counter's entry for a different number; that entry
 * still has to be moved or voided, and its flag stays.
 */
final class ManualAddTest extends EngineTestCase
{
    /** Ten cars lapping every 180 s; car 9 crosses at 03:56, ..., 18:56, except the crossings a test types itself. */
    private function steadyRace(array $withoutCrossingsOfCar9At = []): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 10], 180, 6);
        foreach (['03:56', '06:56', '09:56', '12:56', '15:56', '18:56'] as $crossing) {
            if (!in_array($crossing, $withoutCrossingsOfCar9At, true)) {
                $race->pass(9, $crossing);
            }
        }
        return $race;
    }

    /**
     * SITUATION: only counter 2 recorded car 9 at 15:56 (a "solitaria"
     * flag, the lap would be plausible). The reviewer presses "Editar
     * manualmente" and adds a pass for car 9 at the flag's time (the form
     * works in whole seconds: 15:56, while the entry is at 15:56.4).
     *
     * EXPECTED: the added pass and the counter's entry are ONE pass. It
     * counts (the reviewer said so), the flag is gone, and the car has its
     * five laps, not six. Confirming the counter's entry afterwards changes
     * nothing: it is the same pass.
     */
    public function testAPassAddedWhereOneCounterHasTheCarIsThatSamePass(): void
    {
        $race = $this->steadyRace(['15:56']);
        $lone = $race->entry(2, 9, '15:56.4');
        $this->assertSingleOpenFlag($race->run('20:00'), Flag::SOLITARIA);

        $race->addPass(9, '15:56');
        $result = $race->run('20:00');

        $this->assertNoOpenFlags($result);
        $this->assertLapCounts([9 => 5], $result);
        $this->assertCount(2, $result->groupOfEntry($lone)->entries, 'The counter\'s entry and the added pass.');
        $this->assertTrue($result->groupOfEntry($lone)->adicionadoPorRevisor);

        $race->confirm($lone);
        $this->assertLapCounts([9 => 5], $race->run('20:00'));
    }

    /**
     * SITUATION: the undecided swap: counters 1 and 2 recorded car 10 and
     * counter 3 recorded car 9, where car 9 was due (it has no other pass
     * on that lap). The flag's first car is 9, the car of the lone entry.
     * The reviewer adds a pass for car 9 there.
     *
     * EXPECTED: the reviewer said car 9 crossed, so counter 3 was right:
     * its entry is that pass, car 9 gets its lap, and the flag is closed.
     * Car 10 is untouched.
     */
    public function testAddingAPassForTheLoneEntrysCarClosesAnUndecidedSwap(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8], 180, 6);
        foreach (['03:56', '06:56', '09:56', '12:56', '18:56'] as $crossing) {
            $race->pass(9, $crossing);
        }
        foreach (['04:03', '07:03', '10:03', '13:03', '19:03'] as $crossing) {
            $race->pass(10, $crossing);
        }
        $race->pass(10, '15:58', [1, 2]);
        $race->entry(3, 9, '15:58.4');
        $flag = $this->assertSingleOpenFlag($race->run('20:00'), Flag::TROCA_PROVAVEL);
        $this->assertSame(9, $flag->carros[0], 'The form is prefilled with the lone entry\'s car.');

        $race->addPass(9, '15:58');
        $result = $race->run('20:00');

        $this->assertNoOpenFlags($result);
        $this->assertLapCounts([9 => 5, 10 => 5], $result);
    }

    /**
     * SITUATION: an ordinary probable typo: counters 1 and 2 recorded car
     * 4 at 15:21 and counter 3 typed 9. The reviewer does not accept the
     * suggestion; instead they "edit manually" and add a pass for car 4,
     * the car that really crossed.
     *
     * EXPECTED (and why this is not a bug): car 4's pass already counted
     * on two counters; the added pass joins it and changes no count. It
     * says nothing about counter 3's entry for car 9, which is still
     * there, alone and uncounted, so its flag stays open, with the same
     * suggestion: move it to car 4. Adding a pass is not how a wrong number
     * is corrected; accepting the suggestion (or voiding the entry) is,
     * and that closes the flag.
     */
    public function testAddingAPassForTheOtherCarDoesNotSettleTheStrayEntry(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 5, 6, 7, 8, 9, 10], 180, 6);
        foreach (['03:21', '06:21', '09:21', '12:21', '18:21'] as $crossing) {
            $race->pass(4, $crossing);
        }
        $agreed = $race->pass(4, '15:21', [1, 2]);
        $typo = $race->entry(3, 9, '15:21.4');

        $race->addPass(4, '15:21');
        $result = $race->run('20:00');

        $this->assertLapCounts([4 => 5, 9 => 5], $result);
        $this->assertCount(3, $result->groupOfEntry($agreed[1])->entries);
        $flag = $this->assertSingleOpenFlag($result, Flag::TROCA_PROVAVEL);
        $this->assertSame(['tipo' => 'reatribuir', 'passagem_id' => $typo, 'equipe_numero' => 4], $flag->sugestao);

        $race->accept($flag);
        $fixed = $race->run('20:00');
        $this->assertNoOpenFlags($fixed);
        $this->assertLapCounts([4 => 5, 9 => 5], $fixed);
    }

    /**
     * SITUATION: two counters have car 9 again at 16:30, 34 seconds after
     * its pass at 15:56, so the pass is held ("passagem_proxima_demais"). The reviewer has seen
     * the car cross twice and adds a pass for car 9 at the flag's time.
     *
     * WHAT WENT WRONG: the added pass joined the two entries and the pass
     * counted, as asked. But a NEW flag then appeared on it ("volta_curta":
     * two passes 34 s apart), and it could not be closed: the event stayed
     * open after the reviewer had decided it.
     *
     * EXPECTED: a pass a reviewer adds exactly where counters have entries
     * is the reviewer vouching for that pass, like "confirmar". It counts
     * (six laps), and nothing is left open.
     */
    public function testAPassAddedOnAHeldPassCountsItAndLeavesNothingOpen(): void
    {
        $race = $this->steadyRace();
        $held = $race->pass(9, '16:30', [1, 2]);
        $this->assertSingleOpenFlag($race->run('20:00'), Flag::PASSAGEM_PROXIMA_DEMAIS);

        $race->addPass(9, '16:30');
        $result = $race->run('20:00');

        $this->assertLapCounts([9 => 6], $result);
        $this->assertCount(3, $result->groupOfEntry($held[1])->entries);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: the same car, but the reviewer adds a pass where NOBODY
     * has an entry: at 16:30, 34 s after the pass at 15:56.
     *
     * EXPECTED: here the check stays. The added pass counts (a reviewer's
     * word always does), and a "volta_curta" flag says the car may have a
     * lap too many: with no counter's entry to go with it, the reviewer may
     * have added a pass that was there already.
     */
    public function testAPassAddedWhereNobodyHasAnEntryIsStillCheckedAgainstThePassNextToIt(): void
    {
        $race = $this->steadyRace();
        $race->addPass(9, '16:30');

        $result = $race->run('20:00');

        $this->assertLapCounts([9 => 6], $result);
        $this->assertSame(Flag::DUVIDA_A_MAIS, $this->assertSingleOpenFlag($result, Flag::VOLTA_CURTA)->contagemEmDuvida);
    }

    /**
     * SITUATION: the knot of crossed wrong numbers (see SwapSuggestionTest):
     * cars 9 and 7 cross together, counter 3 types 8 for the 9 and counter
     * 2 types 8 for the 7, so three passes each lack one counter. Car 8 had
     * passed 38 s earlier, so its phantom pass here is also held. The
     * reviewer adds a pass for the flag's first car at the flag's time.
     *
     * WHAT WENT WRONG:
     * - the knot's flag hid the flag of car 8's held pass, the one that
     *   says a count is in doubt;
     * - after the reviewer vouched for one of the passes, the knot was
     *   flagged again from another of its passes under a DIFFERENT id, so
     *   to the review screen the old flag vanished and a new one appeared.
     *
     * EXPECTED: the held pass keeps its own flag next to the knot's. The
     * knot's flag has one id whichever pass raises it. Vouching for one car
     * does not say which of the others is the phantom, so the flag stays
     * open, the same flag, until the stray entries are moved; then
     * everything closes.
     */
    public function testTheKnotKeepsOneFlagWhateverPassRaisesItAndDoesNotHideAHeldPass(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 10], 180, 6);
        foreach ([229, 409, 589, 769, 1129] as $seconds) {
            $race->pass(7, RaceScenario::clock($seconds + 10));
            $race->pass(8, RaceScenario::clock($seconds));
            $race->pass(9, RaceScenario::clock($seconds + 7));
        }
        $race->pass(8, '15:20');
        $race->pass(9, '15:56', [1, 2]);
        $race->pass(7, '15:58.7', [1, 3]);
        $forCar9 = $race->entry(3, 8, '15:57.2');
        $forCar7 = $race->entry(2, 8, '15:59.2');

        $before = $race->run('20:00');
        $knot = $this->openFlags($before, Flag::TROCA_PROVAVEL)[0];
        $held = $this->openFlags($before, Flag::PASSAGEM_PROXIMA_DEMAIS);
        $this->assertCount(1, $held, 'The held phantom of car 8 keeps its own flag.');
        $this->assertSame([8], $held[0]->carros);
        $this->assertCount(2, $before->openFlags());

        $race->addPass($knot->carros[0], RaceScenario::clock(intdiv($knot->tsMs - RaceScenario::CLOCK_ZERO_MS, 1000)));
        $after = $race->run('20:00');
        $this->assertSame($knot->id, $this->openFlags($after, Flag::TROCA_PROVAVEL)[0]->id, 'Still the same flag.');
        $this->assertSame($before->lapCount(9), $after->lapCount(9), 'The added pass joined a pass that counted already.');

        $race->reassign($forCar9, 9);
        $race->reassign($forCar7, 7);
        $this->assertNoOpenFlags($race->run('20:00'));
    }
}
