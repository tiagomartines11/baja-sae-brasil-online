<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Model\ContagemDispositivo;
use Baja\Model\ContagemFonte;
use Baja\Model\ContagemFonteQuery;
use Baja\Model\ProvaQuery;
use Propel\Runtime\Propel;

/**
 * The upload endpoint, without the HTTP: juiz/contagem_sync.php hands over
 * the Authorization header and the request body and sends back whatever
 * this returns. Keeping HTTP out makes every rule here testable by calling
 * handle() directly.
 *
 * Count pages, pit-log pages and external automated sources all talk to
 * this one endpoint. It never looks at the forum session: the token in the
 * header is the only credential, so an upload keeps working after the
 * counter's login has expired.
 */
final class SyncHandler
{
    /** A device heard from within this long is "counting right now" for the other devices of its source. */
    private const PRESENCE_WINDOW_MS = 15000;

    /**
     * @param string|null $authorizationHeader the raw header value ("Bearer ...")
     * @param string $rawBody the request body, JSON
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function handle(?string $authorizationHeader, string $rawBody): array
    {
        $token = self::bearerToken($authorizationHeader);
        if ($token === null) {
            return self::error(401, 'token_ausente');
        }

        // Unknown and revoked tokens get the same answer on purpose: the
        // reply must not tell a guesser which tokens once existed.
        $device = DeviceTokens::findActiveByToken($token);
        if ($device === null) {
            return self::error(401, 'token_invalido');
        }

        $request = json_decode($rawBody, true);
        if (!is_array($request)) {
            return self::error(400, 'json_invalido');
        }

        $fonte = ContagemFonteQuery::create()->findPk($device->getFonteId());
        $prova = $fonte === null ? null : ProvaQuery::create()->findPk([$fonte->getEventoId(), $fonte->getProvaId()]);
        if ($fonte === null || $prova === null) {
            return self::error(401, 'token_invalido');
        }

        $service = ContagemService::forProva($prova);
        $nowMs = Clock::nowMs();

        // Storing and recomputing happen under one lock so that the engine
        // never runs on half of an upload, and two uploads never interleave.
        $stored = [];
        $result = $service->withLock(function () use ($service, $device, $fonte, $request, $nowMs, &$stored): EngineResult {
            $stored = self::store($device, $fonte, $request, $nowMs);
            return $service->recomputeAfterSync($stored['new'] > 0);
        });

        $isBoxDevice = $fonte->getTipo() === Source::TIPO_BOX;

        return [
            'status' => 200,
            'body' => [
                'stored_uuids' => $stored['stored_uuids'],
                'voided_uuids' => $stored['voided_uuids'],
                'server_now_ms' => Clock::nowMs(),
                'race' => [
                    'largada_ms' => $result->timeline->largadaMs,
                    'encerramento_ms' => $result->timeline->encerramentoMs,
                    'estado' => $result->timeline->stateAt($nowMs),
                ],
                'fonte' => [
                    'id' => (int) $fonte->getFonteId(),
                    'nome' => (string) $fonte->getNome(),
                    'tipo' => (string) $fonte->getTipo(),
                ],
                'valid_cars' => self::validCars($service),
                'janela_ms' => $service->getSettings()->janelaMs,
                'outros_usuarios' => self::otherUsersCounting($device, $nowMs),
                'cars_in_pit' => $isBoxDevice ? $result->timeline->carsInPitAt($nowMs) : [],
            ],
        ];
    }

    /**
     * Updates the device row and stores what the upload carried.
     *
     * @param array<string, mixed> $request
     * @return array{stored_uuids: string[], voided_uuids: string[], new: int}
     */
    private static function store(ContagemDispositivo $device, ContagemFonte $fonte, array $request, int $nowMs): array
    {
        $entries = self::listOf($request['entries'] ?? null);
        $voids = self::listOf($request['voids'] ?? null);
        $pitEvents = self::listOf($request['pit_events'] ?? null);

        $requestOffsetMs = UploadFields::intOrNull($request['clock_offset_ms'] ?? null);
        $entryOffsetsMs = [];
        foreach (array_merge($entries, $pitEvents) as $item) {
            $entryOffsetsMs[] = is_array($item) ? UploadFields::intOrNull($item['clock_offset_ms'] ?? null) : null;
        }
        $firstOffsetMs = ClockCorrection::firstOffset(
            UploadFields::intOrNull($device->getPrimeiroDesvioMs()),
            $requestOffsetMs,
            $entryOffsetsMs
        );

        $writer = new EntryWriter(
            (int) $fonte->getFonteId(),
            (int) $device->getDispositivoId(),
            (string) $fonte->getEventoId(),
            (string) $fonte->getProvaId(),
            $device->getUsuario(),
            $nowMs
        );

        $storedUuids = [];
        $voidedUuids = [];
        $newCount = 0;

        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            // Which lists are accepted depends on what the device is bound
            // to: a pit-log phone cannot add passes, a counter cannot log
            // pit stops. Anything else in the request is ignored.
            if ($fonte->getTipo() === Source::TIPO_BOX) {
                $written = $writer->storePitEvents($pitEvents, $requestOffsetMs ?? $firstOffsetMs);
                $storedUuids = $written['uuids'];
                $newCount += $written['new'];
            } elseif (in_array($fonte->getTipo(), [Source::TIPO_MANUAL, Source::TIPO_AUTOMATICA], true)) {
                $written = $writer->storeEntries($entries);
                $storedUuids = $written['uuids'];
                $newCount += $written['new'];

                $written = $writer->storeVoids($voids);
                $voidedUuids = $written['uuids'];
                $newCount += $written['new'];
            }

            $device->setPrimeiroDesvioMs($firstOffsetMs);
            $device->setVistoEmMs($nowMs);
            $device->setSincronizadoAteMs(ClockCorrection::syncedThrough(
                UploadFields::intOrNull($device->getSincronizadoAteMs()),
                ($request['queue_empty'] ?? false) === true,
                UploadFields::intOrNull($request['device_now_ms'] ?? null),
                $requestOffsetMs ?? $firstOffsetMs,
                $nowMs
            ));
            $device->save();

            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }

        return ['stored_uuids' => $storedUuids, 'voided_uuids' => $voidedUuids, 'new' => $newCount];
    }

    /**
     * Cars a counter may legitimately type, with the team name the count
     * page flashes after each entry.
     *
     * @return array<int, array{numero: int, nome: string}>
     */
    private static function validCars(ContagemService $service): array
    {
        $cars = [];
        foreach ($service->loadTeams(true) as $team) {
            $cars[] = ['numero' => $team['numero'], 'nome' => $team['nome']];
        }
        return $cars;
    }

    /**
     * Usernames of the other people counting on the same source right now.
     * Two people on one source is normal during a handover, and each should
     * see that the other is there.
     *
     * @return string[]
     */
    private static function otherUsersCounting(ContagemDispositivo $device, int $nowMs): array
    {
        $statement = Propel::getReadConnection('resultados')->prepare(
            'SELECT DISTINCT usuario
               FROM contagem_dispositivo
              WHERE fonte_id = :fonte
                AND dispositivo_id <> :este
                AND revogado_em_ms IS NULL
                AND usuario IS NOT NULL
                AND visto_em_ms >= :desde
           ORDER BY usuario'
        );
        $statement->execute([
            ':fonte' => $device->getFonteId(),
            ':este' => $device->getDispositivoId(),
            ':desde' => $nowMs - self::PRESENCE_WINDOW_MS,
        ]);

        $others = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $usuario) {
            // The same person with two tabs open is not "someone else".
            if (strcasecmp((string) $usuario, (string) $device->getUsuario()) !== 0) {
                $others[] = (string) $usuario;
            }
        }
        return $others;
    }

    private static function bearerToken(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null) {
            return null;
        }
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorizationHeader, $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    /**
     * @param mixed $value
     * @return array<int, mixed> the value if it is a JSON list, otherwise an empty list
     */
    private static function listOf($value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @return array{status: int, body: array<string, string>} */
    private static function error(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['erro' => $code]];
    }
}
