<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * The lap counting engine: raw entries in, official laps and review flags out.
 *
 * It is a pure function. It reads no database and no clock; everything it
 * needs is in the EngineInput, including "now". Every run recomputes the
 * whole race from the raw data, so there is no state to get out of step:
 * the answer after a correction is exactly the answer a fresh start would
 * give.
 *
 * The steps, each in its own class:
 *
 *   1. DecisionApplier   apply what people decided
 *   2. DuplicateFilter   drop double taps and handover duplicates
 *   3. PassMatcher       group entries of the same crossing
 *      PassTimeFuser     one pass time per group
 *   4. PublishRule       which groups are official laps
 *      LapBuilder        number and time the laps
 *   5. FlagDetector      what a reviewer should look at
 *   6. CarStatus         where each car stands now
 */
final class Engine
{
    public static function run(EngineInput $input): EngineResult
    {
        $settings = $input->settings;
        $timeline = RaceTimeline::fromOcorrencias($input->ocorrencias);
        $result = new EngineResult($timeline);

        $sourcesById = [];
        foreach ($input->sources as $source) {
            $sourcesById[$source->id] = $source;
        }

        $applied = DecisionApplier::apply($input->entries, $input->decisions, $sourcesById);
        $availability = new SourceAvailability($sourcesById, $applied, $settings->janelaMs);

        // Lap N of a car is its Nth counted pass after the start, so entries
        // from before the start (or while there is no start) take no part.
        $entriesAfterStart = [];
        if ($timeline->largadaMs !== null) {
            foreach ($applied->entries as $entry) {
                if ($entry->tsMs >= $timeline->largadaMs) {
                    $entriesAfterStart[] = $entry;
                }
            }
        }

        $duplicates = DuplicateFilter::filter($entriesAfterStart, $settings, $applied->confirmedEntryIds, $sourcesById);
        $result->autoVoidedEntryIds = $duplicates->autoVoidedEntryIds;
        foreach ($duplicates->keptEntries as $entry) {
            $result->effectiveEntries[$entry->id] = $entry;
        }

        $groupsByCar = PassMatcher::match($duplicates->keptEntries, $settings->janelaMs, $applied->confirmedEntryIds);
        $groupsInTimeOrder = self::inTimeOrder($groupsByCar);
        $fuser = PassTimeFuser::fuse($groupsInTimeOrder, $sourcesById);

        PublishRule::decide($groupsByCar, $settings, $availability);
        $lapsByCar = LapBuilder::build($groupsByCar, $timeline);
        $pace = new PaceModel($lapsByCar, $settings);

        $flagDetector = new FlagDetector($settings, $availability, $pace, $timeline, $sourcesById, $input->validCars);
        $flags = $flagDetector->detect($groupsByCar, $groupsInTimeOrder, $lapsByCar, $duplicates->openRepeatEntryIds);
        $flags = array_merge($flags, $duplicates->flags);

        LapBuilder::markBestLapEligibility($lapsByCar, $settings, $pace);

        $carStatus = new CarStatus($settings, $timeline, $pace, $availability);
        $result->cars = $carStatus->build(self::carsToReport($input->validCars, $lapsByCar), $lapsByCar, $groupsByCar, $input->nowMs);

        $flags = array_merge($flags, self::totalsMismatchFlags($input, $sourcesById, $result));
        $result->flags = self::inReviewOrder($flags, $result);

        $result->groups = $groupsInTimeOrder;
        foreach ($lapsByCar as $laps) {
            foreach ($laps as $lap) {
                $result->laps[] = $lap;
            }
        }
        $result->sourceStats = SourceStatsBuilder::build(
            $sourcesById,
            $duplicates->keptEntries,
            $groupsInTimeOrder,
            $applied,
            $duplicates,
            $fuser,
            $availability,
            $timeline,
            $settings,
            $input->nowMs
        );

        return $result;
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
     * The teams present at the event, plus any other number that somehow
     * earned counted laps (so a lap is never invisible). Without a team list
     * every number that was typed is reported.
     *
     * @param int[] $validCars
     * @param array<int, \Baja\Contagem\Engine\Data\Lap[]> $lapsByCar
     * @return int[]
     */
    private static function carsToReport(array $validCars, array $lapsByCar): array
    {
        $cars = array_fill_keys($validCars, true);
        $noTeamListGiven = $validCars === [];
        foreach ($lapsByCar as $carro => $laps) {
            // Counted laps come first in each car's list.
            if ($noTeamListGiven || ($laps !== [] && $laps[0]->isCounted())) {
                $cars[$carro] = true;
            }
        }
        $carNumbers = array_keys($cars);
        sort($carNumbers);
        return $carNumbers;
    }

    /**
     * Imported paper totals that disagree with the official count.
     *
     * @param array<int, Source> $sourcesById
     * @return Flag[]
     */
    private static function totalsMismatchFlags(EngineInput $input, array $sourcesById, EngineResult $result): array
    {
        $flags = [];
        foreach ($input->totals as $fonteId => $totalsByCar) {
            $source = $sourcesById[$fonteId] ?? null;
            if ($source === null || !$source->ativa) {
                continue;
            }
            foreach ($totalsByCar as $carro => $importedLaps) {
                $officialLaps = $result->lapCount((int) $carro);
                if ((int) $importedLaps === $officialLaps) {
                    continue;
                }
                $flag = new Flag(Flag::DIVERGENCIA_TOTAL, [(int) $carro], [], $fonteId . '-' . $carro);
                $flag->fonteId = (int) $fonteId;
                $flag->motivo = sprintf(
                    '%s informa %d voltas para o carro %d; a contagem oficial tem %d.',
                    $source->nome,
                    $importedLaps,
                    $carro,
                    $officialLaps
                );
                $flags[] = $flag;
            }
        }
        return $flags;
    }

    /**
     * Open flags first, the ones touching the top of the standings before
     * the rest and older before newer; auto-resolved ones last, newest first.
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
