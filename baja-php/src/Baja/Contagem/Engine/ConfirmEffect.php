<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\PassGroup;

/**
 * Answers, for a flag: what would a reviewer's "confirmar" do to the count?
 *
 * Confirming a pass group means two things at once: "stop flagging it" and
 * "it counts". For a group that already counts (two counters agree on it)
 * only the first matters. For a group that does not (one counter's lone
 * entry, a car that is not classified, a pass typed before the start),
 * confirming gives the car one more official pass, on one person's word.
 * The review screen must say which of the two a button does, and ask twice
 * when the lap it would create is too short to be real. It gets both
 * answers from here, through `Flag::toArray()`.
 *
 * Unlike the flags themselves, which are the same in every publish mode,
 * this answer is about the count actually in force: in "todas" mode a lone
 * entry counts already, so confirming it adds nothing there.
 */
final class ConfirmEffect
{
    private RaceTimeline $timeline;

    /** @var array<int, PassGroup[]> carro => its pass groups in time order */
    private array $groupsByCar;

    /**
     * @param array<int, PassGroup[]> $groupsByCar
     */
    public function __construct(RaceTimeline $timeline, array $groupsByCar)
    {
        $this->timeline = $timeline;
        $this->groupsByCar = $groupsByCar;
    }

    /** Writes the effect of confirming `$group` on a flag that is about that group. */
    public function describe(Flag $flag, PassGroup $group): void
    {
        // A group that is a pass already gains nothing. And a pass the
        // race rules leave out after the finish stays left out whatever a
        // reviewer confirms. (The other groups the rules leave out, in the
        // start window or before the start under the "largada" rule, never
        // get a flag in the first place.)
        if ($group->contado || $group->ignoradoAposEncerramento) {
            return;
        }
        $flag->efeitoDeConfirmar = Flag::EFEITO_MAIS_UMA_PASSAGEM;
        $flag->voltaResultanteMs = $this->shortestLapIfPassAt($group->carro, $group->tsMs, $group, true);
    }

    /** Writes the effect of confirming a single entry that is in no group: it would become a pass of its own. */
    public function describeEntryOutsideAnyGroup(Flag $flag, int $carro, int $tsMs): void
    {
        $flag->efeitoDeConfirmar = Flag::EFEITO_MAIS_UMA_PASSAGEM;
        $flag->voltaResultanteMs = $this->shortestLapIfPassAt($carro, $tsMs, null, true);
    }

    /**
     * Writes, on each of the flag's "tempos_possiveis", what "usar_tempo"
     * with that time would do to the count in force. The decision leaves
     * ONE counted pass where `$groups` are now (the flag's own group, or
     * the two lone entries of a crossing typed late), so the car's count
     * changes by one minus the number of them that count today: +1 in
     * "confirmadas", where none does; 0 in "fonte_principal" when one of
     * them is the primary source's; -1 in "todas", where both do. Also the
     * shortest lap the pass would make at that time, among the passes in
     * force other than `$groups`, for the short-lap question.
     *
     * @param PassGroup[] $groups
     */
    public function describeTimes(Flag $flag, array $groups): void
    {
        if ($flag->temposPossiveis === null || $groups === []) {
            return;
        }
        $counted = count(array_filter($groups, fn(PassGroup $group): bool => $group->contado));
        $carro = $groups[0]->carro;
        foreach ($flag->temposPossiveis as $i => $tempo) {
            [$beforeMs, $afterMs] = $this->lapsAroundPassAt($carro, $tempo['ts_ms'], $groups, true);
            $flag->temposPossiveis[$i]['voltas'] = 1 - $counted;
            $flag->temposPossiveis[$i]['volta_resultante_ms'] = $beforeMs === null || $afterMs === null
                ? ($beforeMs ?? $afterMs)
                : min($beforeMs, $afterMs);
        }
    }

    /**
     * If the car had one more pass at $tsMs, that pass would split one of
     * its laps in two (or add a lap at either end). Returns the shorter of
     * the laps it would create, in racing time, or null when the car has no
     * other pass to measure a lap from.
     *
     * @param PassGroup|null $itself the group being judged, so it is not compared with itself
     * @param bool $amongPassesInForce true: measure against the passes that count in the publish
     *   mode in force (what the count would really become); false: against the passes of the
     *   default rule (what flags and suggestions are judged by, so they are the same in every mode)
     */
    public function shortestLapIfPassAt(int $carro, int $tsMs, ?PassGroup $itself, bool $amongPassesInForce = false): ?int
    {
        // Laps are measured from crossing to crossing. Under the "largada"
        // rule lap 1 does begin at the start, but it is not a full lap of
        // the track (the counting line is after the start line), so however
        // short it is, it is not "too short to be real" and is left out.
        [$beforeMs, $afterMs] = $this->lapsAroundPassAt($carro, $tsMs, $itself === null ? [] : [$itself], $amongPassesInForce);
        if ($beforeMs === null || $afterMs === null) {
            return $beforeMs ?? $afterMs;
        }
        return min($beforeMs, $afterMs);
    }

    /** Racing time from the car's previous pass (under the default rule) to $tsMs; null when it has none. */
    public function lapBeforePassAt(int $carro, int $tsMs, ?PassGroup $itself): ?int
    {
        return $this->lapsAroundPassAt($carro, $tsMs, $itself === null ? [] : [$itself], false)[0];
    }

    /** Racing time from $tsMs to the car's next pass (under the default rule); null when it has none yet. */
    public function lapAfterPassAt(int $carro, int $tsMs, ?PassGroup $itself): ?int
    {
        return $this->lapsAroundPassAt($carro, $tsMs, $itself === null ? [] : [$itself], false)[1];
    }

    /** The car's last pass (under the default rule) at or before $tsMs; null when it has none. */
    public function previousPassBefore(int $carro, int $tsMs, ?PassGroup $itself): ?PassGroup
    {
        $previous = null;
        foreach ($this->groupsByCar[$carro] ?? [] as $other) {
            if ($other !== $itself && $other->referencia && $other->tsMs <= $tsMs) {
                $previous = $other;
            }
        }
        return $previous;
    }

    /**
     * @param PassGroup[] $leftOut the groups being judged, so they are not compared with themselves
     * @return array{0: ?int, 1: ?int} racing time since the car's previous pass and until its next one
     */
    private function lapsAroundPassAt(int $carro, int $tsMs, array $leftOut, bool $amongPassesInForce): array
    {
        $previousMs = null;
        $nextMs = null;
        foreach ($this->groupsByCar[$carro] ?? [] as $other) {
            if (in_array($other, $leftOut, true) ||!($amongPassesInForce ? $other->contado : $other->referencia)) {
                continue;
            }
            if ($other->tsMs <= $tsMs) {
                $previousMs = $other->tsMs;
            } elseif ($nextMs === null) {
                $nextMs = $other->tsMs;
            }
        }
        return [
            $previousMs === null ? null : $this->timeline->racingMsBetween($previousMs, $tsMs),
            $nextMs === null ? null : $this->timeline->racingMsBetween($tsMs, $nextMs),
        ];
    }

}
