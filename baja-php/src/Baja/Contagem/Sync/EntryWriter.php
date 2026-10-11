<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Propel\Runtime\Propel;

/**
 * Stores what one upload carried: entries, the counter's own voids, pit
 * events.
 *
 * Everything here is idempotent. A phone that never received our answer
 * sends the same batch again, possibly many times, and the second copy
 * must change nothing. The client-generated uuid is what makes that
 * possible: a uuid we already hold is acknowledged and otherwise ignored.
 *
 * Each method returns the uuids the client may now forget ("uuids") and
 * how many of them were new to us ("new").
 */
final class EntryWriter
{
    private const MODES = ['teclado', 'teclado_tela', 'blocos', 'api'];

    /** Stored when a client sends a mode we do not know. Dropping the entry would be worse. */
    private const UNKNOWN_MODE = 'desconhecido';

    private int $fonteId;
    private int $dispositivoId;
    private string $eventoId;
    private string $provaId;
    private ?string $usuario;
    private int $nowMs;

    public function __construct(int $fonteId, int $dispositivoId, string $eventoId, string $provaId, ?string $usuario, int $nowMs)
    {
        $this->fonteId = $fonteId;
        $this->dispositivoId = $dispositivoId;
        $this->eventoId = $eventoId;
        $this->provaId = $provaId;
        $this->usuario = $usuario;
        $this->nowMs = $nowMs;
    }

    /**
     * @param array<int, mixed> $entries as decoded from the request
     * @return array{uuids: string[], new: int}
     */
    public function storeEntries(array $entries): array
    {
        $acknowledged = [];
        $valid = [];
        foreach ($entries as $entry) {
            $uuid = UploadFields::uuidOf($entry);
            if ($uuid === null) {
                continue;
            }
            $car = UploadFields::carOf($entry);
            $deviceTimeMs = UploadFields::intOrNull($entry['ts_device_ms'] ?? null);
            if ($car === null || $deviceTimeMs === null || $deviceTimeMs <= 0) {
                // Not acknowledged, so the client keeps it and keeps showing
                // it as unsent, which is the visible sign that something is
                // wrong with that device.
                continue;
            }
            $acknowledged[] = $uuid;
            $valid[UploadFields::key($uuid)] = [
                'uuid' => $uuid,
                'car' => $car,
                'ts_device_ms' => $deviceTimeMs,
                'clock_offset_ms' => $entry['clock_offset_ms'] ?? null,
                'typing_ms' => $entry['typing_ms'] ?? null,
                'mode' => $entry['mode'] ?? null,
            ];
        }

        $alreadyStored = $this->existingValues(
            'SELECT uuid_cliente FROM contagem_passagem WHERE fonte_id = ? AND uuid_cliente IN (%s)',
            [$this->fonteId],
            array_column($valid, 'uuid')
        );

        $insert = Propel::getWriteConnection('resultados')->prepare(
            'INSERT INTO contagem_passagem
                 (fonte_id, dispositivo_id, uuid_cliente, equipe_numero, ts_dispositivo_ms,
                  desvio_relogio_ms, ts_ms, digitacao_ms, modo_entrada, recebido_em_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $newCount = 0;
        foreach ($valid as $key => $entry) {
            if (isset($alreadyStored[$key])) {
                continue;
            }
            $offsetMs = UploadFields::intOrNull($entry['clock_offset_ms']);
            $typingMs = UploadFields::intOrNull($entry['typing_ms']);
            $mode = $entry['mode'];

            $insert->execute([
                $this->fonteId,
                $this->dispositivoId,
                $entry['uuid'],
                $entry['car'],
                $entry['ts_device_ms'],
                $offsetMs,
                // NULL when the device knew no offset yet. The time is then
                // worked out at read time from the device's first offset.
                ClockCorrection::correctedTime($entry['ts_device_ms'], $offsetMs),
                $typingMs === null ? null : max(0, min($typingMs, UploadFields::INT_MAX)),
                is_string($mode) && in_array($mode, self::MODES, true) ? $mode : self::UNKNOWN_MODE,
                $this->nowMs,
            ]);
            $newCount++;
        }

        return ['uuids' => $acknowledged, 'new' => $newCount];
    }

    /**
     * A counter pressing "desfazer" on one of their own entries. The entry
     * itself is kept; a decision of type "anular" is added.
     *
     * A counter can void an entry once. If a reviewer later reverses that
     * void, a stale resend of the same request must not void it again, so
     * any earlier counter void of the entry, undone or not, makes this a
     * no-op.
     *
     * @param array<int, mixed> $voids
     * @return array{uuids: string[], new: int}
     */
    public function storeVoids(array $voids): array
    {
        $uuidByKey = [];
        foreach ($voids as $void) {
            $uuid = UploadFields::uuidOf($void);
            if ($uuid !== null) {
                $uuidByKey[UploadFields::key($uuid)] = $uuid;
            }
        }

        // Only entries of this device's own source can be voided this way.
        $entryIdByKey = $this->existingValues(
            'SELECT uuid_cliente, passagem_id FROM contagem_passagem WHERE fonte_id = ? AND uuid_cliente IN (%s)',
            [$this->fonteId],
            array_values($uuidByKey)
        );
        if ($entryIdByKey === []) {
            return ['uuids' => [], 'new' => 0];
        }

        $alreadyVoided = $this->existingValues(
            'SELECT passagem_id FROM contagem_decisao WHERE tipo = ? AND origem = ? AND passagem_id IN (%s)',
            [Decision::ANULAR, Decision::ORIGEM_CONTADOR],
            array_values($entryIdByKey)
        );

        $insert = Propel::getWriteConnection('resultados')->prepare(
            'INSERT INTO contagem_decisao
                 (evento_id, prova_id, tipo, passagem_id, origem, usuario, criado_em_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $acknowledged = [];
        $newCount = 0;
        foreach ($uuidByKey as $key => $uuid) {
            $entryId = $entryIdByKey[$key] ?? null;
            if ($entryId === null) {
                // The entry has not reached us. The client sends entries
                // before their voids, so it will try this void again.
                continue;
            }
            $acknowledged[] = $uuid;
            if (isset($alreadyVoided[UploadFields::key((string) $entryId)])) {
                continue;
            }
            $insert->execute([
                $this->eventoId,
                $this->provaId,
                Decision::ANULAR,
                (int) $entryId,
                Decision::ORIGEM_CONTADOR,
                $this->usuario,
                $this->nowMs,
            ]);
            $newCount++;
        }

        return ['uuids' => $acknowledged, 'new' => $newCount];
    }

    /**
     * Pit-lane entries and exits from a pit-log device.
     *
     * @param array<int, mixed> $events
     * @param int|null $fallbackOffsetMs offset to use for an event sent without one
     * @return array{uuids: string[], new: int}
     */
    public function storePitEvents(array $events, ?int $fallbackOffsetMs): array
    {
        $acknowledged = [];
        $valid = [];
        foreach ($events as $event) {
            $uuid = UploadFields::uuidOf($event);
            if ($uuid === null) {
                continue;
            }
            $car = UploadFields::carOf($event);
            $deviceTimeMs = UploadFields::intOrNull($event['ts_device_ms'] ?? null);
            $tipo = $event['tipo'] ?? null;
            if ($car === null || $deviceTimeMs === null || $deviceTimeMs <= 0 || !in_array($tipo, Ocorrencia::TIPOS_BOX, true)) {
                continue;
            }
            // contagem_ocorrencia has no "time unknown" state, so an event
            // sent before the device had an offset gets the best one known.
            $offsetMs = UploadFields::intOrNull($event['clock_offset_ms'] ?? null) ?? $fallbackOffsetMs ?? 0;
            $acknowledged[] = $uuid;
            $valid[UploadFields::key($uuid)] = ['uuid' => $uuid, 'car' => $car, 'tipo' => $tipo, 'ts_ms' => $deviceTimeMs + $offsetMs];
        }

        $alreadyStored = $this->existingValues(
            'SELECT uuid_cliente FROM contagem_ocorrencia WHERE evento_id = ? AND prova_id = ? AND uuid_cliente IN (%s)',
            [$this->eventoId, $this->provaId],
            array_column($valid, 'uuid')
        );

        $insert = Propel::getWriteConnection('resultados')->prepare(
            'INSERT INTO contagem_ocorrencia
                 (evento_id, prova_id, tipo, equipe_numero, ts_ms, usuario, criado_em_ms, uuid_cliente)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $newCount = 0;
        foreach ($valid as $key => $event) {
            if (isset($alreadyStored[$key])) {
                continue;
            }
            $insert->execute([
                $this->eventoId,
                $this->provaId,
                $event['tipo'],
                $event['car'],
                $event['ts_ms'],
                $this->usuario,
                $this->nowMs,
                $event['uuid'],
            ]);
            $newCount++;
        }

        return ['uuids' => $acknowledged, 'new' => $newCount];
    }

    /**
     * Runs "SELECT key[, value] ... IN (%s)" for a list of values and
     * returns the rows found as key => value (or key => true for a single
     * column), with keys normalised by UploadFields::key().
     *
     * @param array<int, mixed> $fixedParams values for the placeholders before the IN list
     * @param array<int, mixed> $inValues
     * @return array<string, mixed>
     */
    private function existingValues(string $sqlWithInList, array $fixedParams, array $inValues): array
    {
        if ($inValues === []) {
            return [];
        }
        $found = [];
        // Chunked so a phone that was offline for an hour does not produce
        // one statement with thousands of placeholders.
        foreach (array_chunk($inValues, 500) as $chunk) {
            $sql = sprintf($sqlWithInList, implode(', ', array_fill(0, count($chunk), '?')));
            $statement = Propel::getWriteConnection('resultados')->prepare($sql);
            $statement->execute(array_merge($fixedParams, array_map('strval', $chunk)));
            foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
                $found[UploadFields::key((string) $row[0])] = $row[1] ?? true;
            }
        }
        return $found;
    }
}
