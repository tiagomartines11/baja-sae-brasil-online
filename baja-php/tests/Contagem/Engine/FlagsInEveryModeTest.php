<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;
use Baja\Tests\Contagem\Engine\Support\SyntheticReviewer;

/**
 * The publish mode decides what COUNTS. It must never decide what a
 * reviewer is SHOWN.
 *
 * If flags depended on the mode, switching mode during a race would make
 * problems appear and disappear, and a race run in "desligada" (next to the
 * old spreadsheet) would be reviewed differently from the same race run for
 * real. Flags are therefore always judged against the default rule ("two
 * counters agree"), whatever the mode in force.
 */
final class FlagsInEveryModeTest extends EngineTestCase
{
    /**
     * SITUATION: whole synthetic races with every kind of mistake, a
     * handover and a finish, computed in each of the four publish modes;
     * first untouched, then after a reviewer accepted every suggestion.
     *
     * EXPECTED: the review queue is identical in the four modes: the same
     * flags, about the same entries, with the same suggestions and the same
     * explanations, in both states of the race. (Only the order may
     * differ, because the queue is sorted by standings and the standings
     * depend on what counts.)
     */
    public function testAWholeRaceShowsTheSameFlagsInAllFourModes(): void
    {
        foreach ([1, 2, 3, 7] as $seed) {
            $race = SyntheticRace::generate(SyntheticRaceConfig::withSeed($seed)->withFinish());

            $unreviewed = [];
            $reviewed = [];
            foreach (Settings::MODOS_PUBLICACAO as $mode) {
                $input = $race->input();
                $input->settings->modoPublicacao = $mode;
                $input->settings->fontePrincipalId = 1;
                $unreviewed[$mode] = $this->describeFlags(Engine::run($input));
                $reviewed[$mode] = $this->describeFlags(SyntheticReviewer::acceptEverySuggestion($input));
            }

            $this->assertGreaterThan(10, count($unreviewed[Settings::MODO_CONFIRMADAS]), 'The race must have something to review.');
            foreach (Settings::MODOS_PUBLICACAO as $mode) {
                $this->assertSame($unreviewed[Settings::MODO_CONFIRMADAS], $unreviewed[$mode], "Seed $seed, mode $mode, before review.");
                $this->assertSame($reviewed[Settings::MODO_CONFIRMADAS], $reviewed[$mode], "Seed $seed, mode $mode, after review.");
            }
        }
    }

    /**
     * SITUATION: "todas" mode. Counter 1 records car 9 at 03:00 with the
     * others, and again at 03:12 (an unresolved "repetida"). In this mode
     * the second entry counts as a pass on its own, twelve seconds after
     * the first.
     *
     * WHAT COULD GO WRONG: the same problem being reported three times: as
     * the repeat, as a lone entry, and as an impossibly short lap.
     *
     * EXPECTED: one flag, the "repetida", in every mode. The repeat is
     * recorded on its pass group, so no other check flags that group again.
     */
    public function testAnOpenRepeatIsReportedOnceWhateverTheMode(): void
    {
        foreach (Settings::MODOS_PUBLICACAO as $mode) {
            $race = RaceScenario::withCounters(3);
            $race->settings->modoPublicacao = $mode;
            $race->settings->fontePrincipalId = 1;
            $race->start('00:00');
            $race->firstCrossing([9]);
            $race->pass(9, '03:00');
            $repeat = $race->entry(1, 9, '03:12');

            $result = $race->run('04:00');

            $this->assertSingleOpenFlag($result, Flag::REPETIDA);
            $this->assertSame([Flag::REPETIDA], $result->groupOfEntry($repeat)->alertasAbertos, "Mode $mode.");
        }
    }

    /**
     * SITUATION: "fonte_principal" mode with counter 1 as the primary. Car 5
     * is recorded at 03:00 by counter 1 only; car 6 at 03:30 by counters 2
     * and 3 only.
     *
     * EXPECTED: the COUNT follows the mode (car 5's lap counts, car 6's
     * does not), but the FLAG follows the default rule: car 5's lone entry
     * is flagged although it counts, and car 6's agreed pass is not flagged
     * although it does not count.
     */
    public function testFlagsFollowTheDefaultRuleEvenWhereTheCountDoesNot(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = 1;
        $race->start('00:00');
        $race->firstCrossing([5, 6]);
        $race->entry(1, 5, '03:00');
        $agreed = $race->pass(6, '03:30', [2, 3]);

        $result = $race->run('05:00');

        $this->assertLapCounts([5 => 1, 6 => 0], $result);
        $flag = $this->assertSingleOpenFlag($result, Flag::SOLITARIA);
        $this->assertSame([5], $flag->carros);
        $this->assertTrue($result->groupOfEntry($agreed[2])->referencia, 'Under the default rule the pass of car 6 would count.');
        $this->assertFalse($result->groupOfEntry($agreed[2])->contado);
    }

    /**
     * @return string[] one line per open flag: id, entries, suggestion and explanation, sorted
     */
    private function describeFlags(EngineResult $result): array
    {
        $descriptions = [];
        foreach ($result->openFlags() as $flag) {
            $descriptions[] = $flag->id . ' ' . json_encode($flag->passagemIds) . ' ' . json_encode($flag->sugestao) . ' ' . $flag->motivo;
        }
        sort($descriptions);
        return $descriptions;
    }
}
