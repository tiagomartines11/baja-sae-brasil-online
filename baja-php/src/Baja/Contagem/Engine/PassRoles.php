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
     * @param array<int, PassGroup[]> $groupsByCar each car's groups in time order
     */
    public static function assign(array $groupsByCar, RaceTimeline $timeline, Settings $settings): void
    {
        $startWindowEndsMs = null;
        if (!$settings->firstPassIsLapZero() && $settings->janelaLargadaS > 0 && $timeline->largadaMs !== null) {
            $startWindowEndsMs = $timeline->largadaMs + $settings->janelaLargadaS * 1000;
        }

        $passesAllowedAfterFinish = $settings->aposEncerramento === Settings::APOS_PROXIMA_PASSAGEM ? 1 : 0;

        foreach ($groupsByCar as $groups) {
            $countedPassesStillAllowed = $passesAllowedAfterFinish;
            $referencePassesStillAllowed = $passesAllowedAfterFinish;
            if ($timeline->largadaMs !== null && $groups[0]->primeiroTsMs < $timeline->largadaMs) {
                self::assignBeforeTheStart($groups, $timeline->largadaMs, $settings);
            }

            foreach ($groups as $group) {
                if ($group->antesDaLargada) {
                    continue;
                }
                if ($startWindowEndsMs !== null && $group->primeiroTsMs < $startWindowEndsMs) {
                    $group->dentroDaJanelaDeLargada = true;
                    $group->contado = false;
                    $group->referencia = false;
                    $group->foraDaReferencia = true;
                    continue;
                }

                if ($timeline->encerramentoMs === null || $group->tsMs <= $timeline->encerramentoMs) {
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
     * @param PassGroup[] $groups one car's groups in time order; the first one begins before the start
     */
    private static function assignBeforeTheStart(array $groups, int $largadaMs, Settings $settings): void
    {
        $passZero = null;
        foreach ($groups as $group) {
            if ($group->primeiroTsMs >= $largadaMs) {
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
