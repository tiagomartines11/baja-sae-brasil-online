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
 *   whole race. A full run made under the count lock (after a decision)
 *   writes them together with the count columns; the background
 *   recalculation fills them in a little later.
 *
 * HOW IT WRITES. Only rows that differ are touched, and they go to the
 * database many rows per statement, in one transaction. A change that
 * touches most of the table (excluding a counter, changing the matching
 * window, moving the start) used to send one statement per lap and held
 * the count lock for about two seconds, during which every phone was
 * answered "busy".
 */
final class LapStore
{
    /** Rows per statement. Keeps each statement far below max_allowed_packet. */
    private const ROWS_PER_STATEMENT = 500;

    private string $eventoId;
    private string $provaId;

    public function __construct(Prova $prova)
    {
        $this->eventoId = (string) $prova->getEventoId();
        $this->provaId = (string) $prova->getProvaId();
    }

    /**
     * After a quick recount: makes the count columns of the given cars
     * match. Where the pass time of an existing lap changes, its timing
     * columns no longer describe it and are cleared until the next full run.
     *
     * @param array<int, Lap[]> $lapsByCar every car to bring up to date, with its laps (possibly none)
     * @return bool true when anything was written
     */
    public function replaceCars(array $lapsByCar): bool
    {
        if ($lapsByCar === []) {
            return false;
        }
        return $this->write($lapsByCar, $this->storedRows(array_keys($lapsByCar)), false);
    }

    /**
     * After a full run made under the count lock: makes the whole table
     * match it, count and timing columns alike. Used after changes made by
     * a person (a decision, a race-control event, a setting), which can
     * touch any car but usually touch one.
     *
     * @param Lap[] $laps every lap of the race
     * @return bool true when anything was written
     */
    public function replaceEveryCar(array $laps): bool
    {
        $stored = $this->storedRows(null);
        $lapsByCar = [];
        foreach ($laps as $lap) {
            $lapsByCar[$lap->carro][] = $lap;
        }
        // A car that has rows in the table but no lap in this run (its
        // entries were all cancelled, say) must lose those rows.
        foreach (array_keys($stored) as $carro) {
            $lapsByCar[$carro] ??= [];
        }
        return $this->write($lapsByCar, $stored, true);
    }

    /**
     * After the background recalculation, which runs WITHOUT the count
     * lock: fills in the timing columns of the laps that still are what the
     * run saw. A lap whose pass time has changed since (an upload arrived
     * in between) or that is gone is left alone; the next run catches it.
     *
     * @param Lap[] $laps
     */
    public function updateTimingColumns(array $laps): void
    {
        $stored = $this->storedRows(null);
        $rows = [];
        foreach ($laps as $lap) {
            $current = $stored[$lap->carro][$lap->numeroVolta] ?? null;
            if ($current === null || $current['ts'] !== $lap->tsMs || $current['timing'] === self::timingOf($lap)) {
                continue;
            }
            $rows[] = [$lap->carro, $lap->numeroVolta, $lap->tsMs, $lap->tempoVoltaMs, $lap->dispersaoMs, $lap->elegivelMelhorVolta ? 1 : 0];
        }

        // The pass time is part of the match on purpose: a row an upload
        // changed while this run was computing must not get this run's
        // timing. (An INSERT ... ON DUPLICATE KEY would also bring back
        // rows that were deleted meanwhile; an UPDATE cannot.)
        $this->inOneTransaction(function ($connection) use ($rows): void {
            foreach (array_chunk($rows, self::ROWS_PER_STATEMENT) as $chunk) {
                // The new values travel as a small table made of SELECTs;
                // the first one names the columns. (Plain SQL on purpose:
                // it runs on every MySQL and MariaDB version.)
                $firstRow = 'SELECT ? AS carro, ? AS volta, ? AS ts_ms, ? AS tempo_volta_ms, ? AS dispersao_ms, ? AS elegivel';
                $otherRows = str_repeat(' UNION ALL SELECT ?, ?, ?, ?, ?, ?', count($chunk) - 1);
                $connection->prepare(
                    'UPDATE contagem_volta v
                       JOIN (' . $firstRow . $otherRows . ') AS novo
                         ON v.equipe_numero = novo.carro AND v.numero_volta = novo.volta AND v.ts_ms = novo.ts_ms
                        SET v.tempo_volta_ms = novo.tempo_volta_ms,
                            v.dispersao_ms = novo.dispersao_ms,
                            v.elegivel_melhor_volta = novo.elegivel
                      WHERE v.evento_id = ? AND v.prova_id = ?'
                )->execute(array_merge(array_merge(...$chunk), [$this->eventoId, $this->provaId]));
            }
        });
    }

    public function deleteAll(): void
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'DELETE FROM contagem_volta WHERE evento_id = :evento AND prova_id = :prova'
        );
        $statement->execute([':evento' => $this->eventoId, ':prova' => $this->provaId]);
    }

    /**
     * @param array<int, Lap[]> $lapsByCar what the given cars must look like
     * @param array<int, array<int, array{ts: int, count: array, timing: array}>> $stored what they look like now
     * @param bool $withTiming true when the laps come from a full run and carry their timing
     */
    private function write(array $lapsByCar, array $stored, bool $withTiming): bool
    {
        $rowsToWrite = [];
        $rowsToDelete = [];
        foreach ($lapsByCar as $carro => $laps) {
            $wantedNumbers = [];
            foreach ($laps as $lap) {
                $wantedNumbers[$lap->numeroVolta] = true;
                $current = $stored[$carro][$lap->numeroVolta] ?? null;
                $sameCount = $current !== null && $current['count'] === [$lap->tsMs, $lap->fontes, $lap->estado];
                // A new pass normally adds one lap at the end; the hundred
                // laps before it are already right and are left alone.
                if ($sameCount && (!$withTiming || $current['timing'] === self::timingOf($lap))) {
                    continue;
                }
                $rowsToWrite[] = [
                    $this->eventoId, $this->provaId, $carro, $lap->numeroVolta, $lap->tsMs, $lap->fontes, $lap->estado,
                    $lap->tempoVoltaMs, $lap->dispersaoMs, $lap->elegivelMelhorVolta ? 1 : 0,
                ];
            }
            foreach (array_keys($stored[$carro] ?? []) as $storedNumber) {
                if (!isset($wantedNumbers[$storedNumber])) {
                    $rowsToDelete[] = [$carro, $storedNumber];
                }
            }
        }
        if ($rowsToWrite === [] && $rowsToDelete === []) {
            return false;
        }

        $this->inOneTransaction(function ($connection) use ($rowsToWrite, $rowsToDelete, $withTiming): void {
            foreach (array_chunk($rowsToDelete, self::ROWS_PER_STATEMENT) as $chunk) {
                $connection->prepare(
                    'DELETE FROM contagem_volta
                      WHERE evento_id = ? AND prova_id = ?
                        AND (equipe_numero, numero_volta) IN (' . self::placeholders(count($chunk), '(?, ?)') . ')'
                )->execute(array_merge([$this->eventoId, $this->provaId], array_merge(...$chunk)));
            }
            foreach (array_chunk($rowsToWrite, self::ROWS_PER_STATEMENT) as $chunk) {
                $connection->prepare(
                    'INSERT INTO contagem_volta
                         (evento_id, prova_id, equipe_numero, numero_volta, ts_ms, fontes, estado,
                          tempo_volta_ms, dispersao_ms, elegivel_melhor_volta)
                     VALUES ' . self::placeholders(count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)') . '
                     ON DUPLICATE KEY UPDATE ' . ($withTiming ? self::UPDATE_WITH_TIMING : self::UPDATE_COUNT_ONLY)
                )->execute(array_merge(...$chunk));
            }
        });
        return true;
    }

    /** A full run knows every column. VALUES(x) is "the value this statement wanted to insert for x". */
    private const UPDATE_WITH_TIMING = '
        tempo_volta_ms = VALUES(tempo_volta_ms),
        dispersao_ms = VALUES(dispersao_ms),
        elegivel_melhor_volta = VALUES(elegivel_melhor_volta),
        ts_ms = VALUES(ts_ms),
        fontes = VALUES(fontes),
        estado = VALUES(estado)';

    /**
     * A quick recount knows the count columns only. The timing columns of a
     * lap whose pass time did not change are kept; where it changed they no
     * longer describe the lap and are cleared. They are listed BEFORE ts_ms
     * on purpose: MySQL applies the assignments left to right, and the
     * comparisons must see the old pass time.
     */
    private const UPDATE_COUNT_ONLY = '
        tempo_volta_ms = IF(ts_ms = VALUES(ts_ms), tempo_volta_ms, NULL),
        dispersao_ms = IF(ts_ms = VALUES(ts_ms), dispersao_ms, 0),
        elegivel_melhor_volta = IF(ts_ms = VALUES(ts_ms), elegivel_melhor_volta, 0),
        ts_ms = VALUES(ts_ms),
        fontes = VALUES(fontes),
        estado = VALUES(estado)';

    /** @return array{0: ?int, 1: int, 2: int} [lap duration, spread, eligible for best lap] */
    private static function timingOf(Lap $lap): array
    {
        return [$lap->tempoVoltaMs, $lap->dispersaoMs, $lap->elegivelMelhorVolta ? 1 : 0];
    }

    /**
     * The rows the table holds now.
     *
     * @param int[]|null $cars null for every car of the prova
     * @return array<int, array<int, array{ts: int, count: array, timing: array}>> car => lap number => its columns
     */
    private function storedRows(?array $cars): array
    {
        $carFilter = $cars === null ? '' : ' AND equipe_numero IN (' . self::placeholders(count($cars), '?') . ')';
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT equipe_numero, numero_volta, ts_ms, fontes, estado, tempo_volta_ms, dispersao_ms, elegivel_melhor_volta
               FROM contagem_volta
              WHERE evento_id = ? AND prova_id = ?' . $carFilter
        );
        $statement->execute(array_merge([$this->eventoId, $this->provaId], array_map('intval', $cars ?? [])));

        $byCar = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $byCar[(int) $row[0]][(int) $row[1]] = [
                'ts' => (int) $row[2],
                'count' => [(int) $row[2], (int) $row[3], (string) $row[4]],
                'timing' => [$row[5] === null ? null : (int) $row[5], (int) $row[6], (int) $row[7]],
            ];
        }
        return $byCar;
    }

    /** "(?, ?), (?, ?), (?, ?)" for three rows of "(?, ?)". */
    private static function placeholders(int $rows, string $oneRow): string
    {
        return implode(', ', array_fill(0, $rows, $oneRow));
    }

    /** Runs $work($connection) in a transaction of its own, or inside the caller's when there is one. */
    private function inOneTransaction(callable $work): void
    {
        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            $work($connection);
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }
}
