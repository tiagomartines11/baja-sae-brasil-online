<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\ChangeCounter;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\LockBusy;
use Baja\Contagem\Service\ProvaLock;
use Baja\Contagem\Service\RaceContext;
use Baja\Model\ProvaQuery;
use Propel\Runtime\Propel;

/**
 * The upload endpoint, without the HTTP: juiz/contagem_sync.php hands over
 * the Authorization header and the request body and sends back whatever
 * this returns. Keeping HTTP out makes every rule here testable by calling
 * handle() directly.
 *
 * Count pages, pit-log pages and external automated sources all talk to
 * this one endpoint. It never looks at the forum session: the token is the
 * only credential, so an upload keeps working after the counter's login has
 * expired.
 *
 * Two very different kinds of request arrive here, and they are kept apart:
 *
 * A HEARTBEAT carries nothing to store. Every phone sends one every five
 * seconds, so over a race they are almost all the traffic. A heartbeat
 * costs one SELECT (the token) and one UPDATE (the device row). It takes no
 * lock and counts nothing.
 *
 * AN UPLOAD carries entries, voids or pit events. It takes the count lock,
 * stores them, recounts only the cars it touched and publishes them, all
 * before answering: when the phone hears "stored", the results page is
 * already right.
 */
final class SyncHandler
{
    /**
     * @param string|null $authorizationHeader the raw header value ("Bearer ...")
     * @param string $rawBody the request body, JSON
     * @param int|null $receivedAtMs when the request reached the server; the clock is read when left out
     * @return array{status: int, body: array<string, mixed>, after?: callable} "after" is work to do once the answer has been sent
     */
    public static function handle(?string $authorizationHeader, string $rawBody, ?int $receivedAtMs = null): array
    {
        // The time is taken on entry, not after the work: the phone works
        // out its clock offset from this value and the round trip, and time
        // spent processing here would look like network delay one way only.
        $nowMs = $receivedAtMs ?? Clock::nowMs();

        $request = json_decode($rawBody, true);
        $token = self::bearerToken($authorizationHeader) ?? self::tokenOfClosingBeacon($request);
        if ($token === null) {
            return self::error(401, 'token_ausente');
        }

        // Unknown and revoked tokens get the same answer on purpose: the
        // reply must not tell a guesser which tokens once existed.
        $device = DeviceTokens::describeByToken($token);
        if ($device === null || $device['revogado_em_ms'] !== null) {
            return self::error(401, 'token_invalido');
        }
        if (!is_array($request)) {
            return self::error(400, 'json_invalido');
        }

        $upload = new UploadContents($request);
        $heartbeat = new DeviceHeartbeat($device, $upload, $nowMs);

        if (!$upload->carriesData()) {
            $heartbeat->save();
            if ($heartbeat->changedWhatIsKnown()) {
                (new ChangeCounter($device['evento_id'], $device['prova_id']))->markChanged();
            }
            return self::answer($device, $nowMs, [], []);
        }

        return self::storeAndCount($device, $upload, $heartbeat, $token, $nowMs);
    }

    /**
     * @param array<string, mixed> $device
     * @return array{status: int, body: array<string, mixed>, after?: callable}
     */
    private static function storeAndCount(array $device, UploadContents $upload, DeviceHeartbeat $heartbeat, string $token, int $nowMs): array
    {
        $prova = ProvaQuery::create()->findPk([$device['evento_id'], $device['prova_id']]);
        if ($prova === null) {
            return self::error(401, 'token_invalido');
        }
        $service = ContagemService::forProva($prova);

        try {
            $stored = $service->withLock(function () use ($service, $device, $upload, $heartbeat, $token, $nowMs): ?array {
                // Looked up again now that we hold the lock: a controller may
                // have revoked this device while we were waiting for it, and
                // a revoked device must not add entries.
                $current = DeviceTokens::describeByToken($token);
                if ($current === null || $current['revogado_em_ms'] !== null) {
                    return null;
                }
                $stored = self::store($current, $upload, $heartbeat, $nowMs);
                if ($stored['pit_events_stored']) {
                    $service->markChanged(true);
                }
                $service->recountCars($stored['cars']);
                return $stored;
            }, ProvaLock::WAIT_FOR_AN_UPLOAD_SECONDS);
        } catch (LockBusy $busy) {
            // Nothing was stored. The phone keeps its queue and tries again
            // in a few seconds, which costs less than a worker held waiting.
            return self::error(503, 'ocupado');
        }
        if ($stored === null) {
            return self::error(401, 'token_invalido');
        }

        $answer = self::answer($device, $nowMs, $stored['stored_uuids'], $stored['voided_uuids']);
        // Flags, lap times and best laps need the whole race. That is done
        // after the phone has its answer, and only if it is due.
        $answer['after'] = fn() => $service->refreshSnapshotIfStale();
        return $answer;
    }

    /**
     * Stores what the upload carried and updates the device row, in one
     * transaction.
     *
     * @param array<string, mixed> $device
     * @return array{stored_uuids: string[], voided_uuids: string[], cars: int[], pit_events_stored: bool}
     */
    private static function store(array $device, UploadContents $upload, DeviceHeartbeat $heartbeat, int $nowMs): array
    {
        $writer = new EntryWriter(
            $device['fonte_id'],
            $device['dispositivo_id'],
            $device['evento_id'],
            $device['prova_id'],
            $device['usuario'],
            $nowMs
        );
        $result = ['stored_uuids' => [], 'voided_uuids' => [], 'cars' => [], 'pit_events_stored' => false];

        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            // Which lists are accepted depends on what the device is bound
            // to: a pit-log phone cannot add passes, a counter cannot log
            // pit stops. Anything else in the request is ignored.
            if ($device['fonte_tipo'] === Source::TIPO_BOX) {
                $written = $writer->storePitEvents($upload->pitEvents, $heartbeat->bestKnownOffsetMs());
                $result['stored_uuids'] = $written['uuids'];
                $result['pit_events_stored'] = $written['new'] > 0;
            } elseif (in_array($device['fonte_tipo'], [Source::TIPO_MANUAL, Source::TIPO_AUTOMATICA], true)) {
                $entries = $writer->storeEntries($upload->entries);
                $voids = $writer->storeVoids($upload->voids);
                $result['stored_uuids'] = $entries['uuids'];
                $result['voided_uuids'] = $voids['uuids'];
                $result['cars'] = array_values(array_unique(array_merge($entries['cars'], $voids['cars'])));
            }
            $heartbeat->save();
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $device
     * @param string[] $storedUuids
     * @param string[] $voidedUuids
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function answer(array $device, int $nowMs, array $storedUuids, array $voidedUuids): array
    {
        $context = (new RaceContext($device['evento_id'], $device['prova_id']))->get();
        $timeline = RaceContext::timelineOf($context);
        $isBoxDevice = $device['fonte_tipo'] === Source::TIPO_BOX;

        return [
            'status' => 200,
            'body' => [
                'stored_uuids' => $storedUuids,
                'voided_uuids' => $voidedUuids,
                'server_now_ms' => $nowMs,
                'race' => [
                    'largada_ms' => $timeline->largadaMs,
                    'encerramento_ms' => $timeline->encerramentoMs,
                    'estado' => $timeline->stateAt($nowMs),
                ],
                'fonte' => [
                    'id' => $device['fonte_id'],
                    'nome' => $device['fonte_nome'],
                    'tipo' => $device['fonte_tipo'],
                ],
                'valid_cars' => $context['valid_cars'] ?? [],
                'janela_ms' => $context['janela_ms'] ?? null,
                'outros_usuarios' => SourcePresence::noteAndListOthers($device['fonte_id'], $device['dispositivo_id'], $device['usuario'], $nowMs),
                'cars_in_pit' => $isBoxDevice ? $timeline->carsInPitAt($nowMs) : [],
            ],
        ];
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
     * A page that is being closed says goodbye with navigator.sendBeacon,
     * which cannot set an Authorization header. For that one kind of
     * request, "this device is done", the token is accepted in the body.
     *
     * @param mixed $request
     */
    private static function tokenOfClosingBeacon($request): ?string
    {
        if (!is_array($request) || ($request['encerrando'] ?? false) !== true) {
            return null;
        }
        $token = $request['token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @return array{status: int, body: array<string, string>} */
    private static function error(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['erro' => $code]];
    }
}
