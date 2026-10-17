// Builds the body of a request to contagem_sync.php, exactly as the upload
// contract describes it (see docs/contagem.md, "Upload protocol").
//
// Every record goes up with its FINAL time (`ts_ms`, already in server
// time). A record whose time is not known yet (typed before this page had an
// anchor, see server-clock.js) is HELD: it is not in the request at all.
//
// Two fields of the body are PROMISES to the server, and this file is where
// they are decided, so that each can be read and tested on its own:
//
//   queue_empty  "you now have everything this device recorded up to
//                now_ms". The server then trusts this source up to that
//                moment: a car missing from it counts as a real miss.
//   encerrando   "this device is done; do not wait for it any more".
//
// A false promise is worse than no promise: it turns "still on its way" into
// "missed". So both are only made when they are certainly true.

/**
 * True when the server has confirmed everything this device recorded AND
 * nothing is on its way to the disk. The second half matters: an entry is
 * timestamped at the key press but appears in the lists only when the disk
 * has answered. In between, the lists look empty although a pass exists.
 * (Held records count as waiting: they are in `pendingCount`.)
 */
export function nothingIsWaiting(store) {
    return store.pendingCount() === 0 && !store.hasWriteInFlight();
}

function timeFieldsOf(record) {
    return {
        ts_ms: record.ts_ms,
        time_quality: record.time_quality,
        ts_device_ms: record.ts_device_ms,
    };
}

function entryForUpload(record) {
    return {
        uuid: record.uuid,
        car: record.car,
        mode: record.mode,
        typing_ms: record.typing_ms,
        ...timeFieldsOf(record),
        anchor: record.anchor || null,
    };
}

function pitEventForUpload(record) {
    const pitEvent = { uuid: record.uuid, car: record.car, tipo: record.tipo, ...timeFieldsOf(record) };
    // Only simulated events say how they were made; the server needs it to
    // refuse them when the prova does not allow simulation.
    if (record.mode) {
        pitEvent.mode = record.mode;
    }
    return pitEvent;
}

// The server processes at most this many entries of one request and leaves
// the rest unacknowledged (upload contract). Sending more would only make
// the same records travel twice.
export const SERVER_MAX_RECORDS_PER_REQUEST = 500;

/**
 * @param {object}  options
 * @param {object}  options.store             LocalStore
 * @param {object}  options.clock             ServerClock
 * @param {number}  options.maxBatch          most records of each kind per
 *        request (never more than SERVER_MAX_RECORDS_PER_REQUEST)
 * @param {boolean} options.vouchesForHistory false when the store is memory
 *        only: this page then cannot know what the device recorded before it
 *        was opened, so it may never claim "you have everything"
 * @param {boolean} [options.encerrando]      the counter asked to end the shift
 * @param {boolean} [options.suppressQueueEmpty] true while a simulation
 *        replays a race that lies in the past (see SyncQueue.suprimirMarcaDeFila)
 */
export function buildSyncRequest({
    store, clock, maxBatch, vouchesForHistory, encerrando = false, suppressQueueEmpty = false,
}) {
    const batchSize = Math.min(maxBatch, SERVER_MAX_RECORDS_PER_REQUEST);
    const entries = store.entriesReadyToSend().slice(0, batchSize).map(entryForUpload);
    const voids = store.voidsReadyToSend().slice(0, batchSize).map((record) => ({ uuid: record.uuid }));
    const pitEvents = store.pitEventsReadyToSend().slice(0, batchSize).map(pitEventForUpload);
    const serverHasEverything = nothingIsWaiting(store);
    // Null until this page load has an anchor, and while the one it has is
    // in doubt. "Everything up to WHEN?" has no answer then, so the promise
    // below cannot be made either.
    const nowMs = clock.trustedServerNowMs();

    const request = {
        entries,
        voids,
        pit_events: pitEvents,
        now_ms: nowMs,
        queue_empty: serverHasEverything && nowMs !== null && vouchesForHistory && !suppressQueueEmpty,
    };
    // The field is left out entirely unless it is true, so an ordinary
    // request looks exactly as it always did.
    if (encerrando && serverHasEverything) {
        request.encerrando = true;
    }
    return request;
}

/**
 * Body of the goodbye sent with `navigator.sendBeacon` while the page closes.
 * sendBeacon cannot set the Authorization header, so for this one request
 * the token travels inside the JSON, as "token".
 *
 * @returns {?object} null when no goodbye may be sent: something is still
 *          waiting (the device is NOT done), or there is no token to sign it
 */
export function buildClosingBeaconBody({ store, clock, vouchesForHistory, token, suppressQueueEmpty = false }) {
    if (typeof token !== 'string' || token === '' || !nothingIsWaiting(store)) {
        return null;
    }
    const request = buildSyncRequest({
        store, clock, maxBatch: 0, vouchesForHistory, encerrando: true, suppressQueueEmpty,
    });
    return { ...request, token };
}
