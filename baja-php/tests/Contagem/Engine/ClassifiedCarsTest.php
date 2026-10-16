<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Cars that are present at the event but not classified.
 *
 * A prova can name one of its fields as the "classification field" (for
 * example APTO, filled SIM or NÃO per car). A car takes part when it is
 * present AND classified. The engine is handed two lists:
 *
 * - `validCars`: the cars that take part;
 * - `unclassifiedCars`: present, but not classified.
 *
 * A pass typed for an unclassified car is never refused and never thrown
 * away (the list on a counter's phone may be out of date, and the record is
 * what answers a protest). But it never counts on the counters' word,
 * however many of them agree: only a reviewer can say "contar mesmo assim".
 *
 * In these tests teams 1, 2 and 3 take part and team 9 is not classified.
 */
final class ClassifiedCarsTest extends EngineTestCase
{
    private function raceWithAnUnclassifiedCar(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([1, 2, 3]);
        $race->teamsNotClassified([9]);
        $race->start('00:00');
        $race->firstCrossing([1, 2, 3]);
        return $race;
    }

    /**
     * SITUATION: car 9 went out on track anyway. All three counters record
     * it at 00:20 and again at 03:00.
     *
     * EXPECTED: three counters agreeing is not enough. Car 9 has no
     * official pass and is not in the standings at all. Each of its two
     * pass groups has a "carro_nao_classificado" flag, with no suggestion.
     * The flag says that confirming WOULD add a pass. The two groups are
     * kept as "pendente" rows, waiting for a reviewer.
     */
    public function testAPassOfAnUnclassifiedCarIsFlaggedAndNeverCountsOnItsOwn(): void
    {
        $race = $this->raceWithAnUnclassifiedCar();
        $first = $race->pass(9, '00:20');
        $race->pass(9, '03:00');

        $result = $race->run('05:00');

        $this->assertSame(0, $result->lapCount(9));
        $this->assertArrayNotHasKey(9, $result->cars, 'A car that does not take part is not in the standings.');
        $flags = $result->openFlags();
        $this->assertSame([Flag::CARRO_NAO_CLASSIFICADO, Flag::CARRO_NAO_CLASSIFICADO], array_map(fn(Flag $flag) => $flag->tipo, $flags));
        $this->assertSame([9], $flags[0]->carros);
        $this->assertSame(array_values($first), $flags[0]->passagemIds);
        $this->assertNull($flags[0]->sugestao);
        $this->assertNull($flags[0]->fonteId, 'Three counters recorded it; the flag is about no one in particular.');
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flags[0]->efeitoDeConfirmar);
        $this->assertStringContainsString('não está classificado', $flags[0]->motivo);
        $this->assertFalse($result->groupOfEntry($first[1])->contado);
        $this->assertFalse($result->groupOfEntry($first[1])->referencia);

        $rowsOfCar9 = array_values(array_filter($result->laps, fn(Lap $lap) => $lap->carro === 9));
        $this->assertSame([Lap::PENDENTE, Lap::PENDENTE], array_map(fn(Lap $lap) => $lap->estado, $rowsOfCar9));
    }

    /**
     * SITUATION: the same two crossings of car 9, in each publish mode,
     * including "todas" (every entry counts) and "fonte_principal" with the
     * primary counter having both entries.
     *
     * EXPECTED: no mode makes an unclassified car count. The flags are the
     * same in all of them. The fast path (`countCars`), given the same list
     * of unclassified cars, agrees.
     */
    public function testNoPublishModeCountsAnUnclassifiedCar(): void
    {
        foreach (Settings::MODOS_PUBLICACAO as $mode) {
            $race = $this->raceWithAnUnclassifiedCar();
            $race->settings->modoPublicacao = $mode;
            $race->settings->fontePrincipalId = 1;
            $race->pass(9, '00:20');
            $race->pass(9, '03:00');

            $result = $race->run('05:00');

            $this->assertSame(0, $result->lapCount(9), "Mode $mode.");
            $this->assertCount(2, $this->openFlags($result, Flag::CARRO_NAO_CLASSIFICADO), "Mode $mode.");
            $count = $race->countCars('05:00', [9]);
            $this->assertSame(0, $count->lapCount(9), "Mode $mode, fast path.");
            $this->assertNull($count->lastPassMs(9), "Mode $mode, fast path.");
        }
    }

    /**
     * SITUATION: the organisers decide car 9 may race after all, but the
     * classification field has not been changed yet. A reviewer presses
     * "contar mesmo assim" on both flags (a confirmation of each group).
     *
     * EXPECTED: a reviewer's word counts. After the first confirmation car
     * 9 has its pass zero, and the flag that is left says what confirming
     * it would do: one more pass, making a lap of 160 seconds. After the
     * second, car 9 has one lap, appears in the standings, and nothing is
     * flagged. The fast path agrees at each step.
     */
    public function testAReviewerCanCountAnUnclassifiedCarsPassesAnyway(): void
    {
        $race = $this->raceWithAnUnclassifiedCar();
        $first = $race->pass(9, '00:20');
        $second = $race->pass(9, '03:00');

        $race->confirm($first[1]);
        $oneConfirmed = $race->run('05:00');
        $this->assertSame(0, $oneConfirmed->lapCount(9));
        $this->assertSame(RaceScenario::ms('00:20'), $oneConfirmed->cars[9]->ultimaPassagemMs, 'Pass zero is official.');
        $remaining = $this->assertSingleOpenFlag($oneConfirmed, Flag::CARRO_NAO_CLASSIFICADO);
        $this->assertSame(160000, $remaining->voltaResultanteMs);
        $this->assertSame(RaceScenario::ms('00:20'), $race->countCars('05:00', [9])->lastPassMs(9));

        $race->confirm($second[2]);
        $bothConfirmed = $race->run('05:00');
        $this->assertSame(1, $bothConfirmed->lapCount(9));
        $this->assertSame(1, $bothConfirmed->cars[9]->voltas);
        $this->assertNoOpenFlags($bothConfirmed);
        $this->assertSame(1, $race->countCars('05:00', [9])->lapCount(9));
    }

    /**
     * SITUATION: nobody recorded car 9, but a reviewer ADDS a pass for it
     * ("adicionar"), twice.
     *
     * EXPECTED: an added pass is the reviewer's word too: car 9 has a pass
     * zero and a lap.
     */
    public function testAPassAReviewerAddsForAnUnclassifiedCarCounts(): void
    {
        $race = $this->raceWithAnUnclassifiedCar();
        $race->addPass(9, '00:20');
        $race->addPass(9, '03:00');

        $result = $race->run('05:00');

        $this->assertSame(1, $result->lapCount(9));
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: car 2 crosses at 03:00. Counters 2 and 3 type 2; counter 1
     * types 9, the unclassified car (the keys are next to each other on
     * nobody's keyboard, but it happens). Later, at 06:00, counter 1 alone
     * types 9 again with no other car around.
     *
     * EXPECTED:
     * - the first stray entry is recognised as a probable typo for car 2,
     *   with the usual suggestion to reassign it: that says more, and fixes
     *   more, than "car 9 is not classified";
     * - the second has no such explanation and gets "carro_nao_classificado"
     *   (not "solitaria"), naming counter 1.
     */
    public function testATypoOntoAnUnclassifiedNumberIsStillRecognisedAsATypo(): void
    {
        $race = $this->raceWithAnUnclassifiedCar();
        $race->pass(2, '03:00', [2, 3]);
        $typo = $race->entry(1, 9, '03:00');
        $stray = $race->entry(1, 9, '06:00');

        $result = $race->run('08:00');

        $byEntry = [];
        foreach ($result->openFlags() as $flag) {
            $byEntry[$flag->passagemIds[0]] = $flag;
        }
        $this->assertSame([$typo, $stray], array_keys($byEntry));
        $this->assertSame(Flag::TROCA_PROVAVEL, $byEntry[$typo]->tipo);
        $this->assertSame(['tipo' => Decision::REATRIBUIR, 'passagem_id' => $typo, 'equipe_numero' => 2], $byEntry[$typo]->sugestao);
        $this->assertSame(Flag::CARRO_NAO_CLASSIFICADO, $byEntry[$stray]->tipo);
        $this->assertSame(1, $byEntry[$stray]->fonteId);
    }

    /**
     * SITUATION: all three counters type 9 (present, not classified) and 99
     * (no such team at the event) at 03:00.
     *
     * EXPECTED: two different flags, because they are two different
     * problems: 9 is "carro_nao_classificado", 99 is "carro_desconhecido".
     * And they are treated differently: the unknown number 99 still counts
     * when counters agree (a lap must never be invisible; somebody typed a
     * real car's number wrong three times, or the team list is wrong),
     * while 9 does not.
     */
    public function testNotClassifiedIsNotTheSameAsUnknown(): void
    {
        $race = $this->raceWithAnUnclassifiedCar();
        $race->pass(9, '03:00');
        $race->pass(99, '03:00');

        $result = $race->run('05:00');

        $typeByCar = [];
        foreach ($result->openFlags() as $flag) {
            $typeByCar[$flag->carros[0]] = $flag->tipo;
        }
        ksort($typeByCar);
        $this->assertSame([9 => Flag::CARRO_NAO_CLASSIFICADO, 99 => Flag::CARRO_DESCONHECIDO], $typeByCar);
        $this->assertArrayHasKey(99, $result->cars, 'The unknown number has an official pass and is shown.');
        $this->assertArrayNotHasKey(9, $result->cars);
    }

    /**
     * SITUATION: the flag "primeira_passagem_tardia" compares each car's
     * first pass with the field's. Teams 1, 2 and 3 take part: cars 1 and 2
     * make their first pass at 00:20, car 3 only at 02:00 (its real first
     * crossing was missed), and all lap in 180 seconds. Four numbers that
     * are NOT teams taking part (91 to 94) were also typed by everybody,
     * each with a first pass at 02:00.
     *
     * EXPECTED: "the field" is the cars that take part. Its median first
     * pass is 00:20, so car 3 at 02:00 is late and is flagged. If the four
     * strangers were counted as part of the field, the median would be
     * 02:00 and car 3 would look perfectly normal; the second half of the
     * test shows exactly that by giving the engine no team list.
     */
    public function testTheLateFirstPassFlagLooksOnlyAtCarsThatTakePart(): void
    {
        $build = function (): RaceScenario {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            foreach ([1 => 20, 2 => 20, 3 => 120, 91 => 120, 92 => 120, 93 => 120, 94 => 120] as $car => $firstPassSeconds) {
                $race->pass($car, RaceScenario::clock($firstPassSeconds));
                $race->pass($car, RaceScenario::clock($firstPassSeconds + 180));
            }
            return $race;
        };

        $withTeamList = $build();
        $withTeamList->teamsPresent([1, 2, 3]);
        $late = $this->openFlags($withTeamList->run('06:00'), Flag::PRIMEIRA_PASSAGEM_TARDIA);
        $this->assertSame([[3]], array_map(fn(Flag $flag) => $flag->carros, $late));

        $withoutTeamList = $build();
        $this->assertSame([], $this->openFlags($withoutTeamList->run('06:00'), Flag::PRIMEIRA_PASSAGEM_TARDIA));
    }

    /**
     * SITUATION: the prova has no classification field, so the list of
     * unclassified cars is empty and car 9 is simply one of the teams.
     *
     * EXPECTED: today's behaviour, unchanged: car 9 counts like any car.
     */
    public function testWithNoClassificationFieldEveryPresentCarTakesPart(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([1, 2, 3, 9]);
        $race->start('00:00');
        $race->pass(9, '00:20');
        $race->pass(9, '03:00');

        $result = $race->run('05:00');

        $this->assertSame(1, $result->lapCount(9));
        $this->assertNoOpenFlags($result);
    }
}
