<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use PHPUnit\Framework\TestCase;

/**
 * Shared vocabulary for the engine tests, so each test can say what it
 * expects in one readable line.
 *
 * Words used throughout these tests:
 *
 * - "counter" / "source": one of the people typing car numbers (source 1 is
 *   "Contagem 1", and so on).
 * - "entry": one typed number, with the moment Enter was pressed.
 * - "pass": a car really crossing the counting line.
 * - "flag": something the engine shows on the review screen for a person to
 *   decide. An "open" flag is waiting for that person; an "auto-resolved"
 *   one was handled by the engine and is only listed for the record.
 */
abstract class EngineTestCase extends TestCase
{
    /**
     * @return Flag[] open flags, optionally of one type only
     */
    protected function openFlags(EngineResult $result, ?string $type = null): array
    {
        $flags = [];
        foreach ($result->openFlags() as $flag) {
            if ($type === null || $flag->tipo === $type) {
                $flags[] = $flag;
            }
        }
        return $flags;
    }

    /**
     * @return Flag[] flags the engine resolved on its own
     */
    protected function autoResolvedFlags(EngineResult $result): array
    {
        return array_values(array_filter($result->flags, fn(Flag $flag) => $flag->autoResolvido));
    }

    protected function assertNoOpenFlags(EngineResult $result, string $message = ''): void
    {
        $descriptions = array_map(fn(Flag $flag) => $flag->id . ' (' . $flag->motivo . ')', $result->openFlags());
        $this->assertSame([], $descriptions, $message !== '' ? $message : 'The review queue should be empty.');
    }

    /** Asserts there is exactly one open flag, of the given type, and returns it. */
    protected function assertSingleOpenFlag(EngineResult $result, string $type): Flag
    {
        $flags = $result->openFlags();
        $summary = implode(', ', array_map(fn(Flag $flag) => $flag->id, $flags));
        $this->assertCount(1, $flags, 'Expected exactly one open flag, got: [' . $summary . ']');
        $this->assertSame($type, $flags[0]->tipo);
        return $flags[0];
    }

    /**
     * @param array<int, int> $expectedLapsByCar car number => official laps
     */
    protected function assertLapCounts(array $expectedLapsByCar, EngineResult $result, string $message = ''): void
    {
        $actual = [];
        foreach (array_keys($expectedLapsByCar) as $car) {
            $actual[$car] = $result->lapCount($car);
        }
        $this->assertSame($expectedLapsByCar, $actual, $message);
    }

    /**
     * THE INVARIANT OF THE SAFE MODE: with nobody reviewing, no car is ever
     * ahead of the truth. Checked for every official pass of every car, at
     * the moment that pass became official: when the engine says "this is
     * the car's N-th pass", the car must really have crossed the line N
     * times by then. A pass two counters agree on became official when the
     * SECOND of them typed it (its pass time, the median, can be a second
     * or two earlier when one entry is a stray that happens to sit right
     * before the real one). A counter may press Enter up to 0.9 s before
     * the car is on the line; the comparison allows for that. Because
     * every pass is checked at its own moment, this covers every moment of
     * the race, not only the moment the result was computed.
     *
     * @return int how many official passes were checked
     */
    protected function assertNoCarIsEverAheadOfTheTruth(SyntheticRace $race, EngineResult $result, string $where = ''): int
    {
        $passesSoFar = [];
        $checked = 0;
        foreach ($result->laps as $lap) {
            if (!$lap->isOfficialPass()) {
                continue;
            }
            $car = $lap->carro;
            $passesSoFar[$car] = ($passesSoFar[$car] ?? 0) + 1;
            $entries = $lap->grupo === null ? [] : $lap->grupo->entries;
            $officialSinceMs = max($lap->tsMs, isset($entries[1]) ? $entries[1]->tsMs : $lap->tsMs);
            $reallyCrossed = $race->truePassesBy($car, $officialSinceMs - SyntheticRace::EARLIEST_TYPING_MS);
            $this->assertLessThanOrEqual(
                $reallyCrossed,
                $passesSoFar[$car],
                sprintf(
                    'Car %d has %d official passes by second %.1f of the race, and had really crossed %d times. %s',
                    $car,
                    $passesSoFar[$car],
                    ($officialSinceMs - SyntheticRace::START_MS) / 1000,
                    $reallyCrossed,
                    $where
                )
            );
            $checked++;
        }
        return $checked;
    }

    /** How many passes of a car one counter has, after corrections. */
    protected function sourceCount(EngineResult $result, int $car, int $source): int
    {
        return $result->cars[$car]->contagemPorFonte[$source] ?? 0;
    }
}
