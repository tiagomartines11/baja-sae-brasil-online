// Builds the body of a request to contagem_sync.php, exactly as the upload
// contract describes it (see docs/contagem.md, "Upload protocol").
//
// Two fields of that body are PROMISES to the server, and this file is where
// they are decided, so that each can be read and tested on its own:
//
//   queue_empty  "you now have everything this device recorded up to
//                device_now_ms". The server then trusts this source up to
//                that moment: a car missing from it counts as a real miss.
//   encerrando   "this device is done; do not wait for it any more".
//
// A false promise is worse than no promise: it turns "still on its way" into
// "missed". So both are only made when they are certainly true.

/**
 * True when the server has confirmed everything this device recorded AND
 * nothing is on its way to the disk. The second half matters: an entry is
 * timestamped at the key press but appears in the lists only when the disk
 * has answered. In between, the lists look empty although a pass exists.
 */
export function nothingIsWaiting(store) {
    return store.pendingCount() === 0 && !store.hasWriteInFlight();
}

/**
 * @param {object}  options
 * @param {object}  options.store             LocalStore
 * @param {object}  options.clock             ClockEstimator
 * @param {function} options.now              () => device time in ms
 * @param {number}  options.maxBatch          most records of each kind per request
 * @param {boolean} options.vouchesForHistory false when the store is memory
 *        only: this page then cannot know what the device recorded before it
 *        was opened, so it may never claim "you have everything"
 * @param {boolean} [options.encerrando]      the counter asked to end the shift
 */
export function buildSyncRequest({ store, clock, now, maxBatch, vouchesForHistory, encerrando = false }) {
    const offsetMs = clock.offsetMs();
    const entries = store.unsentEntries().slice(0, maxBatch).map((record) => ({
        uuid: record.uuid,
        car: record.car,
        ts_device_ms: record.ts_device_ms,
        clock_offset_ms: record.clock_offset_ms,
        typing_ms: record.typing_ms,
        mode: record.mode,
    }));
    const voids = store.voidsReadyToSend().slice(0, maxBatch).map((record) => ({ uuid: record.uuid }));
    const pitEvents = store.unsentPitEvents().slice(0, maxBatch).map((record) => ({
        uuid: record.uuid,
        car: record.car,
        tipo: record.tipo,
        ts_device_ms: record.ts_device_ms,
        clock_offset_ms: record.clock_offset_ms,
    }));
    const serverHasEverything = nothingIsWaiting(store);

    const request = {
        entries,
        voids,
        pit_events: pitEvents,
        device_now_ms: now(),
        clock_offset_ms: offsetMs,
        // Also needs the clock offset: without it the server could not place
        // device_now_ms on its own clock.
        queue_empty: serverHasEverything && offsetMs !== null && vouchesForHistory,
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
export function buildClosingBeaconBody({ store, clock, now, vouchesForHistory, token }) {
    if (typeof token !== 'string' || token === '' || !nothingIsWaiting(store)) {
        return null;
    }
    const request = buildSyncRequest({ store, clock, now, maxBatch: 0, vouchesForHistory, encerrando: true });
    return { ...request, token };
}
