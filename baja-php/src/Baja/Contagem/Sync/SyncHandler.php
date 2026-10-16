<?php
declare(strict_types=1);

namespace Baja\Contagem\Sync;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Service\ChangeCounter;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\LockBusy;
use Baja\Contagem\Service\ProvaLock;
use Baja\Contagem\Service\RaceContext;
use Baja\Contagem\Service\SnapshotStore;
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
 *
 * Either kind may, AFTER its answer has been sent, start the whole-race
 * recalculation if it is due. That keeps flags and best laps fresh even
 * when nobody has the review screen open.
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
        // The time is taken on entry, not after the work: the phone anchors
        // its clock to this value and the round trip, and time spent
        // processing here would look like network delay one way only.
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
            if ($heartbeat->changedWhatTheEngineSees()) {
                (new ChangeCounter($device['evento_id'], $device['prova_id']))->markChanged();
            }
            $answer = self::answer($device, $nowMs, $heartbeat, [], [], []);
            $answer['after'] = fn() => self::recalculateIfDue($device['evento_id'], $device['prova_id']);
            return $answer;
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
            $stored = $service->withLock(function () use ($service, $prova, $device, $upload, $heartbeat, $token, $nowMs): ?array {
                // Everything read before this point may be out of date: we
                // may have waited for the lock while a controller revoked
                // this device or switched publishing off. Both are read
                // again now that nobody else can change the race.
                $current = DeviceTokens::describeByToken($token);
                if ($current === null || $current['revogado_em_ms'] !== null) {
                    return null;
                }
                $prova->reload();

                $stored = self::store($current, $upload, $heartbeat, $nowMs, $service->getSettings());
                if ($stored['stop_events_stored']) {
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

        $answer = self::answer($device, $nowMs, $heartbeat, $stored['stored_uuids'], $stored['voided_uuids'], $stored['refused']);
        $answer['after'] = fn() => $service->refreshSnapshotIfStale();
        return $answer;
    }

    /**
     * Stores what the upload carried and updates the device row, in one
     * transaction.
     *
     * @param array<string, mixed> $device
     * @return array{stored_uuids: string[], voided_uuids: string[], cars: int[], stop_events_stored: bool, refused: array}
     */
    private static function store(array $device, UploadContents $upload, DeviceHeartbeat $heartbeat, int $nowMs, Settings $settings): array
    {
        $writer = new EntryWriter(
            $device['fonte_id'],
            $device['dispositivo_id'],
            $device['evento_id'],
            $device['prova_id'],
            $device['usuario'],
            $nowMs,
            $settings
        );
        $result = ['stored_uuids' => [], 'voided_uuids' => [], 'cars' => [], 'stop_events_stored' => false, 'refused' => []];

        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            // Which lists are accepted depends on what the device is bound
            // to: a pit-log phone cannot add passes, a counter cannot log
            // pit stops. Anything else in the request is ignored.
            if ($device['fonte_tipo'] === Source::TIPO_BOX) {
                $written = $writer->storeStopEvents($upload->pitEvents);
                $result['stored_uuids'] = $written['uuids'];
                $result['stop_events_stored'] = $written['new'] > 0;
                // A stop changes which passes of that car are held.
                $result['cars'] = $written['cars'];
            } elseif (in_array($device['fonte_tipo'], [Source::TIPO_MANUAL, Source::TIPO_AUTOMATICA], true)) {
                $entries = $writer->storeEntries($upload->entries);
                $voids = $writer->storeVoids($upload->voids);
                $result['stored_uuids'] = $entries['uuids'];
                $result['voided_uuids'] = $voids['uuids'];
                $result['cars'] = array_values(array_unique(array_merge($entries['cars'], $voids['cars'])));
            }
            $result['refused'] = $writer->refused;
            $heartbeat->save();
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
        return $result;
    }

    /**
     * The whole-race recalculation, when a heartbeat finds it due. Checked
     * first against a few bytes in Redis, so that the five-second heartbeat
     * of every phone does not load the prova, let alone the snapshot.
     */
    private static function recalculateIfDue(string $eventoId, string $provaId): void
    {
        $changes = (new ChangeCounter($eventoId, $provaId))->current();
        if (!SnapshotStore::mayBeStale($eventoId, $provaId, $changes, Clock::nowMs())) {
            return;
        }
        $prova = ProvaQuery::create()->findPk([$eventoId, $provaId]);
        if ($prova !== null) {
            ContagemService::forProva($prova)->refreshSnapshotIfStale();
        }
    }

    /**
     * @param array<string, mixed> $device
     * @param string[] $storedUuids
     * @param string[] $voidedUuids
     * @param array<int, array{uuid: string, motivo: string}> $refused
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function answer(array $device, int $nowMs, DeviceHeartbeat $heartbeat, array $storedUuids, array $voidedUuids, array $refused): array
    {
        $context = (new RaceContext($device['evento_id'], $device['prova_id']))->get();
        $timeline = RaceContext::timelineOf($context);
        $isStopLogDevice = $device['fonte_tipo'] === Source::TIPO_BOX;

        return [
            'status' => 200,
            'body' => [
                'stored_uuids' => $storedUuids,
                'voided_uuids' => $voidedUuids,
                'refused' => $refused,
                'server_now_ms' => $nowMs,
                // The page anchors its clock to the middle of the trip. With
                // both ends of our own work it can leave that work out: an
                // upload that waited a second for the lock would otherwise
                // look like a second of network delay, half of it wrongly
                // counted in each direction.
                'server_received_ms' => $nowMs,
                'server_sent_ms' => max($nowMs, Clock::nowMs()),
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
                'unclassified_cars' => $context['unclassified_cars'] ?? [],
                'janela_ms' => $context['janela_ms'] ?? null,
                'abastecimento' => (bool) ($context['abastecimento'] ?? false),
                'simulacao' => (bool) ($context['simulacao'] ?? false),
                // For the simulation's own checks: whether counts go to the
                // official results, and which crossing is "pass zero".
                'publica' => (bool) ($context['publica'] ?? true),
                'passagem_inicial' => $context['passagem_inicial'] ?? null,
                'encerrado' => $heartbeat->closedTheDevice(),
                'outros_usuarios' => SourcePresence::noteAndListOthers($device['fonte_id'], $device['dispositivo_id'], $device['usuario'], $nowMs),
                'cars_in_pit' => $isStopLogDevice ? $timeline->carsInPitAt($nowMs) : [],
                'cars_in_fuel' => $isStopLogDevice ? $timeline->carsFuelingAt($nowMs) : [],
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
