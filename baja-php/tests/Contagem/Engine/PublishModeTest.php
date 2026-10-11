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
     * counter 1 is switched off on the settings page.
     *
     * EXPECTED: trusting a counter that is no longer counting would freeze
     * the race, so the engine falls back to "confirmadas".
     */
    public function testFontePrincipalFallsBackWhenThePrimarySourceWasSwitchedOff(): void
    {
        $race = $this->raceWithOneCarOfEachKind();
        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = 1;
        $race->deactivateSource(1, '10:00');

        $this->assertLapCounts([1 => 1, 2 => 0, 3 => 1, 4 => 1], $race->run('12:00'));
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
