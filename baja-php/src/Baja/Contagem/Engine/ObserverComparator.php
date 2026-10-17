<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\ObserverStats;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * Measures an observer source against the official count, after counting.
 *
 * An observer (Source::PAPEL_OBSERVADORA, typically a camera reading car
 * numbers) records passes like any counter, but none of its entries takes
 * part in the count (DecisionApplier sets them aside). Here each of them is
 * compared with the official passes, which it had no say in:
 *
 *   acerto            paired with an official pass of the same car within
 *                     the matching window. Pairs are made closest first, so
 *                     two quick entries cannot both claim one pass.
 *   leitura_trocada   no pass of its car, but an official pass of ANOTHER
 *                     car within MISREAD_WINDOW_MS that the observer has no
 *                     entry for: it read that car's number wrong.
 *   falso_positivo    neither: it saw a car that did not pass.
 *   falta             an official pass, at a moment the observer was
 *                     observing and had synced past, with no entry of it.
 *
 * Nothing here changes a count, a time or a flag. With no observer source it
 * is not even run.
 */
final class ObserverComparator
{
    /** How close another car's pass must be for an unpaired entry to be called a misread. */
    public const MISREAD_WINDOW_MS = 2000;

    public const ACERTO = 'acerto';
    public const LEITURA_TROCADA = 'leitura_trocada';
    public const FALSO_POSITIVO = 'falso_positivo';
    public const FALTA = 'falta';

    /** @var array<int, ObserverStats> fonteId => its comparison */
    public array $statsBySource = [];

    /**
     * One row per observer entry and per missed pass, for the CSV export.
     *
     * @var array<int, array{fonte_id: int, resultado: string, passagem_id: ?int, carro_lido: ?int, ts_lido_ms: ?int, carro_oficial: ?int, ts_oficial_ms: ?int, desvio_ms: ?int}>
     */
    public array $rows = [];

    /**
     * True when at least one source is an observer, or was one before being
     * promoted: only then is there anything to compare.
     *
     * @param array<int, Source> $sourcesById
     */
    public static function hasObservers(array $sourcesById): bool
    {
        foreach ($sourcesById as $source) {
            if ($source->hasObserved()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, Source> $sourcesById
     * @param PassGroup[] $groupsInTimeOrder every pass group of the race
     */
    public static function compare(
        array $sourcesById,
        array $groupsInTimeOrder,
        AppliedDecisions $applied,
        SourceAvailability $availability,
        RaceTimeline $timeline,
        int $janelaMs
    ): self {
        $comparator = new self();

        // The official passes, in time order.
        $passes = [];
        foreach ($groupsInTimeOrder as $group) {
            if ($group->contado) {
                $passes[] = $group;
            }
        }
        usort($passes, fn(PassGroup $a, PassGroup $b) => $a->tsMs <=> $b->tsMs);
        $passTimes = array_map(fn(PassGroup $group) => $group->tsMs, $passes);
        $passIndexesByCar = [];
        foreach ($passes as $index => $group) {
            $passIndexesByCar[$group->carro][] = $index;
        }

        $entriesBySource = [];
        foreach ($applied->observedEntries as $entry) {
            // Warm-up before the start, and times nobody knows, say nothing.
            if ($entry->qualidadeTempo === Entry::TEMPO_DESCONHECIDO || $timeline->largadaMs === null || $entry->tsMs < $timeline->largadaMs) {
                continue;
            }
            $entriesBySource[$entry->fonteId][] = $entry;
        }

        foreach ($sourcesById as $source) {
            if (!$source->hasObserved()) {
                continue;
            }
            $comparator->compareOne($source, $entriesBySource[$source->id] ?? [], $passes, $passTimes, $passIndexesByCar, $applied, $availability, $janelaMs);
        }
        return $comparator;
    }

    /**
     * @param Entry[] $entries
     * @param PassGroup[] $passes
     * @param int[] $passTimes
     * @param array<int, int[]> $passIndexesByCar
     */
    private function compareOne(
        Source $source,
        array $entries,
        array $passes,
        array $passTimes,
        array $passIndexesByCar,
        AppliedDecisions $applied,
        SourceAvailability $availability,
        int $janelaMs
    ): void {
        $stats = new ObserverStats($source->id);
        $stats->entradas = count($entries);

        // 1. Same car, within the window, closest first.
        $candidates = [];
        foreach ($entries as $entryIndex => $entry) {
            foreach ($passIndexesByCar[$entry->carro] ?? [] as $passIndex) {
                $distanceMs = abs($passes[$passIndex]->tsMs - $entry->tsMs);
                if ($distanceMs <= $janelaMs) {
                    $candidates[] = [$distanceMs, $entryIndex, $passIndex];
                }
            }
        }
        [$passOfEntry, $entryOfPass] = self::pairClosestFirst($candidates);

        // 2. What is left: another car's unrecorded pass close by is a misread.
        $candidates = [];
        foreach ($entries as $entryIndex => $entry) {
            if (isset($passOfEntry[$entryIndex])) {
                continue;
            }
            $first = Stats::countAtMost($passTimes, $entry->tsMs - self::MISREAD_WINDOW_MS - 1);
            for ($passIndex = $first; $passIndex < count($passTimes) && $passTimes[$passIndex] <= $entry->tsMs + self::MISREAD_WINDOW_MS; $passIndex++) {
                if (!isset($entryOfPass[$passIndex]) && $passes[$passIndex]->carro !== $entry->carro) {
                    $candidates[] = [abs($passTimes[$passIndex] - $entry->tsMs), $entryIndex, $passIndex];
                }
            }
        }
        [$misreadPassOfEntry, $entryOfMisreadPass] = self::pairClosestFirst($candidates);

        $offsets = [];
        $misreadCounts = [];
        foreach ($entries as $entryIndex => $entry) {
            if (isset($passOfEntry[$entryIndex])) {
                $pass = $passes[$passOfEntry[$entryIndex]];
                $offsets[] = $entry->tsMs - $pass->tsMs;
                $stats->acertos++;
                $this->addRow($source->id, self::ACERTO, $entry, $pass);
            } elseif (isset($misreadPassOfEntry[$entryIndex])) {
                $pass = $passes[$misreadPassOfEntry[$entryIndex]];
                $stats->leiturasTrocadas++;
                $key = $entry->carro . '>' . $pass->carro;
                $misreadCounts[$key] = ($misreadCounts[$key] ?? 0) + 1;
                $this->addRow($source->id, self::LEITURA_TROCADA, $entry, $pass);
            } else {
                $stats->falsosPositivos++;
                $this->addRow($source->id, self::FALSO_POSITIVO, $entry, null);
            }
        }

        // 3. Official passes it did not record, where its silence means something.
        foreach ($passes as $passIndex => $pass) {
            if (isset($entryOfPass[$passIndex]) || isset($entryOfMisreadPass[$passIndex])) {
                continue;
            }
            if (!$source->wasObservingAt($pass->tsMs) || $applied->isSourceExcludedAt($source->id, $pass->tsMs) || !$availability->isKnownAt($source, $pass->tsMs)) {
                continue;
            }
            $stats->faltas++;
            $this->addRow($source->id, self::FALTA, null, $pass);
        }

        if ($offsets !== []) {
            $stats->desvioMedianoMs = (int) round(Stats::median($offsets));
            $absolute = array_map('abs', $offsets);
            sort($absolute);
            $stats->desvioP90Ms = $absolute[(int) ceil(0.9 * count($absolute)) - 1];
        }
        arsort($misreadCounts);
        foreach (array_slice($misreadCounts, 0, ObserverStats::TOP_MISREADS, true) as $key => $times) {
            [$read, $real] = array_map('intval', explode('>', (string) $key));
            $stats->trocasFrequentes[] = ['carro_lido' => $read, 'carro_real' => $real, 'vezes' => $times];
        }

        $this->statsBySource[$source->id] = $stats;
    }

    /**
     * Greedy matching: the closest pair first, then the closest of what is
     * left, each entry and each pass used once.
     *
     * @param array<int, array{0: int, 1: int, 2: int}> $candidates [distance, entry index, pass index]
     * @return array{0: array<int, int>, 1: array<int, int>} entry index => pass index, and the reverse
     */
    private static function pairClosestFirst(array $candidates): array
    {
        sort($candidates);
        $passOfEntry = [];
        $entryOfPass = [];
        foreach ($candidates as [, $entryIndex, $passIndex]) {
            if (isset($passOfEntry[$entryIndex]) || isset($entryOfPass[$passIndex])) {
                continue;
            }
            $passOfEntry[$entryIndex] = $passIndex;
            $entryOfPass[$passIndex] = $entryIndex;
        }
        return [$passOfEntry, $entryOfPass];
    }

    private function addRow(int $fonteId, string $resultado, ?Entry $entry, ?PassGroup $pass): void
    {
        $this->rows[] = [
            'fonte_id' => $fonteId,
            'resultado' => $resultado,
            'passagem_id' => $entry === null ? null : $entry->id,
            'carro_lido' => $entry === null ? null : $entry->carro,
            'ts_lido_ms' => $entry === null ? null : $entry->tsMs,
            'carro_oficial' => $pass === null ? null : $pass->carro,
            'ts_oficial_ms' => $pass === null ? null : $pass->tsMs,
            'desvio_ms' => $entry !== null && $pass !== null ? $entry->tsMs - $pass->tsMs : null,
        ];
    }
}
