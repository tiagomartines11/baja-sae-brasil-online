<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Access\ContagemAccess;
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
     * The fields of this prova that the lap counting engine writes and
     * people therefore must not: VOLTAS, LAST and BEST.
     *
     * None when the prova is not a laps prova, and none while publishing is
     * switched off ("desligada"): the engine then writes nothing to the
     * scoring system, the spreadsheet or a person is the one filling those
     * three in, and the entry page has to let them.
     *
     * @return string[]
     */
    public static function engineOwnedFields(Prova $prova): array
    {
        if ($prova->getType() != ContagemAccess::PROVA_TYPE) {
            return [];
        }
        return ContagemService::forProva($prova)->getSettings()->publishesToScoring() ? ScorePublisher::ENGINE_FIELDS : [];
    }

    /**
     * Saves the fields of one car that people type.
     *
     * When the value of the prova's classification field changes, the car
     * joins or leaves the race, so the whole race is recounted in the same
     * locked step: its passes start or stop counting at once, not at the
     * next upload.
     *
     * @param array<string, mixed> $newValues field code => value, as the entry page prepared them
     * @return array<string, mixed> the fields that changed, with their new values; empty when nothing did
     * @throws \Baja\Contagem\Service\LockBusy when the count is busy for too long
     */
    public static function save(Prova $prova, int $equipeId, array $newValues): array
    {
        $newValues = array_diff_key($newValues, array_flip(self::engineOwnedFields($prova)));
        $service = ContagemService::forProva($prova);

        return $service->withLock(function () use ($prova, $service, $equipeId, $newValues): array {
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

            $classificationField = $service->carRoster()->classificationField();
            if ($classificationField !== null && array_key_exists($classificationField, $changed)) {
                $service->recomputeAndPublish();
            }
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
        $emptyValues = array_diff_key(array_fill_keys($manualFieldCodes, null), array_flip(ScorePublisher::ENGINE_FIELDS));
        $service = ContagemService::forProva($prova);

        return $service->withLock(function () use ($prova, $service, $equipeId, $emptyValues): bool {
            $row = self::freshRow($prova, $equipeId);
            if ($row === null) {
                return false;
            }
            $stored = (array) $row->getDados();
            $row->setDados(array_merge($stored, $emptyValues));
            $row->save();
            $prova->refreshVarsAndPontos();

            // Emptying the classification field takes the car out of the race.
            $classificationField = $service->carRoster()->classificationField();
            if ($classificationField !== null && array_key_exists($classificationField, $emptyValues)
                && ($stored[$classificationField] ?? null) !== null) {
                $service->recomputeAndPublish();
            }
            return true;
        });
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
