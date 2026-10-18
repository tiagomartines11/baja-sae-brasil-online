<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Settings;
use Propel\Runtime\Propel;

/**
 * Stores what one upload carried: entries, the counter's own voids and
 * fixes, pit lane and fueling events.
 *
 * Everything here is idempotent. A phone that never received our answer
 * sends the same batch again, possibly many times, and the second copy
 * must change nothing. The client-generated uuid is what makes that
 * possible: a uuid we already hold is acknowledged and otherwise ignored.
 *
 * TIME. The page works out the time of each entry itself, on the server's
 * clock, and sends it final; it is stored exactly as received and never
 * touched again. An entry is NEVER refused because of its time, however
 * absurd: a refused entry would sit in the phone's queue for ever and block
 * everything behind it. An odd time is stored and becomes the reviewer's
 * business (the engine flags it).
 *
 * UNREAD PASSES. An entry with `"car": null` is a pass whose number nobody
 * could read: a counter's "??", a camera that could not read the plate. It
 * is stored with no car (equipe_numero NULL), from a counting page and from
 * an automatic system alike; whether the page offers "??" at all is the
 * page's business. Every entry of a timing sensor (tipo "sensor") is stored
 * that way: a sensor marks the moment, never the car, so a car it sends
 * anyway is IGNORED, not refused (a refused entry would sit in the
 * system's queue for ever, and the moment is still good).
 *
 * Each method returns the uuids the client may now forget ("uuids") and
 * how many of them were new to us ("new"), the cars whose count it may
 * have changed ("cars") and the moments of the entries it touched
 * ("times"): an unread entry changes no car by itself, but may change the
 * cars around it (see Recount::forCars()).
 */
final class EntryWriter
{
    private const MODES = ['teclado', 'teclado_tela', 'blocos', 'api', self::MODE_SIMULATION];
    public const MODE_SIMULATION = 'simulacao';

    /** Stored when a client sends a mode we do not know. Dropping the entry would be worse. */
    private const UNKNOWN_MODE = 'desconhecido';

    private const TIME_QUALITIES = [Entry::TEMPO_EXATO, Entry::TEMPO_ESTIMADO, Entry::TEMPO_DESCONHECIDO];

    private int $fonteId;
    private int $dispositivoId;
    private string $eventoId;
    private string $provaId;
    private ?string $usuario;
    private int $nowMs;
    private bool $simulationAllowed;
    private bool $fuelingInUse;
    /** The device belongs to a timing sensor: its entries never carry a car. */
    private bool $isTimingSensor;

    /** @var array<int, array{uuid: string, motivo: string}> entries that will never be stored, with the reason */
    public array $refused = [];

    /**
     * @param Settings $settings the prova's settings as read INSIDE the count lock:
     *        whether simulated records and fueling events are accepted
     */
    public function __construct(
        int $fonteId,
        int $dispositivoId,
        string $eventoId,
        string $provaId,
        ?string $usuario,
        int $nowMs,
        Settings $settings,
        string $fonteTipo = Source::TIPO_MANUAL
    ) {
        $this->isTimingSensor = $fonteTipo === Source::TIPO_SENSOR;
        $this->fonteId = $fonteId;
        $this->dispositivoId = $dispositivoId;
        $this->eventoId = $eventoId;
        $this->provaId = $provaId;
        $this->usuario = $usuario;
        $this->nowMs = $nowMs;
        $this->simulationAllowed = $settings->permitirSimulacao;
        $this->fuelingInUse = $settings->abastecimento;
    }

    /**
     * @param array<int, mixed> $entries as decoded from the request
     * @return array{uuids: string[], new: int, cars: int[], times: int[]}
     */
    public function storeEntries(array $entries): array
    {
        $acknowledged = [];
        $valid = [];
        $cars = [];
        $times = [];
        foreach ($entries as $entry) {
            $uuid = UploadFields::uuidOf($entry);
            if ($uuid === null) {
                continue;
            }
            $car = $this->isTimingSensor ? null : UploadFields::carOf($entry);
            $isUnread = $car === null && ($this->isTimingSensor || UploadFields::isUnread($entry));
            $tsMs = UploadFields::intOrNull($entry['ts_ms'] ?? null);
            if (($car === null && !$isUnread) || $tsMs === null) {
                // Not an entry at all (no usable car nor "??", no time):
                // nothing to store. The client's own safety net marks it as
                // refused after a few answers that do not acknowledge it.
                continue;
            }
            if ($this->isForbiddenSimulation($entry)) {
                $this->refused[] = ['uuid' => $uuid, 'motivo' => 'simulacao_nao_permitida'];
                continue;
            }
            $acknowledged[] = $uuid;
            if ($car !== null) {
                $cars[$car] = true;
            }
            $stored = ['uuid' => $uuid, 'car' => $car, 'raw' => $entry] + self::storableTime($tsMs, $entry);
            $valid[UploadFields::key($uuid)] = $stored;
            $times[] = $stored['ts_ms'];
        }

        $alreadyStored = $this->existingValues(
            'SELECT uuid_cliente FROM contagem_passagem WHERE fonte_id = ? AND uuid_cliente IN (%s)',
            [$this->fonteId],
            array_column($valid, 'uuid')
        );

        $insert = Propel::getWriteConnection('resultados')->prepare(
            'INSERT INTO contagem_passagem
                 (fonte_id, dispositivo_id, uuid_cliente, equipe_numero, ts_ms, qualidade_tempo, ts_dispositivo_ms,
                  ancora_servidor_ms, ancora_rtt_ms, ancora_idade_ms, digitacao_ms, modo_entrada, recebido_em_ms,
                  imagem_ref, confianca)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $newCount = 0;
        foreach ($valid as $key => $entry) {
            if (isset($alreadyStored[$key])) {
                continue;
            }
            $raw = $entry['raw'];
            $anchor = is_array($raw['anchor'] ?? null) ? $raw['anchor'] : [];
            $typingMs = UploadFields::intOrNull($raw['typing_ms'] ?? null);
            $mode = $raw['mode'] ?? null;
            // An automatic system may say which picture an entry came from
            // and how sure it was. Shown to reviewers, never computed with;
            // a browser's count page never sends them.
            $fromSystem = $mode === 'api';

            $insert->execute([
                $this->fonteId,
                $this->dispositivoId,
                $entry['uuid'],
                $entry['car'],
                $entry['ts_ms'],
                $entry['quality'],
                // For audit only: the device's own clock and the anchor the
                // page derived ts_ms from. Nothing computes with them.
                $entry['ts_device_ms'],
                UploadFields::intOrNull($anchor['server_ms'] ?? null),
                self::boundedInt($anchor['rtt_ms'] ?? null),
                UploadFields::intOrNull($anchor['age_ms'] ?? null),
                self::boundedInt($typingMs),
                is_string($mode) && in_array($mode, self::MODES, true) ? $mode : self::UNKNOWN_MODE,
                $this->nowMs,
                $fromSystem ? UploadFields::imageRefOf($raw) : null,
                $fromSystem ? UploadFields::confidenceOf($raw) : null,
            ]);
            $newCount++;
        }

        // The cars of ALL acknowledged entries, new or not. If a previous
        // attempt stored the entries and then failed before publishing, the
        // phone sends them again; they are no longer new, but their cars
        // still have to be recounted.
        return ['uuids' => $acknowledged, 'new' => $newCount, 'cars' => array_keys($cars), 'times' => $times];
    }

    /**
     * A counter pressing "desfazer" on one of their own entries. The entry
     * itself is kept; a decision of type "anular" is added.
     *
     * Nothing is added when the entry is already voided by anybody (a
     * second void would survive the undo of the first), nor when this
     * counter voided it before, even if a reviewer has since reversed that:
     * a stale resend must not void it again.
     *
     * @param array<int, mixed> $voids
     * @return array{uuids: string[], new: int, cars: int[], times: int[]}
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
            return ['uuids' => [], 'new' => 0, 'cars' => [], 'times' => []];
        }

        $voidedByThisCounterBefore = $this->existingValues(
            'SELECT passagem_id FROM contagem_decisao WHERE tipo = ? AND origem = ? AND passagem_id IN (%s)',
            [Decision::ANULAR, Decision::ORIGEM_CONTADOR],
            array_values($entryIdByKey)
        );
        $voidInForce = $this->existingValues(
            'SELECT d.passagem_id FROM contagem_decisao d
              WHERE d.tipo = ?
                AND NOT EXISTS (SELECT 1 FROM contagem_decisao u WHERE u.tipo = ? AND u.desfaz_decisao_id = d.decisao_id)
                AND d.passagem_id IN (%s)',
            [Decision::ANULAR, Decision::DESFAZER],
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
            $entryKey = UploadFields::key((string) $entryId);
            if (isset($voidedByThisCounterBefore[$entryKey]) || isset($voidInForce[$entryKey])) {
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

        return [
            'uuids' => $acknowledged,
            'new' => $newCount,
            'cars' => $this->carsOfEntries(array_values($entryIdByKey)),
            'times' => $this->timesOfEntries(array_values($entryIdByKey)),
        ];
    }

    /**
     * A counter saying, on the phone, which car one of their own "??" was
     * ("era o 17"). Stored as a "reatribuir" decision with the counter as
     * its origin, like a void from the phone; from then on the entry is an
     * ordinary entry of that car.
     *
     * Only an entry of this device's own source that was stored with no car
     * can be fixed this way. Any other is acknowledged (so the phone stops
     * sending it) and listed in `refused` (the answer's `fixes_recusados`),
     * with nothing stored: an entry stored with a car, one that is voided,
     * one a reviewer reassigned (the reviewer's word stands), any entry of a
     * timing sensor, and a car that is not a number. So is a fix whose
     * entry travelled in the same request and was refused (`refused`): it
     * will never be stored. A fix for an entry that has not reached us is
     * not acknowledged: the page sends entries before their fixes, so it
     * will try again.
     *
     * Resending is harmless: nothing is added when this counter's NEWEST
     * fix of the entry already names the same car, even if a reviewer undid
     * it since (a stale resend must not bring it back). A fix to another
     * car is stored, and the newest one counts: fixed to 17, then to 71,
     * then back to 17, the entry is car 17 again.
     *
     * @param array<int, mixed> $fixes
     * @return array{uuids: string[], refused: string[], new: int, cars: int[], times: int[]}
     */
    public function storeFixes(array $fixes): array
    {
        $fixByKey = [];
        foreach ($fixes as $fix) {
            $uuid = UploadFields::uuidOf($fix);
            if ($uuid !== null) {
                // Of two fixes of one entry in a request, the later one counts.
                $fixByKey[UploadFields::key($uuid)] = ['uuid' => $uuid, 'car' => UploadFields::carOf($fix)];
            }
        }
        $done = ['uuids' => [], 'refused' => [], 'new' => 0, 'cars' => [], 'times' => []];
        if ($fixByKey === []) {
            return $done;
        }

        $entries = [];
        foreach (array_chunk(array_column($fixByKey, 'uuid'), 500) as $chunk) {
            $statement = Propel::getWriteConnection('resultados')->prepare(
                'SELECT uuid_cliente, passagem_id, equipe_numero, ts_ms FROM contagem_passagem
                  WHERE fonte_id = ? AND uuid_cliente IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')'
            );
            $statement->execute(array_merge([$this->fonteId], $chunk));
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $entries[UploadFields::key((string) $row['uuid_cliente'])] = $row;
            }
        }
        $decisions = $this->decisionsOn(array_map(fn(array $row): int => (int) $row['passagem_id'], array_values($entries)));

        $insert = Propel::getWriteConnection('resultados')->prepare(
            'INSERT INTO contagem_decisao
                 (evento_id, prova_id, tipo, passagem_id, equipe_numero, origem, usuario, criado_em_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $refusedEntries = [];
        foreach ($this->refused as $refused) {
            $refusedEntries[UploadFields::key($refused['uuid'])] = true;
        }
        $cars = [];
        foreach ($fixByKey as $key => $fix) {
            $entry = $entries[$key] ?? null;
            if ($entry === null) {
                if (isset($refusedEntries[$key])) {
                    // Its entry travelled with it and will never be stored.
                    $done['uuids'][] = $fix['uuid'];
                    $done['refused'][] = $fix['uuid'];
                }
                continue;
            }
            $done['uuids'][] = $fix['uuid'];
            $entryId = (int) $entry['passagem_id'];
            $onEntry = $decisions[$entryId] ?? ['anulada' => false, 'pelo_revisor' => false, 'pelo_contador' => []];
            $car = $fix['car'];
            if ($car === null || $entry['equipe_numero'] !== null || $this->isTimingSensor || $onEntry['anulada'] || $onEntry['pelo_revisor']) {
                $done['refused'][] = $fix['uuid'];
                continue;
            }
            $done['times'][] = (int) $entry['ts_ms'];
            $cars[$car] = true;
            foreach ($onEntry['pelo_contador'] as $earlierCar) {
                // The car it counted for until now.
                $cars[$earlierCar] = true;
            }
            if ($car === ($onEntry['pelo_contador'] === [] ? null : $onEntry['pelo_contador'][count($onEntry['pelo_contador']) - 1])) {
                // The counter's newest fix already says so.
                continue;
            }
            $insert->execute([
                $this->eventoId,
                $this->provaId,
                Decision::REATRIBUIR,
                $entryId,
                $car,
                Decision::ORIGEM_CONTADOR,
                $this->usuario,
                $this->nowMs,
            ]);
            $done['new']++;
        }
        $done['cars'] = array_keys($cars);
        return $done;
    }

    /**
     * What has been decided about some entries: whether a void is in force,
     * whether a reviewer's reassignment is, and every car this counter ever
     * fixed each one to, oldest first.
     *
     * @param int[] $entryIds
     * @return array<int, array{anulada: bool, pelo_revisor: bool, pelo_contador: int[]}>
     */
    private function decisionsOn(array $entryIds): array
    {
        $found = [];
        foreach (array_chunk($entryIds, 500) as $chunk) {
            $statement = Propel::getWriteConnection('resultados')->prepare(
                'SELECT d.passagem_id, d.tipo, d.origem, d.equipe_numero,
                        EXISTS (SELECT 1 FROM contagem_decisao u WHERE u.tipo = ? AND u.desfaz_decisao_id = d.decisao_id) AS desfeita
                   FROM contagem_decisao d
                  WHERE d.tipo IN (?, ?) AND d.passagem_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')
               ORDER BY d.decisao_id'
            );
            $statement->execute(array_merge([Decision::DESFAZER, Decision::ANULAR, Decision::REATRIBUIR], $chunk));
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $entryId = (int) $row['passagem_id'];
                $found[$entryId] ??= ['anulada' => false, 'pelo_revisor' => false, 'pelo_contador' => []];
                $inForce = !(bool) $row['desfeita'];
                if ($row['tipo'] === Decision::ANULAR) {
                    $found[$entryId]['anulada'] = $found[$entryId]['anulada'] || $inForce;
                } elseif ($row['origem'] === Decision::ORIGEM_CONTADOR) {
                    $found[$entryId]['pelo_contador'][] = (int) $row['equipe_numero'];
                } elseif ($inForce) {
                    $found[$entryId]['pelo_revisor'] = true;
                }
            }
        }
        return $found;
    }

    /**
     * Entries and exits of the pit lane and of the fueling station, from a
     * pit-log device.
     *
     * @param array<int, mixed> $events
     * @return array{uuids: string[], new: int, cars: int[]}
     */
    public function storeStopEvents(array $events): array
    {
        $acknowledged = [];
        $valid = [];
        foreach ($events as $event) {
            $uuid = UploadFields::uuidOf($event);
            if ($uuid === null) {
                continue;
            }
            $car = UploadFields::carOf($event);
            $tsMs = UploadFields::intOrNull($event['ts_ms'] ?? null);
            $tipo = $event['tipo'] ?? null;
            if ($car === null || $tsMs === null || !in_array($tipo, Ocorrencia::TIPOS_DE_PARADA, true)) {
                continue;
            }
            if ($this->isForbiddenSimulation($event)) {
                $this->refused[] = ['uuid' => $uuid, 'motivo' => 'simulacao_nao_permitida'];
                continue;
            }
            // A phone whose page was opened before the fueling station was
            // switched off may still offer that area. Stored, its events
            // would change car statuses and lap times of a race that is
            // not supposed to have a fueling station at all.
            if (in_array($tipo, Ocorrencia::TIPOS_ABASTECIMENTO, true) && !$this->fuelingInUse) {
                $this->refused[] = ['uuid' => $uuid, 'motivo' => 'abastecimento_desligado'];
                continue;
            }
            $acknowledged[] = $uuid;
            $valid[UploadFields::key($uuid)] = ['uuid' => $uuid, 'car' => $car, 'tipo' => $tipo, 'raw' => $event] + self::storableTime($tsMs, $event);
        }

        $alreadyStored = $this->existingValues(
            'SELECT uuid_cliente FROM contagem_ocorrencia WHERE evento_id = ? AND prova_id = ? AND uuid_cliente IN (%s)',
            [$this->eventoId, $this->provaId],
            array_column($valid, 'uuid')
        );

        $insert = Propel::getWriteConnection('resultados')->prepare(
            'INSERT INTO contagem_ocorrencia
                 (evento_id, prova_id, tipo, equipe_numero, ts_ms, usuario, criado_em_ms, uuid_cliente,
                  qualidade_tempo, ts_dispositivo_ms, modo_entrada)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $newCount = 0;
        foreach ($valid as $key => $event) {
            if (isset($alreadyStored[$key])) {
                continue;
            }
            $mode = $event['raw']['mode'] ?? null;
            $insert->execute([
                $this->eventoId,
                $this->provaId,
                $event['tipo'],
                $event['car'],
                $event['ts_ms'],
                $this->usuario,
                $this->nowMs,
                $event['uuid'],
                $event['quality'],
                $event['ts_device_ms'],
                is_string($mode) && preg_match('/^[a-z_]{1,16}$/', $mode) === 1 ? $mode : null,
            ]);
            $newCount++;
        }

        // The cars of every acknowledged event, new or not (see the same
        // remark in storeEntries()). A car's count depends on its stops: a
        // pass while it is logged inside an area is held, and counts once
        // its exit is recorded. So these cars must be recounted.
        return ['uuids' => $acknowledged, 'new' => $newCount, 'cars' => array_values(array_unique(array_column($valid, 'car')))];
    }

    /**
     * The three time columns of a record, from what the client sent.
     *
     * A time that cannot be computed with (negative, or so large that
     * arithmetic on it overflows; see UploadFields::TIME_LIMIT_MS) is still
     * not a reason to refuse the record. It is stored with the nearest
     * usable time, marked as of unknown time (so it is never counted or
     * paired automatically), and the value the client sent is kept beside
     * it for whoever investigates.
     *
     * @param array<string, mixed> $record the element of the request
     * @return array{ts_ms: int, quality: string, ts_device_ms: int}
     */
    private static function storableTime(int $tsMs, array $record): array
    {
        if (!UploadFields::isUsableTime($tsMs)) {
            return [
                'ts_ms' => UploadFields::clampTime($tsMs),
                'quality' => Entry::TEMPO_DESCONHECIDO,
                'ts_device_ms' => $tsMs,
            ];
        }
        return [
            'ts_ms' => $tsMs,
            'quality' => self::timeQualityOf($record),
            'ts_device_ms' => UploadFields::intOrNull($record['ts_device_ms'] ?? null) ?? $tsMs,
        ];
    }

    /**
     * Simulated entries are only accepted while the prova allows simulation,
     * so that a rehearsal script left running can never write into a race.
     *
     * @param array<string, mixed> $entry
     */
    private function isForbiddenSimulation(array $entry): bool
    {
        return ($entry['mode'] ?? null) === self::MODE_SIMULATION && !$this->simulationAllowed;
    }

    /** @param array<string, mixed> $entry */
    private static function timeQualityOf(array $entry): string
    {
        $quality = $entry['time_quality'] ?? null;
        // A client that does not say is one that cannot vouch for its time.
        return is_string($quality) && in_array($quality, self::TIME_QUALITIES, true) ? $quality : Entry::TEMPO_DESCONHECIDO;
    }

    /** A value for a signed 32-bit INT column, or null. */
    private static function boundedInt($value): ?int
    {
        $number = UploadFields::intOrNull($value);
        return $number === null ? null : max(0, min($number, UploadFields::INT_MAX));
    }

    /**
     * The cars whose count a change to these entries can affect: the number
     * each was typed with, and the car a reviewer may have reassigned it to.
     *
     * @param array<int, mixed> $entryIds
     * @return int[]
     */
    private function carsOfEntries(array $entryIds): array
    {
        $typed = $this->existingValues(
            'SELECT equipe_numero FROM contagem_passagem WHERE fonte_id = ? AND equipe_numero IS NOT NULL AND passagem_id IN (%s)',
            [$this->fonteId],
            $entryIds
        );
        $reassigned = $this->existingValues(
            'SELECT equipe_numero FROM contagem_decisao WHERE tipo = ? AND equipe_numero IS NOT NULL AND passagem_id IN (%s)',
            [Decision::REATRIBUIR],
            $entryIds
        );
        $cars = [];
        foreach (array_keys($typed + $reassigned) as $key) {
            // existingValues() prefixes its keys with one letter.
            $cars[] = (int) substr($key, 1);
        }
        return $cars;
    }

    /**
     * The moments of some of this source's entries.
     *
     * @param array<int, mixed> $entryIds
     * @return int[]
     */
    private function timesOfEntries(array $entryIds): array
    {
        $times = $this->existingValues(
            'SELECT passagem_id, ts_ms FROM contagem_passagem WHERE fonte_id = ? AND passagem_id IN (%s)',
            [$this->fonteId],
            $entryIds
        );
        return array_map('intval', array_values($times));
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
