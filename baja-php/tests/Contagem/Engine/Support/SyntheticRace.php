<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A made-up race, generated from a seed.
 *
 * First it invents what REALLY happened (the "ground truth"): every car,
 * every crossing of the line, with a field that gets slower as the track
 * deteriorates, breakdowns and pit stops. Then it invents what each human
 * counter typed while watching that race, including their mistakes.
 *
 * Because the truth is known, a test can check the engine's answer against
 * it. No real race data is used anywhere; this repository is public.
 *
 * One rule keeps the test honest: two mistakes never happen within a minute
 * of each other. When two of three counters get the same moment wrong, no
 * algorithm can know which one to believe, and the system rightly leaves
 * that to a person. The generator therefore only produces situations the
 * engine is supposed to solve on its own or with its own suggestion.
 */
final class SyntheticRace
{
    public const START_MS = RaceScenario::CLOCK_ZERO_MS;

    /** Mistakes are kept at least this far apart, in ms (three matching windows). */
    private const MIN_GAP_BETWEEN_ERRORS_MS = 60000;

    /** @var array<int, int> car => laps it really completed */
    public array $truthLapsByCar = [];

    /** @var array<int, array{tipo: string, source: int, car: int, tsMs: int}> the mistakes that were injected */
    public array $injectedErrors = [];

    public int $endMs;

    /** @var int[] */
    private array $carNumbers = [];
    /** @var array<int, array{car: int, tsMs: int}> every real pass, in time order */
    private array $passes = [];
    /** @var Entry[] */
    private array $entries = [];
    /** @var Ocorrencia[] */
    private array $ocorrencias = [];

    private SyntheticRaceConfig $config;
    private Randomizer $random;
    private int $nextEntryId = 1;
    private int $nextOcorrenciaId = 1;

    public static function generate(SyntheticRaceConfig $config): self
    {
        $race = new self($config);
        $race->ocorrencias[] = new Ocorrencia($race->nextOcorrenciaId++, Ocorrencia::LARGADA, self::START_MS);
        $race->inventWhatReallyHappened();
        $race->inventWhatTheCountersTyped();
        return $race;
    }

    private function __construct(SyntheticRaceConfig $config)
    {
        $this->config = $config;
        $this->random = new Randomizer(new Mt19937($config->seed));
        $this->endMs = self::START_MS + $config->raceMinutes * 60000;
        $this->carNumbers = range(1, $config->cars);
    }

    /**
     * What the server knows at a given moment of the race: only the entries
     * typed so far, minus those still sitting in a phone that is offline.
     * With no argument: after the race, everything uploaded.
     */
    public function input(?int $nowMs = null): EngineInput
    {
        $nowMs = $nowMs ?? $this->endMs + 60000;

        $input = new EngineInput();
        $input->nowMs = $nowMs;
        $input->validCars = $this->carNumbers;
        $input->ocorrencias = array_values(array_filter($this->ocorrencias, fn(Ocorrencia $o) => $o->tsMs <= $nowMs));

        for ($sourceId = 1; $sourceId <= $this->config->counters; $sourceId++) {
            $heldBackFromMs = $this->offlineSinceMs($sourceId, $nowMs);
            $syncedThroughMs = $heldBackFromMs ?? $nowMs;
            $input->sources[] = new Source($sourceId, 'Contagem ' . $sourceId, Source::TIPO_MANUAL, Source::TEMPO_HUMANA, true, $sourceId, $syncedThroughMs, $syncedThroughMs);
        }

        foreach ($this->entries as $entry) {
            if ($entry->tsMs > $nowMs) {
                continue;
            }
            $heldBackFromMs = $this->offlineSinceMs($entry->fonteId, $nowMs);
            if ($heldBackFromMs !== null && $entry->tsMs >= $heldBackFromMs) {
                continue;
            }
            $input->entries[] = $entry;
        }

        return $input;
    }

    /** If the source is offline at $nowMs, the moment it lost connection; otherwise null. */
    private function offlineSinceMs(int $sourceId, int $nowMs): ?int
    {
        foreach ($this->config->offlinePeriods as $period) {
            $fromMs = self::START_MS + $period['fromSeconds'] * 1000;
            $toMs = self::START_MS + $period['toSeconds'] * 1000;
            if ($period['source'] === $sourceId && $nowMs >= $fromMs && $nowMs < $toMs) {
                return $fromMs;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------
    // Part 1: the truth
    // ---------------------------------------------------------------

    private function inventWhatReallyHappened(): void
    {
        $raceLengthMs = $this->endMs - self::START_MS;

        foreach ($this->carNumbers as $car) {
            // Some cars are simply faster than others, for the whole race.
            $carFactor = $this->random->getFloat(0.85, 1.3);
            // The counting line is somewhere along the lap, so the first
            // crossing comes after only part of a lap.
            $passMs = self::START_MS + (int) ($this->random->getFloat(0.3, 1.0) * $this->config->baseLapSeconds * 1000 * $carFactor);
            $this->truthLapsByCar[$car] = 0;

            while ($passMs <= $this->endMs) {
                $this->passes[] = ['car' => $car, 'tsMs' => $passMs];
                $this->truthLapsByCar[$car]++;

                // The track gets slower through the race, for everybody.
                $trackFactor = 1.0 + 0.25 * (($passMs - self::START_MS) / $raceLengthMs);
                $lapMs = (int) ($this->config->baseLapSeconds * 1000 * $carFactor * $trackFactor * $this->random->getFloat(0.94, 1.06));

                if ($this->happens($this->config->breakdownRate)) {
                    $lapMs += $this->random->getInt(180, 600) * 1000;
                } elseif ($this->happens($this->config->pitStopRate)) {
                    $stayMs = $this->random->getInt(120, 300) * 1000;
                    $pitInMs = $passMs + $this->random->getInt(20, 40) * 1000;
                    $this->ocorrencias[] = new Ocorrencia($this->nextOcorrenciaId++, Ocorrencia::BOX_ENTRADA, $pitInMs, $car);
                    $this->ocorrencias[] = new Ocorrencia($this->nextOcorrenciaId++, Ocorrencia::BOX_SAIDA, $pitInMs + $stayMs, $car);
                    $lapMs += $stayMs;
                }

                $passMs += $lapMs;
            }
        }

        usort($this->passes, fn(array $a, array $b) => $a['tsMs'] <=> $b['tsMs']);
    }

    // ---------------------------------------------------------------
    // Part 2: what the counters typed
    // ---------------------------------------------------------------

    private function inventWhatTheCountersTyped(): void
    {
        // Each counter has a habit: some press Enter a little early, some a
        // little late, consistently.
        $habitualOffsetMs = [];
        for ($sourceId = 1; $sourceId <= $this->config->counters; $sourceId++) {
            $habitualOffsetMs[$sourceId] = $this->random->getInt(-500, 1500);
        }

        $lastErrorMs = PHP_INT_MIN;
        foreach ($this->passes as $passIndex => $pass) {
            $error = null;
            if ($pass['tsMs'] - $lastErrorMs >= self::MIN_GAP_BETWEEN_ERRORS_MS) {
                $error = $this->maybePickError($passIndex);
            }
            if ($error !== null) {
                $lastErrorMs = $pass['tsMs'];
                $this->injectedErrors[] = $error + ['car' => $pass['car'], 'tsMs' => $pass['tsMs']];
            }

            for ($sourceId = 1; $sourceId <= $this->config->counters; $sourceId++) {
                $typedAtMs = $pass['tsMs'] + $habitualOffsetMs[$sourceId] + $this->random->getInt(-400, 400);
                $errorHere = ($error !== null && $error['source'] === $sourceId) ? $error : null;
                $this->typeOnePass($sourceId, $pass['car'], $typedAtMs, $errorHere);
            }
        }
    }

    /**
     * @param array{tipo: string, source: int, wrongCar?: int}|null $error the mistake this counter makes on this pass, if any
     */
    private function typeOnePass(int $sourceId, int $car, int $typedAtMs, ?array $error): void
    {
        $tipo = $error['tipo'] ?? null;

        if ($tipo === 'miss') {
            return;
        }
        if ($tipo === 'typo') {
            $this->addEntry($sourceId, $error['wrongCar'], $typedAtMs);
            return;
        }
        if ($tipo === 'late') {
            $this->addEntry($sourceId, $car, $typedAtMs + $this->random->getInt(6000, 12000));
            return;
        }

        $this->addEntry($sourceId, $car, $typedAtMs);
        if ($tipo === 'double_tap') {
            $this->addEntry($sourceId, $car, $typedAtMs + $this->random->getInt(300, 3000));
        }
        if ($tipo === 'repeat') {
            $this->addEntry($sourceId, $car, $typedAtMs + $this->random->getInt(8000, 15000));
        }
    }

    /**
     * @return array{tipo: string, source: int, wrongCar?: int}|null
     */
    private function maybePickError(int $passIndex): ?array
    {
        $rates = [
            'miss' => $this->config->missRate,
            'typo' => $this->config->typoRate,
            'double_tap' => $this->config->doubleTapRate,
            'repeat' => $this->config->repeatRate,
            'late' => $this->config->lateRate,
        ];
        $roll = $this->random->getFloat(0.0, 1.0);
        foreach ($rates as $tipo => $rate) {
            if ($roll < $rate) {
                $error = ['tipo' => $tipo, 'source' => $this->random->getInt(1, $this->config->counters)];
                if ($tipo === 'typo') {
                    $wrongCar = $this->pickWrongCar($passIndex);
                    if ($wrongCar === null) {
                        return null;
                    }
                    $error['wrongCar'] = $wrongCar;
                }
                return $error;
            }
            $roll -= $rate;
        }
        return null;
    }

    /**
     * The number typed instead of the right one: the two digits swapped when
     * that is another car in the race (41 becomes 14), otherwise any other
     * car. A car that is itself crossing the line around that moment is not
     * used, because the counter would then have typed that number twice,
     * which is a different mistake (see "repeat").
     */
    private function pickWrongCar(int $passIndex): ?int
    {
        $pass = $this->passes[$passIndex];
        $carsNearby = $this->carsPassingAround($passIndex, 45000);

        $swapped = (int) strrev((string) $pass['car']);
        $candidates = [$swapped];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidates[] = $this->carNumbers[$this->random->getInt(0, count($this->carNumbers) - 1)];
        }
        foreach ($candidates as $candidate) {
            $isAnotherRealCar = $candidate !== $pass['car'] && in_array($candidate, $this->carNumbers, true);
            if ($isAnotherRealCar && !isset($carsNearby[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    /** @return array<int, true> cars with a real pass within $marginMs of the given pass */
    private function carsPassingAround(int $passIndex, int $marginMs): array
    {
        $centerMs = $this->passes[$passIndex]['tsMs'];
        $cars = [];
        for ($i = $passIndex - 1; $i >= 0 && $centerMs - $this->passes[$i]['tsMs'] <= $marginMs; $i--) {
            $cars[$this->passes[$i]['car']] = true;
        }
        $count = count($this->passes);
        for ($i = $passIndex + 1; $i < $count && $this->passes[$i]['tsMs'] - $centerMs <= $marginMs; $i++) {
            $cars[$this->passes[$i]['car']] = true;
        }
        return $cars;
    }

    private function addEntry(int $sourceId, int $car, int $tsMs): void
    {
        // Nobody types a number before the race has started.
        $tsMs = max($tsMs, self::START_MS);
        $this->entries[] = new Entry($this->nextEntryId++, $sourceId, $sourceId * 10, $car, $tsMs);
    }

    private function happens(float $rate): bool
    {
        return $rate > 0 && $this->random->getFloat(0.0, 1.0) < $rate;
    }
}
