<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\Lap;
use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * Writes the engine's laps to contagem_volta.
 *
 * The table is a convenience copy for exports and for answering a protest
 * with SQL. Nothing reads it back as truth.
 *
 * Its columns have two owners, matching the two halves of the engine:
 *
 * - the COUNT columns (lap number, pass time, how many sources, state) are
 *   written by the quick per-car recount inside an upload;
 * - the TIMING columns (lap duration, spread, best-lap eligibility) need the
 *   whole race and are filled in by the full recalculation a little later.
 *
 * So an upload touches only the rows of the cars it concerns, and leaves
 * the timing columns of unchanged laps alone.
 */
final class LapStore
{
    private string $eventoId;
    private string $provaId;

    public function __construct(Prova $prova)
    {
        $this->eventoId = (string) $prova->getEventoId();
        $this->provaId = (string) $prova->getProvaId();
    }

    /**
     * Makes the rows of the given cars match their recount. Rows whose count
     * columns already match are not touched.
     *
     * @param array<int, Lap[]> $lapsByCar every car to bring up to date, with its laps (possibly none)
     * @return bool true when anything was written
     */
    public function replaceCars(array $lapsByCar): bool
    {
        if ($lapsByCar === []) {
            return false;
        }
        $storedByCar = $this->storedCountColumns(array_keys($lapsByCar));
        $wroteSomething = false;

        foreach ($lapsByCar as $carro => $laps) {
            $wanted = [];
            foreach ($laps as $lap) {
                $wanted[$lap->numeroVolta] = [$lap->tsMs, $lap->fontes, $lap->estado];
            }
            $stored = $storedByCar[$carro] ?? [];
            if ($wanted === $stored) {
                continue;
            }
            $this->writeCar((int) $carro, $laps, $stored);
            $wroteSomething = true;
        }
        return $wroteSomething;
    }

    /**
     * Makes the whole table match a full run, car by car, writing only the
     * rows that differ. Used after changes made by a person (a decision, a
     * race-control event), which can touch any car but usually touch one.
     *
     * @param Lap[] $laps every lap of the race
     * @return bool true when anything was written
     */
    public function replaceEveryCar(array $laps): bool
    {
        $lapsByCar = [];
        foreach ($laps as $lap) {
            $lapsByCar[$lap->carro][] = $lap;
        }
        // A car that has rows in the table but no lap in this run (its
        // entries were all cancelled, say) must lose those rows.
        foreach ($this->carsWithStoredLaps() as $carro) {
            $lapsByCar[$carro] ??= [];
        }
        return $this->replaceCars($lapsByCar);
    }

    /**
     * Fills in the timing columns from a full run, for the laps that still
     * are what the full run saw. A lap whose pass time has changed since
     * (an upload arrived in between) is skipped; the next run catches it.
     *
     * @param Lap[] $laps
     */
    public function updateTimingColumns(array $laps): void
    {
        $stored = $this->storedTimingColumns();
        $connection = Propel::getWriteConnection('resultados');
        $update = $connection->prepare(
            'UPDATE contagem_volta
                SET tempo_volta_ms = ?, dispersao_ms = ?, elegivel_melhor_volta = ?
              WHERE evento_id = ? AND prova_id = ? AND equipe_numero = ? AND numero_volta = ? AND ts_ms = ?'
        );
        // One transaction for all of them. Left to commit one by one, each
        // row waits for the disk: after a rebuild that is several thousand
        // waits, and took close to twenty seconds for a full race.
        $connection->beginTransaction();
        try {
            foreach ($laps as $lap) {
                $wanted = [$lap->tsMs, $lap->tempoVoltaMs, $lap->dispersaoMs, $lap->elegivelMelhorVolta ? 1 : 0];
                $key = $lap->carro . ':' . $lap->numeroVolta;
                if (!isset($stored[$key]) || $stored[$key] === $wanted || $stored[$key][0] !== $lap->tsMs) {
                    continue;
                }
                $update->execute([
                    $lap->tempoVoltaMs,
                    $lap->dispersaoMs,
                    $lap->elegivelMelhorVolta ? 1 : 0,
                    $this->eventoId,
                    $this->provaId,
                    $lap->carro,
                    $lap->numeroVolta,
                    $lap->tsMs,
                ]);
            }
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }

    public function deleteAll(): void
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'DELETE FROM contagem_volta WHERE evento_id = :evento AND prova_id = :prova'
        );
        $statement->execute([':evento' => $this->eventoId, ':prova' => $this->provaId]);
    }

    /**
     * @param Lap[] $laps
     * @param array<int, array{0: int, 1: int, 2: string}> $stored what the car has in the table now: lap number => [ts, sources, state]
     */
    private function writeCar(int $carro, array $laps, array $stored): void
    {
        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            $wantedNumbers = array_map(fn(Lap $lap) => $lap->numeroVolta, $laps);
            $obsolete = array_diff(array_keys($stored), $wantedNumbers);
            if ($obsolete !== []) {
                $delete = $connection->prepare(
                    'DELETE FROM contagem_volta
                      WHERE evento_id = ? AND prova_id = ? AND equipe_numero = ?
                        AND numero_volta IN (' . implode(', ', array_fill(0, count($obsolete), '?')) . ')'
                );
                $delete->execute(array_merge([$this->eventoId, $this->provaId, $carro], array_values($obsolete)));
            }

            // When the pass time of an existing lap changes, its timing
            // columns no longer describe it and are cleared until the next
            // full run. They are listed BEFORE ts_ms on purpose: MySQL
            // applies the assignments left to right, and the comparison must
            // see the old pass time.
            $upsert = $connection->prepare(
                'INSERT INTO contagem_volta
                     (evento_id, prova_id, equipe_numero, numero_volta, ts_ms, fontes, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     tempo_volta_ms = IF(ts_ms = VALUES(ts_ms), tempo_volta_ms, NULL),
                     elegivel_melhor_volta = IF(ts_ms = VALUES(ts_ms), elegivel_melhor_volta, 0),
                     ts_ms = VALUES(ts_ms),
                     fontes = VALUES(fontes),
                     estado = VALUES(estado)'
            );
            foreach ($laps as $lap) {
                // A new pass normally adds one lap at the end; the hundred
                // laps before it are already right and are left alone.
                if (($stored[$lap->numeroVolta] ?? null) === [$lap->tsMs, $lap->fontes, $lap->estado]) {
                    continue;
                }
                $upsert->execute([$this->eventoId, $this->provaId, $carro, $lap->numeroVolta, $lap->tsMs, $lap->fontes, $lap->estado]);
            }
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }

    /**
     * @param int[] $cars
     * @return array<int, array<int, array{0: int, 1: int, 2: string}>> carro => [lap number => [ts, sources, state]]
     */
    private function storedCountColumns(array $cars): array
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT equipe_numero, numero_volta, ts_ms, fontes, estado
               FROM contagem_volta
              WHERE evento_id = ? AND prova_id = ?
                AND equipe_numero IN (' . implode(', ', array_fill(0, count($cars), '?')) . ')
           ORDER BY equipe_numero, numero_volta'
        );
        $statement->execute(array_merge([$this->eventoId, $this->provaId], array_map('intval', $cars)));

        $byCar = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $byCar[(int) $row[0]][(int) $row[1]] = [(int) $row[2], (int) $row[3], (string) $row[4]];
        }
        return $byCar;
    }

    /** @return array<string, array{0: int, 1: ?int, 2: int, 3: int}> "car:lap" => [ts, duration, spread, eligible] */
    private function storedTimingColumns(): array
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT equipe_numero, numero_volta, ts_ms, tempo_volta_ms, dispersao_ms, elegivel_melhor_volta
               FROM contagem_volta
              WHERE evento_id = ? AND prova_id = ?'
        );
        $statement->execute([$this->eventoId, $this->provaId]);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $rows[$row[0] . ':' . $row[1]] = [(int) $row[2], $row[3] === null ? null : (int) $row[3], (int) $row[4], (int) $row[5]];
        }
        return $rows;
    }

    /** @return int[] */
    private function carsWithStoredLaps(): array
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT DISTINCT equipe_numero FROM contagem_volta WHERE evento_id = ? AND prova_id = ?'
        );
        $statement->execute([$this->eventoId, $this->provaId]);
        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
