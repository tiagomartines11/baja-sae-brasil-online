<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\ScorePublisher;
use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\Map\InputTableMap;
use Baja\Model\Prova;

/**
 * The fields of a laps prova that people type on the old entry page
 * (DESCLAS and the like), saved without disturbing the lap count.
 *
 * One row of `input` holds both kinds of field: VOLTAS, LAST and BEST,
 * written by the lap counting engine many times a minute, and the fields a
 * judge types. The entry page used to read the row, change it in memory and
 * write the whole of it back. Between that read and that write the engine
 * may publish a new lap, and the page then wrote the OLD lap count back
 * over it. Its "Deletar Nota" button deleted the row, laps and all.
 *
 * So here every change is made while holding the count lock (the publisher
 * holds the same lock while it writes), the row is read again INSIDE the
 * lock, and only the manual fields are ever touched.
 */
final class ManualScoreFields
{
    /**
     * Saves the manual fields of one car.
     *
     * @param array<string, mixed> $newValues field code => value, as the entry page prepared them
     * @return array<string, mixed> the fields that changed, with their new values; empty when nothing did
     * @throws \Baja\Contagem\Service\LockBusy when the count is busy for too long
     */
    public static function save(Prova $prova, int $equipeId, array $newValues): array
    {
        $newValues = self::withoutEngineFields($newValues);

        return ContagemService::forProva($prova)->withLock(function () use ($prova, $equipeId, $newValues): array {
            $row = self::freshRow($prova, $equipeId) ?? self::newRow($prova, $equipeId);
            $stored = (array) $row->getDados();

            $changed = [];
            foreach ($newValues as $field => $value) {
                // A row created by the engine has no key yet for the fields
                // people type; a missing key and an empty field are the same.
                if (($stored[$field] ?? null) !== $value) {
                    $changed[$field] = $value;
                }
            }
            if ($changed === []) {
                // Also keeps a car nobody filled anything in for without a
                // row: an empty row would list it on the results page.
                return [];
            }

            $row->setDados(array_merge($stored, $newValues));
            $row->save();
            $prova->refreshVarsAndPontos();
            return $changed;
        });
    }

    /**
     * "Deletar Nota" on a laps prova: empties the manual fields of one car
     * and leaves the row, with its lap count, where it is.
     *
     * @param string[] $manualFieldCodes the fields people type in this prova
     * @return bool true when there was something to clear
     */
    public static function clear(Prova $prova, int $equipeId, array $manualFieldCodes): bool
    {
        $emptyValues = self::withoutEngineFields(array_fill_keys($manualFieldCodes, null));

        return ContagemService::forProva($prova)->withLock(function () use ($prova, $equipeId, $emptyValues): bool {
            $row = self::freshRow($prova, $equipeId);
            if ($row === null) {
                return false;
            }
            $row->setDados(array_merge((array) $row->getDados(), $emptyValues));
            $row->save();
            $prova->refreshVarsAndPontos();
            return true;
        });
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function withoutEngineFields(array $values): array
    {
        return array_diff_key($values, array_flip(ScorePublisher::ENGINE_FIELDS));
    }

    /** The row as it is in the database right now, not as Propel remembers it. */
    private static function freshRow(Prova $prova, int $equipeId): ?Input
    {
        InputTableMap::clearInstancePool();
        return InputQuery::create()
            ->filterByEventoId($prova->getEventoId())
            ->filterByProvaId($prova->getProvaId())
            ->findOneByEquipeId($equipeId);
    }

    private static function newRow(Prova $prova, int $equipeId): Input
    {
        $row = new Input();
        $row->setEventoId($prova->getEventoId());
        $row->setProvaId($prova->getProvaId());
        $row->setEquipeId($equipeId);
        $row->setDados([]);
        return $row;
    }
}
