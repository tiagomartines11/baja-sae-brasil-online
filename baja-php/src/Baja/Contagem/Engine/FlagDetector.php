<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * Finds what a reviewer should look at.
 *
 * What is deliberately NOT a flag:
 * - A pass two sources recorded and a third did not. It is counted, and the
 *   miss goes into the third source's statistics.
 * - A long gap since a car's last pass. Real races show about one lap in six
 *   taking more than 1.5 times the car's usual lap, so a long gap is weak
 *   evidence of a missed pass. It feeds the car's status instead.
 *
 * Everything here is judged against the default counting rule (the
 * `referencia` of each group and the laps built from it), never against the
 * publish mode in force, so the same flags appear in every mode.
 *
 * A reviewer silences any flag about a pass group by confirming it.
 */
final class FlagDetector
{
    private Settings $settings;
    private SourceAvailability $availability;
    private PaceModel $pace;
    private RaceTimeline $timeline;

    /** @var array<int, Source> */
    private array $sourcesById;

    /** @var array<int, true> car numbers present at the event; empty = do not check */
    private array $validCars;

    /** @var Flag[] */
    private array $flags = [];

    /**
     * @param array<int, Source> $sourcesById
     * @param int[] $validCars
     */
    public function __construct(
        Settings $settings,
        SourceAvailability $availability,
        PaceModel $pace,
        RaceTimeline $timeline,
        array $sourcesById,
        array $validCars
    ) {
        $this->settings = $settings;
        $this->availability = $availability;
        $this->pace = $pace;
        $this->timeline = $timeline;
        $this->sourcesById = $sourcesById;
        $this->validCars = array_fill_keys($validCars, true);
    }

    /**
     * @param array<int, PassGroup[]> $groupsByCar
     * @param PassGroup[] $groupsInTimeOrder
     * @param array<int, Lap[]> $referenceLapsByCar laps under the default counting rule
     * @param array<int, true> $openRepeatEntryIds entries already flagged as "repetida"
     * @return Flag[]
     */
    public function detect(array $groupsByCar, array $groupsInTimeOrder, array $referenceLapsByCar, array $openRepeatEntryIds): array
    {
        $swapFinder = new SwapFinder($groupsInTimeOrder, $this->settings->janelaMs);

        foreach ($groupsInTimeOrder as $group) {
            // Crossings the race rules leave out (start window, after the
            // finish) are not anybody's mistake.
            if ($group->foraDaReferencia) {
                continue;
            }
            $hasOpenRepeat = $this->markOpenRepeat($group, $openRepeatEntryIds);
            if ($group->confirmadoPorRevisor) {
                continue;
            }
            if (!$hasOpenRepeat && $group->isUnconfirmedSingleSource()) {
                $this->examineLoneEntry($group, $swapFinder, $groupsByCar[$group->carro]);
            }
            if ($group->alertasAbertos === [] && $this->isUnknownCar($group->carro)) {
                $this->flagUnknownCar($group);
            }
            if ($group->alertasAbertos === [] && $this->timeline->isInPitAt($group->carro, $group->tsMs)) {
                $this->flagPassWhileInPit($group);
            }
        }

        foreach ($referenceLapsByCar as $laps) {
            foreach ($laps as $lap) {
                $this->flagIfTooShort($lap);
            }
        }

        if ($this->settings->firstPassIsLapZero()) {
            foreach (LateFirstPass::find($referenceLapsByCar) as [$flag, $group]) {
                $this->attach($flag, $group);
            }
        }

        return $this->flags;
    }

    /**
     * The later entry of an unresolved "repetida" has its flag already (made
     * by DuplicateFilter). Recording it on the group keeps every other check
     * from flagging the same thing again, in whatever publish mode.
     *
     * @param array<int, true> $openRepeatEntryIds
     */
    private function markOpenRepeat(PassGroup $group, array $openRepeatEntryIds): bool
    {
        if ($openRepeatEntryIds === []) {
            return false;
        }
        foreach ($group->entries as $entry) {
            if (isset($openRepeatEntryIds[$entry->id])) {
                $group->alertasAbertos[] = Flag::REPETIDA;
                return true;
            }
        }
        return false;
    }

    /**
     * A pass only one source recorded: a typo for another car, an entry that
     * should not exist, or a real pass the others missed.
     *
     * @param PassGroup[] $sameCarGroups
     */
    private function examineLoneEntry(PassGroup $group, SwapFinder $swapFinder, array $sameCarGroups): void
    {
        $entry = $group->entries[0];
        $otherActiveSources = array_diff($this->availability->activeSourceIdsAt($entry->tsMs), [$entry->fonteId]);
        if ($otherActiveSources === []) {
            // Nobody else was counting; the entry stands on its own.
            return;
        }
        if ($this->availability->hasUnknownSourceAt($entry->tsMs, [$entry->fonteId])) {
            // Another counter may simply be offline. Its entries could still
            // arrive and complete this group, so say nothing yet.
            return;
        }

        $partner = $swapFinder->findPartnerFor($group);
        if ($partner !== null) {
            $this->flagProbableSwap($group, $partner);
            return;
        }
        if ($this->isUnknownCar($group->carro)) {
            // Handled as "carro desconhecido", which says more.
            return;
        }
        $this->flagLoneEntry($group, $sameCarGroups);
    }

    private function flagProbableSwap(PassGroup $group, PassGroup $partner): void
    {
        $entry = $group->entries[0];
        $resemblance = DigitSimilarity::describe(DigitSimilarity::rank($entry->carro, $partner->carro));

        $flag = new Flag(Flag::TROCA_PROVAVEL, [$entry->carro, $partner->carro], [$entry->id], (string) $entry->id);
        $flag->fonteId = $entry->fonteId;
        $flag->tsMs = $entry->tsMs;
        $flag->sugestao = [
            'tipo' => Decision::REATRIBUIR,
            'passagem_id' => $entry->id,
            'equipe_numero' => $partner->carro,
        ];
        $flag->motivo = sprintf(
            '%s registrou sozinha o carro %d e não registrou o carro %d, que as outras contagens viram no mesmo momento%s.',
            $this->sourceName($entry->fonteId),
            $entry->carro,
            $partner->carro,
            $resemblance !== '' ? ' (' . $resemblance . ')' : ''
        );
        $this->attach($flag, $group);
    }

    /** @param PassGroup[] $sameCarGroups */
    private function flagLoneEntry(PassGroup $group, array $sameCarGroups): void
    {
        $entry = $group->entries[0];
        $flag = new Flag(Flag::SOLITARIA, [$entry->carro], [$entry->id], (string) $entry->id);
        $flag->fonteId = $entry->fonteId;
        $flag->tsMs = $entry->tsMs;

        $shortestLapMs = $this->shortestLapIfCounted($group, $sameCarGroups);
        $expectedMs = $this->pace->expectedLapMs($entry->carro, $entry->tsMs);

        if ($shortestLapMs !== null && $this->settings->isImpossiblyShortLap($shortestLapMs, $expectedMs)) {
            $flag->sugestao = ['tipo' => Decision::ANULAR, 'passagem_id' => $entry->id];
            $flag->motivo = sprintf(
                'Só %s registrou o carro %d, e contar esta passagem criaria uma volta de %d s%s.',
                $this->sourceName($entry->fonteId),
                $entry->carro,
                (int) round($shortestLapMs / 1000),
                $expectedMs !== null ? sprintf(', quando o esperado para este carro é cerca de %d s', (int) round($expectedMs / 1000)) : ''
            );
        } else {
            $flag->motivo = sprintf(
                'Só %s registrou o carro %d neste momento; o tempo de volta seria plausível, então a decisão é do revisor.',
                $this->sourceName($entry->fonteId),
                $entry->carro
            );
        }
        $this->attach($flag, $group);
    }

    /**
     * If this group were a pass it would split one lap of the car in two.
     * Returns the shorter of the two pieces (racing time), or null when the
     * car has no other pass to measure from.
     *
     * @param PassGroup[] $sameCarGroups in time order
     */
    private function shortestLapIfCounted(PassGroup $group, array $sameCarGroups): ?int
    {
        // Only when the start itself is pass zero does a lap begin there.
        $previousMs = $this->settings->firstPassIsLapZero() ? null : $this->timeline->largadaMs;
        $nextMs = null;
        foreach ($sameCarGroups as $other) {
            if ($other === $group || !$other->referencia) {
                continue;
            }
            if ($other->tsMs <= $group->tsMs) {
                $previousMs = $other->tsMs;
            } elseif ($nextMs === null) {
                $nextMs = $other->tsMs;
            }
        }

        $pieces = [];
        if ($previousMs !== null) {
            $pieces[] = $this->timeline->racingMsBetween($previousMs, $group->tsMs);
        }
        if ($nextMs !== null) {
            $pieces[] = $this->timeline->racingMsBetween($group->tsMs, $nextMs);
        }
        return $pieces === [] ? null : min($pieces);
    }

    private function flagUnknownCar(PassGroup $group): void
    {
        $flag = new Flag(Flag::CARRO_DESCONHECIDO, [$group->carro], $group->entryIds(), (string) $group->anchorEntryId());
        $flag->fonteId = count($group->entries) === 1 ? $group->entries[0]->fonteId : null;
        $flag->tsMs = $group->tsMs;
        $flag->motivo = sprintf('O número %d não é de nenhuma equipe presente no evento.', $group->carro);
        $this->attach($flag, $group);
    }

    private function flagPassWhileInPit(PassGroup $group): void
    {
        $flag = new Flag(Flag::NO_BOX, [$group->carro], $group->entryIds(), (string) $group->anchorEntryId());
        $flag->tsMs = $group->tsMs;
        $flag->motivo = sprintf('O carro %d foi registrado na linha enquanto constava como dentro do box.', $group->carro);
        $this->attach($flag, $group);
    }

    private function flagIfTooShort(Lap $lap): void
    {
        $group = $lap->grupo;
        if (!$lap->isCounted() || $group === null || $lap->grupoAnterior === null) {
            return;
        }
        if ($group->confirmadoPorRevisor || $group->alertasAbertos !== []) {
            return;
        }
        $lapStartMs = $lap->grupoAnterior->tsMs;
        $expectedMs = $this->pace->expectedLapMs($lap->carro, $lapStartMs);
        $racingMs = $this->timeline->racingMsBetween($lapStartMs, $group->tsMs);
        if (!$this->settings->isImpossiblyShortLap($racingMs, $expectedMs)) {
            return;
        }

        $flag = new Flag(Flag::VOLTA_CURTA, [$lap->carro], $group->entryIds(), (string) $group->anchorEntryId());
        $flag->tsMs = $group->tsMs;
        $flag->motivo = sprintf(
            'O carro %d foi registrado duas vezes com %d s de intervalo%s.',
            $lap->carro,
            (int) round($racingMs / 1000),
            $expectedMs !== null ? sprintf(', quando o esperado para este carro é cerca de %d s por volta', (int) round($expectedMs / 1000)) : ''
        );
        $this->attach($flag, $group);
    }

    private function attach(Flag $flag, PassGroup $group): void
    {
        $group->alertasAbertos[] = $flag->tipo;
        $this->flags[] = $flag;
    }

    private function isUnknownCar(int $carro): bool
    {
        return $this->validCars !== [] && !isset($this->validCars[$carro]);
    }

    private function sourceName(int $fonteId): string
    {
        return isset($this->sourcesById[$fonteId]) ? $this->sourcesById[$fonteId]->nome : 'uma contagem';
    }
}
