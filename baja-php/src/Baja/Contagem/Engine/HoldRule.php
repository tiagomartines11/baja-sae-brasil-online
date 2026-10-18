<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\PassGroup;

/**
 * The hold rule: a pass that comes too soon after the car's previous
 * official pass is not counted on the counters' word alone.
 *
 * WHY. Two counters agreeing is strong evidence, but not proof: two stray
 * entries for the same car a second apart look exactly like a real pass.
 * What gives such a phantom away is WHEN it happens: a car cannot cross the
 * line again a few seconds, or a quarter of a lap, after it just did. So,
 * for each car, walking through its passes in time order: a group that
 * would count is HELD when it is closer to the car's previous official pass
 * than the car's minimum plausible lap (Settings::minimumPlausibleLapMs: two
 * matching windows, or half the car's own normal lap).
 *
 * The car's laps behind that normal lap are measured between the passes the
 * counters agreed on, held ones included, and are also split at a crossing
 * only one counter typed when it sits where a lap can end (lapsBetween), so
 * that a lap one counter missed does not read as one twice as long.
 *
 * A held group is not official. It stays visible as a pending row, with the
 * flag "passagem_proxima_demais", and counts the moment a reviewer confirms
 * it. What a reviewer said always counts: a confirmed group and an added
 * pass are never held.
 *
 * In "confirmadas" mode this is what guarantees that the count never runs
 * ahead of the truth by itself, whatever the counters typed. The price is
 * that a held pass may be the real one (when the phantom came first); the
 * count is then still right, and the flag asks a person to look.
 *
 * LOGGED INSIDE AN AREA. The same goes for a pass at the line while the pit
 * log or the fueling log says the car is inside: one of the two records is
 * wrong, and until a person says which, the pass is held (reason "no_box" or
 * "no_abastecimento"). Recording the exit nobody logged ("marcar saída")
 * puts the car outside at that moment, and the pass then counts by the
 * ordinary rules. A pass held this way is not what later passes are
 * measured from, like any held pass.
 *
 * AN EXIT NOBODY LOGGED. One missed exit would otherwise hold every later
 * pass of the car. So: when a SECOND pass the counters agree on comes one
 * lap after the first held one, inside the same logged stay, the car is
 * plainly lapping. The engine assumes it left one second before the first
 * of the two passes: the stay ends there (RaceTimeline::assumeExit), both
 * passes count, and so do the later ones. The first pass carries an
 * informational flag ("saida_presumida"). "One lap after" is strict on
 * purpose, because a car that really is standing in its pit can collect
 * two phantom passes (wrong numbers): the second pass must come no sooner
 * than the car's minimum plausible lap and no later than one and a half
 * of its normal laps after the first, the car's normal lap must be known
 * (three laps of its own), both passes must be agreed by two counters
 * close together (or vouched for by a reviewer), and the first must not
 * also be too close to the car's previous pass. Anything else stays held.
 *
 * ONLY THE CAR'S OWN DATA. The rule looks at the car's own pass times and
 * the race's red flags, never at other cars or at the pace of the field, so
 * recounting one car alone gives exactly what a full run gives.
 *
 * It is applied twice, independently: to what counts in the publish mode in
 * force (`contado`) and to what would count under the default rule
 * (`referencia`, which the flags are judged by). In "todas" mode every
 * entry counts by definition and nothing is held in the count in force.
 * Groups the race rules leave out (before the start, the start window) are
 * not passes and take no part.
 */
final class HoldRule
{
    /**
     * @param PassGroup[] $groups one car's groups, ordered by first entry; pass times already set
     * @param string $ruleApplied the counting rule in force (what PublishRule::decide returned)
     */
    public static function applyToCar(array $groups, RaceTimeline $timeline, Settings $settings, string $ruleApplied): void
    {
        $groups = self::inPassOrder($groups);
        self::holdAlongOneChain($groups, $timeline, $settings, true);
        if ($ruleApplied === Settings::MODO_CONFIRMADAS) {
            // The default rule is the one in force: the two chains are the same.
            foreach ($groups as $group) {
                if ($group->retidaNaReferencia) {
                    $group->contado = false;
                    $group->retida = true;
                }
            }
        } elseif ($ruleApplied !== Settings::MODO_TODAS) {
            self::holdAlongOneChain($groups, $timeline, $settings, false);
        }
    }

    /**
     * After a pass held for being logged inside an area, the next agreed
     * pass releases it only if it comes no later than this many normal
     * laps after it. A real car that left the pits comes round again one
     * lap later; two unrelated phantoms (wrong numbers typed for a car that
     * really is standing in its pit) land any time apart.
     */
    private const LONGEST_LAP_AFTER_A_MISSED_EXIT = 1.5;

    /**
     * @param PassGroup[] $groups in pass-time order
     * @param bool $reference true: the passes of the default rule; false: the passes of the mode in force
     */
    private static function holdAlongOneChain(array $groups, RaceTimeline $timeline, Settings $settings, bool $reference): void
    {
        $carro = $groups[0]->carro;
        // Most cars never stop: knowing that once spares two questions per pass.
        $hasStays = isset($timeline->pitIntervals[$carro]) || isset($timeline->fuelIntervals[$carro]);
        $twoWindowsMs = 2 * $settings->janelaMs;
        $fraction = $settings->fracaoVoltaMinima;
        $raceWasStopped = $timeline->redFlagIntervals !== [];
        $previousPassMs = null;
        // The car's most recent laps, oldest first (at most five): the time
        // from each group the counters agreed on to the next one. A held
        // group is one of those too. If it were not, holding a pass would
        // make the lap after it look twice as long, the car's "normal lap"
        // would grow, and the next ordinary lap would be held as well.
        // A crossing only one counter typed is split at too, but only where
        // a lap can end (see lapsBetween).
        $recentLaps = [];
        $previousAgreedMs = null;
        $loneSinceAgreed = [];
        // The pass that may turn out to be the car's first lap after an
        // exit nobody logged: [group, area, entry time of the stay].
        $afterMissedExit = null;

        foreach ($groups as $group) {
            if (!($reference ? $group->referencia : $group->contado)) {
                if ($previousAgreedMs !== null && self::isLoneCrossing($group, $reference)) {
                    $loneSinceAgreed[] = $group;
                }
                continue;
            }
            $passMs = $group->tsMs;
            $vouched = $group->confirmadoPorRevisor || $group->adicionadoPorRevisor;
            $gapMs = null;
            $minimumMs = $twoWindowsMs;
            $isTooClose = false;
            if ($previousPassMs !== null) {
                // A red flag stops the clock: cars standing on the track are not lapping.
                $gapMs = $raceWasStopped ? $timeline->racingMsBetween($previousPassMs, $passMs) : $passMs - $previousPassMs;
                // The car's usual lap is only worked out when the gap is
                // short enough for it to matter, which is rare: this runs
                // for every pass of the race.
                if (isset($recentLaps[Settings::MIN_LAPS_FOR_MINIMUM_LAP - 1]) && $gapMs < $fraction * max($recentLaps)) {
                    $minimumMs = $settings->minimumPlausibleLapMs($recentLaps);
                }
                $isTooClose = $gapMs < $minimumMs && !$vouched;
            }

            $stay = $hasStays ? self::stayTheCarIsLoggedIn($group, $timeline) : null;
            if ($stay !== null) {
                [$area, $stayEntryMs] = $stay;
                $isAgreed = $vouched || (isset($group->entries[1]) && !$group->dispersa && count($group->realSourceIds()) >= 2);
                if ($reference && $isAgreed && $afterMissedExit !== null && $afterMissedExit[1] === $area && $afterMissedExit[2] === $stayEntryMs) {
                    $sinceFirstMs = $timeline->racingMsBetween($afterMissedExit[0]->tsMs, $passMs);
                    $normalLapMs = Settings::normalLapMs($recentLaps);
                    if ($normalLapMs !== null && $sinceFirstMs >= $settings->minimumPlausibleLapMs($recentLaps) && $sinceFirstMs <= self::LONGEST_LAP_AFTER_A_MISSED_EXIT * $normalLapMs) {
                        // The car is lapping: it left before the first of
                        // the two passes, and nobody logged it. From here on
                        // the stay is over, for the count and for everything
                        // else that reads the timeline.
                        self::release($afterMissedExit[0], $area);
                        $timeline->assumeExit($area, $carro, $stayEntryMs, $afterMissedExit[0]->tsMs - 1000);
                        $recentLaps[] = $sinceFirstMs;
                        if (isset($recentLaps[Settings::LAPS_FOR_MINIMUM_LAP])) {
                            array_shift($recentLaps);
                        }
                        $afterMissedExit = null;
                        $loneSinceAgreed = [];
                        $previousAgreedMs = $passMs;
                        $previousPassMs = $passMs;
                        continue;
                    }
                    if ($sinceFirstMs > self::LONGEST_LAP_AFTER_A_MISSED_EXIT * (int) $normalLapMs) {
                        // Too long after the first one to be its next lap:
                        // this pass is the new candidate.
                        $afterMissedExit = null;
                    }
                }
                if ($reference && $isAgreed && !$isTooClose && $afterMissedExit === null) {
                    $afterMissedExit = [$group, $area, $stayEntryMs];
                }
                if (!$vouched) {
                    // Held for being logged inside; when it is also too close
                    // to the previous pass, the flag can say both.
                    $reason = $area === RaceTimeline::AREA_BOX ? PassGroup::RETIDA_NO_BOX : PassGroup::RETIDA_NO_ABASTECIMENTO;
                    self::hold($group, $reference, $reason, $isTooClose ? $gapMs : null, $isTooClose ? $minimumMs : null);
                    continue;
                }
            }

            if ($previousPassMs === null) {
                // The car's first official pass has nothing to be too close to.
                $previousPassMs = $passMs;
                $previousAgreedMs = $passMs;
                continue;
            }

            if ($loneSinceAgreed === []) {
                $recentLaps[] = $raceWasStopped ? $timeline->racingMsBetween($previousAgreedMs, $passMs) : $passMs - $previousAgreedMs;
            } else {
                array_push($recentLaps, ...self::lapsBetween($previousAgreedMs, $passMs, $loneSinceAgreed, $recentLaps, $timeline, $settings, $hasStays));
                $loneSinceAgreed = [];
            }
            while (isset($recentLaps[Settings::LAPS_FOR_MINIMUM_LAP])) {
                array_shift($recentLaps);
            }
            $previousAgreedMs = $passMs;

            if ($isTooClose) {
                self::hold($group, $reference, PassGroup::RETIDA_PROXIMA_DEMAIS, $gapMs, $minimumMs);
                continue;
            }
            $previousPassMs = $passMs;
        }
    }

    /**
     * A lone crossing splits a gap between agreed passes only when both
     * parts are at least this many of the car's normal laps (or its
     * minimum plausible lap, when `fracao_volta_minima` is set higher).
     */
    private const SHORTEST_PART_OF_A_SPLIT_LAP = 0.75;

    /**
     * A crossing that only one counter typed, nobody vouched for, and that
     * the race rules do not leave out: either a pass the other counter
     * missed, or a stray.
     */
    private static function isLoneCrossing(PassGroup $group, bool $reference): bool
    {
        return $group->isUnconfirmedSingleSource()
            && !$group->antesDaLargada
            && !$group->dentroDaJanelaDeLargada
            && !$group->dispersa
            && !($reference ? $group->retidaNaReferencia : $group->retida);
    }

    /**
     * The laps the time between two agreed passes is made of, for the
     * car's normal lap (Settings::normalLapMs).
     *
     * WHY. A crossing only one counter typed is not counted, so without
     * this the lap around it looks twice as long. Two of those and two
     * long stops among a car's last five laps made its normal lap twice
     * the real one, and its next ordinary lap was held (race simulator,
     * two counters). So a lone crossing splits the gap, as a held pass
     * does, but only where a lap can end: both parts at least three
     * quarters of the car's normal lap. A stray in the middle of an
     * ordinary lap splits nothing, so it cannot lower the bar that holds
     * phantoms; and the split parts are never shorter than a lap the hold
     * rule itself would let through. A lone crossing while the car is
     * logged inside an area, and any while the car has no normal lap yet,
     * split nothing either.
     *
     * @param PassGroup[] $lone lone crossings between the two passes, in pass-time order
     * @param int[] $recentLaps the car's laps so far
     * @return int[] the laps, oldest first: one when nothing splits the gap
     */
    private static function lapsBetween(int $fromMs, int $toMs, array $lone, array $recentLaps, RaceTimeline $timeline, Settings $settings, bool $hasStays): array
    {
        $raceWasStopped = $timeline->redFlagIntervals !== [];
        $racingMs = fn(int $a, int $b): int => $raceWasStopped ? $timeline->racingMsBetween($a, $b) : $b - $a;
        $laps = [];
        $normalLapMs = Settings::normalLapMs($recentLaps);
        if ($normalLapMs !== null) {
            $shortestPartMs = max(self::SHORTEST_PART_OF_A_SPLIT_LAP, $settings->fracaoVoltaMinima) * $normalLapMs;
            foreach ($lone as $group) {
                if ($group->tsMs <= $fromMs || $group->tsMs >= $toMs
                    || ($hasStays && self::stayTheCarIsLoggedIn($group, $timeline) !== null)) {
                    continue;
                }
                $beforeMs = $racingMs($fromMs, $group->tsMs);
                if ($beforeMs >= $shortestPartMs && $racingMs($group->tsMs, $toMs) >= $shortestPartMs) {
                    $laps[] = $beforeMs;
                    $fromMs = $group->tsMs;
                }
            }
        }
        $laps[] = $racingMs($fromMs, $toMs);
        return $laps;
    }

    /**
     * @return array{0: string, 1: int}|null the area (RaceTimeline::AREA_*) whose log has the car inside
     *   at the pass time, and when that stay began; null when the car is logged in neither
     */
    private static function stayTheCarIsLoggedIn(PassGroup $group, RaceTimeline $timeline): ?array
    {
        $entryMs = $timeline->pitEntryBefore($group->carro, $group->tsMs);
        if ($entryMs !== null) {
            return [RaceTimeline::AREA_BOX, $entryMs];
        }
        $entryMs = $timeline->fuelEntryBefore($group->carro, $group->tsMs);
        return $entryMs === null ? null : [RaceTimeline::AREA_ABASTECIMENTO, $entryMs];
    }

    /** The first pass after an exit nobody logged counts after all. */
    private static function release(PassGroup $group, string $area): void
    {
        $group->saidaPresumida = $area;
        if ($group->retidaNaReferencia) {
            $group->referencia = true;
            $group->retidaNaReferencia = false;
            $group->motivoRetencao = null;
            $group->intervaloDesdeAnteriorMs = null;
            $group->voltaMinimaMs = null;
        }
    }

    private static function hold(PassGroup $group, bool $reference, string $reason, ?int $gapMs, ?int $minimumMs): void
    {
        if ($reference) {
            $group->referencia = false;
            $group->retidaNaReferencia = true;
        } else {
            $group->contado = false;
            $group->retida = true;
            if ($group->motivoRetencao !== null) {
                // Already held under the default rule, which is what the
                // flags describe; its reason stands in every publish mode.
                return;
            }
        }
        $group->motivoRetencao = $reason;
        $group->intervaloDesdeAnteriorMs = $gapMs;
        $group->voltaMinimaMs = $minimumMs;
    }

    /**
     * The groups arrive ordered by their first entry. The pass time (the
     * median of the entries) nearly always follows the same order; when a
     * stray entry sits inside another group's span it may not, and the
     * chain must be walked by pass time.
     *
     * @param PassGroup[] $groups
     * @return PassGroup[]
     */
    private static function inPassOrder(array $groups): array
    {
        $previousMs = PHP_INT_MIN;
        foreach ($groups as $group) {
            if ($group->tsMs < $previousMs) {
                usort($groups, fn(PassGroup $a, PassGroup $b) => [$a->tsMs, $a->primeiroTsMs, $a->entries[0]->id] <=> [$b->tsMs, $b->primeiroTsMs, $b->entries[0]->id]);
                return $groups;
            }
            $previousMs = $group->tsMs;
        }
        return $groups;
    }
}
