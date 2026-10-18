<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\PassGroup;

/**
 * Takes out the passes that the rules of the race say are not laps, however
 * well the counters agree on them: anything before the start, the crossing
 * at the start and the crossings after the finish.
 *
 * BEFORE THE START
 *
 * A pass can only follow the start, so a group whose pass time is before it
 * is not an official pass. It is kept and marked (`antesDaLargada`) rather
 * than dropped: when the start was recorded a few seconds late, the pack's
 * first crossing lands here, and silently losing it would cost those cars a
 * lap. A reviewer who confirms such a group makes it the car's pass zero.
 * Only one group per car can be that (the latest confirmed one), so nobody
 * gets a lap that ended before the race began. Under the "largada" rule the
 * start itself is pass zero, and nothing before it ever counts.
 *
 * This is decided from the group alone and the reviewer's word, never from
 * what other cars did, so recounting one car gives the same answer.
 *
 * AT THE START (setting `passagem_inicial`)
 *
 * The counting line is after the start line, so a car's first crossing
 * completes no lap.
 *
 * - "primeira_passagem" (default): each car's first counted pass is its
 *   pass zero. Nothing to do here; LapBuilder numbers it 0.
 * - "largada": the organisers decided not to type the first crossing, and
 *   the start itself is everybody's pass zero. A counter may type the start
 *   pack anyway, so entries in the first `janela_largada_s` seconds are
 *   treated as that initial crossing and never count.
 *
 * AFTER THE FINISH (setting `apos_encerramento`)
 *
 * When the race ends every car crosses the line once more, and that pass
 * counts. With "proxima_passagem" each car's first official pass after the
 * finish counts and anything later is left out, so nobody has to check
 * whether a car crossed twice on its way in. With "nenhuma" nothing after
 * the finish counts.
 *
 * TOO SOON AFTER THE PREVIOUS PASS
 *
 * Between those two, HoldRule holds back the passes that come sooner after
 * the car's previous official pass than the car could have lapped. It runs
 * here because it needs to know which groups before the start are passes,
 * and because a held pass must not use up the one pass allowed after the
 * finish.
 *
 * Passes left out stay visible on the car's timeline.
 *
 * Every rule is applied twice, independently: once to what counts in the
 * publish mode in force (`contado`) and once to what would count under the
 * default rule (`referencia`), which is what flags are judged against. A
 * lone entry after the finish counts in "todas" mode and uses up the car's
 * one pass there; it must not thereby hide the car's real last pass from
 * the review flags.
 */
final class PassRoles
{
    /**
     * How long before a group's first entry a sensor's mark can be its pass
     * time, with room to spare: the mark is matched within a few seconds of
     * the counters' first reading (UnreadAssigner::sensorToleranceMs()).
     * Walking the groups in their order, one whose first entry is later than
     * this after an instant cannot have its pass time before it.
     */
    private const MARCA_ANTES_MS = 30000;

    /**
     * @param array<int, PassGroup[]> $groupsByCar each car's groups in time order
     * @param string $ruleApplied the counting rule in force (what PublishRule::decide returned)
     */
    public static function assign(array $groupsByCar, RaceTimeline $timeline, Settings $settings, string $ruleApplied = Settings::MODO_CONFIRMADAS): void
    {
        $startWindowEndsMs = null;
        if (!$settings->firstPassIsLapZero() && $settings->janelaLargadaS > 0 && $timeline->largadaMs !== null) {
            $startWindowEndsMs = $timeline->largadaMs + $settings->janelaLargadaS * 1000;
        }

        $passesAllowedAfterFinish = $settings->aposEncerramento === Settings::APOS_PROXIMA_PASSAGEM ? 1 : 0;

        foreach ($groupsByCar as $groups) {
            if ($timeline->largadaMs !== null && self::beginsBeforeTheStart($groups, $timeline->largadaMs)) {
                self::assignBeforeTheStart($groups, $timeline->largadaMs, $settings);
            }
            if ($startWindowEndsMs !== null) {
                foreach ($groups as $group) {
                    if ($group->primeiroTsMs >= $startWindowEndsMs + self::MARCA_ANTES_MS) {
                        break;
                    }
                    if ($group->inicioMs() < $startWindowEndsMs && !$group->antesDaLargada) {
                        $group->dentroDaJanelaDeLargada = true;
                        $group->contado = false;
                        $group->referencia = false;
                        $group->foraDaReferencia = true;
                    }
                }
            }

            HoldRule::applyToCar($groups, $timeline, $settings, $ruleApplied);

            if ($timeline->encerramentoMs === null) {
                continue;
            }
            $countedPassesStillAllowed = $passesAllowedAfterFinish;
            $referencePassesStillAllowed = $passesAllowedAfterFinish;
            foreach ($groups as $group) {
                if ($group->antesDaLargada || $group->dentroDaJanelaDeLargada || $group->tsMs <= $timeline->encerramentoMs) {
                    continue;
                }
                if (self::isLeftOutAfterFinish($group->contado, $countedPassesStillAllowed)) {
                    $group->ignoradoAposEncerramento = true;
                    $group->contado = false;
                }
                if (self::isLeftOutAfterFinish($group->referencia, $referencePassesStillAllowed)) {
                    $group->foraDaReferencia = true;
                    $group->referencia = false;
                }
            }
        }
    }

    /**
     * Whether one of the car's groups begins before the start: its first
     * entry, or the sensor's mark that times it (PassGroup::inicioMs()).
     *
     * @param PassGroup[] $groups one car's groups in time order
     */
    private static function beginsBeforeTheStart(array $groups, int $largadaMs): bool
    {
        foreach ($groups as $group) {
            if ($group->inicioMs() < $largadaMs) {
                return true;
            }
            if ($group->primeiroTsMs >= $largadaMs + self::MARCA_ANTES_MS) {
                return false;
            }
        }
        return false;
    }

    /**
     * @param PassGroup[] $groups one car's groups in time order; one of the first ones begins before the start
     */
    private static function assignBeforeTheStart(array $groups, int $largadaMs, Settings $settings): void
    {
        $passZero = null;
        foreach ($groups as $group) {
            if ($group->primeiroTsMs >= $largadaMs + self::MARCA_ANTES_MS) {
                // Groups are ordered by their first entry: none of the
                // remaining ones can have a pass time before the start.
                break;
            }
            // The pass time decides, not the first entry: a group typed on
            // both sides of the start instant with its median after it is
            // an ordinary pass.
            if ($group->tsMs >= $largadaMs) {
                continue;
            }
            $group->antesDaLargada = true;
            if ($group->isReviewersWord() && $settings->firstPassIsLapZero()) {
                $passZero = $group;
            }
            $group->contado = false;
            $group->referencia = false;
            // Under the "largada" rule nothing before the start is a
            // mistake to be reviewed: the first crossing is not typed at all.
            $group->foraDaReferencia = !$settings->firstPassIsLapZero();
        }
        if ($passZero !== null) {
            $passZero->contado = true;
            $passZero->referencia = true;
        }
    }

    /**
     * While the car still has a pass allowed after the finish, nothing is
     * left out, and a group that is a pass uses the allowance up. A group
     * that is not a pass yet (one counter only) does not: the real pass may
     * be the next one, or this one once it is confirmed.
     */
    private static function isLeftOutAfterFinish(bool $isPass, int &$passesStillAllowed): bool
    {
        if ($passesStillAllowed === 0) {
            return true;
        }
        if ($isPass) {
            $passesStillAllowed--;
        }
        return false;
    }
}
