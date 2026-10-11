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

    /**
     * @param Entry[] $entries entries after decisions
     * @param array<int, true> $confirmedEntryIds entries a reviewer said to keep
     * @param array<int, Source> $sourcesById
     */
    public static function filter(array $entries, Settings $settings, array $confirmedEntryIds, array $sourcesById): self
    {
        $result = new self();

        $bySourceAndCar = [];
        foreach ($entries as $entry) {
            if ($entry->fonteId === Source::REVISOR_ID) {
                // A pass added by a reviewer is deliberate; never second-guess it.
                $result->keptEntries[] = $entry;
                continue;
            }
            $bySourceAndCar[$entry->fonteId . ':' . $entry->carro][] = $entry;
        }

        foreach ($bySourceAndCar as $sameSourceSameCar) {
            if (count($sameSourceSameCar) > 1) {
                usort($sameSourceSameCar, fn(Entry $a, Entry $b) => ($a->tsMs <=> $b->tsMs) ?: ($a->id <=> $b->id));
            }
            $result->filterOneSequence($sameSourceSameCar, $settings, $confirmedEntryIds, $sourcesById);
        }

        return $result;
    }

    /**
     * @param Entry[] $sequence one source's entries for one car, in time order
     * @param array<int, true> $confirmedEntryIds
     * @param array<int, Source> $sourcesById
     */
    private function filterOneSequence(array $sequence, Settings $settings, array $confirmedEntryIds, array $sourcesById): void
    {
        $lastKept = null;
        foreach ($sequence as $entry) {
            if ($lastKept === null || $entry->tsMs - $lastKept->tsMs >= $settings->janelaMs) {
                $this->keptEntries[] = $entry;
                $lastKept = $entry;
                continue;
            }

            $gapMs = $entry->tsMs - $lastKept->tsMs;
            $fromAnotherDevice = $entry->dispositivoId !== $lastKept->dispositivoId;
            $sourceName = isset($sourcesById[$entry->fonteId]) ? $sourcesById[$entry->fonteId]->nome : 'A contagem';

            if ($fromAnotherDevice || $gapMs <= $settings->duplicataMs) {
                $this->autoVoidedEntryIds[] = $entry->id;
                $this->autoVoidedCountBySource[$entry->fonteId] = ($this->autoVoidedCountBySource[$entry->fonteId] ?? 0) + 1;

                $flag = new Flag(Flag::REPETIDA, [$entry->carro], [$lastKept->id, $entry->id], $lastKept->id . '-' . $entry->id);
                $flag->fonteId = $entry->fonteId;
                $flag->tsMs = $entry->tsMs;
                $flag->autoResolvido = true;
                $flag->motivo = $fromAnotherDevice
                    ? sprintf('%s registrou o carro %d em dois aparelhos (troca de contador); a segunda passagem foi ignorada.', $sourceName, $entry->carro)
                    : sprintf('%s registrou o carro %d duas vezes em %d s (toque duplo); a segunda passagem foi ignorada.', $sourceName, $entry->carro, (int) round($gapMs / 1000));
                $this->flags[] = $flag;
                continue;
            }

            // Kept: either a reviewer already said "keep both", or it stays
            // in the count until a reviewer decides.
            $this->keptEntries[] = $entry;
            if (!isset($confirmedEntryIds[$entry->id])) {
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
            }
            $lastKept = $entry;
        }
    }
}
