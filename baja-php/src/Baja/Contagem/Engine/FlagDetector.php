<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Entry;
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
 * A reviewer silences any flag about a pass group by confirming it. For a
 * group that does not count yet, confirming also makes it count; every flag
 * says which of the two it would be (see ConfirmEffect).
 *
 * A group gets at most one flag of its own, the one that says most: once a
 * check has flagged it, the later checks leave it alone.
 */
final class FlagDetector
{
    private Settings $settings;
    private SourceAvailability $availability;
    private PaceModel $pace;
    private RaceTimeline $timeline;

    /** Words the flags that are about one group or one entry on its own. */
    private PassFlags $passFlags;

    /** @var array<int, true> cars that take part (present and classified); empty = do not check */
    private array $validCars;

    /** @var array<int, true> cars present at the event but not classified */
    private array $unclassifiedCars;

    private ConfirmEffect $effect;

    /** @var array<int, PassGroup> id of the later entry of an open "repetida" => the group it is in */
    private array $groupByOpenRepeatEntryId = [];

    /** @var Flag[] */
    private array $flags = [];

    /**
     * @param array<int, Source> $sourcesById
     * @param int[] $validCars
     * @param int[] $unclassifiedCars
     */
    public function __construct(
        Settings $settings,
        SourceAvailability $availability,
        PaceModel $pace,
        RaceTimeline $timeline,
        array $sourcesById,
        array $validCars,
        array $unclassifiedCars = []
    ) {
        $this->settings = $settings;
        $this->availability = $availability;
        $this->pace = $pace;
        $this->timeline = $timeline;
        $this->passFlags = new PassFlags($sourcesById);
        $this->validCars = array_fill_keys($validCars, true);
        $this->unclassifiedCars = array_fill_keys($unclassifiedCars, true);
    }

    /**
     * @param array<int, PassGroup[]> $groupsByCar
     * @param PassGroup[] $groupsInTimeOrder
     * @param array<int, Lap[]> $referenceLapsByCar laps under the default counting rule
     * @param DuplicateFilter $duplicates what step 2 found: the "repetida" flags are already made
     * @param Entry[] $unknownTimeEntries entries of unknown time, which are in no group
     * @return Flag[] the flags found here (the "repetida" ones are not repeated in this list)
     */
    public function detect(array $groupsByCar, array $groupsInTimeOrder, array $referenceLapsByCar, DuplicateFilter $duplicates, array $unknownTimeEntries = []): array
    {
        $openRepeatEntryIds = $duplicates->openRepeatEntryIds;
        $openRepeatCars = $duplicates->openRepeatCars;
        // This loop visits every pass group of the race; what it asks of
        // each one is kept as plain comparisons wherever possible.
        $teamsAreKnown = $this->validCars !== [];
        $validCars = $this->validCars;
        $unclassifiedCars = $this->unclassifiedCars;
        $this->effect = new ConfirmEffect($this->timeline, $groupsByCar);
        $swapFinder = new SwapFinder($groupsInTimeOrder, $this->settings->janelaMs);
        $preStart = new PreStartPasses($this->settings, $this->timeline, $referenceLapsByCar, $this->validCars);
        /** @var array<int, PassGroup> carro => its last group shortly before the start */
        $lastGroupBeforeTheStart = [];
        $finishMs = $this->timeline->encerramentoMs;
        $stops = $this->timeline->pitIntervals + $this->timeline->fuelIntervals;

        foreach ($groupsInTimeOrder as $group) {
            $carro = $group->carro;
            if ($finishMs !== null && $group->primeiroTsMs <= $finishMs && !$group->confirmadoPorRevisor) {
                $acrossTheFinish = $this->passFlags->acrossTheFinish($group, $finishMs);
                if ($acrossTheFinish !== null) {
                    $this->attach($acrossTheFinish, $group);
                }
            }
            // Crossings the race rules leave out (start window, after the
            // finish) are not anybody's mistake.
            if ($group->foraDaReferencia) {
                continue;
            }
            if ($group->antesDaLargada && !$group->referencia) {
                if ($group->isReviewersWord()) {
                    continue;
                }
                if (!$preStart->isShortlyBeforeTheStart($group->tsMs)) {
                    // Warm-up or formation lap. Only an estimated time is
                    // worth a word: the entry may not belong here at all.
                    if ($group->tempoEstimado) {
                        $this->attach($this->passFlags->estimatedTime($group), $group);
                    }
                } elseif ($this->isUnknownCar($carro)) {
                    $this->attach($this->passFlags->unknownCar($group), $group);
                } elseif (isset($unclassifiedCars[$carro])) {
                    $this->attach($this->passFlags->unclassifiedCar($group), $group);
                } elseif (!isset($lastGroupBeforeTheStart[$carro]) || count($group->entries) >= 2 || count($lastGroupBeforeTheStart[$carro]->entries) < 2) {
                    // One flag per car, about its last group before the
                    // start; a group two counters agree on is preferred to
                    // a later stray entry.
                    $lastGroupBeforeTheStart[$carro] = $group;
                }
                continue;
            }
            $hasOpenRepeat = isset($openRepeatCars[$carro]) && $this->markOpenRepeat($group, $openRepeatEntryIds);
            if ($group->confirmadoPorRevisor) {
                continue;
            }
            // A lone entry: one source, and no reviewer vouching for it.
            if (!$hasOpenRepeat && !$group->adicionadoPorRevisor && count($group->entries) === 1) {
                $this->examineLoneEntry($group, $swapFinder);
            }
            if ($group->alertasAbertos !== []) {
                continue;
            }
            if ($teamsAreKnown && !isset($validCars[$carro]) && !isset($unclassifiedCars[$carro])) {
                $this->attach($this->passFlags->unknownCar($group), $group);
            } elseif (isset($unclassifiedCars[$carro]) && !$group->adicionadoPorRevisor) {
                // (A pass a reviewer added for the car is the reviewer
                // saying "count it" already.)
                $this->attach($this->passFlags->unclassifiedCar($group), $group);
            } elseif (isset($stops[$carro]) && $this->timeline->isInPitAt($carro, $group->tsMs)) {
                $this->attach($this->passFlags->inThePitLane($group), $group);
            } elseif (isset($stops[$carro]) && $this->timeline->isFuelingAt($carro, $group->tsMs)) {
                $this->attach($this->passFlags->inTheFuelingStation($group), $group);
            } elseif ($group->tempoEstimado) {
                $this->attach($this->passFlags->estimatedTime($group), $group);
            }
        }

        $carsFlaggedBeforeTheStart = [];
        foreach ($lastGroupBeforeTheStart as $carro => $group) {
            if (!$preStart->hasInitialPassBeforeTheStart($carro)) {
                $this->attach($preStart->flagFor($group, $this->effect), $group);
                $carsFlaggedBeforeTheStart[$carro] = true;
            }
        }

        $raceWasStopped = $this->timeline->redFlagIntervals !== [];
        foreach ($referenceLapsByCar as $laps) {
            foreach ($laps as $lap) {
                // Only counted laps measured from another crossing can be
                // "too short"; pass zero and a lap from the start cannot.
                if ($lap->grupoAnterior !== null && $lap->estado === Lap::CONTADA) {
                    $this->flagIfTooShort($lap, $raceWasStopped);
                }
            }
        }

        if ($this->settings->firstPassIsLapZero()) {
            foreach (LateFirstPass::find($referenceLapsByCar, $this->validCars) as [$flag, $group]) {
                // A car with a pass waiting just before the start has its
                // explanation already. Asking twice about one missing pass
                // invites fixing it twice.
                if (!isset($carsFlaggedBeforeTheStart[$group->carro])) {
                    $this->attach($flag, $group);
                }
            }
        }

        foreach ($unknownTimeEntries as $entry) {
            $flag = $this->passFlags->unknownTime($entry);
            $this->effect->describeEntryOutsideAnyGroup($flag, $entry->carro, $entry->tsMs);
            $this->flags[] = $flag;
        }

        return $this->flags;
    }

    /**
     * Says, on each "repetida" flag DuplicateFilter made, what confirming
     * its later entry would do. Call after detect().
     *
     * - An entry voided automatically is in no group; confirming it brings
     *   it back as a pass of its own.
     * - An unresolved repeat stays in the count meanwhile, in a group that
     *   may or may not be a pass already.
     *
     * @param Flag[] $repeatFlags
     */
    public function describeRepeats(array $repeatFlags): void
    {
        foreach ($repeatFlags as $flag) {
            if ($flag->autoResolvido) {
                $this->effect->describeEntryOutsideAnyGroup($flag, $flag->carros[0], (int) $flag->tsMs);
                continue;
            }
            $group = $this->groupByOpenRepeatEntryId[$flag->passagemIds[0]] ?? null;
            if ($group !== null) {
                $this->effect->describe($flag, $group);
            }
        }
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
        foreach ($group->entries as $entry) {
            if (isset($openRepeatEntryIds[$entry->id])) {
                $group->alertasAbertos[] = Flag::REPETIDA;
                $this->groupByOpenRepeatEntryId[$entry->id] = $group;
                return true;
            }
        }
        return false;
    }

    /**
     * A pass only one source recorded: a typo for another car, an entry that
     * should not exist, or a real pass the others missed.
     */
    private function examineLoneEntry(PassGroup $group, SwapFinder $swapFinder): void
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
        if ($this->isUnknownCar($group->carro) || isset($this->unclassifiedCars[$group->carro])) {
            // Handled as "carro desconhecido" or "carro não classificado",
            // which say more.
            return;
        }
        $this->flagLoneEntry($group);
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
            $this->passFlags->sourceName($entry->fonteId),
            $entry->carro,
            $partner->carro,
            $resemblance !== '' ? ' (' . $resemblance . ')' : ''
        );
        $this->attach($flag, $group);
    }

    private function flagLoneEntry(PassGroup $group): void
    {
        $entry = $group->entries[0];
        $flag = new Flag(Flag::SOLITARIA, [$entry->carro], [$entry->id], (string) $entry->id);
        $flag->fonteId = $entry->fonteId;
        $flag->tsMs = $entry->tsMs;

        $shortestLapMs = $this->effect->shortestLapIfPassAt($group->carro, $group->tsMs, $group);
        $expectedMs = $this->pace->expectedLapMs($entry->carro, $entry->tsMs);

        if ($shortestLapMs !== null && $this->settings->isImpossiblyShortLap($shortestLapMs, $expectedMs)) {
            $flag->sugestao = ['tipo' => Decision::ANULAR, 'passagem_id' => $entry->id];
            $flag->motivo = sprintf(
                'Só %s registrou o carro %d, e contar esta passagem criaria uma volta de %d s, %s.',
                $this->passFlags->sourceName($entry->fonteId),
                $entry->carro,
                (int) round($shortestLapMs / 1000),
                $this->whyTooShort($shortestLapMs, $expectedMs)
            );
        } else {
            $flag->motivo = sprintf(
                'Só %s registrou o carro %d neste momento; o tempo de volta seria plausível, então a decisão é do revisor.',
                $this->passFlags->sourceName($entry->fonteId),
                $entry->carro
            );
        }
        $this->attach($flag, $group);
    }

    /**
     * @param Lap $lap a counted lap that began at another crossing
     * @param bool $raceWasStopped whether the race has any red flag (without one, racing time is plain time)
     */
    private function flagIfTooShort(Lap $lap, bool $raceWasStopped): void
    {
        $group = $lap->grupo;
        if ($group === null || $group->confirmadoPorRevisor || $group->alertasAbertos !== []) {
            return;
        }
        $lapStartMs = $lap->grupoAnterior->tsMs;
        $expectedMs = $this->pace->expectedLapMs($lap->carro, $lapStartMs);
        $racingMs = $raceWasStopped ? $this->timeline->racingMsBetween($lapStartMs, $group->tsMs) : max(0, $group->tsMs - $lapStartMs);
        if (!$this->settings->isImpossiblyShortLap($racingMs, $expectedMs)) {
            return;
        }

        $flag = new Flag(Flag::VOLTA_CURTA, [$lap->carro], $group->entryIds(), (string) $group->anchorEntryId());
        $flag->tsMs = $group->tsMs;
        $flag->motivo = sprintf(
            'O carro %d foi registrado duas vezes com %d s de intervalo, %s.',
            $lap->carro,
            (int) round($racingMs / 1000),
            $this->whyTooShort($racingMs, $expectedMs)
        );
        $this->attach($flag, $group);
    }

    /**
     * The end of the sentence that tells the reviewer WHY a lap is too
     * short, naming the rule that actually applied. Two rules can make a
     * lap "impossibly short" (see Settings::isImpossiblyShortLap), and
     * quoting the wrong one produces nonsense such as "29 s, when about
     * 28 s is expected".
     */
    private function whyTooShort(int $lapMs, ?int $expectedMs): string
    {
        if ($expectedMs !== null && $lapMs < $this->settings->fracaoVoltaMinima * $expectedMs) {
            return sprintf('quando o esperado para este carro é cerca de %d s por volta', (int) round($expectedMs / 1000));
        }
        return sprintf(
            'e duas passagens do mesmo carro com menos de %d s são tratadas como a mesma passagem registrada duas vezes',
            (int) round(2 * $this->settings->janelaMs / 1000)
        );
    }

    private function attach(Flag $flag, PassGroup $group): void
    {
        $this->effect->describe($flag, $group);
        if ($group->tempoEstimado && $flag->tipo !== Flag::TEMPO_ESTIMADO) {
            $flag->motivo .= ' O horário de uma das passagens foi estimado pelo aparelho.';
        }
        $group->alertasAbertos[] = $flag->tipo;
        $this->flags[] = $flag;
    }

    /** A number that is no team at the event: neither taking part nor present and unclassified. */
    private function isUnknownCar(int $carro): bool
    {
        return $this->validCars !== [] && !isset($this->validCars[$carro]) && !isset($this->unclassifiedCars[$carro]);
    }
}
