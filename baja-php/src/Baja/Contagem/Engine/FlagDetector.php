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
 *
 * IS THE COUNT IN DOUBT? A flag about a held pass, or about two official
 * passes too close together, means the car's lap count itself may be off
 * by one right now. Those flags say so (`contagemEmDuvida`) and go to the
 * top of the review queue.
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

    /** Decides what a pass only one counter recorded is. */
    private LoneEntryFlags $loneEntries;

    /** @var array<int, PassGroup> id of either entry of an open "repetida" => the group it is in */
    private array $groupByOpenRepeatEntryId = [];

    /** @var array<int, true> ids of the EARLIER entry of each open "repetida" */
    private array $earlierRepeatEntryIds = [];

    /** @var array<int, true> ids of the later entry of each "repetida" that turned out to be two passes, not a repeat */
    private array $notRepeatsAfterAll = [];

    // What examining a lone entry can conclude, besides flagging it.
    private const NOT_LONE = 0;
    /** No other counter was supposed to be counting: the entry stands on its own. */
    private const ONLY_COUNTER = 1;
    /** Another counter may simply be offline; its entry could still arrive. */
    private const OTHERS_MAY_STILL_UPLOAD = 2;
    /** The other counters are known to have nothing here. */
    private const OTHERS_MISSED_IT = 3;

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
     * @param array<int, true> $confirmedEntryIds entries a reviewer confirmed
     * @return Flag[] the flags found here (the "repetida" ones are not repeated in this list)
     */
    public function detect(
        array $groupsByCar,
        array $groupsInTimeOrder,
        array $referenceLapsByCar,
        DuplicateFilter $duplicates,
        array $unknownTimeEntries = [],
        array $confirmedEntryIds = []
    ): array {
        $openRepeatEntryIds = $duplicates->openRepeatEntryIds;
        $openRepeatCars = $duplicates->openRepeatCars;
        $this->setAsideRepeatsThatAreTwoPasses($duplicates->flags, $groupsByCar);
        foreach ($this->notRepeatsAfterAll as $laterEntryId => $unused) {
            unset($openRepeatEntryIds[$laterEntryId]);
        }
        // This loop visits every pass group of the race; what it asks of
        // each one is kept as plain comparisons wherever possible.
        $teamsAreKnown = $this->validCars !== [];
        $validCars = $this->validCars;
        $unclassifiedCars = $this->unclassifiedCars;
        $this->effect = new ConfirmEffect($this->timeline, $groupsByCar);
        $swapFinder = new SwapFinder($groupsInTimeOrder, $this->settings->janelaMs, $this->validCars);
        $this->loneEntries = new LoneEntryFlags($this->settings, $this->pace, $this->timeline, $this->passFlags, $this->effect, $swapFinder, $groupsByCar, $this->validCars);
        $preStart = new PreStartPasses($this->settings, $this->timeline, $referenceLapsByCar, $this->validCars, $this->passFlags);
        /** @var array<int, PassGroup> carro => its last group shortly before the start */
        $lastGroupBeforeTheStart = [];
        $finishMs = $this->timeline->encerramentoMs;
        $stops = $this->timeline->pitIntervals + $this->timeline->fuelIntervals;

        foreach ($groupsInTimeOrder as $group) {
            $carro = $group->carro;
            if ($finishMs !== null && $group->inicioMs() <= $finishMs && !$group->confirmadoPorRevisor) {
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
            $lone = self::NOT_LONE;
            // (Not one another lone entry already claimed: see LoneEntryFlags::lateEntry.)
            if (!$hasOpenRepeat && !$group->adicionadoPorRevisor && count($group->entries) === 1 && $group->alertasAbertos === []) {
                $lone = $this->examineLoneEntry($group);
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
            } elseif ($group->retidaNaReferencia) {
                $this->attach($this->heldPassFlag($group, $groupsByCar[$carro] ?? []), $group);
            } elseif ($group->saidaPresumida !== null) {
                $this->attach($this->passFlags->assumedExit($group, $group->saidaPresumida), $group);
            } elseif (isset($stops[$carro]) && ($area = $this->areaTheCarIsLoggedIn($carro, $group->tsMs)) !== null) {
                $areaFlag = $this->passFlags->insideAnArea($group, $area);
                $areaFlag->voltasAoMarcarSaida = 0;
                $this->attach($areaFlag, $group);
            } elseif (
                isset($group->entries[1]) && !isset($group->entries[2]) && !$group->adicionadoPorRevisor
                && ($knot = $this->loneEntries->everyWitnessMayHaveMeantAnotherCar($group, $this->availability)) !== null
            ) {
                // One flag for the whole knot: the other passes in it are
                // marked so that they do not raise the same flag again.
                $this->attach($knot[0], $group);
                foreach ($knot[1] as $otherPass) {
                    // (A held pass keeps its own flag: that one says the
                    // car's count is in doubt, which this one does not.)
                    if ($otherPass->alertasAbertos === [] && !$otherPass->retidaNaReferencia) {
                        $otherPass->alertasAbertos[] = Flag::TROCA_PROVAVEL;
                    }
                }
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
            // Confirming such an entry changes no count (it is never
            // matched); it only says "seen, stop warning".
            if (!isset($confirmedEntryIds[$entry->id])) {
                $this->flags[] = $this->passFlags->unknownTime($entry);
            }
        }

        return $this->flags;
    }

    /**
     * Says, on each "repetida" flag DuplicateFilter made, what confirming
     * its later entry would do, and which of the two entries is the one to
     * void. Call after detect().
     *
     * - An entry voided automatically is in no group; confirming it brings
     *   it back as a pass of its own.
     * - An unresolved repeat stays in the count meanwhile, in a group that
     *   may or may not be a pass already. DuplicateFilter suggests voiding
     *   the later entry, which is right when nothing else is known. But
     *   when the later entry sits with the other counters' entries and the
     *   earlier one stands alone, the earlier one is the stray (a typo for
     *   this car just before it really passed), and voiding the later one
     *   would throw away the good record.
     *
     * A "repetida" that turned out to be two passes (see
     * setAsideRepeatsThatAreTwoPasses) is dropped here.
     *
     * @param Flag[] $repeatFlags
     * @return Flag[] the "repetida" flags that stand
     */
    public function describeRepeats(array $repeatFlags): array
    {
        $standing = [];
        foreach ($repeatFlags as $flag) {
            if (!$flag->autoResolvido && isset($this->notRepeatsAfterAll[$flag->passagemIds[0]])) {
                continue;
            }
            $standing[] = $flag;
            if ($flag->autoResolvido) {
                $this->effect->describeEntryOutsideAnyGroup($flag, $flag->carros[0], (int) $flag->tsMs);
                continue;
            }
            [$laterId, $earlierId] = $flag->passagemIds;
            $laterGroup = $this->groupByOpenRepeatEntryId[$laterId] ?? null;
            $earlierGroup = $this->groupByOpenRepeatEntryId[$earlierId] ?? null;
            if ($laterGroup === null) {
                continue;
            }
            $this->effect->describe($flag, $laterGroup);
            $this->markDoubt($flag, $laterGroup);
            $earlierIsTheStray = $earlierGroup !== null
                && $earlierGroup !== $laterGroup
                && count($earlierGroup->entries) === 1
                && count($laterGroup->realSourceIds()) >= 2;
            if ($earlierIsTheStray) {
                $flag->sugestao = ['tipo' => Decision::ANULAR, 'passagem_id' => $earlierId];
                $flag->motivo .= ' O segundo registro coincide com o das outras contagens; o que sobra é o primeiro.';
            }
        }
        return $standing;
    }

    /**
     * DuplicateFilter flags "the same counter, the same car, twice within
     * the matching window" before anything is matched, and suggests voiding
     * the later entry. That is right for a counter's double entry. It is
     * wrong when EACH of the two entries sits in a group other counters
     * agree on: then nobody typed anything twice. There are two candidate
     * passes a few seconds apart, each seen by several people, and one of
     * them is probably a wrong number (two counters typed this car for
     * another one, just before this car really passed). That doubt has its
     * own flag: the hold rule holds the later pass. A "repetida" per
     * counter on top of it would suggest voiding each counter's entry of
     * the later pass, which is usually the REAL one, and accepting those
     * suggestions would dismantle it and leave the phantom counted with
     * nothing open.
     *
     * So such a pair is not a "repetida": no flag, no suggestion. The
     * later group is then examined like any other and gets the flag of a
     * held pass.
     *
     * @param Flag[] $repeatFlags
     * @param array<int, PassGroup[]> $groupsByCar
     */
    private function setAsideRepeatsThatAreTwoPasses(array $repeatFlags, array $groupsByCar): void
    {
        $pairsByCar = [];
        foreach ($repeatFlags as $flag) {
            if (!$flag->autoResolvido) {
                $pairsByCar[$flag->carros[0]][] = $flag->passagemIds;
            }
        }
        foreach ($pairsByCar as $carro => $pairs) {
            $groupOfEntry = [];
            foreach ($groupsByCar[$carro] ?? [] as $group) {
                foreach ($group->entries as $entry) {
                    $groupOfEntry[$entry->id] = $group;
                }
            }
            foreach ($pairs as [$laterId, $earlierId]) {
                $later = $groupOfEntry[$laterId] ?? null;
                $earlier = $groupOfEntry[$earlierId] ?? null;
                $areTwoSharedGroups = $later !== null && $earlier !== null && $later !== $earlier
                    && count($later->realSourceIds()) >= 2 && count($earlier->realSourceIds()) >= 2;
                // The later entry sits with another counter's entry far
                // from it (a `dispersa` group, held): the repeat and that
                // entry may be one late crossing, two stray entries, or a
                // real pass. Which entry to void is not something to
                // accept blindly, so no "repetida" with its suggestion: the
                // held group gets its "dispersao_alta" flag, with none.
                $laterIsInAnUnagreedGroup = $later !== null && $later->dispersa;
                if ($areTwoSharedGroups || $laterIsInAnUnagreedGroup) {
                    $this->notRepeatsAfterAll[$laterId] = true;
                } else {
                    $this->earlierRepeatEntryIds[$earlierId] = true;
                }
            }
        }
    }

    /**
     * The entries of an unresolved "repetida" have their flag already (made
     * by DuplicateFilter). Recording it on the group keeps every other
     * check from flagging the same thing again, in whatever publish mode.
     * That goes for the group of the later entry, and for the earlier
     * entry when it stands alone.
     *
     * @param array<int, true> $openRepeatEntryIds ids of the later entries
     */
    private function markOpenRepeat(PassGroup $group, array $openRepeatEntryIds): bool
    {
        $isMarked = false;
        $standsAlone = count($group->entries) === 1;
        foreach ($group->entries as $entry) {
            $isLater = isset($openRepeatEntryIds[$entry->id]);
            if (!$isLater && !isset($this->earlierRepeatEntryIds[$entry->id])) {
                continue;
            }
            $this->groupByOpenRepeatEntryId[$entry->id] = $group;
            if (($isLater || $standsAlone) && !$isMarked) {
                $group->alertasAbertos[] = Flag::REPETIDA;
                $isMarked = true;
            }
        }
        return $isMarked;
    }

    /**
     * A pass only one source recorded: see LoneEntryFlags for what it can
     * be. Returns what was concluded, for the checks that follow.
     */
    private function examineLoneEntry(PassGroup $group): int
    {
        $entry = $group->entries[0];
        $otherActiveSources = array_diff($this->availability->activeSourceIdsAt($entry->tsMs), [$entry->fonteId]);
        if ($otherActiveSources === []) {
            // Nobody else was counting; the entry stands on its own.
            return self::ONLY_COUNTER;
        }
        if ($this->availability->hasUnknownSourceAt($entry->tsMs, [$entry->fonteId])) {
            // Another counter may simply be offline. Its entries could still
            // arrive and complete this group, so say nothing yet.
            return self::OTHERS_MAY_STILL_UPLOAD;
        }

        $flag = $this->loneEntries->setApartOrSwap($group);
        if ($flag !== null) {
            $this->attach($flag, $group);
            return self::OTHERS_MISSED_IT;
        }
        if ($this->isUnknownCar($group->carro) || isset($this->unclassifiedCars[$group->carro])) {
            // Handled as "carro desconhecido" or "carro não classificado",
            // which say more.
            return self::OTHERS_MISSED_IT;
        }
        if ($this->areaTheCarIsLoggedIn($group->carro, $group->tsMs) !== null) {
            // Handled as "no_box" or "no_abastecimento": the log says the
            // car was not on the track at all.
            return self::OTHERS_MISSED_IT;
        }
        $this->attach($this->loneEntries->lateEntry($group) ?? $this->loneEntries->loneEntry($group), $group);
        return self::OTHERS_MISSED_IT;
    }

    /**
     * The flag of a group that is held, by whichever rule held it. One flag per pass, saying everything.
     *
     * @param PassGroup[] $groupsOfTheCar
     */
    private function heldPassFlag(PassGroup $group, array $groupsOfTheCar): Flag
    {
        if ($group->motivoRetencao === PassGroup::RETIDA_NO_BOX || $group->motivoRetencao === PassGroup::RETIDA_NO_ABASTECIMENTO) {
            $area = $group->motivoRetencao === PassGroup::RETIDA_NO_BOX ? RaceTimeline::AREA_BOX : RaceTimeline::AREA_ABASTECIMENTO;
            $flag = $this->passFlags->insideAnArea($group, $area);
            $flag->voltasAoMarcarSaida = $this->passesAnExitWouldRelease($group, $area, $groupsOfTheCar);
            return $flag;
        }
        if ($group->motivoRetencao === PassGroup::RETIDA_DISPERSAO) {
            $flag = $this->passFlags->spreadTooWide($group, $this->settings->agreementSpreadMs());
            $this->effect->describeTimes($flag, [$group]);
            return $flag;
        }
        return $this->passFlags->tooCloseToThePreviousPass($group);
    }

    /**
     * How many held passes would start counting if the car's exit were
     * recorded just before this pass: this one and the later ones held in
     * the same stay, counting only passes two counters agree on that are
     * not also too close to the car's previous pass. Zero when this pass
     * itself rests on one counter.
     *
     * @param PassGroup[] $groupsOfTheCar
     */
    private function passesAnExitWouldRelease(PassGroup $group, string $area, array $groupsOfTheCar): int
    {
        $isAgreed = fn(PassGroup $pass): bool => count($pass->realSourceIds()) >= 2 && !$pass->dispersa && $pass->intervaloDesdeAnteriorMs === null;
        if (!$isAgreed($group)) {
            return 0;
        }
        $stayOf = fn(PassGroup $pass): ?int => $area === RaceTimeline::AREA_BOX
            ? $this->timeline->pitEntryBefore($pass->carro, $pass->tsMs)
            : $this->timeline->fuelEntryBefore($pass->carro, $pass->tsMs);
        $stay = $stayOf($group);
        $count = 0;
        foreach ($groupsOfTheCar as $pass) {
            if ($pass->tsMs >= $group->tsMs && $pass->motivoRetencao === $group->motivoRetencao && $pass->retidaNaReferencia && $isAgreed($pass) && $stayOf($pass) === $stay) {
                $count++;
            }
        }
        return $count;
    }

    /** @return string|null the area (RaceTimeline::AREA_*) whose log says the car is inside at that moment */
    private function areaTheCarIsLoggedIn(int $carro, int $tsMs): ?string
    {
        if ($this->timeline->isInPitAt($carro, $tsMs)) {
            return RaceTimeline::AREA_BOX;
        }
        return $this->timeline->isFuelingAt($carro, $tsMs) ? RaceTimeline::AREA_ABASTECIMENTO : null;
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
        if ($group->adicionadoPorRevisor && isset($group->entries[1])) {
            // A reviewer added a pass exactly where the counters have
            // entries: that is the reviewer saying "this pass happened",
            // like a confirmation. Flagging it again would leave the event
            // open after the person has decided it. (A pass added where
            // nobody has anything is still checked: it may duplicate the
            // pass next to it.)
            return;
        }
        $lapStartMs = $lap->grupoAnterior->tsMs;
        $expectedMs = $this->pace->expectedLapMs($lap->carro, $lapStartMs);
        $racingMs = $raceWasStopped ? $this->timeline->racingMsBetween($lapStartMs, $group->tsMs) : max(0, $group->tsMs - $lapStartMs);
        if (!$this->settings->isImpossiblyShortLap($racingMs, $expectedMs)) {
            return;
        }

        // Either pass may be the wrong one, so the flag is about both. The
        // later one comes first: it is the one a plain "confirmar" is about.
        $earlier = $lap->grupoAnterior;
        $flag = new Flag(Flag::VOLTA_CURTA, [$lap->carro], array_merge($group->entryIds(), $earlier->entryIds()), (string) $group->anchorEntryId());
        $flag->tsMs = $group->tsMs;
        $flag->passagens = [
            ['passagem_ids' => $earlier->entryIds(), 'ts_ms' => $earlier->tsMs],
            ['passagem_ids' => $group->entryIds(), 'ts_ms' => $group->tsMs],
        ];
        $flag->contagemEmDuvida = Flag::DUVIDA_A_MAIS;
        $flag->motivo = sprintf(
            'O carro %d tem duas passagens oficiais com %d s de intervalo (a primeira registrada por %s, a segunda por %s), %s. Uma das duas provavelmente não aconteceu, e pode ser qualquer uma: descarte a que estiver errada.',
            $lap->carro,
            (int) round($racingMs / 1000),
            $this->passFlags->sourceNames($earlier),
            $this->passFlags->sourceNames($group),
            $this->loneEntries->whyTooShort($racingMs, $expectedMs)
        );
        $this->attach($flag, $group);
    }

    /**
     * A flag attached to a held group is about a pass that may be real and
     * is not in the count: the car may be a lap short. (When the publish
     * mode in force counts the group anyway, it is the other way round.)
     */
    private function markDoubt(Flag $flag, PassGroup $group): void
    {
        if ($group->retidaNaReferencia) {
            $flag->contagemEmDuvida = $group->contado ? Flag::DUVIDA_A_MAIS : Flag::DUVIDA_A_MENOS;
        }
    }

    private function attach(Flag $flag, PassGroup $group): void
    {
        $this->effect->describe($flag, $group);
        $this->markDoubt($flag, $group);
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
