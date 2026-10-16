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
 * can be cleared before the real race.
 *
 * Because a wrong click here would destroy the only record of a race, every
 * row is first copied, as JSON, into contagem_arquivo. Nothing in the
 * application reads that table; it is there to be queried by hand on the
 * day somebody wipes the wrong race.
 */
final class RaceReset
{
    /**
     * The tables that are archived and emptied, with the columns copied and
     * the condition that selects the rows of this prova.
     */
    private const TABLES = [
        'contagem_decisao' => [
            'columns' => ['decisao_id', 'tipo', 'passagem_id', 'fonte_id', 'equipe_numero', 'ts_ms', 'ts_fim_ms', 'desfaz_decisao_id', 'origem', 'usuario', 'motivo', 'criado_em_ms'],
            'where' => 't.evento_id = :evento AND t.prova_id = :prova',
        ],
        'contagem_ocorrencia' => [
            'columns' => ['ocorrencia_id', 'tipo', 'equipe_numero', 'ts_ms', 'usuario', 'criado_em_ms', 'substitui_id', 'uuid_cliente'],
            'where' => 't.evento_id = :evento AND t.prova_id = :prova',
        ],
        'contagem_total' => [
            'columns' => ['fonte_id', 'equipe_numero', 'voltas'],
            'where' => 't.fonte_id IN (SELECT fonte_id FROM contagem_fonte WHERE evento_id = :evento AND prova_id = :prova)',
        ],
        'contagem_passagem' => [
            'columns' => ['passagem_id', 'fonte_id', 'dispositivo_id', 'uuid_cliente', 'equipe_numero', 'ts_ms', 'qualidade_tempo', 'ts_dispositivo_ms', 'ancora_servidor_ms', 'ancora_rtt_ms', 'ancora_idade_ms', 'digitacao_ms', 'modo_entrada', 'recebido_em_ms'],
            'where' => 't.fonte_id IN (SELECT fonte_id FROM contagem_fonte WHERE evento_id = :evento AND prova_id = :prova)',
        ],
    ];

    private string $eventoId;
    private string $provaId;

    public function __construct(Prova $prova)
    {
        $this->eventoId = (string) $prova->getEventoId();
        $this->provaId = (string) $prova->getProvaId();
    }

    /**
     * Archives and then removes entries, decisions, race-control and pit
     * events and imported totals. Sources, counter links and devices stay:
     * the same people count the real race on the same phones.
     *
     * @return int how many rows were archived
     */
    public function archiveAndDeleteRawData(string $usuario): int
    {
        $connection = Propel::getWriteConnection('resultados');
        $prova = [':evento' => $this->eventoId, ':prova' => $this->provaId];
        $nowMs = Clock::nowMs();
        $archived = 0;

        $connection->beginTransaction();
        try {
            foreach (self::TABLES as $table => $definition) {
                $jsonPairs = [];
                foreach ($definition['columns'] as $column) {
                    $jsonPairs[] = "'" . $column . "', t." . $column;
                }
                $archive = $connection->prepare(
                    'INSERT INTO contagem_arquivo (evento_id, prova_id, arquivado_em_ms, arquivado_por, tabela, dados)
                     SELECT :evento_arquivo, :prova_arquivo, :agora, :usuario, :tabela, JSON_OBJECT(' . implode(', ', $jsonPairs) . ')
                       FROM ' . $table . ' t
                      WHERE ' . $definition['where']
                );
                $archive->execute($prova + [
                    ':evento_arquivo' => $this->eventoId,
                    ':prova_arquivo' => $this->provaId,
                    ':agora' => $nowMs,
                    ':usuario' => $usuario,
                    ':tabela' => $table,
                ]);
                $archived += $archive->rowCount();

                $connection->prepare('DELETE t FROM ' . $table . ' t WHERE ' . $definition['where'])->execute($prova);
            }

            // The devices' "synced through" marks describe data that is now
            // gone, so they are cleared. The devices are also marked closed:
            // the phones of a rehearsal are often not the phones of the
            // race, and a device left "open" with no mark would make its
            // source look unknown, holding every review flag back until it
            // had been silent for ten minutes. A phone that is still in use
            // reopens itself with its next heartbeat, seconds later.
            $connection->prepare(
                'UPDATE contagem_dispositivo SET sincronizado_ate_ms = NULL, fechado_em_ms = :agora
                  WHERE fonte_id IN (SELECT fonte_id FROM contagem_fonte WHERE evento_id = :evento AND prova_id = :prova)'
            )->execute($prova + [':agora' => $nowMs]);

            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
        return $archived;
    }
}
