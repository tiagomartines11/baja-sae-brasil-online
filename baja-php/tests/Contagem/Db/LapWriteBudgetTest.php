<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\Clock;
use Propel\Runtime\Propel;

/**
 * A change that touches most of the laps table must still be quick.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * While the server recounts a race after a change made by a person, it
 * holds the "count lock", and every phone that tries to upload during that
 * time is told "busy, try again". Most changes touch a lap or two. But
 * some renumber or re-time nearly every lap of the race: switching the
 * pass-zero rule, changing the matching window, excluding a counter,
 * moving the start.
 *
 * WHAT WENT WRONG BEFORE: the laps were written to the database one
 * statement per lap, twice. For a full race (about 4,300 laps) that held
 * the lock for around two seconds.
 *
 * The test builds a race with about as many laps as a real one (three cars
 * with 1,400 passes each, since the test event has only three cars; it is
 * the number of rows that matters), then changes the pass-zero rule, which
 * gives every lap a new number, and measures the whole change.
 */
final class LapWriteBudgetTest extends DbTestCase
{
    private const PASSES_PER_CAR = 1400;
    /** Well above the shortest lap the engine believes (two matching windows, 40 s). */
    private const SECONDS_BETWEEN_PASSES = 60;

    /**
     * Generous on purpose: machines that run tests are slow and busy, and a
     * test that fails at random teaches people to ignore it. The old code
     * needed several times this. The figure that matters for a race day
     * (under 300 ms on the load-test data) is measured separately.
     */
    private const BUDGET_MS = 1500;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(self::PASSES_PER_CAR * self::SECONDS_BETWEEN_PASSES + 600));
        $this->startRace($this->at(0));
    }

    /** Stores the whole made-up race with a few large statements (one per chunk of rows). */
    private function storeAWholeRace(): void
    {
        $deviceIdBySource = [];
        foreach ([$this->fontes[0], $this->fontes[1]] as $fonte) {
            // addEntry() creates the stand-in device of a source on first use.
            $this->addEntry($fonte, 999, $this->at(1));
            $deviceIdBySource[(int) $fonte->getFonteId()] = (int) $this->fetchValue(
                'SELECT dispositivo_id FROM contagem_dispositivo WHERE fonte_id = ? ORDER BY dispositivo_id DESC LIMIT 1',
                [$fonte->getFonteId()]
            );
        }

        $rows = [];
        $number = 0;
        foreach ([self::CAR_A, self::CAR_B, self::CAR_C] as $position => $car) {
            for ($pass = 0; $pass < self::PASSES_PER_CAR; $pass++) {
                $tsMs = $this->at(10 + $position + $pass * self::SECONDS_BETWEEN_PASSES);
                foreach ($deviceIdBySource as $fonteId => $deviceId) {
                    array_push($rows, $fonteId, $deviceId, sprintf('dddddddd-0000-4000-8000-%012d', $number++), $car, $tsMs, $tsMs, $tsMs);
                }
            }
        }
        $connection = Propel::getWriteConnection('resultados');
        foreach (array_chunk($rows, 7 * 1000) as $chunk) {
            $connection->prepare(
                'INSERT INTO contagem_passagem (fonte_id, dispositivo_id, uuid_cliente, equipe_numero, ts_dispositivo_ms, ts_ms, recebido_em_ms, modo_entrada)
                 VALUES ' . implode(', ', array_fill(0, intdiv(count($chunk), 7), "(?, ?, ?, ?, ?, ?, ?, 'teclado')"))
            )->execute($chunk);
        }
    }

    /**
     * SITUATION: a race of 4,200 passes is in the table, counted. The
     * person in charge switches the pass-zero rule from "each car's first
     * pass" to "the start": every lap of every car gets a new number.
     *
     * EXPECTED: the laps table ends up right (4,200 laps now, three more
     * than before), and the whole change, engine run and publishing
     * included, takes less than the budget.
     */
    public function testAChangeThatRenumbersEveryLapIsWrittenQuickly(): void
    {
        $this->storeAWholeRace();
        $this->recompute();
        $lapsBefore = $this->countRows('contagem_volta', 'evento_id = ? AND prova_id = ? AND numero_volta > 0', [self::EVENTO_ID, self::PROVA_ID]);
        $this->assertSame(3 * (self::PASSES_PER_CAR - 1), $lapsBefore);

        $startedAt = hrtime(true);
        $this->changeSettings(['passagem_inicial' => 'largada']);
        $elapsedMs = (hrtime(true) - $startedAt) / 1e6;

        // (Car 999 is the entry storeAWholeRace() made only to get a device; it is not part of the race.)
        $lapsAfter = $this->countRows('contagem_volta', 'evento_id = ? AND prova_id = ? AND numero_volta > 0 AND equipe_numero <> 999', [self::EVENTO_ID, self::PROVA_ID]);
        $this->assertSame(3 * self::PASSES_PER_CAR, $lapsAfter);
        $this->assertEquals(self::PASSES_PER_CAR, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertLessThan(self::BUDGET_MS, $elapsedMs, sprintf('The change took %.0f ms.', $elapsedMs));
    }

    /**
     * SITUATION: car 11 has laps with their durations filled in. Its last
     * pass was typed by counter 1 at 7:00 and by counter 2 at 7:02, so its
     * time is the middle, 7:01. Then counter 3's entry for the same pass
     * arrives, typed at 7:03: the middle of the three is now 7:02. The
     * pass time changed, through the quick recount of an upload.
     *
     * EXPECTED: the lap that ends at that pass no longer has a duration,
     * a spread or "eligible for best lap": they described the old pass
     * time. They come back with the next whole-race recalculation. The
     * laps that did not move keep theirs.
     */
    public function testALapWhosePassTimeChangedLosesItsTimingUntilTheNextFullRun(): void
    {
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recordCountedPass(self::CAR_A, $this->at(240));
        $this->addEntry($this->fontes[0], self::CAR_A, $this->at(420));
        $this->addEntry($this->fontes[1], self::CAR_A, $this->at(422));
        $this->recompute();
        $timingOf = function (int $lap): array {
            $statement = Propel::getWriteConnection('resultados')->prepare(
                'SELECT tempo_volta_ms, dispersao_ms, elegivel_melhor_volta FROM contagem_volta
                  WHERE evento_id = ? AND prova_id = ? AND equipe_numero = ? AND numero_volta = ?'
            );
            $statement->execute([self::EVENTO_ID, self::PROVA_ID, self::CAR_A, $lap]);
            return $statement->fetch(\PDO::FETCH_NUM);
        };
        $this->assertEquals(181000, $timingOf(2)[0], 'lap 2 was timed by the full run');
        $this->executeSql('UPDATE contagem_volta SET dispersao_ms = 77 WHERE evento_id = ? AND prova_id = ? AND equipe_numero = ?', [self::EVENTO_ID, self::PROVA_ID, self::CAR_A]);

        $this->addEntry($this->fontes[2], self::CAR_A, $this->at(423));
        $this->newRequest();
        $this->service->withLock(fn() => $this->service->recountCars([self::CAR_A]));

        $this->assertSame([null, 0, 0], array_map(fn($value) => $value === null ? null : (int) $value, $timingOf(2)));
        $this->assertSame([180000, 77], array_map('intval', array_slice($timingOf(1), 0, 2)), 'lap 1 did not move and keeps its timing');
    }
}
