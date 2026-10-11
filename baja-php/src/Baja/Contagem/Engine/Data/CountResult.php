<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

use Baja\Contagem\Engine\RaceTimeline;

/**
 * The official passes of a few cars, recounted on their own (the fast path
 * that runs inside an upload request). See Engine::countCars().
 */
final class CountResult
{
    public RaceTimeline $timeline;

    /**
     * Every requested car is present, with an empty list when it has no
     * passes at all.
     *
     * @var array<int, Lap[]> carro => pass zero (if any), counted laps, then pending rows
     */
    public array $lapsByCar = [];

    public function __construct(RaceTimeline $timeline)
    {
        $this->timeline = $timeline;
    }

    /** Official lap count of a car. */
    public function lapCount(int $carro): int
    {
        $laps = 0;
        foreach ($this->lapsByCar[$carro] ?? [] as $lap) {
            if ($lap->isCounted()) {
                $laps = $lap->numeroVolta;
            }
        }
        return $laps;
    }

    /** Time of the car's last official pass (pass zero included), or null. */
    public function lastPassMs(int $carro): ?int
    {
        $lastPassMs = null;
        foreach ($this->lapsByCar[$carro] ?? [] as $lap) {
            if ($lap->isOfficialPass()) {
                $lastPassMs = $lap->tsMs;
            }
        }
        return $lastPassMs;
    }
}
