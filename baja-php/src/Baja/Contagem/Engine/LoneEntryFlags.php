<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * The flag for a pass only one counter recorded.
 *
 * Such an entry is one of four things, tried in this order:
 *
 * 1. THE SAME CROSSING, TYPED FAR FROM THE OTHERS ("dispersao_alta"). Two
 *    other counters agree on this car a few seconds away. The pass counts
 *    on those two; this entry was set apart because it is further from
 *    them than `dispersao_alerta_ms`. Suggestion: void it.
 *
 * 2. A WRONG NUMBER ("troca_provavel"). At the same moment the other
 *    counters recorded another car, which this counter does not have. Two
 *    stories fit that picture, and they look exactly alike:
 *      (a) this counter typed the wrong number  -> move its entry;
 *      (b) the OTHER TWO typed the wrong number -> move their two entries.
 *    Two counters against one makes (a) by far the likelier, and it is
 *    the suggestion, unless the timing says otherwise:
 *      - the lone entry's car was DUE at the line (about a lap after its
 *        previous pass, and no pass of its own nearby) while the others'
 *        pass is doubtful (held by the hold rule, or too close to another
 *        pass of that car): suggest (b), with both stories in the reason;
 *      - the lone entry's car was due and the others' pass is fine: both
 *        stories are believable. No suggestion, both stories in the reason;
 *      - the others' pass is doubtful and the lone entry's car was not
 *        due: no suggestion, both stories in the reason. (When the lone
 *        entry cannot be a pass of its car at all, an impossibly short
 *        lap, the suggestion is to void it.) The plain "move the
 *        lone entry" is never suggested towards a pass that is in doubt:
 *        that would feed a pass that probably did not happen and leave
 *        the lone entry's car short with nothing open.
 *    Nothing is ever moved onto a number that is not a classified team, or
 *    into a held group.
 *
 * 3. A NUMBER THAT IS NO TEAM, or a car that is not classified: left to the
 *    flags that say so (FlagDetector raises them).
 *
 * 4. THE SAME CROSSING, ONE COUNTER LATE ("registro_tardio"). Another
 *    counter has a lone entry of the same car, so close to this one that
 *    the two cannot be two passes (an impossibly short lap between them),
 *    yet beyond the matching window and within two of them (seen at a
 *    race: 23 s, the counter had picked the car and pressed "Passou"
 *    late). Each alone would be a
 *    "solitaria" with a plausible lap, and confirming both gave the car a
 *    lap too many. One flag about both, no suggestion: the reviewer says
 *    whose time is right ("usar_tempo"), and the pass counts once.
 *
 * 5. JUST A LONE ENTRY ("solitaria"): a real pass the others missed, or an
 *    entry that should not exist. Suggestion: void it when counting it
 *    would create an impossibly short lap; otherwise the reviewer decides.
 *
 * Both lone-entry flags name the car's previous official pass, so the
 * review screen can show the lap the entry would make.
 */
final class LoneEntryFlags
{
    private Settings $settings;
    private PaceModel $pace;
    private RaceTimeline $timeline;
    private PassFlags $passFlags;
    private ConfirmEffect $effect;
    private SwapFinder $swapFinder;

    /** @var array<int, PassGroup[]> carro => its groups, ordered by first entry */
    private array $groupsByCar;

    /** @var array<int, true> cars that take part; empty = every number does */
    private array $validCars;

    /**
     * @param array<int, PassGroup[]> $groupsByCar
     * @param array<int, true> $validCars
     */
    public function __construct(
        Settings $settings,
        PaceModel $pace,
        RaceTimeline $timeline,
        PassFlags $passFlags,
        ConfirmEffect $effect,
        SwapFinder $swapFinder,
        array $groupsByCar,
        array $validCars
    ) {
        $this->settings = $settings;
        $this->pace = $pace;
        $this->timeline = $timeline;
        $this->passFlags = $passFlags;
        $this->effect = $effect;
        $this->swapFinder = $swapFinder;
        $this->groupsByCar = $groupsByCar;
        $this->validCars = $validCars;
    }

    /**
     * Case 1 or 2 of the class comment, or null when the entry is neither.
     *
     * @param PassGroup $group a group of one entry, from a real counter, that no reviewer vouched for
     */
    public function setApartOrSwap(PassGroup $group): ?Flag
    {
        $agreed = $this->agreedPassNearby($group);
        if ($agreed !== null) {
            return $this->setApart($group, $agreed);
        }
        $partner = $this->swapFinder->findPartnerFor($group);
        return $partner === null ? null : $this->swap($group, $partner);
    }

    /**
     * Case 4 of the class comment: the other lone entry of the same
     * crossing, or null when there is none. The other group is marked, so
     * that it does not raise a flag of its own.
     *
     * @param PassGroup $group a group of one entry, from a real counter, that no reviewer vouched for
     */
    public function lateEntry(PassGroup $group): ?Flag
    {
        $entry = $group->entries[0];
        $expectedMs = $this->pace->expectedLapMs($group->carro, $group->tsMs);
        // Typing a crossing late is a matter of seconds. Two lone entries a
        // minute apart are a real pass and a stray far more often than one
        // crossing, so the partner is looked for within two matching windows.
        $longestDelayMs = 2 * $this->settings->janelaMs;
        $partner = null;
        $partnerGapMs = PHP_INT_MAX;
        foreach ($this->groupsByCar[$group->carro] ?? [] as $other) {
            if ($other === $group || !$other->isUnconfirmedSingleSource() || $other->alertasAbertos !== []
                || $other->foraDaReferencia || $other->antesDaLargada) {
                continue;
            }
            $otherEntry = $other->entries[0];
            if ($otherEntry->fonteId === $entry->fonteId || $otherEntry->fonteId === Source::REVISOR_ID) {
                continue;
            }
            $gapMs = $other->tsMs >= $group->tsMs
                ? $this->timeline->racingMsBetween($group->tsMs, $other->tsMs)
                : $this->timeline->racingMsBetween($other->tsMs, $group->tsMs);
            if ($gapMs < $partnerGapMs && $gapMs <= $longestDelayMs && $this->settings->isImpossiblyShortLap($gapMs, $expectedMs)) {
                $partner = $other;
                $partnerGapMs = $gapMs;
            }
        }
        if ($partner === null) {
            return null;
        }

        [$early, $late] = $partner->tsMs < $group->tsMs ? [$partner, $group] : [$group, $partner];
        $earlyEntry = $early->entries[0];
        $lateEntry = $late->entries[0];
        $flag = new Flag(Flag::REGISTRO_TARDIO, [$group->carro], [$earlyEntry->id, $lateEntry->id], (string) min($earlyEntry->id, $lateEntry->id));
        $flag->fonteId = $lateEntry->fonteId;
        $flag->tsMs = $earlyEntry->tsMs;
        // One crossing, two lone entries. Which way the count is in doubt
        // depends on the publish mode in force: in "confirmadas" neither
        // counts (the car may be a lap short), in "todas" both do (a lap too
        // many), and in "fonte_principal" the primary source's may count
        // alone, which is the right number of passes, only perhaps mistimed.
        $counted = (int) $early->contado + (int) $late->contado;
        $flag->contagemEmDuvida = $counted === 0 ? Flag::DUVIDA_A_MENOS : ($counted === 2 ? Flag::DUVIDA_A_MAIS : null);
        $flag->passagemAnterior = $this->previousPass($group->carro, $earlyEntry->tsMs, $early);
        $flag->temposPossiveis = [
            ['passagem_id' => $earlyEntry->id, 'fonte_id' => $earlyEntry->fonteId, 'ts_ms' => $earlyEntry->tsMs, 'passagem_alvo_id' => $lateEntry->id],
            ['passagem_id' => $lateEntry->id, 'fonte_id' => $lateEntry->fonteId, 'ts_ms' => $lateEntry->tsMs, 'passagem_alvo_id' => $earlyEntry->id],
        ];
        $this->effect->describeTimes($flag, [$early, $late]);
        $flag->motivo = sprintf(
            '%s registrou o carro %d %d s depois de %s: provavelmente a mesma passagem, digitada com atraso. Como duas passagens, dariam uma volta de %d s, %s. Escolha o horário de quem registrou na hora: a passagem conta uma vez, e o outro registro fica junto dela sem definir o horário.',
            $this->passFlags->sourceName($lateEntry->fonteId),
            $group->carro,
            (int) round($partnerGapMs / 1000),
            $this->passFlags->sourceName($earlyEntry->fonteId),
            (int) round($partnerGapMs / 1000),
            $this->whyTooShort($partnerGapMs, $expectedMs)
        );
        $partner->alertasAbertos[] = Flag::REGISTRO_TARDIO;
        return $flag;
    }

    /** Case 5 of the class comment. */
    public function loneEntry(PassGroup $group): Flag
    {
        $entry = $group->entries[0];
        $flag = new Flag(Flag::SOLITARIA, [$entry->carro], [$entry->id], (string) $entry->id);
        $flag->fonteId = $entry->fonteId;
        $flag->tsMs = $entry->tsMs;
        $flag->passagemAnterior = $this->previousPass($entry->carro, $entry->tsMs, $group);

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
        return $flag;
    }

    /** @return array{passagem_id: int, ts_ms: int}|null the car's official pass before $tsMs */
    private function previousPass(int $carro, int $tsMs, PassGroup $itself): ?array
    {
        $previous = $this->effect->previousPassBefore($carro, $tsMs, $itself);
        return $previous === null ? null : ['passagem_id' => $previous->anchorEntryId(), 'ts_ms' => $previous->tsMs];
    }

    /**
     * The end of the sentence that tells the reviewer WHY a lap is too
     * short, naming the rule that actually applied. Two rules can make a
     * lap "impossibly short" (see Settings::isImpossiblyShortLap), and
     * quoting the wrong one produces nonsense such as "29 s, when about
     * 28 s is expected".
     */
    public function whyTooShort(int $lapMs, ?int $expectedMs): string
    {
        if ($expectedMs !== null && $lapMs < $this->settings->fracaoVoltaMinima * $expectedMs) {
            return sprintf('quando o esperado para este carro é cerca de %d s por volta', (int) round($expectedMs / 1000));
        }
        return sprintf(
            'e duas passagens do mesmo carro com menos de %d s são tratadas como a mesma passagem registrada duas vezes',
            (int) round(2 * $this->settings->janelaMs / 1000)
        );
    }

    /**
     * A group of the same car, agreed by two or more OTHER counters, close
     * enough to the lone entry that the matcher would have put them
     * together if the entry were not so far from the counters who agree.
     */
    private function agreedPassNearby(PassGroup $loneGroup): ?PassGroup
    {
        $loneMs = $loneGroup->tsMs;
        $janelaMs = $this->settings->janelaMs;
        $nearest = null;
        $nearestMs = PHP_INT_MAX;
        foreach ($this->groupsByCar[$loneGroup->carro] ?? [] as $other) {
            if ($other->primeiroTsMs > $loneMs + $janelaMs) {
                break;
            }
            if ($other === $loneGroup || $other->dispersa || count($other->realSourceIds()) < 2) {
                continue;
            }
            if ($other->hasSource($loneGroup->entries[0]->fonteId)) {
                // The counter is among those who agree: this is a second
                // entry of its own, not the same crossing typed late.
                continue;
            }
            $entries = $other->entries;
            $fromMs = min($loneMs, $entries[0]->tsMs);
            $toMs = max($loneMs, $entries[count($entries) - 1]->tsMs);
            $distanceMs = abs($other->tsMs - $loneMs);
            if ($toMs - $fromMs <= $janelaMs && $distanceMs < $nearestMs) {
                $nearest = $other;
                $nearestMs = $distanceMs;
            }
        }
        return $nearest;
    }

    /**
     * The other shape of a wrong number: NO lone entry. Every counter who
     * recorded this car is missing from an agreed pass of ANOTHER car at
     * the same moment. Each of them may have typed this car's number for
     * the car it lacks, and then this pass never happened, although two
     * counters "agree" on it (it happened in a simulated race: cars 9 and
     * 7 crossed together, one counter typed 8 for the 9 and another typed
     * 8 for the 7, and car 8 gained a lap with nothing to see).
     *
     * The same is then true of the other passes in the knot: seen from the
     * entries alone, any ONE of those cars may be the one that did not
     * pass. So there is one flag for the whole knot, about every car and
     * every entry in it, raised on the first of its passes.
     *
     * Flagged whatever the timing says, with no suggestion: it changes no
     * count, it puts the passes in front of a person. Only when ALL the
     * witnesses of a pass are in that situation: one counter missing
     * another car is an ordinary miss next to an ordinary pass.
     *
     * Both halves rest on a counter's silence, so the flag waits, like any
     * flag that does (SourceAvailability), until the silent counters have
     * uploaded past it: a counter not in this pass may still have typed the
     * car (then not every witness lacks another car), and a witness may
     * still have typed the other car. Without the wait, a phone offline for
     * a quarter of an hour raised it on the passes its counter had not
     * uploaded yet (LiveRaceTest, two seeds in four hundred).
     *
     * @param PassGroup $group a group of exactly two counters' entries that no reviewer vouched for
     * @return array{0: Flag, 1: PassGroup[]}|null the flag and the other passes of the knot
     */
    public function everyWitnessMayHaveMeantAnotherCar(PassGroup $group, SourceAvailability $availability): ?array
    {
        $witnesses = $group->realSourceIds();
        if ($availability->hasUnknownSourceAt($group->tsMs, $witnesses)) {
            return null;
        }
        $lacking = [];
        $others = [];
        foreach ($group->entries as $entry) {
            $other = $this->swapFinder->agreedPassOfAnotherCarWithout($entry);
            if ($other === null) {
                return null;
            }
            if (!$availability->isSourceKnownAt($entry->fonteId, $other->tsMs)) {
                return null;
            }
            $lacking[] = sprintf('%s não tem o carro %d', $this->passFlags->sourceName($entry->fonteId), $other->carro);
            $others[spl_object_id($other)] = $other;
        }
        $cars = [$group->carro];
        $entryIds = $group->entryIds();
        foreach ($others as $other) {
            $cars[] = $other->carro;
            $entryIds = array_merge($entryIds, $other->entryIds());
        }
        // The id is built from the whole knot, not from the pass that
        // happened to raise the flag: when a reviewer vouches for one of
        // the passes, another one raises it, and it must still be the same
        // flag to the review screen. (Only counters' entries: a pass a
        // reviewer added has a negative id and comes and goes.)
        $flag = new Flag(Flag::TROCA_PROVAVEL, array_values(array_unique($cars)), $entryIds, 'grupo-' . min(array_filter($entryIds, fn(int $id): bool => $id > 0)));
        $flag->tsMs = $group->tsMs;
        $flag->motivo = sprintf(
            'Os carros %s foram registrados no mesmo momento, e todas as contagens que registraram o carro %d (%s) deixaram de registrar outro deles: %s. Um desses carros pode não ter passado: as contagens que o registraram teriam digitado o número dele no lugar do carro que lhes falta. Confira qual e mova os registros.',
            implode(', ', array_unique($cars)),
            $group->carro,
            $this->passFlags->sourceNames($group),
            implode('; ', $lacking)
        );
        return [$flag, array_values($others)];
    }

    private function setApart(PassGroup $group, PassGroup $agreed): Flag
    {
        $entry = $group->entries[0];
        $flag = new Flag(Flag::DISPERSAO_ALTA, [$entry->carro], [$entry->id], (string) $entry->id);
        $flag->fonteId = $entry->fonteId;
        $flag->tsMs = $entry->tsMs;
        $flag->sugestao = ['tipo' => Decision::ANULAR, 'passagem_id' => $entry->id];
        $flag->motivo = sprintf(
            '%s registrou o carro %d %d s %s das outras contagens, que concordam entre si (%s). A passagem conta pelas que concordam; este registro ficou separado, fora da contagem. Se é a mesma passagem digitada fora de hora, anule-o.',
            $this->passFlags->sourceName($entry->fonteId),
            $entry->carro,
            (int) round(abs($entry->tsMs - $agreed->tsMs) / 1000),
            $entry->tsMs < $agreed->tsMs ? 'antes' : 'depois',
            $this->passFlags->sourceNames($agreed)
        );
        return $flag;
    }

    /** Case 2 of the class comment. Both cars are always flagged together, whatever the timing says. */
    private function swap(PassGroup $group, PassGroup $partner): Flag
    {
        $entry = $group->entries[0];
        $loneCarWasDue = $this->wasDueAtTheLine($group);
        $partnerIsHeld = $partner->retidaNaReferencia;
        $partnerIsDoubtful = $partnerIsHeld || $this->isTooCloseToAnotherAgreedPass($partner);

        $resemblance = DigitSimilarity::describe(DigitSimilarity::rank($entry->carro, $partner->carro));
        $sourceName = $this->passFlags->sourceName($entry->fonteId);
        $flag = new Flag(Flag::TROCA_PROVAVEL, [$entry->carro, $partner->carro], [$entry->id], (string) $entry->id);
        $flag->fonteId = $entry->fonteId;
        $flag->tsMs = $entry->tsMs;

        if (!$loneCarWasDue && !$partnerIsDoubtful) {
            $flag->sugestao = ['tipo' => Decision::REATRIBUIR, 'passagem_id' => $entry->id, 'equipe_numero' => $partner->carro];
            $flag->motivo = sprintf(
                '%s registrou sozinha o carro %d e não registrou o carro %d, que as outras contagens viram no mesmo momento%s.',
                $sourceName,
                $entry->carro,
                $partner->carro,
                $resemblance !== '' ? ' (' . $resemblance . ')' : ''
            );
            return $flag;
        }

        // From here on the lone entry may be the one that is right (its car
        // was due, or the others' pass is itself in doubt), so the reviewer
        // is shown the others' entries too and may move those. The plain
        // "move the lone entry" is never suggested here.
        $bothStories = sprintf(
            '%s registrou o carro %d enquanto %s registraram o carro %d no mesmo momento%s. Ou %s errou o número (o registro dela é do carro %d), ou as outras erraram (os registros delas são do carro %d).',
            $sourceName,
            $entry->carro,
            $this->passFlags->sourceNames($partner),
            $partner->carro,
            $resemblance !== '' ? ' (' . $resemblance . ')' : '',
            $sourceName,
            $partner->carro,
            $entry->carro
        );
        $movesTheOthers = $loneCarWasDue && $partnerIsDoubtful && count($partner->entries) === 2;
        if ($movesTheOthers) {
            $flag->passagemIds = array_merge([$entry->id], $partner->entryIds());
            $flag->sugestao = ['tipo' => 'reatribuir_grupo', 'passagem_ids' => $partner->entryIds(), 'equipe_numero' => $entry->carro];
            $flag->motivo = $bothStories . sprintf(
                ' Uma passagem do carro %d era esperada neste momento e a do carro %d %s; por isso a sugestão é mover os dois registros para o carro %d.',
                $entry->carro,
                $partner->carro,
                $partnerIsHeld ? 'está retida, perto demais da passagem anterior dele' : 'ficaria perto demais de outra passagem dele',
                $entry->carro
            );
            return $flag;
        }
        $flag->passagemIds = array_merge([$entry->id], $partner->entryIds());
        if (!$loneCarWasDue && $this->cannotBeAPassOfItsCar($group)) {
            // Neither story helps: the others' pass is in doubt, and the
            // lone entry cannot be a pass of its own car. Both cars are
            // still shown together; the one safe thing to suggest is what a
            // lone entry at an impossible moment always gets.
            $flag->sugestao = ['tipo' => Decision::ANULAR, 'passagem_id' => $entry->id];
            $flag->motivo = $bothStories . sprintf(
                ' A passagem do carro %d está em dúvida, e o carro %d não pode ter passado neste momento (ficaria com uma volta curta demais); a sugestão é anular o registro de %s.',
                $partner->carro,
                $entry->carro,
                $sourceName
            );
            return $flag;
        }
        $flag->motivo = $bothStories . ($loneCarWasDue
            ? sprintf(' As duas leituras são plausíveis (uma passagem do carro %d também era esperada neste momento), então a decisão é do revisor.', $entry->carro)
            : sprintf(' A passagem do carro %d está em dúvida (perto demais de outra passagem dele), então não há sugestão: a decisão é do revisor.', $partner->carro));
        return $flag;
    }

    /** True when counting the lone entry would give its car an impossibly short lap: it cannot be a real pass. */
    private function cannotBeAPassOfItsCar(PassGroup $group): bool
    {
        $shortestLapMs = $this->effect->shortestLapIfPassAt($group->carro, $group->tsMs, $group);
        return $shortestLapMs !== null
            && $this->settings->isImpossiblyShortLap($shortestLapMs, $this->pace->expectedLapMs($group->carro, $group->tsMs));
    }

    /** A car is "due" from this fraction of its expected lap after its previous pass. */
    private const DUE_FROM_FRACTION_OF_LAP = 0.75;

    /**
     * Was the car the lone entry names DUE at the line at that moment? Yes
     * when it is a team that takes part, about a lap (three quarters of
     * its expected lap, or more) has gone by since its previous pass, and
     * its next pass, if it has one already, is as far away again: the lone
     * entry sits where the car is missing a pass.
     *
     * A stray entry for a car that is somewhere else on the track almost
     * never passes this test: it falls inside one of that car's laps, close
     * to the pass before it or the pass after it.
     */
    private function wasDueAtTheLine(PassGroup $group): bool
    {
        $carro = $group->carro;
        if ($this->validCars !== [] && !isset($this->validCars[$carro])) {
            return false;
        }
        $expectedMs = $this->pace->expectedLapMs($carro, $group->tsMs);
        $sincePreviousMs = $this->effect->lapBeforePassAt($carro, $group->tsMs, $group);
        if ($expectedMs === null || $sincePreviousMs === null) {
            return false;
        }
        $dueFromMs = self::DUE_FROM_FRACTION_OF_LAP * $expectedMs;
        $untilNextMs = $this->effect->lapAfterPassAt($carro, $group->tsMs, $group);
        return $sincePreviousMs >= $dueFromMs && ($untilNextMs === null || $untilNextMs >= $dueFromMs);
    }

    /**
     * True when the group and another pass of its car that the counters
     * agree on (official, or held by the hold rule), BEFORE OR AFTER it,
     * are closer together than a lap can be: one of the two did not happen,
     * and it may be this one. Two yardsticks, either is enough: the hold
     * rule's own (the other pass is held within the car's minimum plausible
     * lap of this one: the phantom came first and the real pass paid for
     * it), and the pace model's.
     */
    private function isTooCloseToAnotherAgreedPass(PassGroup $group): bool
    {
        $shortestMs = null;
        foreach ($this->groupsByCar[$group->carro] ?? [] as $other) {
            if ($other === $group || !($other->referencia || $other->retidaNaReferencia)) {
                continue;
            }
            $gapMs = $other->tsMs <= $group->tsMs
                ? $this->timeline->racingMsBetween($other->tsMs, $group->tsMs)
                : $this->timeline->racingMsBetween($group->tsMs, $other->tsMs);
            if ($other->motivoRetencao === PassGroup::RETIDA_PROXIMA_DEMAIS && $other->voltaMinimaMs !== null && $gapMs < $other->voltaMinimaMs) {
                return true;
            }
            if ($shortestMs === null || $gapMs < $shortestMs) {
                $shortestMs = $gapMs;
            }
        }
        return $shortestMs !== null
            && $this->settings->isImpossiblyShortLap($shortestMs, $this->pace->expectedLapMs($group->carro, $group->tsMs));
    }
}
