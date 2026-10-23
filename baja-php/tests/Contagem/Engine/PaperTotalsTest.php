<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Source;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Imported paper totals (a source of type "totais").
 *
 * After the race somebody may type in the totals of a count kept on paper:
 * one line per car, "car, laps". These totals NEVER change the official
 * count. They are compared with it, and every difference becomes a
 * "divergencia_total" flag for a person to look into.
 *
 * (PitAndLapChecksTest has the basic case: a different number on paper.)
 */
final class PaperTotalsTest extends EngineTestCase
{
    private const PAPER = 9;

    /** Cars 1 and 2 each cross three times (two laps); car 3 crosses once (no lap). */
    private function raceWithPaperSource(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->addSource(self::PAPER, 'Planilha de papel', Source::TIPO_TOTAIS);
        $race->start('00:00');
        $race->firstCrossing([1, 2, 3]);
        foreach ([1, 2] as $car) {
            $race->pass($car, '03:0' . $car);
            $race->pass($car, '06:0' . $car);
        }
        return $race;
    }

    /**
     * SITUATION: the paper list says "car 1: 2 laps" and does not mention
     * car 2 at all, although car 2 has two official laps. Car 3, which has
     * no lap, is not on the list either.
     *
     * WHAT WENT WRONG: whoever kept the paper count lost car 2 entirely.
     *
     * EXPECTED: a car with official laps that the paper does not list is a
     * difference like any other: one flag, for car 2, saying the list does
     * not have it. Car 1 agrees, and car 3 (no laps, not listed) is fine.
     */
    public function testACarWithOfficialLapsThatThePaperDoesNotListIsFlagged(): void
    {
        $race = $this->raceWithPaperSource();
        $race->importTotals(self::PAPER, [1 => 2]);

        $result = $race->run('10:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::DIVERGENCIA_TOTAL);
        $this->assertSame([2], $flag->carros);
        $this->assertSame(self::PAPER, $flag->fonteId);
        $this->assertNull($flag->sugestao);
        $this->assertSame('Planilha de papel não lista o carro 2, que tem 2 voltas na contagem oficial.', $flag->motivo);
        $this->assertSame('divergencia_total:9-2', $flag->id);
    }

    /**
     * SITUATION: the paper lists a car the official count has never seen
     * (car 50, 4 laps), and gives car 3 one lap where the official count
     * has none.
     *
     * EXPECTED: both are differences: two flags, each giving both numbers.
     */
    public function testACarOnPaperWithMoreLapsThanTheOfficialCountIsFlagged(): void
    {
        $race = $this->raceWithPaperSource();
        $race->importTotals(self::PAPER, [1 => 2, 2 => 2, 3 => 1, 50 => 4]);

        $flags = $this->openFlags($race->run('10:00'), Flag::DIVERGENCIA_TOTAL);

        $reasonsByCar = [];
        foreach ($flags as $flag) {
            $reasonsByCar[$flag->carros[0]] = $flag->motivo;
        }
        ksort($reasonsByCar);
        $this->assertSame(
            [
                3 => 'Planilha de papel informa 1 voltas para o carro 3; a contagem oficial tem 0.',
                50 => 'Planilha de papel informa 4 voltas para o carro 50; a contagem oficial tem 0.',
            ],
            $reasonsByCar
        );
    }

    /**
     * SITUATION: the paper agrees with the official count on every car; or
     * no totals were imported yet; or the paper source was switched off.
     *
     * EXPECTED: no flags in any of the three cases. In particular an EMPTY
     * list is "nothing imported yet", not "every car is missing from it".
     */
    public function testAgreeingEmptyOrSwitchedOffTotalsRaiseNothing(): void
    {
        $agreeing = $this->raceWithPaperSource();
        $agreeing->importTotals(self::PAPER, [1 => 2, 2 => 2, 3 => 0]);
        $this->assertNoOpenFlags($agreeing->run('10:00'));

        $empty = $this->raceWithPaperSource();
        $empty->importTotals(self::PAPER, []);
        $this->assertNoOpenFlags($empty->run('10:00'));

        $switchedOff = $this->raceWithPaperSource();
        $switchedOff->importTotals(self::PAPER, [1 => 7]);
        $switchedOff->source(self::PAPER)->ativa = false;
        $this->assertNoOpenFlags($switchedOff->run('10:00'));
    }

    /**
     * EXPECTED: whatever the paper says, the official count is untouched,
     * and the paper source is not one of the counters: it is never
     * "expected" to record a pass, so two human counters agreeing is still
     * what makes a pass official.
     */
    public function testTotalsNeverChangeTheOfficialCount(): void
    {
        $race = $this->raceWithPaperSource();
        $withoutPaper = $race->run('10:00');
        $race->importTotals(self::PAPER, [1 => 9, 2 => 0]);
        $withPaper = $race->run('10:00');

        $this->assertLapCounts([1 => 2, 2 => 2, 3 => 0], $withPaper);
        $this->assertSame($withoutPaper->cars[1]->ultimaPassagemMs, $withPaper->cars[1]->ultimaPassagemMs);
        $this->assertSame(0, $withPaper->sourceStats[self::PAPER]->faltas);
    }
}
