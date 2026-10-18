<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\UnreadAssigner;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Passes recorded without a number ("??"): a counter saw a car cross and
 * could not read it. The engine works out which car it was from what the
 * other counters read at that moment, and gives it that car by itself only
 * when no other car is a believable answer. Otherwise a reviewer is asked,
 * with the candidates ("numero_ilegivel").
 *
 * (`firstCrossing` is each car's pass zero, seen by every counter.)
 */
final class UnreadPassTest extends EngineTestCase
{
    /**
     * SITUATION: car 7 crosses at 04:00. Counters 1 and 3 type 7, counter 2
     * presses "??" 0.6 s later. Nothing else crosses near then.
     *
     * EXPECTED: the "??" is car 7 (the others read it, counter 2 did not
     * read car 7 there, no other car is near). It is counter 2's entry for
     * car 7 from then on: counter 2 is not charged a miss, and nothing is
     * left for a reviewer. The record says what was done, and with what
     * evidence.
     */
    public function testAClearUnreadPassIsGivenItsCar(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7, 9]);
        $read = $race->pass(7, '04:00', [1, 3]);
        $unread = $race->unread(2, '04:00.6');

        $result = $race->run('10:00');

        $this->assertLapCounts([7 => 1, 9 => 0], $result);
        $this->assertNoOpenFlags($result);
        $entry = $result->effectiveEntries[$unread];
        $this->assertSame(7, $entry->carro);
        $this->assertNull($entry->carroDigitado, 'what was typed ("??") is never lost');
        $this->assertTrue($entry->atribuidoAutomaticamente);
        $this->assertEqualsCanonicalizing([$read[1], $unread, $read[3]], $result->groupOfEntry($unread)->entryIds());

        $record = $this->resolvedFlag($result, Flag::NUMERO_ATRIBUIDO);
        $this->assertSame([7], $record->carros);
        $this->assertSame(7, $record->atribuicao['carro']);
        $this->assertSame(600, $record->atribuicao['desvio_ms']);
        $this->assertSame([1, 3], $record->atribuicao['fonte_ids']);
        $this->assertEqualsCanonicalizing([$read[1], $read[3]], $record->atribuicao['passagem_ids']);
        $this->assertNull($record->atribuicao['margem_ms'], 'no other car was possible at all');
        $this->assertArrayHasKey('atribuicao', $record->toArray());

        $this->assertSame(1, $result->sourceStats[2]->ilegiveis);
        $this->assertSame(1, $result->sourceStats[2]->ilegiveisAtribuidas);
        $this->assertSame(0, $result->sourceStats[2]->faltas);
        $this->assertSame(1, $this->lapsOf($race->countCars('10:00', [7])->lapsByCar[7]), 'the per-car recount says the same');
    }

    /**
     * SITUATION: as above, but counter 3 missed car 7: only counter 1 typed
     * it, and counter 2 pressed "??".
     *
     * EXPECTED: car 7 is still the only answer, but here the "??" would be
     * the vote that makes the pass count, and counter 1's reading could be
     * a wrong number. So it is not applied by itself: the reviewer gets
     * "numero_ilegivel" suggesting car 7, saying car 7 may be a lap short.
     * Accepting it counts the pass.
     */
    public function testAnUnreadPassThatWouldDecideTheCountIsOnlySuggested(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7, 9]);
        $race->entry(1, 7, '04:00');
        $unread = $race->unread(2, '04:00.6');

        $result = $race->run('10:00');

        $this->assertLapCounts([7 => 0], $result);
        $flag = $this->openFlags($result, Flag::NUMERO_ILEGIVEL)[0];
        $this->assertSame([7], array_column($flag->candidatos, 'carro'));
        $this->assertSame(['tipo' => Decision::REATRIBUIR, 'passagem_id' => $unread, 'equipe_numero' => 7], $flag->sugestao);
        $this->assertSame(Flag::DUVIDA_A_MENOS, $flag->contagemEmDuvida);

        $race->accept($flag);
        $this->assertLapCounts([7 => 1], $race->run('10:00'));
    }

    /**
     * SITUATION: cars 7, 9 and 11 cross in a bunch at 04:00, 04:01 and
     * 04:02.5. Counters 1 and 3 type all three; counter 2 types 7 and
     * presses "??" twice, at 04:01.3 and 04:02.6.
     *
     * EXPECTED: counter 2 typed 7, so its two "??" are 9 and 11. Taken in
     * order they are 0.3 s and 0.1 s from those crossings; swapped, 1.2 s
     * and 1.6 s. The order is clearly right and both are applied.
     */
    public function testTwoUnreadPassesInABunchAreSolvedTogether(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7, 9, 11]);
        $race->pass(7, '04:00');
        $race->pass(9, '04:01', [1, 3]);
        $race->pass(11, '04:02.5', [1, 3]);
        $first = $race->unread(2, '04:01.3');
        $second = $race->unread(2, '04:02.6');

        $result = $race->run('10:00');

        $this->assertSame(9, $result->effectiveEntries[$first]->carro);
        $this->assertSame(11, $result->effectiveEntries[$second]->carro);
        $this->assertLapCounts([7 => 1, 9 => 1, 11 => 1], $result);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: cars 7 and 9 cross a second apart (04:00 and 04:01); two
     * counters type both, counter 2 types neither and presses "??" once,
     * at 04:00.4.
     *
     * EXPECTED: 0.4 s from car 7 and 0.6 s from car 9 is no evidence either
     * way. Nothing is applied; the reviewer gets "numero_ilegivel" with
     * both cars, the closer first, and the closer as the suggestion. The
     * counts do not wait for it: both cars have two counters. Choosing car
     * 9 settles it: the entry is car 9's from then on.
     */
    public function testCompetingCandidatesAreLeftToTheReviewer(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7, 9]);
        $race->pass(7, '04:00', [1, 3]);
        $race->pass(9, '04:01', [1, 3]);
        $unread = $race->unread(2, '04:00.4');

        $result = $race->run('10:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::NUMERO_ILEGIVEL);
        $this->assertSame([$unread], $flag->passagemIds);
        $this->assertSame(2, $flag->fonteId);
        $this->assertSame([7, 9], array_column($flag->candidatos, 'carro'));
        $this->assertSame(['outros_contadores', 'outros_contadores'], array_column($flag->candidatos, 'motivo'));
        $this->assertSame([RaceScenario::ms('04:00'), RaceScenario::ms('04:01')], array_column($flag->candidatos, 'ts_ms'));
        $this->assertGreaterThan($flag->candidatos[1]['score'], $flag->candidatos[0]['score']);
        $this->assertSame(['tipo' => Decision::REATRIBUIR, 'passagem_id' => $unread, 'equipe_numero' => 7], $flag->sugestao);
        $this->assertNull($flag->contagemEmDuvida, 'both candidates count already');
        $this->assertArrayHasKey('candidatos', $flag->toArray());
        $this->assertLapCounts([7 => 1, 9 => 1], $result);
        $this->assertSame([$unread], array_map(fn($entry) => $entry->id, $result->unreadEntries));

        $race->reassign($unread, 9);
        $settled = $race->run('10:00');
        $this->assertNoOpenFlags($settled);
        $this->assertSame(3, $settled->groupOfEntry($unread)->sourceCount());
        $this->assertSame(9, $settled->groupOfEntry($unread)->carro);
    }

    /**
     * SITUATION: as in the first test (counters 1 and 3 type 7, counter 2
     * "??"), but a reviewer, or counter 2 on the phone, says the "??" was
     * car 9.
     *
     * EXPECTED: a person's word always wins over the engine's guess: the
     * entry is car 9's (a lone entry of counter 2 for 9, waiting), car 7's
     * pass is the other two counters', and nothing is "atribuído".
     */
    public function testAReviewerOrTheCounterOverridesTheAssignment(): void
    {
        foreach (['reviewer', 'counter'] as $who) {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            $race->firstCrossing([7, 9]);
            $race->pass(7, '04:00', [1, 3]);
            $unread = $race->unread(2, '04:00.6');
            if ($who === 'reviewer') {
                $race->reassign($unread, 9);
            } else {
                $race->counterFixes($unread, 9);
            }

            $result = $race->run('10:00');

            $entry = $result->effectiveEntries[$unread];
            $this->assertSame(9, $entry->carro, $who);
            $this->assertFalse($entry->atribuidoAutomaticamente, $who);
            $this->assertLapCounts([7 => 1, 9 => 0], $result, $who);
            $this->assertSame([], array_filter($result->flags, fn(Flag $flag) => $flag->tipo === Flag::NUMERO_ATRIBUIDO), $who);
            $this->assertSame(1, $result->sourceStats[2]->ilegiveis, $who . ': still a "??" in the statistics');
            $this->assertSame(0, $result->sourceStats[2]->ilegiveisAtribuidas, $who);
        }
    }

    /**
     * SITUATION: counter 1 types car 7 at 04:00; counter 2 presses "??"
     * twice, at 04:00.3 and 04:01.0 (a double tap on "??").
     *
     * EXPECTED: two "??" are never a repeat of each other: neither is
     * voided. One crossing cannot be both, and either could be it (0.3 s
     * against 1.0 s is no clear difference), so both go to the reviewer.
     */
    public function testTwoUnreadPassesInARowAreNoRepeat(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $race->entry(1, 7, '04:00');
        $race->unread(2, '04:00.3');
        $race->unread(2, '04:01');

        $result = $race->run('10:00');

        $this->assertCount(2, $this->openFlags($result, Flag::NUMERO_ILEGIVEL));
        $this->assertSame([], $this->openFlags($result, Flag::REPETIDA));
        $this->assertSame([], $result->autoVoidedEntryIds);
    }

    /**
     * SITUATION: a counter presses "??" at 04:00 and nobody else recorded
     * anything near then; there is no pace yet to say which car was due.
     *
     * EXPECTED: flagged with no candidate and no suggestion: the reviewer
     * types the car if somebody knows it, or voids the entry. "Confirmar"
     * only stops the warning.
     */
    public function testAnUnreadPassWithNothingNearIsFlaggedWithoutCandidates(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $unread = $race->unread(2, '04:00');

        $flag = $this->assertSingleOpenFlag($race->run('10:00'), Flag::NUMERO_ILEGIVEL);
        $this->assertSame([], $flag->candidatos);
        $this->assertSame([], $flag->carros);
        $this->assertNull($flag->sugestao);

        $race->confirm($unread);
        $this->assertNoOpenFlags($race->run('10:00'));
    }

    /**
     * SITUATION: eight cars lap every two minutes, every counter types every
     * crossing. Car 5 crosses again at 15:28 and every counter misses the
     * number; counter 2 presses "??" then.
     *
     * EXPECTED: nobody read a car there, but car 5 was due at that moment
     * (its last pass 13:28 plus its 2:00 lap) and has no pass near: it is
     * the first candidate, as "carro_esperado". Its count may be a lap
     * short, which the flag says.
     */
    public function testACarDueAtTheLineIsACandidate(): void
    {
        $race = $this->steadyRace();
        $race->unread(2, '15:28');

        $flag = $this->assertSingleOpenFlag($race->run('15:40'), Flag::NUMERO_ILEGIVEL);
        $this->assertSame(5, $flag->candidatos[0]['carro']);
        $this->assertSame('carro_esperado', $flag->candidatos[0]['motivo']);
        $this->assertNull($flag->candidatos[0]['ts_ms']);
        $this->assertSame(Flag::DUVIDA_A_MENOS, $flag->contagemEmDuvida);
        $this->assertNull($flag->sugestao, 'a car nobody read is never suggested at one keystroke');
    }

    /**
     * SITUATION: the same race; car 5 crosses at 15:28 and again at 19:28
     * and both are typed, but the 17:28 crossing between them nobody read:
     * counter 2 pressed "??" at 17:28.
     *
     * EXPECTED: car 5's passes around the "??" are one short (four minutes
     * between them, laps of two): "volta_faltando".
     */
    public function testACarThatWouldMissALapIsACandidate(): void
    {
        $race = $this->steadyRace();
        $race->pass(5, '15:28');
        $race->unread(2, '17:28');
        $race->pass(5, '19:28');

        $flag = $this->assertSingleOpenFlag($race->run('20:00'), Flag::NUMERO_ILEGIVEL);
        $this->assertSame(5, $flag->candidatos[0]['carro']);
        $this->assertSame('volta_faltando', $flag->candidatos[0]['motivo']);
    }

    // ----- the timing sensor -----

    /**
     * SITUATION: a precise timing sensor at the line. Car 7 crosses at
     * 04:00.50; the three counters press Enter at 04:00.8, 04:01.2 and
     * 04:01.5 (the median is 04:01.2), the sensor marks 04:00.50.
     *
     * EXPECTED: the mark is matched to car 7's crossing and times its lap:
     * the lap that pass ends is measured from the sensor's mark (220.5 s
     * from the pass zero at 00:20), and the pass is precisely timed (it may
     * give a best lap). It is no vote and no entry of the group: the count
     * is the counters'. The pass time itself (and so LAST) stays the
     * counters' median, as the per-car recount of an upload, which never
     * looks at marks, also says.
     */
    public function testASensorMarkTimesTheLap(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->addSensor(4);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $race->entry(1, 7, '04:00.8');
        $race->entry(2, 7, '04:01.2');
        $race->entry(3, 7, '04:01.5');
        $mark = $race->mark(4, '04:00.50');

        $result = $race->run('10:00');

        $group = $this->groupAt($result, 7, '04:00');
        $this->assertSame($mark, $group->marcaSensor->id);
        $this->assertSame(7, $group->marcaSensor->carro);
        $this->assertSame(RaceScenario::ms('04:00.50'), $group->tsAjustadoMs);
        $this->assertTrue($group->tempoPreciso);
        $this->assertSame(RaceScenario::ms('04:01.2'), $group->tsMs);
        $this->assertSame([1, 2, 3], $group->realSourceIds());
        $lap = array_values(array_filter($result->laps, fn($lap) => $lap->carro === 7 && $lap->numeroVolta === 1))[0];
        $this->assertSame(220500, $lap->tempoVoltaMs);
        $this->assertLapCounts([7 => 1], $result);
        $this->assertNoOpenFlags($result);
        $this->assertSame(1, $result->sourceStats[4]->sensor['atribuidas']);
        $this->assertSame(-700, $result->sourceStats[4]->sensor['desvio_mediano_ms']);
        $this->assertSame([], array_filter($result->flags, fn(Flag $flag) => $flag->tipo === Flag::NUMERO_ATRIBUIDO), 'a sensor would fill the list with them');

        $fast = $race->countCars('10:00', [7])->lapsByCar[7];
        $this->assertSame(RaceScenario::ms('04:01.2'), $fast[1]->tsMs, 'the per-car recount gives the same pass time');
    }

    /**
     * SITUATION: only counter 1 types car 9 at 05:00; the sensor marks a
     * crossing at 05:00.3.
     *
     * EXPECTED: the mark is matched (car 9 is the only crossing near) and
     * times the pass, but a sensor never knows the car, so it is no second
     * witness: the pass is still one counter's, waiting, and flagged as such.
     */
    public function testASensorMarkNeverMakesALoneEntryCount(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->addSensor(4);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->entry(1, 9, '05:00');
        $race->mark(4, '05:00.3');

        $result = $race->run('10:00');

        $this->assertLapCounts([9 => 0], $result);
        $this->assertNotNull($this->groupAt($result, 9, '05:00')->marcaSensor);
        $this->assertSame(Flag::SOLITARIA, $this->openFlags($result)[0]->tipo);
    }

    /**
     * SITUATION: the sensor marks 06:30 when nobody crossed: a bird, a
     * marshal. There is no pace yet to say a car was due.
     *
     * EXPECTED: noise. No flag (a false trigger a minute would flood the
     * queue); it shows in the sensor's statistics as a mark with no pass.
     */
    public function testASensorMarkWithNothingNearIsNoise(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->addSensor(4);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $race->mark(4, '06:30');

        $result = $race->run('10:00');

        $this->assertNoOpenFlags($result);
        $this->assertSame(1, $result->sourceStats[4]->sensor['sem_passagem']);
        $this->assertSame(1, $result->sourceStats[4]->sensor['marcas']);
        $this->assertSame(0, $result->sourceStats[4]->entradas);
        $this->assertSame(0, $result->sourceStats[1]->faltas, 'and nobody is charged a miss');
    }

    /**
     * SITUATION: the steady race with a sensor. At 15:28 the sensor marks a
     * crossing and nobody typed anything: car 5 was due then. Later, at
     * 15:28.2 the next lap of the field has not begun.
     *
     * EXPECTED: probably a crossing every counter missed: flagged, with car
     * 5 as the candidate and no suggestion. It does not count by itself;
     * when the reviewer says it was car 5 it does (on the reviewer's word,
     * timed by the sensor).
     */
    public function testAnUnmatchedSensorMarkIsFlaggedWhenACarWasDue(): void
    {
        $race = $this->steadyRace();
        $race->addSensor(4);
        $mark = $race->mark(4, '15:28');
        $before = $race->run('15:40')->lapCount(5);

        $result = $race->run('15:40');
        $flag = $this->assertSingleOpenFlag($result, Flag::NUMERO_ILEGIVEL);
        $this->assertSame(4, $flag->fonteId);
        $this->assertSame(5, $flag->candidatos[0]['carro']);
        $this->assertNull($flag->sugestao);

        $race->reassign($mark, 5);
        $settled = $race->run('15:40');
        $this->assertSame($before + 1, $settled->lapCount(5));
        $this->assertSame(RaceScenario::ms('15:28'), $this->groupAt($settled, 5, '15:28')->tsMs);
        $this->assertNoOpenFlags($settled);
    }

    /**
     * SITUATION: the same marks from a sensor that is an observer, and a
     * camera, also an observer, that sends "??" for car 7's crossing.
     *
     * EXPECTED: an observer changes nothing: no time, no count, no flag.
     * The sensor's marks are compared with the official passes (a hit, with
     * its offset); the camera's "??" counts as unreadable, beside an
     * official pass, and not as a miss.
     */
    public function testObserversOnlyMeasure(): void
    {
        $build = function (bool $withObservers): RaceScenario {
            $race = RaceScenario::withCounters(2);
            if ($withObservers) {
                $race->addSensor(3);
                $race->makeObserver(3);
                $race->addSource(4, 'Câmera', Source::TIPO_AUTOMATICA, Source::TEMPO_PRECISA);
                $race->makeObserver(4);
            }
            $race->start('00:00');
            $race->firstCrossing([7]);
            $race->entry(1, 7, '04:01');
            $race->entry(2, 7, '04:01.4');
            if ($withObservers) {
                $race->mark(3, '04:00.5');
                $race->unread(4, '04:00.6');
                $race->mark(3, '06:30');
            }
            return $race;
        };
        $without = $build(false)->run('10:00');
        $with = $build(true)->run('10:00');

        $this->assertSame($this->groupTimes($without), $this->groupTimes($with));
        $this->assertSame(array_map(fn(Flag $flag) => $flag->id, $without->flags), array_map(fn(Flag $flag) => $flag->id, $with->flags));

        $sensor = $with->sourceStats[3]->observacao;
        $this->assertSame(1, $sensor->acertos);
        $this->assertSame(1, $sensor->falsosPositivos);
        $this->assertSame(-700, $sensor->desvioMedianoMs);
        $camera = $with->sourceStats[4]->observacao;
        $this->assertSame(1, $camera->ilegiveis);
        $this->assertSame(1, $camera->ilegiveisComPassagem);
        $this->assertSame(1, $camera->faltas, 'only the pass zero, which it did not send: the 04:01 crossing it saw and could not read');
        $this->assertSame(0, $camera->falsosPositivos);
    }

    /**
     * SITUATION: a race with no unread entry and no sensor.
     *
     * EXPECTED: nothing of this exists: no statistics keys, no assigner.
     */
    public function testARaceWithoutUnreadPassesIsUntouched(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $race->pass(7, '04:00');

        $result = $race->run('10:00');

        $this->assertSame([], $result->unreadEntries);
        $this->assertArrayNotHasKey('ilegiveis', $result->sourceStats[1]->toArray());
        $this->assertArrayNotHasKey('sensor', $result->sourceStats[1]->toArray());
        $this->assertArrayNotHasKey('candidatos', $result->flags === [] ? [] : $result->flags[0]->toArray());
    }

    /**
     * What the per-car recount must load around unread entries: the
     * clusters of unread entries near the given moments, with room for the
     * crossings that decide them.
     */
    public function testTheRangesToLoadCoverTheClustersNearTheCars(): void
    {
        $race = RaceScenario::withCounters(3);
        $settings = $race->settings;
        $near = new \Baja\Contagem\Engine\Data\Entry(1, 2, 20, null, 1000000);
        $alsoNear = new \Baja\Contagem\Engine\Data\Entry(2, 2, 20, null, 1006000);
        $far = new \Baja\Contagem\Engine\Data\Entry(3, 2, 20, null, 5000000);
        $reachMs = UnreadAssigner::reachMs($settings);

        $ranges = UnreadAssigner::rangesToLoad([$far, $alsoNear, $near], [1003000], $settings);

        $this->assertSame([[1000000 - $reachMs, 1006000 + $reachMs]], $ranges);
        $this->assertSame([], UnreadAssigner::rangesToLoad([$far], [1003000], $settings));
    }

    // ----- helpers -----

    /** Eight cars, a two-minute lap, seven seconds apart, six crossings each: 03:00 to 13:49. */
    private function steadyRace(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8], 120, 6, '03:00');
        return $race;
    }

    private function resolvedFlag(EngineResult $result, string $type): Flag
    {
        foreach ($this->autoResolvedFlags($result) as $flag) {
            if ($flag->tipo === $type) {
                return $flag;
            }
        }
        $this->fail('No auto-resolved flag of type ' . $type);
    }

    private function groupAt(EngineResult $result, int $car, string $around): PassGroup
    {
        foreach ($result->groups as $group) {
            if ($group->carro === $car && abs($group->primeiroTsMs - RaceScenario::ms($around)) <= 5000) {
                return $group;
            }
        }
        $this->fail('No group of car ' . $car . ' around ' . $around);
    }

    /** @return array<int, array{0: int, 1: int, 2: int, 3: bool}> */
    private function groupTimes(EngineResult $result): array
    {
        return array_map(fn(PassGroup $group) => [$group->carro, $group->tsMs, $group->tsAjustadoMs, $group->contado], $result->groups);
    }

    /** @param \Baja\Contagem\Engine\Data\Lap[] $laps */
    private function lapsOf(array $laps): int
    {
        return count(array_filter($laps, fn($lap) => $lap->isCounted()));
    }
}
