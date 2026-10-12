<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Model\Log;
use Baja\Model\Map\InputTableMap;
use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * "Marcar todos os presentes como classificados": fills in the prova's
 * classification field for every present car that does not have it.
 *
 * Classification is normally typed car by car on the scores page, after
 * the qualifying events. On a race morning where every car that showed up
 * may run, that is sixty forms to fill; this does it in one go.
 *
 * Each car changed gets a line in the application's `log` table, the same
 * record a judge leaves when saving the field by hand, so "who classified
 * car 12?" has an answer either way.
 */
final class ClassificationWriter
{
    /**
     * Only BLANK fields are filled in. A car somebody marked "NÃO" was
     * decided about by a person, and one button must not overrule that;
     * those cars are returned separately so the page can list them.
     *
     * @return array{marcados: int[], ja_nao: int[]} the cars now marked, and the cars left alone because their field was not blank
     */
    public static function markAllPresent(Prova $prova, string $usuario): array
    {
        $roster = CarRoster::forProva($prova);
        $field = $roster->classificationField();
        if ($field === null) {
            return ['marcados' => [], 'ja_nao' => []];
        }
        $leftAlone = $roster->markedOtherwise();
        $blank = array_diff_key($roster->unclassified(), array_flip($leftAlone));

        $connection = Propel::getWriteConnection('resultados');
        // Only this one key is set, with JSON_SET, for the reason given in
        // ScorePublisher: the row also holds the lap count and fields typed
        // by judges, and none of them may be overwritten from an old copy.
        $write = $connection->prepare(
            'INSERT INTO input (evento_id, prova_id, equipe_id, dados)
             VALUES (?, ?, ?, JSON_OBJECT(?, ?))
             ON DUPLICATE KEY UPDATE dados = JSON_SET(COALESCE(dados, JSON_OBJECT()), ?, ?)'
        );

        $connection->beginTransaction();
        try {
            foreach ($blank as $car) {
                $write->execute([
                    $prova->getEventoId(),
                    $prova->getProvaId(),
                    $car['numero'],
                    $field,
                    CarRoster::CLASSIFIED_VALUE,
                    '$.' . $field,
                    CarRoster::CLASSIFIED_VALUE,
                ]);

                $log = new Log();
                $log->setUser($usuario);
                $log->setPagina($prova->getEventoId() . '_' . $prova->getProvaId());
                $log->setEquipe($car['numero']);
                $log->setDados((string) json_encode([$field => CarRoster::CLASSIFIED_VALUE, 'origem' => 'marcar todos os presentes']));
                $log->save();
            }
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }

        // Rows were changed with plain SQL; Propel must not answer from memory.
        InputTableMap::clearInstancePool();
        return ['marcados' => array_keys($blank), 'ja_nao' => array_values($leftAlone)];
    }
}
