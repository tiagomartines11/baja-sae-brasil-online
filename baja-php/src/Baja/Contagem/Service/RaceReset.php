<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * Deletes the raw lap counting data of one prova ("Zerar corrida").
 *
 * This is the one place in the application allowed to delete from the
 * append-only tables. It exists so that a rehearsal with made-up entries
 * can be cleared before the real race; the page that offers it asks for
 * confirmation first.
 */
final class RaceReset
{
    private string $eventoId;
    private string $provaId;

    public function __construct(Prova $prova)
    {
        $this->eventoId = (string) $prova->getEventoId();
        $this->provaId = (string) $prova->getProvaId();
    }

    /**
     * Removes entries, decisions, race-control and pit events and imported
     * totals. Sources, counter links and devices stay: the same people
     * count the real race on the same phones.
     */
    public function deleteRawData(): void
    {
        $sourcesOfProva = 'SELECT fonte_id FROM contagem_fonte WHERE evento_id = :evento AND prova_id = :prova';

        $statements = [
            'DELETE FROM contagem_decisao WHERE evento_id = :evento AND prova_id = :prova',
            'DELETE FROM contagem_ocorrencia WHERE evento_id = :evento AND prova_id = :prova',
            'DELETE FROM contagem_total WHERE fonte_id IN (' . $sourcesOfProva . ')',
            'DELETE FROM contagem_passagem WHERE fonte_id IN (' . $sourcesOfProva . ')',
            // The devices' "synced through" marks describe data that is now
            // gone. Clearing them makes every source prove again that it is
            // up to date before its silence is trusted.
            'UPDATE contagem_dispositivo SET sincronizado_ate_ms = NULL WHERE fonte_id IN (' . $sourcesOfProva . ')',
        ];

        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            foreach ($statements as $sql) {
                $connection->prepare($sql)->execute([':evento' => $this->eventoId, ':prova' => $this->provaId]);
            }
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }
}
