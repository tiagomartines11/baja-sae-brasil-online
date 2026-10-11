<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * Step 3: decide which entries from different sources describe the same
 * crossing of the line.
 *
 * Matching is done per car and by time only. It deliberately ignores the
 * order in which each counter typed: when a pack of five cars crosses
 * together, three people type the five numbers in three different orders.
 */
final class PassMatcher
{
    /**
     * @param Entry[] $entriesInTimeOrder already sorted by time (LapCounter does it once for every step)
     * @param array<int, true> $confirmedEntryIds
     * @return array<int, PassGroup[]> carro => its pass groups in time order
     */
    public static function match(array $entriesInTimeOrder, int $janelaMs, array $confirmedEntryIds): array
    {
        $entriesByCar = [];
        foreach ($entriesInTimeOrder as $entry) {
            $entriesByCar[$entry->carro][] = $entry;
        }

        $groupsByCar = [];
        foreach ($entriesByCar as $carro => $carEntries) {
            $groupsByCar[$carro] = self::matchOneCar($carEntries, $janelaMs, $confirmedEntryIds);
        }

        return $groupsByCar;
    }

    /**
     * @param Entry[] $carEntries one car's entries in time order
     * @param array<int, true> $confirmedEntryIds
     * @return PassGroup[]
     */
    private static function matchOneCar(array $carEntries, int $janelaMs, array $confirmedEntryIds): array
    {
        $groups = [];
        // Groups whose window has not closed yet. Normally there is one. A
        // second appears when a source has the car twice inside the window
        // (an unresolved "repetida"); later entries from the other sources
        // must then join the EARLIEST group, or the two halves would each
        // gather two sources and count as two laps.
        $openGroups = [];

        foreach ($carEntries as $entry) {
            $fonteId = $entry->fonteId;
            $joinedGroup = null;
            foreach ($openGroups as $index => $openGroup) {
                if ($entry->tsMs - $openGroup->primeiroTsMs > $janelaMs) {
                    unset($openGroups[$index]);
                    continue;
                }
                // (PassGroup::hasSource(), written out: this runs once per entry of the race.)
                $alreadyHasTheSource = false;
                foreach ($openGroup->entries as $other) {
                    if ($other->fonteId === $fonteId) {
                        $alreadyHasTheSource = true;
                        break;
                    }
                }
                if (!$alreadyHasTheSource) {
                    $joinedGroup = $openGroup;
                    break;
                }
            }

            if ($joinedGroup !== null) {
                $joinedGroup->entries[] = $entry;
            } else {
                $joinedGroup = new PassGroup($entry);
                $groups[] = $joinedGroup;
                $openGroups[] = $joinedGroup;
            }

            if ($fonteId === Source::REVISOR_ID) {
                $joinedGroup->adicionadoPorRevisor = true;
            }
            if (isset($confirmedEntryIds[$entry->id])) {
                $joinedGroup->confirmadoPorRevisor = true;
            }
            if ($entry->qualidadeTempo !== Entry::TEMPO_EXATO) {
                $joinedGroup->tempoEstimado = true;
            }
        }

        return $groups;
    }
}
