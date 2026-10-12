<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Source;

/**
 * Step 2: the same source recording the same car twice inside the matching
 * window ("repetida").
 *
 * Two situations are resolved without bothering a reviewer, by ignoring the
 * later entry:
 *
 * - the two entries are a few seconds apart (duplicata_ms): a double tap;
 * - the two entries came from different devices: two people are on the same
 *   counter during a handover and both recorded the car.
 *
 * Anything else (same device, 5 to 20 seconds apart) is odd enough to show
 * to a reviewer, with "void the later one" as the suggestion.
 *
 * Repeats typed before the start (warm-up, rehearsal) are dealt with in the
 * same way, silently: no flag, and nothing held against the counter.
 */
final class DuplicateFilter
{
    /** @var Entry[] entries that go on to matching */
    public array $keptEntries = [];

    /** @var int[] ids of the entries voided automatically */
    public array $autoVoidedEntryIds = [];

    /** @var array<int, int> fonteId => number of entries voided automatically */
    public array $autoVoidedCountBySource = [];

    /** @var Flag[] auto-resolved and open "repetida" flags */
    public array $flags = [];

    /** @var array<int, true> ids of the later entry of each open "repetida" flag */
    public array $openRepeatEntryIds = [];

    /** @var array<int, true> cars that have an open "repetida" flag, so only their groups need to be searched for it */
    public array $openRepeatCars = [];

    /**
     * Entries typed before the start are warm-up and rehearsal. Repeats
     * among them are handled like any other (so a pass confirmed there is
     * not doubled by a double tap), but nobody is asked to review them.
     */
    private int $flagsFromMs = PHP_INT_MIN;

    /**
     * @param Entry[] $entriesInTimeOrder entries after decisions, in time order
     * @param array<int, true> $confirmedEntryIds entries a reviewer said to keep
     * @param array<int, Source> $sourcesById
     * @param int|null $largadaMs repeats typed before this moment are dealt with the same way but raise no flag
     */
    public static function filter(array $entriesInTimeOrder, Settings $settings, array $confirmedEntryIds, array $sourcesById, ?int $largadaMs = null): self
    {
        $result = new self();
        $result->flagsFromMs = $largadaMs ?? PHP_INT_MIN;
        $janelaMs = $settings->janelaMs;

        /** @var array<int, array<int, Entry>> fonteId => carro => the last entry kept for that source and car */
        $lastKeptBySourceAndCar = [];

        foreach ($entriesInTimeOrder as $entry) {
            $fonteId = $entry->fonteId;
            if ($fonteId === Source::REVISOR_ID) {
                // A pass added by a reviewer is deliberate; never second-guess it.
                $result->keptEntries[] = $entry;
                continue;
            }

            $lastKept = $lastKeptBySourceAndCar[$fonteId][$entry->carro] ?? null;
            if ($lastKept === null || $entry->tsMs - $lastKept->tsMs >= $janelaMs) {
                // The ordinary case: this source's first entry for the car in a while.
                $result->keptEntries[] = $entry;
                $lastKeptBySourceAndCar[$fonteId][$entry->carro] = $entry;
                continue;
            }
            if ($lastKept->tsMs < $result->flagsFromMs && $entry->tsMs >= $result->flagsFromMs) {
                // The earlier entry was typed before the start (warm-up, or
                // a clock a little early) and this one after it. What is
                // typed before the start is not a pass, so it cannot make
                // the car's first real crossing a "double tap": voiding
                // this entry would cost the car a counter's word on its
                // pass zero, or the pass itself.
                $result->keptEntries[] = $entry;
                $lastKeptBySourceAndCar[$fonteId][$entry->carro] = $entry;
                continue;
            }

            if ($result->keepsRepeat($entry, $lastKept, $settings, $confirmedEntryIds, $sourcesById)) {
                $result->keptEntries[] = $entry;
                $lastKeptBySourceAndCar[$fonteId][$entry->carro] = $entry;
            }
        }

        return $result;
    }

    /**
     * Deals with an entry that repeats `$lastKept` (same source, same car,
     * inside the matching window). Returns true when the entry stays in the
     * count, false when it was voided automatically.
     *
     * @param array<int, true> $confirmedEntryIds
     * @param array<int, Source> $sourcesById
     */
    private function keepsRepeat(Entry $entry, Entry $lastKept, Settings $settings, array $confirmedEntryIds, array $sourcesById): bool
    {
        $gapMs = $entry->tsMs - $lastKept->tsMs;
        $fromAnotherDevice = $entry->dispositivoId !== $lastKept->dispositivoId;
        $sourceName = isset($sourcesById[$entry->fonteId]) ? $sourcesById[$entry->fonteId]->nome : 'A contagem';

        // A reviewer who pressed "keep both" on this entry overrules the
        // automatic void, however close the two entries are, and has
        // already answered the question an open flag would ask.
        if (isset($confirmedEntryIds[$entry->id])) {
            return true;
        }

        $isBeforeTheStart = $entry->tsMs < $this->flagsFromMs;

        if ($fromAnotherDevice || $gapMs <= $settings->duplicataMs) {
            $this->autoVoidedEntryIds[] = $entry->id;
            if ($isBeforeTheStart) {
                return false;
            }
            $this->autoVoidedCountBySource[$entry->fonteId] = ($this->autoVoidedCountBySource[$entry->fonteId] ?? 0) + 1;

            $flag = new Flag(Flag::REPETIDA, [$entry->carro], [$lastKept->id, $entry->id], $lastKept->id . '-' . $entry->id);
            $flag->fonteId = $entry->fonteId;
            $flag->tsMs = $entry->tsMs;
            $flag->autoResolvido = true;
            $flag->motivo = $fromAnotherDevice
                ? sprintf('%s registrou o carro %d em dois aparelhos (troca de contador); a segunda passagem foi ignorada.', $sourceName, $entry->carro)
                : sprintf('%s registrou o carro %d duas vezes em %d s (toque duplo); a segunda passagem foi ignorada.', $sourceName, $entry->carro, (int) round($gapMs / 1000));
            $this->flags[] = $flag;
            return false;
        }

        // Odd enough to show to a reviewer. It stays in the count meanwhile.
        if ($isBeforeTheStart) {
            return true;
        }
        $flag = new Flag(Flag::REPETIDA, [$entry->carro], [$entry->id, $lastKept->id], $lastKept->id . '-' . $entry->id);
        $flag->fonteId = $entry->fonteId;
        $flag->tsMs = $entry->tsMs;
        $flag->sugestao = ['tipo' => Decision::ANULAR, 'passagem_id' => $entry->id];
        $flag->motivo = sprintf(
            '%s registrou o carro %d duas vezes com %d s de intervalo, tempo curto demais para uma volta.',
            $sourceName,
            $entry->carro,
            (int) round($gapMs / 1000)
        );
        $this->flags[] = $flag;
        $this->openRepeatEntryIds[$entry->id] = true;
        $this->openRepeatCars[$entry->carro] = true;
        return true;
    }
}
