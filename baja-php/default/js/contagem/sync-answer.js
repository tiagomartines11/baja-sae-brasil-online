// What the upload queue does with a good answer from contagem_sync.php.
// (Split from sync-queue.js, which decides WHEN to talk to the server.)

import { resolveHeldRecords } from './held-records.js';
import { markRefused, MOTIVO_SEM_CONFIRMACAO } from './refused-records.js';

export const META_LAST_SYNC = 'ultimo_sync_ms';
export const META_SERVER_INFO = 'servidor';
export const META_ANCHOR = 'ancora';

function uuidsOf(records) {
    return records.map((record) => record.uuid);
}

/** What the page keeps from an answer, on disk, for a reload without network. */
function serverInfoFrom(body) {
    return {
        race: body.race || null,
        fonte: body.fonte || null,
        valid_cars: Array.isArray(body.valid_cars) ? body.valid_cars : null,
        unclassified_cars: Array.isArray(body.unclassified_cars) ? body.unclassified_cars : null,
        janela_ms: Number.isFinite(body.janela_ms) ? body.janela_ms : null,
        cars_in_pit: Array.isArray(body.cars_in_pit) ? body.cars_in_pit : [],
        cars_in_fuel: Array.isArray(body.cars_in_fuel) ? body.cars_in_fuel : [],
        abastecimento: body.abastecimento === true,
        simulacao: body.simulacao === true,
        // Read by the race simulation (simulacao*.js), not by the pages:
        // whether this prova's counts go to the official results (an older
        // server that does not say is taken to publish), and what counts as
        // a car's first pass.
        publica: body.publica !== false,
        passagem_inicial: typeof body.passagem_inicial === 'string' ? body.passagem_inicial : null,
    };
}

/**
 * When the request reached the server and when the answer left it, on the
 * server's clock. The time in between is the server working, not the network,
 * and the clock takes it out of the round trip (see ServerClock.addSample).
 * An older server writes one time only (`server_now_ms`, read on arrival).
 */
function serverTimesOf(body) {
    const receivedMs = body.server_received_ms;
    const sentMs = body.server_sent_ms;
    if (Number.isFinite(receivedMs) && Number.isFinite(sentMs) && sentMs >= receivedMs) {
        return { receivedMs, sentMs };
    }
    return { receivedMs: body.server_now_ms, sentMs: body.server_now_ms };
}

/**
 * Marks as refused what the server named in `refused`, and what several
 * answers in a row have skipped (see refused-records.js).
 */
async function applyRefusals({ store, tracker, request, body, stored, voided }) {
    const refusedByServer = new Map();
    for (const item of Array.isArray(body.refused) ? body.refused : []) {
        if (item && typeof item.uuid === 'string') {
            refusedByServer.set(item.uuid, typeof item.motivo === 'string' ? item.motivo : 'recusado');
        }
    }
    const sentByCollection = {
        entries: uuidsOf(request.entries),
        pit_events: uuidsOf(request.pit_events),
        voids: uuidsOf(request.voids),
    };
    for (const collection of ['entries', 'pit_events']) {
        for (const uuid of sentByCollection[collection]) {
            if (refusedByServer.has(uuid) && !stored.has(uuid)) {
                await markRefused(store, collection, uuid, refusedByServer.get(uuid));
            }
        }
    }
    // The safety net looks only at what the server neither stored nor refused.
    const accountedFor = new Set([...stored, ...refusedByServer.keys()]);
    for (const collection of ['entries', 'pit_events', 'voids']) {
        const acknowledged = collection === 'voids' ? voided : accountedFor;
        for (const uuid of tracker.noteAnswer(collection, sentByCollection[collection], acknowledged)) {
            await markRefused(store, collection, uuid, MOTIVO_SEM_CONFIRMACAO);
        }
    }
}

/**
 * @param {object} context
 * @param {object} context.store    LocalStore
 * @param {object} context.clock    ServerClock
 * @param {object} context.tracker  UnacknowledgedTracker
 * @param {object} context.request  the body that was sent
 * @param {object} context.body     the answer (already checked to be a proper 200)
 * @param {{sentMonoMs: number, receivedMonoMs: number, usable: boolean}} context.timing
 *        `usable` is false when the monotonic clock misbehaved during the trip
 * @returns {Promise<{confirmados: number, carimbados: number}>} how many
 *          records the server confirmed, and how many held records got
 *          their time from this answer (those can go up in the next request)
 */
export async function applySyncAnswer({ store, clock, tracker, request, body, timing }) {
    if (timing.usable !== false) {
        const serverTimes = serverTimesOf(body);
        clock.addSample(timing.sentMonoMs, timing.receivedMonoMs, serverTimes.receivedMs, serverTimes.sentMs);
    }
    // First thing after the anchor: the records that were waiting for it.
    const carimbados = await resolveHeldRecords(store, clock);
    // Null when this answer could not be used as an anchor either, and while
    // the anchor there is is in doubt: no time is written down from those.
    const answeredAtServerMs = clock.trustedServerNowMs();

    // Only uuids that were in THIS request and that the server named are
    // marked. Anything the server left out stays in the queue.
    const stored = new Set(body.stored_uuids);
    const voided = new Set(Array.isArray(body.voided_uuids) ? body.voided_uuids : []);
    const storedEntries = uuidsOf(request.entries).filter((uuid) => stored.has(uuid));
    const storedPitEvents = uuidsOf(request.pit_events).filter((uuid) => stored.has(uuid));
    const confirmedVoids = uuidsOf(request.voids).filter((uuid) => voided.has(uuid));

    await store.markEntriesSent(storedEntries, answeredAtServerMs);
    await store.markPitEventsSent(storedPitEvents, answeredAtServerMs);
    await store.markVoidsSent(confirmedVoids);
    await applyRefusals({ store, tracker, request, body, stored, voided });

    // Kept on disk so that a reload without network still knows the car
    // list, the race start and the warning window.
    await store.setMeta(META_SERVER_INFO, serverInfoFrom(body));
    if (answeredAtServerMs !== null) {
        await store.setMeta(META_LAST_SYNC, answeredAtServerMs);
        // Refreshed on every answer: the fresher the pairing of server time
        // and system clock, the better the estimate a later page load can
        // make from it for records it finds without a time (brief 6.4).
        await store.setMeta(META_ANCHOR, clock.anchorToPersist());
    }

    return { confirmados: storedEntries.length + storedPitEvents.length + confirmedVoids.length, carimbados };
}
