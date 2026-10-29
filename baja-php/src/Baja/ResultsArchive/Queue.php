<?php
declare(strict_types=1);

namespace Baja\ResultsArchive;

use Baja\Evento\EventCode;
use Baja\Logger;
use Baja\Model\Evento;
use Baja\Model\Map\EventoTableMap;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use RuntimeException;
use Throwable;

/**
 * The queue of event archives (BAJAAPP-67): requests to freeze a finished
 * event's published results in the backups.
 *
 * The web request does the cheap part only. When an admin marks an event
 * finalizado, juiz/evento.php saves it through saveEvento(), which then
 * adds one `pendente` row to evento_arquivamento. Taking the mark off
 * cancels that row. Saving the event never depends on the queue: see
 * saveEvento(). Everything slow — running every results
 * page of the event, encrypting, uploading — happens at night, in
 * baja-infra/scripts/backup-run.sh, which goes through scripts/
 * results-archive.php: pending() to find the work, then markArchived() or
 * markFailed() for each request.
 *
 * Rules, all enforced here:
 *   - Only a change from not finalizado to finalizado asks for an archive.
 *     Saving an event that already was finalizado asks for nothing.
 *   - An event has at most one pending request.
 *   - Un-marking cancels the pending request; marking again makes a new
 *     one, so a second archive is made and the first is kept.
 *   - A request whose event is no longer finalizado (or no longer exists)
 *     when the job runs is cancelled, not archived: the results it would
 *     freeze are not final.
 *
 * Plain SQL on Propel's connection rather than generated model classes;
 * schema.xml has the table and says why.
 */
final class Queue
{
    public const PENDENTE = 'pendente';
    public const ARQUIVADO = 'arquivado';
    public const CANCELADO = 'cancelado';

    private const ERROR_MAX_LENGTH = 500;

    public function __construct(private ConnectionInterface $con)
    {
    }

    public static function forApp(): self
    {
        return new self(Propel::getWriteConnection(EventoTableMap::DATABASE_NAME));
    }

    /**
     * Saves the event, then records what its finalizado change asks for.
     *
     * Two steps, not one transaction, on purpose: editing an event must
     * never depend on the archive queue. If the queue cannot be written —
     * the migration that creates evento_arquivamento was missed, or the
     * insert fails — the event is saved all the same, the error is logged,
     * and false comes back so the page can tell the admin that the archive
     * was not queued. A missed migration is also caught at night: the
     * backup job cannot read the queue, and the archive monitor alerts.
     *
     * An error saving the EVENT is not caught: that is the page's failure,
     * as it always was.
     *
     * @param bool          $eraFinalizado finalizado as it was loaded, before the form changed it (false for a new event)
     * @param string|null   $usuario       who saved it, for pedido_por
     * @param callable|null $logError      fn(string $message, array $context): void; Baja\Logger::error by default
     * @return bool true when whatever the change asked for was recorded (or it asked for nothing); false when the queue could not be written
     */
    public static function saveEvento(Evento $evento, bool $eraFinalizado, ?string $usuario, ?int $agoraMs = null, ?callable $logError = null): bool
    {
        $evento->save();

        try {
            self::forApp()->finalizadoChanged(
                (string) $evento->getEventoId(),
                $eraFinalizado,
                (bool) $evento->getFinalizado(),
                $usuario,
                $agoraMs ?? self::nowMs()
            );
            return true;
        } catch (Throwable $e) {
            $logError ??= static fn(string $message, array $context) => Logger::error($message, $context);
            $logError('Event saved, but its results archive request could not be recorded', [
                'evento_id' => (string) $evento->getEventoId(),
                'finalizado_antes' => $eraFinalizado,
                'finalizado_depois' => (bool) $evento->getFinalizado(),
                'error' => get_class($e) . ': ' . $e->getMessage(),
            ]);
            return false;
        }
    }

    /** What a change of the finalizado flag asks for. Nothing when it did not change. */
    public function finalizadoChanged(string $eventoId, bool $antes, bool $depois, ?string $usuario, int $agoraMs): void
    {
        if ($antes === $depois) {
            return;
        }
        if (!$depois) {
            $this->cancelPending($eventoId, $agoraMs, 'evento desmarcado como finalizado antes do arquivamento');
            return;
        }
        // NOT EXISTS keeps it at one pending request per event even if a
        // pending one is somehow already there (a double submit racing).
        $this->execute(
            "INSERT INTO evento_arquivamento (evento_id, pedido_em_ms, pedido_por, estado)
             SELECT ?, ?, ?, ? FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM evento_arquivamento WHERE evento_id = ? AND estado = ?)",
            [$eventoId, $agoraMs, $usuario, self::PENDENTE, $eventoId, self::PENDENTE]
        );
    }

    /**
     * The requests to archive now, oldest first. Requests whose event is
     * no longer finalizado, or no longer exists, are cancelled first and
     * not returned.
     *
     * @return list<array{arquivamento_id: int, evento_id: string, pedido_em_ms: int, tentativas: int}>
     */
    public function pending(int $agoraMs): array
    {
        $this->execute(
            "UPDATE evento_arquivamento a
             LEFT JOIN evento e ON e.evento_id = a.evento_id
             SET a.estado = ?, a.concluido_em_ms = ?,
                 a.ultimo_erro = IF(e.evento_id IS NULL, 'evento apagado antes do arquivamento',
                                                         'evento não está mais finalizado')
             WHERE a.estado = ? AND (e.evento_id IS NULL OR COALESCE(e.finalizado, 0) <> 1)",
            [self::CANCELADO, $agoraMs, self::PENDENTE]
        );
        $rows = $this->select(
            "SELECT arquivamento_id, evento_id, pedido_em_ms, tentativas
             FROM evento_arquivamento WHERE estado = ?
             ORDER BY pedido_em_ms, arquivamento_id",
            [self::PENDENTE]
        );
        return array_map(static fn(array $row): array => [
            'arquivamento_id' => (int) $row['arquivamento_id'],
            'evento_id' => (string) $row['evento_id'],
            'pedido_em_ms' => (int) $row['pedido_em_ms'],
            'tentativas' => (int) $row['tentativas'],
        ], $rows);
    }

    /** @return array<string, mixed>|null the row, as stored */
    public function find(int $arquivamentoId): ?array
    {
        $rows = $this->select('SELECT * FROM evento_arquivamento WHERE arquivamento_id = ?', [$arquivamentoId]);
        return $rows[0] ?? null;
    }

    /**
     * The newest request of an event, whatever its state: what the event
     * page shows next to the finalizado box.
     *
     * @return array<string, mixed>|null
     */
    public function latestFor(string $eventoId): ?array
    {
        $rows = $this->select(
            'SELECT * FROM evento_arquivamento WHERE evento_id = ?
             ORDER BY pedido_em_ms DESC, arquivamento_id DESC LIMIT 1',
            [$eventoId]
        );
        return $rows[0] ?? null;
    }

    /**
     * The archive is on the Shared Drive, manifest and all. Only a pending
     * request can be marked: marking one twice, or one that was cancelled,
     * is a bug in the caller and is refused.
     */
    public function markArchived(int $arquivamentoId, string $pasta, int $agoraMs): void
    {
        if (preg_match('#^events/([^/]*)/\d{8}T\d{6}Z$#D', $pasta, $match) !== 1 || !EventCode::isValid($match[1])) {
            throw new RuntimeException('Not an event archive folder: ' . $pasta);
        }
        $changed = $this->execute(
            'UPDATE evento_arquivamento SET estado = ?, pasta = ?, concluido_em_ms = ?, ultimo_erro = NULL
             WHERE arquivamento_id = ? AND estado = ?',
            [self::ARQUIVADO, $pasta, $agoraMs, $arquivamentoId, self::PENDENTE]
        );
        if ($changed !== 1) {
            throw new RuntimeException('Request ' . $arquivamentoId . ' is not pending; nothing marked.');
        }
    }

    /** This attempt failed. The request stays pending and is tried again the next night. */
    public function markFailed(int $arquivamentoId, string $erro): void
    {
        $this->execute(
            'UPDATE evento_arquivamento SET tentativas = tentativas + 1, ultimo_erro = ?
             WHERE arquivamento_id = ? AND estado = ?',
            [mb_substr($erro, 0, self::ERROR_MAX_LENGTH), $arquivamentoId, self::PENDENTE]
        );
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function cancelPending(string $eventoId, int $agoraMs, string $motivo): void
    {
        $this->execute(
            'UPDATE evento_arquivamento SET estado = ?, concluido_em_ms = ?, ultimo_erro = ?
             WHERE evento_id = ? AND estado = ?',
            [self::CANCELADO, $agoraMs, $motivo, $eventoId, self::PENDENTE]
        );
    }

    /** @return int rows affected */
    private function execute(string $sql, array $params): int
    {
        $statement = $this->con->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    /** @return list<array<string, mixed>> */
    private function select(string $sql, array $params): array
    {
        $statement = $this->con->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
