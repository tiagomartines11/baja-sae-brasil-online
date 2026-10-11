<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * The publish modes (setting `modo_publicacao`) decide which passes become
 * official:
 *
 * - "confirmadas" (default): at least two counters agree, or a reviewer
 *   said so.
 * - "fonte_principal": whatever the primary counter has, plus what a
 *   reviewer said. This is how the old spreadsheet worked ("trust Count 1").
 * - "todas": every pass anybody recorded, unless voided.
 * - "desligada": counted exactly like "confirmadas". The only difference is
 *   outside the engine: nothing is written to the scoring system. It exists
 *   to run next to the old spreadsheet without both writing VOLTAS.
 *
 * The mode changes the COUNT only. The review flags are the same in all of
 * them, so changing mode mid-race never hides a problem.
 */
final class PublishModeTest extends EngineTestCase
{
    /**
     * The same race is fed to the engine once per mode. Every car makes its
     * first crossing (pass zero) seen by everybody; then, one lap later:
     *
     *   car 1  recorded by all three counters
     *   car 2  recorded by counter 1 only
     *   car 3  recorded by counters 2 and 3, missed by counter 1
     *   car 4  recorded by counter 2 only, and confirmed by a reviewer
     */
    private function raceWithOneCarOfEachKind(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([1, 2, 3, 4]);
        $race->pass(1, '03:00', [1, 2, 3]);
        $race->pass(2, '05:00', [1]);
        $race->pass(3, '07:00', [2, 3]);
        $confirmed = $race->pass(4, '09:00', [2]);
        $race->confirm($confirmed[2]);
        return $race;
    }

    /**
     * EXPECTED in "confirmadas": car 1 (three agree), car 3 (two agree) and
     * car 4 (reviewer) count. Car 2, seen by one counter, does not.
     */
    public function testConfirmadasCountsPassesTwoCountersAgreeOnOrAReviewerConfirmed(): void
    {
        $race = $this->raceWithOneCarOfEachKind();
        $race->settings->modoPublicacao = Settings::MODO_CONFIRMADAS;

        $this->assertLapCounts([1 => 1, 2 => 0, 3 => 1, 4 => 1], $race->run('12:00'));
    }

    /**
     * EXPECTED in "fonte_principal" with counter 1 as primary: car 1 and
     * car 2 count (counter 1 has them), car 4 counts (reviewer). Car 3 does
     * NOT count even though two counters saw it, because the primary missed
     * it. That weakness is exactly why this is not the default mode.
     */
    public function testFontePrincipalCountsWhatThePrimaryCounterHas(): void
    {
        $race = $this->raceWithOneCarOfEachKind();
        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = 1;

        $this->assertLapCounts([1 => 1, 2 => 1, 3 => 0, 4 => 1], $race->run('12:00'));
    }

    /**
     * EXPECTED in "todas": everything counts.
     */
    public function testTodasCountsEveryPassAnybodyRecorded(): void
    {
        $race = $this->raceWithOneCarOfEachKind();
        $race->settings->modoPublicacao = Settings::MODO_TODAS;

        $this->assertLapCounts([1 => 1, 2 => 1, 3 => 1, 4 => 1], $race->run('12:00'));
    }

    /**
     * SITUATION: somebody selects "fonte_principal" on the settings page
     * and forgets to choose which source is the primary one.
     *
     * EXPECTED: the engine behaves as in "confirmadas" instead of counting
     * nothing. A half-filled settings form must not zero a race.
     */
    public function testFontePrincipalWithoutAPrimarySourceFallsBackToConfirmadas(): void
    {
        $race = $this->raceWithOneCarOfEachKind();
        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = null;

        $this->assertLapCounts([1 => 1, 2 => 0, 3 => 1, 4 => 1], $race->run('12:00'));
    }

    /**
     * SITUATION: the organisers run the new system next to the old
     * spreadsheet and choose "desligada" so that only the spreadsheet writes
     * the results.
     *
     * EXPECTED: the engine counts exactly as in "confirmadas" (the review
     * screen and the live feed still need a count). The only thing that
     * changes is the answer to "should this be written to the scoring
     * system?", which is no.
     */
    public function testDesligadaCountsLikeConfirmadasAndOnlyStopsPublishing(): void
    {
        $race = $this->raceWithOneCarOfEachKind();
        $race->settings->modoPublicacao = Settings::MODO_DESLIGADA;

        $this->assertLapCounts([1 => 1, 2 => 0, 3 => 1, 4 => 1], $race->run('12:00'));
        $this->assertFalse($race->settings->publishesToScoring());

        foreach ([Settings::MODO_CONFIRMADAS, Settings::MODO_FONTE_PRINCIPAL, Settings::MODO_TODAS] as $mode) {
            $race->settings->modoPublicacao = $mode;
            $this->assertTrue($race->settings->publishesToScoring(), "Mode $mode publishes.");
        }
    }

    /**
     * SITUATION: "fonte_principal" with counter 1 as the primary, and then
     * counter 1 is switched off on the settings page at 10:00. After that,
     * car 1 is recorded by counters 2 and 3 at 11:00 and car 2 by counter 2
     * alone at 11:30.
     *
     * EXPECTED: the question "does the primary have it?" only makes sense
     * for the moments at which the primary was counting. It is asked pass
     * by pass:
     * - before 10:00 the primary decides, exactly as in the test above
     *   (car 2 counts on its word alone; car 3, which it missed, does not);
     * - from 10:00 on the primary has nothing BY DECISION, so the other
     *   counters decide by agreement: car 1's pass at 11:00 counts (two
     *   agree), car 2's lone entry at 11:30 waits.
     * Trusting a counter that is no longer counting would freeze the race.
     *
     * (Until round 3 a switched-off primary made the WHOLE race fall back
     * to "confirmadas", which also changed laps the primary had decided
     * while it was counting.)
     */
    public function testFontePrincipalAsksThePrimaryOnlyWhileItWasCounting(): void
    {
        $race = $this->raceWithOneCarOfEachKind();
        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = 1;
        $race->deactivateSource(1, '10:00');
        $race->pass(1, '11:00', [2, 3]);
        $race->pass(2, '11:30', [2]);

        $this->assertLapCounts([1 => 2, 2 => 1, 3 => 0, 4 => 1], $race->run('12:00'));
    }

    /**
     * SITUATION: "fonte_principal" with counter 1 as the primary. Counter 1
     * was confused from minute 4 to minute 8 and a reviewer excluded it for
     * that interval ("excluir esta fonte"). During the interval car 2 was
     * recorded by counters 2 and 3, and car 3 by counter 2 alone. Later a
     * reviewer excludes counter 1 for the whole race, with no end time.
     *
     * WHAT WENT WRONG BEFORE: an excluded source's entries are dropped, so
     * "count what the primary has" counted nothing while it was excluded.
     * Excluding the primary for the whole race zeroed the race.
     *
     * EXPECTED: inside the exclusion the passes follow the agreement rule
     * (car 2 gets its lap, car 3's lone entry waits); outside it the
     * primary still decides (car 4, recorded by the primary alone at
     * 09:00, counts). With the primary excluded for the whole race, the
     * race is counted by agreement throughout, not zeroed.
     */
    public function testFontePrincipalWithThePrimaryExcludedCountsThatStretchByAgreement(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = 1;
        $race->start('00:00');
        $race->firstCrossing([2, 3, 4]);
        $race->pass(2, '05:00', [2, 3]);
        $race->pass(3, '06:00', [2]);
        $race->pass(4, '09:00', [1]);
        $exclusion = $race->excludeSource(1, '04:00', '08:00');

        $this->assertLapCounts([2 => 1, 3 => 0, 4 => 1], $race->run('12:00'));

        // Without the exclusion the primary decides everywhere: it has
        // neither car 2 nor car 3 at minutes 5 and 6.
        $race->undo($exclusion);
        $this->assertLapCounts([2 => 0, 3 => 0, 4 => 1], $race->run('12:00'));

        // Excluded for the whole race: the first crossings (pass zero) are
        // still there thanks to counters 2 and 3, car 2 has its lap, and
        // car 4's pass, which only the excluded primary had, is gone.
        $race->excludeSource(1, null, null);
        $wholeRace = $race->run('12:00');
        $this->assertLapCounts([2 => 1, 3 => 0, 4 => 0], $wholeRace);
        $this->assertSame(RaceScenario::ms('00:20'), $wholeRace->cars[4]->ultimaPassagemMs, 'The race is not zeroed: pass zero stands.');
    }

    /**
     * SITUATION: "fonte_principal" with counter 1 as the primary. Nobody
     * recorded car 5 at minute 6, and a reviewer who watched the video adds
     * the pass ("adicionar"). The primary has no entry for it.
     *
     * EXPECTED: a reviewer's word counts in every mode, whether it is a
     * confirmation of somebody's entry (car 4 above) or a pass added from
     * nothing. Car 5 has its lap.
     */
    public function testFontePrincipalCountsAPassAReviewerAdded(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = 1;
        $race->start('00:00');
        $race->firstCrossing([5]);
        $this->assertLapCounts([5 => 0], $race->run('08:00'));

        $race->addPass(5, '06:00');

        $this->assertLapCounts([5 => 1], $race->run('08:00'));
    }

    /**
     * SITUATION: "fonte_principal" is selected with a primary id that is
     * not a counter at all: the pit log's source, or a number that no
     * source has.
     *
     * EXPECTED: as with no primary chosen, the race is counted by
     * agreement.
     */
    public function testFontePrincipalPointingAtSomethingThatIsNotACounterFallsBack(): void
    {
        foreach ([9 => 'the pit log', 77 => 'no source at all'] as $primaryId => $what) {
            $race = $this->raceWithOneCarOfEachKind();
            $race->addSource(9, 'Box', \Baja\Contagem\Engine\Data\Source::TIPO_BOX);
            $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
            $race->settings->fontePrincipalId = $primaryId;

            $this->assertLapCounts([1 => 1, 2 => 0, 3 => 1, 4 => 1], $race->run('12:00'), "Primary is $what.");
        }
    }

    /**
     * EXPECTED: the open flags are identical in the four modes. Here that
     * is one flag: car 2's lone entry from counter 1. (Car 4's lone entry
     * was confirmed by a reviewer, so it is not flagged.)
     */
    public function testTheSameFlagsAreRaisedInEveryMode(): void
    {
        $flagIdsByMode = [];
        foreach (Settings::MODOS_PUBLICACAO as $mode) {
            $race = $this->raceWithOneCarOfEachKind();
            $race->settings->modoPublicacao = $mode;
            $race->settings->fontePrincipalId = 1;
            $flagIdsByMode[$mode] = array_map(fn(Flag $flag) => $flag->id, $race->run('12:00')->openFlags());
        }

        $this->assertCount(1, $flagIdsByMode[Settings::MODO_CONFIRMADAS]);
        $this->assertStringContainsString(Flag::SOLITARIA, $flagIdsByMode[Settings::MODO_CONFIRMADAS][0]);
        $this->assertSame($flagIdsByMode[Settings::MODO_CONFIRMADAS], $flagIdsByMode[Settings::MODO_FONTE_PRINCIPAL]);
        $this->assertSame($flagIdsByMode[Settings::MODO_CONFIRMADAS], $flagIdsByMode[Settings::MODO_TODAS]);
        $this->assertSame($flagIdsByMode[Settings::MODO_CONFIRMADAS], $flagIdsByMode[Settings::MODO_DESLIGADA]);
    }
}
