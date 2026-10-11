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
 * with SQL. Nothing reads it back as truth, so "replace everything" is the
 * whole job: no attempt is made to patch individual rows.
 */
final class LapStore
{
    /** Rows per INSERT statement. Keeps each statement far below max_allowed_packet. */
    private const INSERT_CHUNK = 500;

    private const COLUMNS_PER_ROW = 10;

    private string $eventoId;
    private string $provaId;

    public function __construct(Prova $prova)
    {
        $this->eventoId = (string) $prova->getEventoId();
        $this->provaId = (string) $prova->getProvaId();
    }

    /**
     * Makes the table match $laps.
     *
     * Most uploads are heartbeats that change nothing, so the stored rows
     * are compared first and left alone when they already match. That keeps
     * a five second heartbeat from rewriting a few thousand rows each time.
     *
     * @param Lap[] $laps
     * @param bool $force rewrite even when nothing differs ("Reconstruir")
     * @return bool true when the table was rewritten
     */
    public function replace(array $laps, bool $force = false): bool
    {
        $rows = [];
        foreach ($laps as $lap) {
            $rows[] = self::rowOf($lap);
        }
        // Same order as storedRows() so the two lists can be compared directly.
        usort($rows, fn(array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        if (!$force && $rows === $this->storedRows()) {
            return false;
        }

        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            $this->deleteAll();
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                $this->insertChunk($chunk);
            }
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
        return true;
    }

    public function deleteAll(): void
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'DELETE FROM contagem_volta WHERE evento_id = :evento AND prova_id = :prova'
        );
        $statement->execute([':evento' => $this->eventoId, ':prova' => $this->provaId]);
    }

    /**
     * One lap as the list of values stored for it, in column order.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: ?int, 6: int, 7: string}
     */
    private static function rowOf(Lap $lap): array
    {
        return [
            $lap->carro,
            $lap->numeroVolta,
            $lap->tsMs,
            $lap->dispersaoMs,
            $lap->fontes,
            $lap->tempoVoltaMs,
            $lap->elegivelMelhorVolta ? 1 : 0,
            $lap->estado,
        ];
    }

    /** @return array<int, array<int, mixed>> rows in the shape of rowOf(), ordered by car and lap */
    private function storedRows(): array
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT equipe_numero, numero_volta, ts_ms, dispersao_ms, fontes, tempo_volta_ms,
                    elegivel_melhor_volta, estado
               FROM contagem_volta
              WHERE evento_id = :evento AND prova_id = :prova
           ORDER BY equipe_numero, numero_volta'
        );
        $statement->execute([':evento' => $this->eventoId, ':prova' => $this->provaId]);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $rows[] = [
                (int) $row[0],
                (int) $row[1],
                (int) $row[2],
                (int) $row[3],
                (int) $row[4],
                $row[5] === null ? null : (int) $row[5],
                (int) $row[6],
                (string) $row[7],
            ];
        }
        return $rows;
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function insertChunk(array $rows): void
    {
        $rowPlaceholder = '(' . implode(', ', array_fill(0, self::COLUMNS_PER_ROW, '?')) . ')';
        $sql = 'INSERT INTO contagem_volta
                    (evento_id, prova_id, equipe_numero, numero_volta, ts_ms, dispersao_ms, fontes,
                     tempo_volta_ms, elegivel_melhor_volta, estado)
                VALUES ' . implode(', ', array_fill(0, count($rows), $rowPlaceholder));

        $values = [];
        foreach ($rows as $row) {
            $values[] = $this->eventoId;
            $values[] = $this->provaId;
            foreach ($row as $value) {
                $values[] = $value;
            }
        }

        Propel::getWriteConnection('resultados')->prepare($sql)->execute($values);
    }
}
