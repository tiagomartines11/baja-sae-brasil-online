<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\Source;

/**
 * The flags about unread passes, in the full run: what UnreadAssigner could
 * not settle ("numero_ilegivel"), and, for the record, what it settled by
 * itself ("numero_atribuido", auto-resolved).
 *
 * A "numero_ilegivel" lists the cars the pass may have been, most likely
 * first:
 *
 *   outros_contadores  another source read that car within the tolerance
 *                      (the candidates UnreadAssigner found), closest first;
 *   carro_esperado     the car was due at the line then (its last official
 *                      pass plus its expected lap, PaceModel), and has had
 *                      no official pass since;
 *   volta_faltando     the car's official passes around then are one short:
 *                      the gap between them is about two laps, and the
 *                      entry about a lap from each.
 *
 * A counter's "??" is always flagged when nothing settled it: somebody saw a
 * car. A timing sensor's mark is not: a mark close to passes the counters
 * recorded is just one the matching could not pair safely (it times nothing
 * and changes no count), and a mark far from any is usually the beam
 * tripping on something else. It is flagged only when nobody recorded a
 * crossing there AND some car was due or is a pass short (both read
 * strictly, see dueCandidates()), before the finish: then it is probably a
 * crossing every counter missed. It never counts until a reviewer says
 * which car it was.
 *
 * Like every flag that depends on what a counter might still upload, these
 * wait until the other sources have synced past the entry.
 */
final class UnreadFlags
{
    /** At most this many candidates are listed. */
    public const MAX_CANDIDATOS = 6;

    private Settings $settings;
    private PaceModel $pace;
    private RaceTimeline $timeline;
    private SourceAvailability $availability;
    private PassFlags $passFlags;

    /** @var array<int, Source> */
    private array $sourcesById;

    /** @var int[] */
    private array $validCars;

    private int $nowMs;

    /** @var array<int, int[]> carro => times of its official passes (default rule), in order */
    private array $passTimesByCar = [];

    /** @var array<int, array<int, int[]>> fonteId => carro => times of that source's read entries, in order */
    private array $readTimes = [];

    /** @var array<int, true> ids of the entries in an official pass (default rule) */
    private array $officialEntryIds = [];

    /**
     * @param array<int, Source> $sourcesById
     * @param int[] $validCars
     */
    public function __construct(Settings $settings, PaceModel $pace, RaceTimeline $timeline, SourceAvailability $availability, array $sourcesById, array $validCars, int $nowMs)
    {
        $this->nowMs = $nowMs;
        $this->settings = $settings;
        $this->pace = $pace;
        $this->timeline = $timeline;
        $this->availability = $availability;
        $this->sourcesById = $sourcesById;
        $this->validCars = $validCars;
        $this->passFlags = new PassFlags($sourcesById);
    }

    /**
     * @param array<int, Lap[]> $referenceLapsByCar laps under the default counting rule
     * @param Entry[] $entries the entries that take part (AppliedDecisions::$entries)
     * @param array<int, true> $confirmedEntryIds
     * @return Flag[]
     */
    public function flags(UnreadAssigner $unread, array $referenceLapsByCar, array $entries, array $confirmedEntryIds): array
    {
        $flags = [];
        $startMs = $this->timeline->largadaMs;
        if ($startMs === null) {
            return [];
        }
        foreach ($unread->assignments as $entryId => $assignment) {
            $source = $this->sourcesById[$unread->sourceOfEntry($entryId) ?? -1] ?? null;
            if ($source === null || $source->isTimingSensor()) {
                // A sensor's every mark would be one: its matched marks show
                // on the passes they time instead.
                continue;
            }
            $flags[] = $this->assignedFlag($entryId, $source, $assignment);
        }

        $toFlag = [];
        foreach ($unread->leftUnread as $entry) {
            if ($entry->tsMs < $startMs || isset($confirmedEntryIds[$entry->id])) {
                continue;
            }
            if ($entry->qualidadeTempo !== Entry::TEMPO_DESCONHECIDO && $this->availability->hasUnknownSourceAt($entry->tsMs, [$entry->fonteId])) {
                // Another counter's entry for this crossing may still arrive.
                continue;
            }
            $toFlag[] = $entry;
        }
        if ($toFlag === []) {
            return $flags;
        }

        foreach ($referenceLapsByCar as $carro => $laps) {
            foreach ($laps as $lap) {
                if ($lap->isOfficialPass()) {
                    $this->passTimesByCar[$carro][] = $lap->tsMs;
                    foreach ($lap->grupo?->entryIds() ?? [] as $entryId) {
                        $this->officialEntryIds[$entryId] = true;
                    }
                }
            }
        }
        foreach ($entries as $entry) {
            if ($entry->carro !== null && $entry->fonteId !== Source::REVISOR_ID) {
                $this->readTimes[$entry->fonteId][$entry->carro][] = $entry->tsMs;
            }
        }
        foreach ($this->readTimes as $fonteId => $byCar) {
            foreach ($byCar as $carro => $times) {
                sort($times);
                $this->readTimes[$fonteId][$carro] = $times;
            }
        }

        foreach ($toFlag as $entry) {
            $source = $this->sourcesById[$entry->fonteId] ?? null;
            $isSensor = $source !== null && $source->isTimingSensor();
            if ($entry->qualidadeTempo === Entry::TEMPO_DESCONHECIDO) {
                if (!$isSensor) {
                    $flags[] = $this->illegibleFlag($entry, [], false, true);
                }
                continue;
            }
            $read = $this->readCandidates($unread->candidates[$entry->id] ?? []);
            $finishMs = $this->timeline->encerramentoMs;
            if ($isSensor && ($read !== [] || ($finishMs !== null && $entry->tsMs > $finishMs))) {
                // Near passes the counters recorded, or after the finish
                // (cars on their way in): nothing anybody missed.
                continue;
            }
            $candidates = array_merge($read, $this->dueCandidates($entry, array_column($read, 'carro'), $isSensor));
            if ($isSensor && $candidates === []) {
                // Noise: counted in the sensor's statistics, not a flag.
                continue;
            }
            $flags[] = $this->illegibleFlag($entry, array_slice($candidates, 0, self::MAX_CANDIDATOS), $isSensor, false);
        }
        return $flags;
    }

    /**
     * @param array<int, array{carro: int, ts_ms: int, desvio_ms: int, passagem_ids: int[], fonte_ids: int[]}> $crossings closest first
     * @return array<int, array{carro: int, motivo: string, score: float, ts_ms: ?int}>
     */
    private function readCandidates(array $crossings): array
    {
        $toleranceMs = max(1, $this->settings->agreementSpreadMs());
        $candidates = [];
        $seen = [];
        foreach ($crossings as $crossing) {
            if (isset($seen[$crossing['carro']])) {
                continue;
            }
            $seen[$crossing['carro']] = true;
            $candidates[] = [
                'carro' => $crossing['carro'],
                'motivo' => 'outros_contadores',
                'score' => round(1 - 0.5 * min(1.0, abs($crossing['desvio_ms']) / $toleranceMs), 2),
                'ts_ms' => $crossing['ts_ms'],
                'fonte_ids' => $crossing['fonte_ids'],
                'passagem_ids' => $crossing['passagem_ids'],
                'desvio_ms' => $crossing['desvio_ms'],
            ];
        }
        return $candidates;
    }

    /**
     * The cars due at the line around the entry, and the ones that would
     * otherwise be a pass short, by the pace. A car the entry's own source
     * read near that moment is not one (it cannot be this "??" too), nor a
     * car logged inside the pit lane or the fueling station.
     *
     * One pass short means the car's passes before and after are about two
     * laps apart AND the entry sits about a lap from each: a car that broke
     * down for five minutes has a long gap too, but no moment a lap from
     * both ends. For a sensor's mark ($strict) the reading is narrower: a
     * car somebody recorded within a matching window of the mark is not
     * one (that entry is its missing pass), the moments must agree within
     * the plain tolerance, and a car whose next pass has not come counts as
     * due only while that pass could not have come yet (the mark is less
     * than a lap old): a car that stopped an hour ago was "due" once, and a
     * false trigger then is no crossing of it.
     *
     * @param int[] $alreadyListed
     * @return array<int, array{carro: int, motivo: string, score: float, ts_ms: ?int}>
     */
    private function dueCandidates(Entry $entry, array $alreadyListed, bool $strict): array
    {
        $tsMs = $entry->tsMs;
        $janelaMs = $this->settings->janelaMs;
        $skip = array_fill_keys($alreadyListed, true);
        $due = [];
        $missing = [];
        $cars = $this->validCars !== [] ? $this->validCars : array_keys($this->passTimesByCar);
        foreach ($cars as $carro) {
            if (isset($skip[$carro]) || $this->sourceReadNear($entry->fonteId, $carro, $tsMs, $janelaMs)) {
                continue;
            }
            $times = $this->passTimesByCar[$carro] ?? [];
            $index = Stats::countAtMost($times, $tsMs);
            if ($index === 0) {
                continue;
            }
            $previousMs = $times[$index - 1];
            $nextMs = $times[$index] ?? null;
            $expectedMs = $this->pace->expectedLapMs($carro, $previousMs);
            if ($expectedMs === null || $expectedMs <= 0) {
                continue;
            }
            $halfLapMs = intdiv($expectedMs, 2);
            if ($tsMs - $previousMs < $halfLapMs || ($nextMs !== null && $nextMs - $tsMs < $halfLapMs)) {
                // It passed (or passes) the line around then: not missing.
                continue;
            }
            if ($this->timeline->isInPitAt($carro, $tsMs) || $this->timeline->isFuelingAt($carro, $tsMs)) {
                continue;
            }
            if ($strict && $this->anySourceReadNear($carro, $tsMs, $janelaMs)) {
                // Somebody did record the car around then (a lone entry,
                // typed late): that, not the mark, is its missing pass.
                continue;
            }
            $toleranceMs = max($janelaMs, intdiv($expectedMs, 10));
            $fromDueMs = abs($previousMs + $expectedMs - $tsMs);
            if ($nextMs === null) {
                // Its next pass has not come (yet): was it due right then?
                if ($fromDueMs <= $toleranceMs && (!$strict || $this->nowMs - $tsMs < $expectedMs)) {
                    $due[] = [$fromDueMs / $toleranceMs, $carro, 'carro_esperado'];
                }
                continue;
            }
            // It came round later: is one pass missing in between?
            $missingMs = max($fromDueMs, abs($nextMs - $expectedMs - $tsMs));
            $missingToleranceMs = $strict ? $toleranceMs : 1.5 * $toleranceMs;
            if ($missingMs <= $missingToleranceMs) {
                $missing[] = [$missingMs / $missingToleranceMs, $carro, 'volta_faltando'];
            }
        }
        sort($due);
        sort($missing);
        $candidates = [];
        foreach ([[$due, 0.5], [$missing, 0.4]] as [$list, $top]) {
            foreach ($list as [$distance, $carro, $motivo]) {
                $candidates[] = ['carro' => $carro, 'motivo' => $motivo, 'score' => round($top * (1 - 0.5 * $distance), 2), 'ts_ms' => null];
            }
        }
        return $candidates;
    }

    private function anySourceReadNear(int $carro, int $tsMs, int $withinMs): bool
    {
        foreach (array_keys($this->readTimes) as $fonteId) {
            if ($this->sourceReadNear($fonteId, $carro, $tsMs, $withinMs)) {
                return true;
            }
        }
        return false;
    }

    private function sourceReadNear(int $fonteId, int $carro, int $tsMs, int $withinMs): bool
    {
        $times = $this->readTimes[$fonteId][$carro] ?? [];
        if ($times === []) {
            return false;
        }
        $index = Stats::countAtMost($times, $tsMs - $withinMs - 1);
        return $index < count($times) && $times[$index] <= $tsMs + $withinMs;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private function illegibleFlag(Entry $entry, array $candidates, bool $isSensor, bool $timeUnknown): Flag
    {
        $cars = array_values(array_unique(array_column($candidates, 'carro')));
        $flag = new Flag(Flag::NUMERO_ILEGIVEL, array_slice($cars, 0, 3), [$entry->id], (string) $entry->id);
        $flag->fonteId = $entry->fonteId;
        $flag->tsMs = $entry->tsMs;
        $flag->candidatos = array_map(fn(array $candidate): array => [
            'carro' => $candidate['carro'],
            'motivo' => $candidate['motivo'],
            'score' => $candidate['score'],
            'ts_ms' => $candidate['ts_ms'],
        ], $candidates);
        $name = $this->passFlags->sourceName($entry->fonteId);

        if ($timeUnknown) {
            $flag->motivo = sprintf('%s registrou uma passagem sem número ("??") sem horário confiável; não há como saber que carro foi. Anule o registro ou, se souber o carro, atribua-o.', $name);
            return $flag;
        }
        $top = $candidates[0] ?? null;
        // A suggests the closest car only when the "??" would join a pass
        // that already counts: then no count changes. A lone reading may be
        // a wrong number (a counter typed 9 as 6 crossed, another read 6 a
        // few tenths further away): with the "??" it would count, and a "??"
        // never decides a count without a person choosing that car.
        if (!$isSensor && $top !== null && $top['motivo'] === 'outros_contadores' && $this->joinsAnOfficialPass($top)) {
            $flag->sugestao = ['tipo' => Decision::REATRIBUIR, 'passagem_id' => $entry->id, 'equipe_numero' => $top['carro']];
        }
        // The car may be a lap short when the likeliest answer is a pass
        // nobody else made count: a lone reading, or a car that was due.
        if ($top !== null && ($top['motivo'] !== 'outros_contadores' || count($top['fonte_ids'] ?? []) < 2)) {
            $flag->contagemEmDuvida = Flag::DUVIDA_A_MENOS;
        }

        $options = implode('; ', array_map(fn(array $candidate): string => $this->describeCandidate($candidate), array_slice($candidates, 0, 3)));
        if ($isSensor) {
            $flag->motivo = sprintf('%s marcou uma passagem que nenhum contador registrou. Pode ser uma passagem que todos perderam: %s. Atribua o carro (a passagem passa a contar) ou anule a marca.', $name, $options);
        } elseif ($candidates === [] && ($own = $this->ownTypingOfACarReadThere($entry)) !== null) {
            // The others did read a car there, but this source typed it too.
            $flag->motivo = sprintf(
                '%s registrou uma passagem sem número ("??") e também digitou o carro %d %s (%s s): provavelmente um toque a mais. Anule o registro ou, se souber o carro, atribua-o.',
                $name,
                $own['carro'],
                $own['desvio_ms'] >= 0 ? 'logo depois' : 'logo antes',
                self::seconds(abs($own['desvio_ms']))
            );
        } elseif ($candidates === []) {
            $flag->motivo = sprintf('%s registrou uma passagem sem número ("??") e nenhuma outra fonte registrou carro nesse momento, nem havia carro esperado na linha. Atribua o carro, se souber qual foi, ou anule o registro.', $name);
        } else {
            $flag->motivo = sprintf('%s registrou uma passagem sem número ("??"). Pode ser: %s. Escolha o carro ou anule o registro.', $name, $options);
        }
        return $flag;
    }

    /**
     * The car the entry's own source typed closest to it, within a matching
     * window, that another source read within the tolerance of the entry:
     * a crossing that is no candidate only because this source typed it.
     *
     * @return array{carro: int, desvio_ms: int}|null desvio_ms: the typing minus the entry
     */
    private function ownTypingOfACarReadThere(Entry $entry): ?array
    {
        $toleranceMs = max(1, $this->settings->agreementSpreadMs());
        $best = null;
        foreach ($this->readTimes[$entry->fonteId] ?? [] as $carro => $times) {
            $readByAnother = false;
            foreach (array_keys($this->readTimes) as $fonteId) {
                if ($fonteId !== $entry->fonteId && $this->sourceReadNear($fonteId, (int) $carro, $entry->tsMs, $toleranceMs)) {
                    $readByAnother = true;
                    break;
                }
            }
            if (!$readByAnother) {
                continue;
            }
            foreach ($times as $tsMs) {
                $offsetMs = $tsMs - $entry->tsMs;
                if (abs($offsetMs) <= $this->settings->janelaMs && ($best === null || abs($offsetMs) < abs($best['desvio_ms']))) {
                    $best = ['carro' => (int) $carro, 'desvio_ms' => $offsetMs];
                }
            }
        }
        return $best;
    }

    /** @param array<string, mixed> $candidate */
    private function joinsAnOfficialPass(array $candidate): bool
    {
        foreach ($candidate['passagem_ids'] ?? [] as $entryId) {
            if (isset($this->officialEntryIds[$entryId])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $candidate */
    private function describeCandidate(array $candidate): string
    {
        if ($candidate['motivo'] === 'outros_contadores') {
            $names = array_map(fn(int $fonteId): string => $this->passFlags->sourceName($fonteId), $candidate['fonte_ids']);
            return sprintf('o carro %d (registrado por %s, a %s s)', $candidate['carro'], self::sentenceList($names), self::seconds(abs((int) $candidate['desvio_ms'])));
        }
        if ($candidate['motivo'] === 'carro_esperado') {
            return sprintf('o carro %d (era esperado na linha)', $candidate['carro']);
        }
        return sprintf('o carro %d (ficaria com uma volta a menos)', $candidate['carro']);
    }

    /**
     * @param array{carro: int, ts_ms: int, desvio_ms: int, margem_ms: ?int, passagem_ids: int[], fonte_ids: int[]} $assignment
     */
    private function assignedFlag(int $entryId, Source $source, array $assignment): Flag
    {
        $flag = new Flag(Flag::NUMERO_ATRIBUIDO, [$assignment['carro']], array_merge([$entryId], $assignment['passagem_ids']), (string) $entryId);
        $flag->fonteId = $source->id;
        $flag->tsMs = $assignment['ts_ms'] + $assignment['desvio_ms'];
        $flag->autoResolvido = true;
        $flag->atribuicao = $assignment;
        $names = array_map(fn(int $fonteId): string => $this->passFlags->sourceName($fonteId), $assignment['fonte_ids']);
        $flag->motivo = sprintf(
            '%s registrou uma passagem sem número ("??"); foi atribuída ao carro %d, que %s %s a %s s, sem outro carro possível por perto.',
            $source->nome,
            $assignment['carro'],
            self::sentenceList($names),
            count($names) > 1 ? 'registraram' : 'registrou',
            self::seconds(abs($assignment['desvio_ms']))
        );
        return $flag;
    }

    /** @param string[] $names */
    private static function sentenceList(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? 'outra contagem';
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' e ' . $last;
    }

    /** "0,4": tenths of a second, written the Brazilian way. */
    private static function seconds(int $ms): string
    {
        return str_replace('.', ',', sprintf('%.1f', $ms / 1000));
    }
}
