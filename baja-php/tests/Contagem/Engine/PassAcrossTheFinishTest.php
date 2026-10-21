<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * A car on the counting line at the very moment the race ends (flag
 * "no_encerramento").
 *
 * AfterFinishTest explains the rule: passes up to the finish are racing
 * passes, and after the finish each car gets one more pass that counts.
 * Which side of the finish a pass is on is decided by its pass time, the
 * median of what the counters typed.
 *
 * When the counters pressed Enter on BOTH sides of the finish instant, that
 * median decides a whole lap on a difference of a second: "last pass of the
 * race" means the car's next crossing still counts, "the pass after the
 * finish" means it does not. Until round 3 this was decided silently. Now
 * the group is flagged so a person looks at it.
 *
 * In every test here car 7 made its first crossing at 00:20 and completed
 * lap 1 at 03:00; race control ended the race at 05:00.
 */
final class PassAcrossTheFinishTest extends EngineTestCase
{
    /**
     * @param string[] $typedAt when counters 1, 2 and 3 pressed Enter for the crossing around the finish
     */
    private function raceEndingAtFiveMinutes(array $typedAt): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '00:20');
        $race->pass(7, '03:00');
        $race->finish('05:00');
        foreach ($typedAt as $index => $clock) {
            $race->entry($index + 1, 7, $clock);
        }
        $race->pass(7, '08:00');
        return $race;
    }

    /**
     * SITUATION: car 7 crosses as the flag falls. Counter 1 types it at
     * 04:59, counter 2 at 05:00 sharp, counter 3 at 05:01. It crosses again
     * at 08:00.
     *
     * EXPECTED: the median is 05:00, which still belongs to the race, so
     * this is lap 2 and the crossing at 08:00 is the one pass allowed after
     * the finish: 3 laps. And a "no_encerramento" flag says that the
     * entries lie on both sides and which side was taken. It has no
     * suggestion (the engine cannot know better than the median), and
     * confirming it adds no pass: the group counts already.
     */
    public function testAPassTypedOnBothSidesOfTheFinishIsFlagged(): void
    {
        $race = $this->raceEndingAtFiveMinutes(['04:59', '05:00', '05:01']);

        $result = $race->run('10:00');

        $this->assertLapCounts([7 => 3], $result);
        $flag = $this->assertSingleOpenFlag($result, Flag::NO_ENCERRAMENTO);
        $this->assertSame([7], $flag->carros);
        $this->assertCount(3, $flag->passagemIds);
        $this->assertSame(RaceScenario::ms('05:00'), $flag->tsMs);
        $this->assertNull($flag->sugestao);
        $this->assertSame(Flag::EFEITO_NENHUM, $flag->efeitoDeConfirmar);
        $this->assertNull($flag->voltaResultanteMs);
        $this->assertStringContainsString('antes do encerramento', $flag->motivo);
        $this->assertStringContainsString('de 1 s antes a 1 s depois', $flag->motivo);
        $this->assertFalse($result->laps[2]->elegivelMelhorVolta, 'A lap ending at a flagged pass is not a best lap.');
    }

    /**
     * SITUATION: the same crossing, typed a little later by two counters:
     * 04:59, 05:01 and 05:02.
     *
     * EXPECTED: now the median is 05:01, after the finish. The pass is the
     * one allowed after the finish (lap 2) and the crossing at 08:00 is
     * left out: 2 laps, one fewer than in the test above, for a difference
     * of one second in what was typed. That is exactly why it is flagged;
     * the reason now says "depois do encerramento".
     */
    public function testWhichSideTheMedianFallsOnDecidesALap(): void
    {
        $race = $this->raceEndingAtFiveMinutes(['04:59', '05:01', '05:02']);

        $result = $race->run('10:00');

        $this->assertLapCounts([7 => 2], $result);
        $flag = $this->assertSingleOpenFlag($result, Flag::NO_ENCERRAMENTO);
        $this->assertStringContainsString('depois do encerramento', $flag->motivo);
        $this->assertStringContainsString('de 1 s antes a 2 s depois', $flag->motivo);
    }

    /** @return array<string, array{0: string[], 1: bool}> what the three counters typed, and whether a flag is expected */
    public static function entriesAroundTheFinish(): array
    {
        return [
            'all three before the finish' => [['04:57', '04:58', '04:59'], false],
            'the last one exactly at the finish' => [['04:58', '04:59', '05:00'], false],
            'all three at the finish instant' => [['05:00', '05:00', '05:00'], false],
            'the last one a millisecond after it' => [['05:00', '05:00', '05:00.001'], true],
            'the first one exactly at the finish, the others after' => [['05:00', '05:01', '05:02'], true],
            'all three after the finish' => [['05:00.001', '05:01', '05:02'], false],
        ];
    }

    /**
     * EXPECTED: the finish instant itself belongs to the race ("after the
     * finish" is strictly after). A group is flagged when at least one of
     * its entries is at or before the finish and at least one is after it,
     * and only then.
     *
     * @param string[] $typedAt
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entriesAroundTheFinish')]
    public function testOnlyGroupsWithEntriesOnBothSidesAreFlagged(array $typedAt, bool $flagExpected): void
    {
        $result = $this->raceEndingAtFiveMinutes($typedAt)->run('10:00');

        $this->assertCount($flagExpected ? 1 : 0, $this->openFlags($result, Flag::NO_ENCERRAMENTO));
    }

    /**
     * SITUATION: the reviewer looks at the video, agrees with the side the
     * median chose, and presses "está correto" (a confirmation).
     *
     * EXPECTED: the flag is gone and the count is what it was: 3 laps.
     */
    public function testConfirmingSilencesTheFlagAndChangesNoCount(): void
    {
        $race = $this->raceEndingAtFiveMinutes(['04:59', '05:00', '05:01']);
        $flag = $this->assertSingleOpenFlag($race->run('10:00'), Flag::NO_ENCERRAMENTO);

        $race->confirm($flag->passagemIds[0]);
        $result = $race->run('10:00');

        $this->assertNoOpenFlags($result);
        $this->assertLapCounts([7 => 3], $result);
    }

    /**
     * SITUATION: `apos_encerramento` = "nenhuma": nothing after the finish
     * counts. Car 7's crossing was typed at 04:59, 05:01 and 05:02.
     *
     * EXPECTED: with the median after the finish the pass is left out, and
     * with this setting that costs car 7 the lap outright (1 lap instead of
     * 2). Passes left out after the finish normally raise no flag, but this
     * one does: one counter saw it before the flag fell.
     */
    public function testWithNenhumaAStraddlingPassThatIsLeftOutIsStillFlagged(): void
    {
        $race = $this->raceEndingAtFiveMinutes(['04:59', '05:01', '05:02']);
        $race->settings->aposEncerramento = Settings::APOS_NENHUMA;

        $result = $race->run('10:00');

        $this->assertLapCounts([7 => 1], $result);
        $flag = $this->assertSingleOpenFlag($result, Flag::NO_ENCERRAMENTO);
        $this->assertSame(Flag::EFEITO_NENHUM, $flag->efeitoDeConfirmar, 'Confirming cannot bring back a pass the race rules leave out.');
    }

    /**
     * SITUATION: the same entries around 05:00, but race control never
     * ended the race (or cancelled the finish).
     *
     * EXPECTED: no finish, nothing to straddle, no flag.
     */
    public function testWithoutAFinishThereIsNothingToStraddle(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '00:20');
        $finish = $race->finish('05:00');
        $race->entry(1, 7, '04:59');
        $race->entry(2, 7, '05:00');
        $race->entry(3, 7, '05:01');
        $this->assertCount(1, $this->openFlags($race->run('06:00'), Flag::NO_ENCERRAMENTO));

        $race->cancel($finish);

        $this->assertNoOpenFlags($race->run('06:00'));
    }
}
