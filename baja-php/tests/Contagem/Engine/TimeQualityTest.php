<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * How far an entry's TIME can be trusted (`Entry::$qualidadeTempo`).
 *
 * A count page takes its time from the server, not from the phone's own
 * clock. Nearly every entry is therefore "exato". Two rare cases are not:
 *
 * - "estimado": the entry was typed with no connection and the page was
 *   reloaded before it got one. Its time was worked out from an older sync
 *   and the phone's clock: probably right to within a few seconds.
 *   The engine matches it like any other entry and flags its pass group
 *   ("tempo_estimado") so a person checks it sits in the right place.
 *
 * - "desconhecido": there was no older sync either. The time is whatever
 *   the phone's clock said, which may be days off. The engine never matches
 *   or counts such an entry on its own: it is listed apart
 *   (`EngineResult::$unknownTimeEntries`) and flagged, one flag per entry
 *   ("tempo_desconhecido"). A reviewer can confirm it, accepting its time,
 *   or void it.
 *
 * Whatever its time, no entry is ever dropped.
 */
final class TimeQualityTest extends EngineTestCase
{
    /**
     * SITUATION: car 7 made its first crossing at 00:20 and crosses again
     * at 03:00. Counters 1 and 2 type it at 03:00; counter 3's phone was
     * offline and got reloaded, so its entry carries an ESTIMATED time,
     * 03:01.
     *
     * EXPECTED: the estimated entry is matched normally: one pass group
     * with all three entries, and the lap counts. The group is flagged
     * "tempo_estimado", naming counter 3. There is no suggestion, and
     * confirming changes no count (the pass counts already). A lap ending
     * at a pass with an open flag is not a best lap. Confirming silences
     * the flag.
     */
    public function testAnEstimatedTimeIsMatchedNormallyAndItsGroupIsFlagged(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $race->pass(7, '03:00', [1, 2]);
        $estimated = $race->entryWithTimeQuality(3, 7, '03:01', Entry::TEMPO_ESTIMADO);

        $result = $race->run('05:00');

        $this->assertLapCounts([7 => 1], $result);
        $group = $result->groupOfEntry($estimated);
        $this->assertCount(3, $group->entries);
        $this->assertTrue($group->tempoEstimado);
        $flag = $this->assertSingleOpenFlag($result, Flag::TEMPO_ESTIMADO);
        $this->assertSame([7], $flag->carros);
        $this->assertSame(3, $flag->fonteId);
        $this->assertCount(3, $flag->passagemIds);
        $this->assertNull($flag->sugestao);
        $this->assertSame(Flag::EFEITO_NENHUM, $flag->efeitoDeConfirmar);
        $this->assertStringContainsString('Contagem 3', $flag->motivo);
        $this->assertFalse($result->laps[1]->elegivelMelhorVolta);
        $this->assertSame([], $result->unknownTimeEntries);

        $race->confirm($estimated);
        $confirmed = $race->run('05:00');
        $this->assertNoOpenFlags($confirmed);
        $this->assertLapCounts([7 => 1], $confirmed);
    }

    /**
     * SITUATION: the same, but the estimate is 25 seconds off (03:25), so
     * the entry falls outside the matching window and stands alone, while
     * counters 1 and 2 agree on 03:00.
     *
     * EXPECTED: one flag for the stray entry, and it is the usual one for a
     * lone entry ("solitaria", with the suggestion to void it, since
     * counting it would make a 25-second lap). Its reason adds that the
     * time was estimated, which is the likely explanation. There is no
     * second flag for the same entry.
     */
    public function testALoneEntryWithAnEstimatedTimeGetsTheLoneEntryFlagWithANote(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $race->pass(7, '03:00', [1, 2]);
        $estimated = $race->entryWithTimeQuality(3, 7, '03:25', Entry::TEMPO_ESTIMADO);

        $result = $race->run('05:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::SOLITARIA);
        $this->assertSame([$estimated], $flag->passagemIds);
        $this->assertStringContainsString('estimado pelo aparelho', $flag->motivo);
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
        $this->assertSame(25000, $flag->voltaResultanteMs);
        $this->assertLapCounts([7 => 1], $result);
    }

    /**
     * SITUATION: an estimated lone entry for car 7 at 06:00, while counter
     * 2's phone has not uploaded past 05:00. The usual lone-entry flag is
     * held back in that case (counter 2 may still have the car).
     *
     * EXPECTED: the entry's time being estimated does not depend on
     * anybody's upload, so "tempo_estimado" is raised right away. Because
     * this group does not count yet, the flag says that confirming it
     * would add a pass, and what lap that would make (180 s).
     */
    public function testAnEstimatedEntryIsFlaggedEvenWhileOtherFlagsAreHeldBack(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $race->pass(7, '03:00');
        $race->entryWithTimeQuality(3, 7, '06:00', Entry::TEMPO_ESTIMADO);
        $race->syncedThrough(2, '05:00');

        $flag = $this->assertSingleOpenFlag($race->run('07:00'), Flag::TEMPO_ESTIMADO);

        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
        $this->assertSame(180000, $flag->voltaResultanteMs);
    }

    /**
     * SITUATION: car 7 crosses at 03:00. Counters 1 and 2 type it. Counter
     * 3 types it too, but on a phone with no usable time at all: the entry
     * carries the phone's own clock, which happens to say 03:00.5.
     *
     * EXPECTED: even though that time looks perfect, it means nothing, so
     * the entry is NOT matched: the pass group has two entries and counts
     * on their agreement. The entry is in no group; it is listed in
     * `unknownTimeEntries` and has its own flag, "tempo_desconhecido",
     * naming counter 3. The flag says confirming would add a pass, and that
     * the lap it would make is half a second long, so the screen can ask
     * "are you sure?". The entry is not among counter 3's passes or entries
     * in use.
     */
    public function testAnEntryOfUnknownTimeIsNeverMatchedAndIsFlaggedOnItsOwn(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $agreed = $race->pass(7, '03:00', [1, 2]);
        $unknown = $race->entryWithTimeQuality(3, 7, '03:00.5', Entry::TEMPO_DESCONHECIDO);

        $result = $race->run('05:00');

        $this->assertLapCounts([7 => 1], $result);
        $this->assertCount(2, $result->groupOfEntry($agreed[1])->entries);
        $this->assertNull($result->groupOfEntry($unknown));
        $this->assertSame([$unknown], array_map(fn(Entry $entry) => $entry->id, $result->unknownTimeEntries));
        $this->assertArrayNotHasKey($unknown, $result->effectiveEntries);

        $flag = $this->assertSingleOpenFlag($result, Flag::TEMPO_DESCONHECIDO);
        $this->assertSame('tempo_desconhecido:' . $unknown, $flag->id);
        $this->assertSame([7], $flag->carros);
        $this->assertSame([$unknown], $flag->passagemIds);
        $this->assertSame(3, $flag->fonteId);
        $this->assertSame(RaceScenario::ms('03:00.5'), $flag->tsMs);
        $this->assertNull($flag->sugestao);
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
        $this->assertSame(500, $flag->voltaResultanteMs);
        $this->assertStringContainsString('Contagem 3', $flag->motivo);
        $this->assertSame(1, $this->sourceCount($result, 7, 3), 'Only the first crossing is among counter 3\'s passes.');
        $this->assertSame(1, $result->sourceStats[3]->entradas);
    }

    /**
     * SITUATION: three ways in which an entry of unknown time could slip
     * into the count if the engine were careless:
     * - car 8: counter 1 typed it at 06:00 and counter 3 at 06:00 with an
     *   unknown time; together they would be "two counters agree";
     * - the publish mode is "todas", in which every entry counts;
     * - (second part) counter 3 is the only counter there is, so its
     *   entries count on their own.
     *
     * EXPECTED: none of it counts. Car 8 has no lap in the default mode
     * (counter 1's entry waits alone, flagged as usual, next to the flag
     * for the unknown time). In "todas", car 8 has one lap from counter 1's
     * entry, not two. With a single counter, car 9 has no lap.
     */
    public function testUnknownTimeNeverCountsAutomaticallyInAnyMode(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([8]);
        $race->entry(1, 8, '06:00');
        $race->entryWithTimeQuality(3, 8, '06:00', Entry::TEMPO_DESCONHECIDO);

        $default = $race->run('08:00');
        $this->assertLapCounts([8 => 0], $default);
        $types = array_map(fn(Flag $flag) => $flag->tipo, $default->openFlags());
        sort($types);
        $this->assertSame([Flag::SOLITARIA, Flag::TEMPO_DESCONHECIDO], $types);

        $race->settings->modoPublicacao = Settings::MODO_TODAS;
        $this->assertLapCounts([8 => 1], $race->run('08:00'));
        $this->assertSame(1, $race->countCars('08:00', [8])->lapCount(8), 'The fast path agrees.');

        $alone = RaceScenario::withCounters(1);
        $alone->start('00:00');
        $alone->firstCrossing([9]);
        $alone->entryWithTimeQuality(1, 9, '03:00', Entry::TEMPO_DESCONHECIDO);
        $this->assertLapCounts([9 => 0], $alone->run('05:00'));
        $this->assertSame(0, $alone->countCars('05:00', [9])->lapCount(9));
    }

    /**
     * SITUATION: car 8 again: counter 1's lone entry at 06:00 and counter
     * 3's entry of unknown time, also saying 06:00. The reviewer watches
     * the video, sees the car cross at 06:00 and confirms counter 3's
     * entry: "the time it carries is right".
     *
     * EXPECTED: a confirmed entry takes part like any other. It joins
     * counter 1's entry in one pass group, which counts (lap 1), and both
     * flags are gone: the group was confirmed. Nothing is listed as unknown
     * any more. The fast path gives the same count.
     */
    public function testConfirmingAnEntryOfUnknownTimeAcceptsItsTime(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([8]);
        $lone = $race->entry(1, 8, '06:00');
        $unknown = $race->entryWithTimeQuality(3, 8, '06:00', Entry::TEMPO_DESCONHECIDO);

        $race->confirm($unknown);
        $result = $race->run('08:00');

        $this->assertLapCounts([8 => 1], $result);
        $this->assertSame($result->groupOfEntry($lone), $result->groupOfEntry($unknown));
        $this->assertNoOpenFlags($result);
        $this->assertSame([], $result->unknownTimeEntries);
        $this->assertSame(1, $race->countCars('08:00', [8])->lapCount(8));
    }

    /**
     * SITUATION: the same entry of unknown time, which the reviewer voids
     * instead ("anular"): the car was counted by the others anyway.
     *
     * EXPECTED: a voided entry is out of everything: not listed, not
     * flagged.
     */
    public function testVoidingAnEntryOfUnknownTimeRemovesItsFlag(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([8]);
        $unknown = $race->entryWithTimeQuality(3, 8, '06:00', Entry::TEMPO_DESCONHECIDO);
        $this->assertSingleOpenFlag($race->run('08:00'), Flag::TEMPO_DESCONHECIDO);

        $race->void($unknown);
        $result = $race->run('08:00');

        $this->assertNoOpenFlags($result);
        $this->assertSame([], $result->unknownTimeEntries);
    }

    /**
     * SITUATION: entries with absurd times. Car 7 raced normally (first
     * crossing, then a lap at 03:00). In addition:
     * - counter 3 has an entry for car 7 of UNKNOWN time dated three days
     *   before the race (its phone's calendar was wrong);
     * - counter 3 has an entry for car 7 with an ESTIMATED time two hours
     *   before the start;
     * - counter 2 has an ordinary ("exato") entry for car 7 dated two hours
     *   in the future.
     *
     * EXPECTED: the engine never drops or refuses an entry for its time.
     * All three are kept and each one ends up in front of a reviewer:
     * - the unknown one is listed apart and flagged "tempo_desconhecido";
     * - the estimated one is in a pass group marked "antes da largada" and
     *   flagged "tempo_estimado" (far from the start as it is: an estimated
     *   time that lands there may simply be wrong);
     * - the one from the future is a lone entry like any other, pending,
     *   with the usual "solitaria" flag.
     * None of them changes car 7's count: one lap.
     */
    public function testEntriesWithAbsurdTimesAreKeptAndFlaggedNeverDropped(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $race->pass(7, '03:00');
        $threeDaysAgo = $race->entryWithTimeQuality(3, 7, '-4320:00', Entry::TEMPO_DESCONHECIDO);
        $twoHoursBefore = $race->entryWithTimeQuality(3, 7, '-120:00', Entry::TEMPO_ESTIMADO);
        $twoHoursAhead = $race->entry(2, 7, '125:00');

        $result = $race->run('05:00');

        $this->assertLapCounts([7 => 1], $result);
        $flagTypeByEntry = [];
        foreach ($result->openFlags() as $flag) {
            $flagTypeByEntry[$flag->passagemIds[0]] = $flag->tipo;
        }
        $this->assertSame(
            [$threeDaysAgo => Flag::TEMPO_DESCONHECIDO, $twoHoursBefore => Flag::TEMPO_ESTIMADO, $twoHoursAhead => Flag::SOLITARIA],
            $flagTypeByEntry
        );
        $this->assertSame([$threeDaysAgo], array_map(fn(Entry $entry) => $entry->id, $result->unknownTimeEntries));
        $this->assertTrue($result->groupOfEntry($twoHoursBefore)->antesDaLargada);
        $pendingRows = array_values(array_filter($result->laps, fn(Lap $lap) => $lap->estado === Lap::PENDENTE));
        $this->assertCount(1, $pendingRows);
        $this->assertSame(RaceScenario::ms('125:00'), $pendingRows[0]->tsMs);
    }

    /**
     * SITUATION: an ordinary ("exato") entry typed half an hour before the
     * start: a counter practising during the warm-up. Its time is the
     * server's, so it really was typed then.
     *
     * EXPECTED: it is kept in a group marked "antes da largada", counts for
     * nothing, and raises no flag: rehearsals are not a reviewer's problem.
     */
    public function testAnExactEntryLongBeforeTheStartIsKeptWithoutAFlag(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $rehearsal = $race->entry(1, 7, '-30:00');

        $result = $race->run('05:00');

        $this->assertNoOpenFlags($result);
        $this->assertTrue($result->groupOfEntry($rehearsal)->antesDaLargada);
        $this->assertArrayHasKey($rehearsal, $result->effectiveEntries);
    }

    /**
     * SITUATION: entries of estimated and of unknown time were uploaded,
     * but race control has not started the race.
     *
     * EXPECTED: as for any entry, without a start there is no race to
     * count and nothing to flag.
     */
    public function testWithoutAStartNothingIsListedOrFlagged(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->entryWithTimeQuality(3, 7, '03:00', Entry::TEMPO_DESCONHECIDO);
        $race->entryWithTimeQuality(3, 7, '06:00', Entry::TEMPO_ESTIMADO);

        $result = $race->run('08:00');

        $this->assertSame([], $result->flags);
        $this->assertSame([], $result->unknownTimeEntries);
        $this->assertSame([], $result->groups);
    }

    /**
     * EXPECTED: an entry is "exato" unless whoever loads it says otherwise,
     * and the three qualities are spelled exactly as the upload protocol
     * spells them.
     */
    public function testTheDefaultQualityAndTheThreeNames(): void
    {
        $this->assertSame(Entry::TEMPO_EXATO, (new Entry(1, 1, 10, 7, 1000))->qualidadeTempo);
        $this->assertSame(['exato', 'estimado', 'desconhecido'], [Entry::TEMPO_EXATO, Entry::TEMPO_ESTIMADO, Entry::TEMPO_DESCONHECIDO]);
    }
}
