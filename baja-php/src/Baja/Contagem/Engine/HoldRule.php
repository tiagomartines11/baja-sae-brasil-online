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
        $recentLaps = [];
        $previousAgreedMs = null;

        foreach ($groups as $group) {
            if (!($reference ? $group->referencia : $group->contado)) {
                continue;
            }
            $passMs = $group->tsMs;
            if ($previousPassMs === null) {
                if ($hasStays && ($area = self::areaHoldingThePass($group, $timeline)) !== null) {
                    self::hold($group, $reference, $area, null, null);
                    continue;
                }
                // The car's first official pass has nothing to be too close to.
                $previousPassMs = $passMs;
                $previousAgreedMs = $passMs;
                continue;
            }
            // A red flag stops the clock: cars standing on the track are not lapping.
            $gapMs = $raceWasStopped ? $timeline->racingMsBetween($previousPassMs, $passMs) : $passMs - $previousPassMs;

            // The car's usual lap is only worked out when the gap is short
            // enough for it to matter, which is rare: this runs for every
            // pass of the race.
            $minimumMs = $twoWindowsMs;
            if (isset($recentLaps[Settings::MIN_LAPS_FOR_MINIMUM_LAP - 1]) && $gapMs < $fraction * max($recentLaps)) {
                $minimumMs = $settings->minimumPlausibleLapMs($recentLaps);
            }
            $isHeld = $gapMs < $minimumMs && !$group->confirmadoPorRevisor && !$group->adicionadoPorRevisor;

            $area = $hasStays ? self::areaHoldingThePass($group, $timeline) : null;
            if ($area !== null) {
                // Held for being logged inside; when it is also too close
                // to the previous pass, the flag can say both.
                self::hold($group, $reference, $area, $isHeld ? $gapMs : null, $isHeld ? $minimumMs : null);
                continue;
            }

            $recentLaps[] = $raceWasStopped ? $timeline->racingMsBetween($previousAgreedMs, $passMs) : $passMs - $previousAgreedMs;
            if (isset($recentLaps[Settings::LAPS_FOR_MINIMUM_LAP])) {
                array_shift($recentLaps);
            }
            $previousAgreedMs = $passMs;

            if ($isHeld) {
                self::hold($group, $reference, PassGroup::RETIDA_PROXIMA_DEMAIS, $gapMs, $minimumMs);
                continue;
            }
            $previousPassMs = $passMs;
        }
    }

    /**
     * @return string|null PassGroup::RETIDA_NO_BOX or RETIDA_NO_ABASTECIMENTO when the car is logged
     *   inside that area at the pass time and no reviewer vouched for the pass; null otherwise
     */
    private static function areaHoldingThePass(PassGroup $group, RaceTimeline $timeline): ?string
    {
        if ($group->confirmadoPorRevisor || $group->adicionadoPorRevisor) {
            return null;
        }
        if ($timeline->isInPitAt($group->carro, $group->tsMs)) {
            return PassGroup::RETIDA_NO_BOX;
        }
        return $timeline->isFuelingAt($group->carro, $group->tsMs) ? PassGroup::RETIDA_NO_ABASTECIMENTO : null;
    }

    private static function hold(PassGroup $group, bool $reference, string $reason, ?int $gapMs, ?int $minimumMs): void
    {
        if ($reference) {
            $group->referencia = false;
            $group->retidaNaReferencia = true;
        } else {
            $group->contado = false;
            $group->retida = true;
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
