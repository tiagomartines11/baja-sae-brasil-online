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
 * that a lap one counter missed does not read as one twice as long. Such a
 * split never brings the normal lap below the car's own counted laps
 * (normalLapMs), so strays cannot lower the bar that holds phantoms. A lap
 * during which the pit log or the fueling log has the car inside is left
 * out of all of those: it measures the stop, not the car's pace (race
 * simulator, two counters: three laps with a pit stop each made a car's
 * normal lap twice its real one, and its next ordinary lap was held). It
 * is left out rather than shortened by the stay: a stay logged wrongly can
 * then only leave the normal lap resting on older laps, never lower it.
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
 * logged stays and the race's red flags, never at other cars or at the pace of the field, so
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
        // The same laps without those splits, and the car's counted laps
        // (from one official pass to the next): what a split may not take
        // the normal lap below (see normalLapMs). None of the three holds a
        // lap during which the car was logged inside an area.
        $unsplitLaps = [];
        $countedLaps = [];
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
                if (isset($recentLaps[Settings::MIN_LAPS_FOR_MINIMUM_LAP - 1]) && $gapMs < $fraction * max(max($recentLaps), max($unsplitLaps))) {
                    $minimumMs = self::minimumPlausibleLapMs(self::normalLapMs($recentLaps, $unsplitLaps, $countedLaps), $settings);
                }
                $isTooClose = $gapMs < $minimumMs && !$vouched;
            }

            $stay = $hasStays ? self::stayTheCarIsLoggedIn($group, $timeline) : null;
            if ($stay !== null) {
                [$area, $stayEntryMs] = $stay;
                $isAgreed = $vouched || (isset($group->entries[1]) && !$group->dispersa && count($group->realSourceIds()) >= 2);
                if ($reference && $isAgreed && $afterMissedExit !== null && $afterMissedExit[1] === $area && $afterMissedExit[2] === $stayEntryMs) {
                    $sinceFirstMs = $timeline->racingMsBetween($afterMissedExit[0]->tsMs, $passMs);
                    $normalLapMs = self::normalLapMs($recentLaps, $unsplitLaps, $countedLaps);
                    if ($normalLapMs !== null && $sinceFirstMs >= self::minimumPlausibleLapMs($normalLapMs, $settings) && $sinceFirstMs <= self::LONGEST_LAP_AFTER_A_MISSED_EXIT * $normalLapMs) {
                        // The car is lapping: it left before the first of
                        // the two passes, and nobody logged it. From here on
                        // the stay is over, for the count and for everything
                        // else that reads the timeline.
                        self::release($afterMissedExit[0], $area);
                        $timeline->assumeExit($area, $carro, $stayEntryMs, $afterMissedExit[0]->tsMs - 1000);
                        self::addLaps($recentLaps, [$sinceFirstMs], Settings::LAPS_FOR_MINIMUM_LAP);
                        self::addLaps($unsplitLaps, [$sinceFirstMs], Settings::LAPS_FOR_MINIMUM_LAP);
                        self::addLaps($countedLaps, [$sinceFirstMs], self::COUNTED_LAPS_KEPT);
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

            if (!($hasStays && self::overlapsALoggedStay($carro, $previousAgreedMs, $passMs, $timeline))) {
                $lapMs = $raceWasStopped ? $timeline->racingMsBetween($previousAgreedMs, $passMs) : $passMs - $previousAgreedMs;
                self::addLaps($recentLaps, $loneSinceAgreed === [] ? [$lapMs]
                    : self::lapsBetween($previousAgreedMs, $passMs, $loneSinceAgreed, self::normalLapMs($recentLaps, $unsplitLaps, $countedLaps), $timeline, $settings, $hasStays), Settings::LAPS_FOR_MINIMUM_LAP);
                self::addLaps($unsplitLaps, [$lapMs], Settings::LAPS_FOR_MINIMUM_LAP);
            }
            $loneSinceAgreed = [];
            $previousAgreedMs = $passMs;

            if ($isTooClose) {
                self::hold($group, $reference, PassGroup::RETIDA_PROXIMA_DEMAIS, $gapMs, $minimumMs);
                continue;
            }
            if (!($hasStays && self::overlapsALoggedStay($carro, $previousPassMs, $passMs, $timeline))) {
                self::addLaps($countedLaps, [$gapMs], self::COUNTED_LAPS_KEPT);
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

    /** How many of the car's counted laps the floor of its normal lap is taken from (see normalLapMs). */
    private const COUNTED_LAPS_KEPT = 10;

    /**
     * @param int[] $laps oldest first; keeps the last $keep
     * @param int[] $newLaps
     */
    private static function addLaps(array &$laps, array $newLaps, int $keep): void
    {
        array_push($laps, ...$newLaps);
        while (isset($laps[$keep])) {
            array_shift($laps);
        }
    }

    /**
     * The car's normal lap for the hold rule: Settings::normalLapMs of its
     * laps, lone crossings split at (lapsBetween), but never below what it
     * would be without those splits, unless the car's own counted laps show
     * a shorter one.
     *
     * WHY. Splitting at a lone crossing is what keeps a lap one counter
     * missed from reading as one twice as long (race simulator, two
     * counters: the car's next real lap was held). But a lone crossing may
     * be a stray, and strays typed while a car stands on the track cut the
     * long gap into short parts; two of them among the last five laps
     * lowered the normal lap to three quarters of the real one, the next
     * long stop lowered it again, and a phantom both counters typed counted
     * (review of the first version of the split). So a split can bring the
     * normal lap down only as far as the second shortest of the car's last
     * ten counted laps (times between two passes that counted, nothing
     * split, nothing held, no logged stay): a lap the car really did, which strays cannot
     * shorten. The second shortest, not the shortest, for the reason
     * Settings::minimumPlausibleLapMs gives. With fewer than two counted
     * laps a split lowers nothing. When no lone crossing split anything
     * this is exactly Settings::normalLapMs.
     *
     * @param int[] $recentLaps the car's last laps, lone crossings split at
     * @param int[] $unsplitLaps the same, not split at lone crossings
     * @param int[] $countedLaps the car's last counted laps
     */
    private static function normalLapMs(array $recentLaps, array $unsplitLaps, array $countedLaps): ?int
    {
        $normalMs = Settings::normalLapMs($recentLaps);
        $unsplitMs = Settings::normalLapMs($unsplitLaps);
        if ($normalMs === null || $unsplitMs === null || $normalMs >= $unsplitMs) {
            return $normalMs ?? $unsplitMs;
        }
        sort($countedLaps);
        $floorMs = isset($countedLaps[1]) ? min($countedLaps[1], $unsplitMs) : $unsplitMs;
        return max($normalMs, $floorMs);
    }

    /** Settings::minimumPlausibleLapMs, for a normal lap already worked out (null: not known yet). */
    private static function minimumPlausibleLapMs(?int $normalLapMs, Settings $settings): int
    {
        $minimumMs = 2 * $settings->janelaMs;
        $fromOwnLapsMs = (int) ($settings->fracaoVoltaMinima * (int) $normalLapMs);
        return $fromOwnLapsMs > $minimumMs ? $fromOwnLapsMs : $minimumMs;
    }

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
     * car's normal lap.
     *
     * WHY. A crossing only one counter typed is not counted, so without
     * this the lap around it looks twice as long. Two of those and two
     * long stops among a car's last five laps made its normal lap twice
     * the real one, and its next ordinary lap was held (race simulator,
     * two counters). So a lone crossing splits the gap, as a held pass
     * does, but only where a lap can end: both parts at least three
     * quarters of the car's normal lap. A stray in the middle of an
     * ordinary lap splits nothing. Strays inside a long gap (a stop on
     * track) can split it, which is why normalLapMs does not let a split
     * take the normal lap below the car's counted laps. When several lone
     * crossings could end the next part, the one that leaves it closest to
     * a normal lap wins: a stray shortly before a missed pass does not
     * take the missed pass's place. A lone crossing while the car is
     * logged inside an area, and any while the car has no normal lap yet,
     * split nothing.
     *
     * @param PassGroup[] $lone lone crossings between the two passes, in pass-time order
     * @param int|null $normalLapMs the car's normal lap so far (normalLapMs)
     * @return int[] the laps, oldest first: one when nothing splits the gap
     */
    private static function lapsBetween(int $fromMs, int $toMs, array $lone, ?int $normalLapMs, RaceTimeline $timeline, Settings $settings, bool $hasStays): array
    {
        $raceWasStopped = $timeline->redFlagIntervals !== [];
        $racingMs = fn(int $a, int $b): int => $raceWasStopped ? $timeline->racingMsBetween($a, $b) : $b - $a;
        $laps = [];
        if ($normalLapMs !== null) {
            $shortestPartMs = max(self::SHORTEST_PART_OF_A_SPLIT_LAP, $settings->fracaoVoltaMinima) * $normalLapMs;
            $ends = [];
            foreach ($lone as $group) {
                if ($group->tsMs > $fromMs && $group->tsMs < $toMs
                    && !($hasStays && self::stayTheCarIsLoggedIn($group, $timeline) !== null)) {
                    $ends[] = $group->tsMs;
                }
            }
            while (true) {
                $bestMs = null;
                $bestMissMs = null;
                foreach ($ends as $endMs) {
                    $beforeMs = $endMs > $fromMs ? $racingMs($fromMs, $endMs) : 0;
                    if ($beforeMs < $shortestPartMs || $racingMs($endMs, $toMs) < $shortestPartMs) {
                        continue;
                    }
                    $missMs = abs($beforeMs - $normalLapMs);
                    if ($bestMissMs === null || $missMs < $bestMissMs) {
                        [$bestMs, $bestMissMs] = [$endMs, $missMs];
                    }
                }
                if ($bestMs === null) {
                    break;
                }
                $laps[] = $racingMs($fromMs, $bestMs);
                $fromMs = $bestMs;
            }
        }
        $laps[] = $racingMs($fromMs, $toMs);
        return $laps;
    }

    /**
     * Whether the pit log or the fueling log has the car inside at some
     * moment between the two passes: the time between them is then no lap
     * of the car's pace (see holdAlongOneChain).
     */
    private static function overlapsALoggedStay(int $carro, int $fromMs, int $toMs, RaceTimeline $timeline): bool
    {
        return $timeline->overlapsPitStay($carro, $fromMs, $toMs) || $timeline->overlapsFuelStay($carro, $fromMs, $toMs);
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
