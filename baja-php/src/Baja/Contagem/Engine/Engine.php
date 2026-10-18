<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\CountResult;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * The lap counting engine: raw entries in, official laps and review flags out.
 *
 * It is a pure function. It reads no database and no clock; everything it
 * needs is in the EngineInput, including "now". There is no state to get
 * out of step: the answer after a correction is exactly the answer a fresh
 * start would give.
 *
 * It has two entry points, because the two halves of its work have very
 * different costs and urgency:
 *
 * - countCars(): the official count of a few cars. Cheap, and all an upload
 *   needs in order to publish VOLTAS and LAST at once. See LapCounter.
 * - run(): the whole race, adding everything that needs the full picture:
 *   lap times, the pace model, review flags, car status, statistics.
 */
final class Engine
{
    /**
     * Recounts only the given cars.
     *
     * The input must hold every entry that counts for those cars after
     * decisions (so also entries typed as another car and reassigned to one
     * of them); entries of other cars may be left out.
     *
     * @param int[] $cars
     */
    public static function countCars(EngineInput $input, array $cars): CountResult
    {
        $counter = LapCounter::count($input, $cars);
        $result = new CountResult($counter->timeline);
        $lapsByCar = $counter->buildLaps();
        foreach ($cars as $carro) {
            $result->lapsByCar[$carro] = $lapsByCar[$carro] ?? [];
        }
        return $result;
    }

    public static function run(EngineInput $input): EngineResult
    {
        $settings = $input->settings;
        $counter = LapCounter::count($input);
        $timeline = $counter->timeline;
        $result = new EngineResult($timeline);

        $result->autoVoidedEntryIds = $counter->duplicates->autoVoidedEntryIds;
        // Keyed by entry id. array_column does in one native call what a
        // loop over tens of thousands of entries would do one by one.
        $result->effectiveEntries = array_column($counter->duplicates->keptEntries, null, 'id');
        $result->unknownTimeEntries = $counter->unknownTimeEntries;
        $result->unreadEntries = $counter->unread === null ? [] : $counter->unread->leftUnread;

        if ($counter->unread !== null && $counter->applied->sensorMarks !== []) {
            // A timing sensor's marks change no count and no pass time, so
            // the per-car recount leaves them out; here they time the laps.
            $counter->unread->matchMarks($counter->applied->sensorMarks, $counter->groupsByCar);
        }
        $groupsInTimeOrder = self::inTimeOrder($counter->groupsByCar);
        $fuser = PassTimeFuser::removeHabits($groupsInTimeOrder, $counter->sourcesById);

        $lapsByCar = $counter->buildLaps();
        // Flags and pace are judged against the default counting rule, so
        // they are the same whichever publish mode is in force. When the
        // default rule IS the one in force, the laps just built are those.
        $referenceLapsByCar = $counter->ruleApplied === Settings::MODO_CONFIRMADAS
            ? $lapsByCar
            : LapBuilder::buildReference($counter->groupsByCar, $timeline, $settings);
        $pace = new PaceModel($referenceLapsByCar, $settings);

        $flagDetector = new FlagDetector($settings, $counter->availability, $pace, $timeline, $counter->sourcesById, $input->validCars, $input->unclassifiedCars);
        $flags = $flagDetector->detect(
            $counter->groupsByCar,
            $groupsInTimeOrder,
            $referenceLapsByCar,
            $counter->duplicates,
            $counter->unknownTimeEntries,
            $counter->applied->confirmedEntryIds
        );
        $flags = array_merge($flags, $flagDetector->describeRepeats($counter->duplicates->flags));
        if ($counter->unread !== null) {
            $unreadFlags = new UnreadFlags($settings, $pace, $timeline, $counter->availability, $counter->sourcesById, $input->validCars, $input->nowMs);
            $flags = array_merge($flags, $unreadFlags->flags($counter->unread, $referenceLapsByCar, $counter->applied->entries, $counter->applied->confirmedEntryIds));
        }

        LapBuilder::markBestLapEligibility($lapsByCar, $settings, $pace);
        LapBuilder::markShortLapsAReviewerVouchedFor($lapsByCar, $settings, $pace, $timeline);

        $carStatus = new CarStatus($settings, $timeline, $pace, $counter->availability);
        $result->cars = $carStatus->build(self::carsToReport($input->validCars, $lapsByCar), $lapsByCar, $counter->groupsByCar, $input->nowMs);
        self::describePace($result, $pace, $timeline, $settings, $input->nowMs);

        $flags = array_merge($flags, self::totalsMismatchFlags($input, $counter->sourcesById, $result));
        $result->flags = self::inReviewOrder($flags, $result);

        $result->setGroups($groupsInTimeOrder);
        $result->laps = $lapsByCar === [] ? [] : array_merge(...array_values($lapsByCar));
        $result->sourceStats = SourceStatsBuilder::build(
            $counter->sourcesById,
            $groupsInTimeOrder,
            $counter->applied,
            $counter->duplicates,
            $fuser,
            $counter->availability,
            $timeline,
            $settings,
            $input->nowMs,
            $counter->unread
        );

        // Machine vision on trial: measured after everything else, and only
        // when some source is an observer.
        if (ObserverComparator::hasObservers($counter->sourcesById)) {
            $comparison = ObserverComparator::compare(
                $counter->sourcesById,
                $groupsInTimeOrder,
                $counter->applied,
                $counter->availability,
                $timeline,
                $settings->janelaMs,
                $settings->agreementSpreadMs()
            );
            foreach ($comparison->statsBySource as $fonteId => $observerStats) {
                if (isset($result->sourceStats[$fonteId])) {
                    $result->sourceStats[$fonteId]->observacao = $observerStats;
                }
            }
            $result->observerRows = $comparison->rows;
        }

        return $result;
    }

    /**
     * The field pace for the public feed and race control: now, and once a
     * minute since the start. Once the race is over both stop at the
     * finish; afterwards the laps would only drop out of the window one by
     * one and the pace would fade into "unknown".
     *
     * The history costs one median per minute of race, most of them already
     * worked out for the flags; it is only made here, in the full run.
     */
    private static function describePace(EngineResult $result, PaceModel $pace, RaceTimeline $timeline, Settings $settings, int $nowMs): void
    {
        $result->ritmo['janela_s'] = $settings->janelaRitmoS;
        if ($timeline->largadaMs === null || $nowMs < $timeline->largadaMs) {
            return;
        }
        $untilMs = $timeline->encerramentoMs === null ? $nowMs : min($nowMs, $timeline->encerramentoMs);
        $result->ritmoHistorico = $pace->fieldPaceHistory($timeline->largadaMs, $untilMs);
        $now = $result->ritmoHistorico[count($result->ritmoHistorico) - 1];
        $result->ritmo['volta_mediana_ms'] = $now['volta_mediana_ms'];
        $result->ritmo['voltas_na_janela'] = $now['voltas_na_janela'];
    }

    /**
     * @param array<int, PassGroup[]> $groupsByCar
     * @return PassGroup[] every group, ordered by the time of its first entry
     */
    private static function inTimeOrder(array $groupsByCar): array
    {
        $allGroups = [];
        $firstTimes = [];
        foreach ($groupsByCar as $groups) {
            foreach ($groups as $group) {
                $allGroups[] = $group;
                $firstTimes[] = $group->primeiroTsMs;
            }
        }
        // Sorting the plain list of times and following the order is much
        // faster than sorting the objects with a comparison callback.
        asort($firstTimes);
        $ordered = [];
        foreach (array_keys($firstTimes) as $index) {
            $ordered[] = $allGroups[$index];
        }
        return $ordered;
    }

    /**
     * The cars that take part (present and classified), plus any other
     * number that somehow earned an official pass (so a lap is never
     * invisible): a number that is no team but two counters agree on, or an
     * unclassified car a reviewer told to count. Without a team list every
     * number that was typed is reported.
     *
     * @param int[] $validCars
     * @param array<int, Lap[]> $lapsByCar
     * @return int[]
     */
    private static function carsToReport(array $validCars, array $lapsByCar): array
    {
        $cars = array_fill_keys($validCars, true);
        $noTeamListGiven = $validCars === [];
        foreach ($lapsByCar as $carro => $laps) {
            // Official passes come first in each car's list.
            if ($noTeamListGiven || ($laps !== [] && $laps[0]->isOfficialPass())) {
                $cars[$carro] = true;
            }
        }
        $carNumbers = array_keys($cars);
        sort($carNumbers);
        return $carNumbers;
    }

    /**
     * Imported paper totals that disagree with the official count, in
     * either direction: a different number on paper, or a car with official
     * laps that the paper list does not mention at all.
     *
     * @param array<int, Source> $sourcesById
     * @return Flag[]
     */
    private static function totalsMismatchFlags(EngineInput $input, array $sourcesById, EngineResult $result): array
    {
        $flags = [];
        foreach ($input->totals as $fonteId => $totalsByCar) {
            $source = $sourcesById[$fonteId] ?? null;
            if ($source === null || !$source->ativa || $totalsByCar === []) {
                continue;
            }
            $carsToCompare = array_keys($totalsByCar);
            foreach ($result->cars as $carro => $car) {
                if ($car->voltas > 0 && !isset($totalsByCar[$carro])) {
                    $carsToCompare[] = $carro;
                }
            }
            foreach ($carsToCompare as $carro) {
                $officialLaps = $result->lapCount((int) $carro);
                $isOnPaper = isset($totalsByCar[$carro]);
                if ($isOnPaper && (int) $totalsByCar[$carro] === $officialLaps) {
                    continue;
                }
                $flag = new Flag(Flag::DIVERGENCIA_TOTAL, [(int) $carro], [], $fonteId . '-' . $carro);
                $flag->fonteId = (int) $fonteId;
                $flag->motivo = $isOnPaper
                    ? sprintf('%s informa %d voltas para o carro %d; a contagem oficial tem %d.', $source->nome, $totalsByCar[$carro], $carro, $officialLaps)
                    : sprintf('%s não lista o carro %d, que tem %d voltas na contagem oficial.', $source->nome, $carro, $officialLaps);
                $flags[] = $flag;
            }
        }
        return $flags;
    }

    /**
     * Open flags first: those that put a car's lap count in doubt (a held
     * pass, two official passes too close together) before the merely
     * informative ones; within each, the ones touching the top of the
     * standings before the rest, and older before newer. Auto-resolved
     * flags last, newest first.
     *
     * @param Flag[] $flags
     * @return Flag[]
     */
    private static function inReviewOrder(array $flags, EngineResult $result): array
    {
        foreach ($flags as $flag) {
            foreach ($flag->carros as $carro) {
                $position = isset($result->cars[$carro]) ? $result->cars[$carro]->posicao : null;
                if ($position !== null && $position < $flag->prioridade) {
                    $flag->prioridade = $position;
                }
            }
        }

        usort($flags, function (Flag $a, Flag $b): int {
            if ($a->autoResolvido !== $b->autoResolvido) {
                return $a->autoResolvido ? 1 : -1;
            }
            if ($a->autoResolvido) {
                return ($b->tsMs ?? 0) <=> ($a->tsMs ?? 0);
            }
            if (($a->contagemEmDuvida === null) !== ($b->contagemEmDuvida === null)) {
                return $a->contagemEmDuvida === null ? 1 : -1;
            }
            if ($a->prioridade !== $b->prioridade) {
                return $a->prioridade <=> $b->prioridade;
            }
            if (($a->tsMs ?? 0) !== ($b->tsMs ?? 0)) {
                return ($a->tsMs ?? 0) <=> ($b->tsMs ?? 0);
            }
            return strcmp($a->id, $b->id);
        });

        return $flags;
    }
}
