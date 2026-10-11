<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Settings;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A made-up race, generated from a seed.
 *
 * It is built in three steps:
 *
 *   1. SyntheticTrack     what REALLY happened: every crossing of every car;
 *   2. SyntheticMistakes  which crossings the counters get wrong, and how;
 *   3. this class         what each counter typed as a result.
 *
 * Because the truth is known, a test can check the engine's answer against
 * it. No real race data is used anywhere; this repository is public.
 *
 * THE TRUTH ABOUT LAPS: the counting line is after the start line, so a
 * car's first crossing completes no lap (it is its "pass zero"). A car that
 * really crossed N times has N - 1 laps. With the other setting
 * (`passagem_inicial` = "largada") it has N.
 */
final class SyntheticRace
{
    public const START_MS = RaceScenario::CLOCK_ZERO_MS;

    /**
     * A counter may press Enter a little BEFORE the car is on the line (up
     * to 0.9 s) or a little after (up to 1.9 s). "What had really happened
     * by now" is compared with that tolerance.
     */
    public const EARLIEST_TYPING_MS = -900;
    public const LATEST_TYPING_MS = 1900;

    /** @var array<int, int> car => laps it really completed (official crossings minus one) */
    public array $truthLapsByCar = [];

    public SyntheticMistakes $mistakes;
    public int $endMs;

    private SyntheticTrack $track;
    /** @var Entry[] */
    private array $entries = [];
    /** @var array<int, ?int> entry id => the car that really crossed, or null when no crossing is behind the entry */
    private array $realCarByEntryId = [];

    private SyntheticRaceConfig $config;
    private Randomizer $random;
    private int $nextEntryId = 1;

    public static function generate(SyntheticRaceConfig $config): self
    {
        $race = new self($config);
        $race->track = SyntheticTrack::invent($config, $race->random, self::START_MS, $race->endMs);
        $race->mistakes = SyntheticMistakes::plan($config, $race->track, $race->random, self::START_MS, $race->endMs);
        $race->inventWhatTheCountersTyped();
        $race->truthLapsByCar = $race->truthLaps(Settings::INICIAL_PRIMEIRA_PASSAGEM);
        return $race;
    }

    private function __construct(SyntheticRaceConfig $config)
    {
        $this->config = $config;
        $this->random = new Randomizer(new Mt19937($config->seed));
        $this->endMs = self::START_MS + $config->raceMinutes * 60000;
    }

    // ---------------------------------------------------------------
    // The truth
    // ---------------------------------------------------------------

    /**
     * @return array<int, int> car => laps it really completed, under the given `passagem_inicial` rule
     */
    public function truthLaps(string $passagemInicial): array
    {
        $laps = [];
        foreach ($this->track->officialPassTimesByCar as $car => $passTimes) {
            $laps[$car] = self::lapsFromPasses(count($passTimes), $passagemInicial);
        }
        return $laps;
    }

    /** Laps the car had really completed by $momentMs (default rule: crossings minus one). */
    public function truthLapsAt(int $car, int $momentMs): int
    {
        return self::lapsFromPasses($this->track->officialPassesBy($car, $momentMs), Settings::INICIAL_PRIMEIRA_PASSAGEM);
    }

    private static function lapsFromPasses(int $passes, string $passagemInicial): int
    {
        return $passagemInicial === Settings::INICIAL_LARGADA ? $passes : max(0, $passes - 1);
    }

    /**
     * What a person watching the video would say about an entry: the car
     * that really crossed when it was typed, or null when nothing crossed
     * (the second half of a double tap or of a repeat).
     */
    public function realCarBehindEntry(int $entryId): ?int
    {
        return $this->realCarByEntryId[$entryId] ?? null;
    }

    /** @return int[] */
    public function carNumbers(): array
    {
        return range(1, $this->config->cars);
    }

    // ---------------------------------------------------------------
    // What the server knows
    // ---------------------------------------------------------------

    /**
     * What the server knows at a given moment of the race: only the entries
     * typed so far, minus those still sitting in a phone that is offline.
     * With no argument: after the race, everything uploaded.
     */
    public function input(?int $nowMs = null): EngineInput
    {
        $nowMs = $nowMs ?? $this->lastEntryMs() + 60000;

        $input = new EngineInput();
        $input->nowMs = $nowMs;
        $input->validCars = $this->carNumbers();
        $input->ocorrencias = array_values(array_filter($this->track->ocorrencias, fn(Ocorrencia $o) => $o->tsMs <= $nowMs));

        for ($sourceId = 1; $sourceId <= $this->config->counters; $sourceId++) {
            $syncedThroughMs = $this->offlineSinceMs($sourceId, $nowMs) ?? $nowMs;
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

    private function lastEntryMs(): int
    {
        $lastMs = $this->endMs;
        foreach ($this->entries as $entry) {
            $lastMs = max($lastMs, $entry->tsMs);
        }
        return $lastMs;
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
    // What the counters typed
    // ---------------------------------------------------------------

    private function inventWhatTheCountersTyped(): void
    {
        // Each counter has a habit: some press Enter a little early, some a
        // little late, consistently.
        $habitMs = [];
        for ($sourceId = 1; $sourceId <= $this->config->counters; $sourceId++) {
            $habitMs[$sourceId] = $this->random->getInt(-500, 1500);
        }

        foreach ($this->track->passes as $passIndex => $pass) {
            for ($sourceId = 1; $sourceId <= $this->config->counters; $sourceId++) {
                $typedAtMs = $pass['tsMs'] + $habitMs[$sourceId] + $this->random->getInt(-400, 400);
                $mistake = $this->mistakes->actions[$passIndex][$sourceId] ?? null;
                $this->typeOneCrossing($sourceId, $pass, $typedAtMs, $mistake);
            }
        }
    }

    /**
     * @param array{car: int, tsMs: int, official: bool} $pass
     * @param array{does: string, car?: int, ms?: int}|null $mistake what this counter does wrong here, if anything
     */
    private function typeOneCrossing(int $sourceId, array $pass, int $typedAtMs, ?array $mistake): void
    {
        $car = $pass['car'];
        $handover = $this->handoverAt($sourceId, $pass['tsMs']);
        $device = $this->deviceInUse($sourceId, $pass['tsMs']);

        switch ($mistake['does'] ?? null) {
            case SyntheticMistakes::DOES_NOT_TYPE:
                return;
            case SyntheticMistakes::TYPES_ANOTHER_CAR:
                $this->addEntry($sourceId, $device, $mistake['car'], $typedAtMs, $car);
                return;
            case SyntheticMistakes::TYPES_LATE:
                $this->addEntry($sourceId, $device, $car, $typedAtMs + $mistake['ms'], $car);
                return;
            case SyntheticMistakes::TYPES_AT_EXACTLY:
                $this->addEntry($sourceId, $device, $car, $pass['tsMs'] + $mistake['ms'], $car);
                return;
            case SyntheticMistakes::TYPES_AGAIN:
                $this->addEntry($sourceId, $device, $car, $typedAtMs, $car);
                $this->addEntry($sourceId, $device, $car, $typedAtMs + $mistake['ms'], null);
                return;
        }

        $this->addEntry($sourceId, $device, $car, $typedAtMs, $car);
        if ($handover !== null) {
            // The newcomer, on their own phone, records the same crossing a
            // little later while finding the rhythm.
            $this->addEntry($sourceId, $sourceId * 10 + 1, $car, $typedAtMs + $this->random->getInt(1000, 8000), $car);
        }
    }

    /**
     * The phone a source's regular entry comes from: the first counter's
     * until the end of a handover, the newcomer's afterwards.
     */
    private function deviceInUse(int $sourceId, int $tsMs): int
    {
        foreach ($this->config->handovers as $handover) {
            if ($handover['source'] === $sourceId && $tsMs > self::START_MS + $handover['toSeconds'] * 1000) {
                return $sourceId * 10 + 1;
            }
        }
        return $sourceId * 10;
    }

    /** @return array{source: int, fromSeconds: int, toSeconds: int}|null the handover going on at that moment */
    private function handoverAt(int $sourceId, int $tsMs): ?array
    {
        foreach ($this->config->handovers as $handover) {
            $fromMs = self::START_MS + $handover['fromSeconds'] * 1000;
            $toMs = self::START_MS + $handover['toSeconds'] * 1000;
            if ($handover['source'] === $sourceId && $tsMs >= $fromMs && $tsMs <= $toMs) {
                return $handover;
            }
        }
        return null;
    }

    private function addEntry(int $sourceId, int $device, int $car, int $tsMs, ?int $realCar): void
    {
        // Nobody types a number before the race has started.
        $tsMs = max($tsMs, self::START_MS);
        $id = $this->nextEntryId++;
        $this->entries[] = new Entry($id, $sourceId, $device, $car, $tsMs);
        $this->realCarByEntryId[$id] = $realCar;
    }
}
