// END OF SHIFT: "Encerrar meu turno".
//
// Background: the server compares three counters. When one of them has not
// reported a car that the other two saw, the server has to decide: did that
// counter MISS the car, or is the entry still on its way from a phone with a
// bad signal? As long as a phone that was counting might still upload
// something, the server waits: it holds back alerts and leaves passes
// unconfirmed.
//
// A counter who finishes their turn and pockets the phone looks, to the
// server, exactly like a counter who lost the signal. So there is a button to
// say goodbye. The goodbye is one last upload request carrying
// `"encerrando": true`, which means "this device is done, do not wait for it".
//
// The one thing that must never happen: saying goodbye while entries are
// still on the phone. The server would stop waiting for entries that exist.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { CONEXAO } from '../../default/js/contagem/sync-queue.js';
import { endShift, resumeShift, closingBeaconBody, TURNO } from '../../default/js/contagem/shift-control.js';
import { createMemoryStorage } from '../../default/js/contagem/storage-memory.js';
import { createDevice, createSlowStorage } from './helpers/fakes.js';

/** The requests that carried the goodbye. */
function goodbyes(device) {
    return device.server.receivedRequests.filter((request) => request.encerrando === true);
}

test('ending the shift uploads what is waiting, then says goodbye in one last empty request', async () => {
    // Situation: the counter's relief has taken over. Two entries are still
    // waiting on the phone (the signal was weak). The counter taps "Encerrar
    // meu turno".
    const device = await createDevice();
    await device.queue.syncOnce();              // first contact, as on any open page
    await device.type(7);
    await device.type(41);

    const result = await endShift(device.queue);

    // Expected: first the entries, and only then, in a request with nothing
    // in it, the goodbye.
    assert.equal(result, TURNO.ENCERRADO);
    assert.deepEqual(device.server.storedCars(), [7, 41]);
    const lastRequest = device.server.receivedRequests.at(-1);
    assert.equal(lastRequest.encerrando, true);
    assert.deepEqual(lastRequest.entries, []);
    assert.equal(lastRequest.queue_empty, true);
    assert.equal(goodbyes(device).length, 1, 'said once');
    // No request that carried entries said goodbye.
    for (const request of device.server.receivedRequests) {
        if (request.entries.length > 0) {
            assert.equal('encerrando' in request, false);
        }
    }
    assert.equal(device.queue.getState().turnoEncerrado, true);
});

test('an ordinary request does not mention "encerrando" at all', async () => {
    // Situation: normal counting. The field must not even be present, so
    // that nothing about ordinary requests changes for the server.
    const device = await createDevice();
    await device.type(41);
    await device.queue.flush();

    for (const request of device.server.receivedRequests) {
        assert.equal('encerrando' in request, false);
    }
});

test('with no connection the shift is NOT ended and nothing is claimed', async () => {
    // Situation: the counter taps the button in a spot with no signal, with
    // one entry still waiting.
    const device = await createDevice();
    await device.type(41);
    device.server.behaviour = 'offline';

    const result = await endShift(device.queue);

    // Expected: the page says the shift was not ended and keeps working as
    // before (the entry still goes up when the signal returns).
    assert.equal(result, TURNO.PENDENTE);
    assert.equal(device.queue.getState().turnoEncerrado, false);
    assert.equal(goodbyes(device).length, 0);

    device.server.behaviour = 'ok';
    await device.queue.flush();
    assert.deepEqual(device.server.storedCars(), [41]);
});

test('with an empty queue but no connection the goodbye is simply not delivered', async () => {
    // Situation: everything was uploaded earlier; now there is no signal.
    const device = await createDevice();
    await device.queue.syncOnce();
    device.server.behaviour = 'offline';

    const result = await endShift(device.queue);

    // Expected: "sem conexão", shift still open. The page must not show
    // "turno encerrado" when the server never heard it.
    assert.equal(result, TURNO.SEM_CONEXAO);
    assert.equal(device.queue.getState().turnoEncerrado, false);
});

test('an entry still being written to the phone blocks the goodbye', async () => {
    // Situation: the counter records a last car and taps "Encerrar meu
    // turno" in the same second. The entry has not reached the phone's disk
    // yet, so the upload queue looks empty.
    const disk = createSlowStorage(createMemoryStorage());
    const device = await createDevice({ disk });
    await device.queue.syncOnce();
    disk.holdWrites = true;
    const typing = device.type(41);

    const result = await endShift(device.queue);

    // Expected: no goodbye went out. (The requests that did go out were
    // ordinary ones.) The shift stays open and the car is uploaded normally.
    assert.notEqual(result, TURNO.ENCERRADO);
    assert.equal(goodbyes(device).length, 0);

    disk.holdWrites = false;
    await disk.finishNextWrite();
    await typing;
    await device.queue.flush();
    assert.deepEqual(device.server.storedCars(), [41]);
});

test('after the goodbye the phone goes quiet: no more heartbeats', async () => {
    // Situation: shift ended; the page stays open on the table and the
    // 5-second timer keeps firing. The network comes and goes.
    const device = await createDevice();
    await device.queue.syncOnce();
    await endShift(device.queue);
    const requestsAtGoodbye = device.server.receivedRequests.length;

    for (let tick = 0; tick < 5; tick++) {
        device.clock.advance(5000);
        device.heartbeat.tick();
        await device.queue.flush();
    }

    // Expected: not one more request. A heartbeat after the goodbye would
    // tell the server "I am counting again", and it would start waiting for
    // this phone once more.
    assert.equal(device.server.receivedRequests.length, requestsAtGoodbye);
});

test('the ended shift survives a reload', async () => {
    // Situation: after ending the shift the phone goes into a pocket; later
    // the browser reloads the page by itself (phones do that to save memory).
    const device = await createDevice();
    await device.queue.syncOnce();
    await endShift(device.queue);
    const requestsAtGoodbye = device.server.receivedRequests.length;

    await device.reload();
    device.heartbeat.start(() => 1, () => {});
    await device.queue.flush();

    // Expected: still "turno encerrado", still quiet. Only the person can
    // reopen the shift, with the button.
    assert.equal(device.queue.getState().turnoEncerrado, true);
    assert.equal(device.server.receivedRequests.length, requestsAtGoodbye);
});

test('"Retomar contagem" brings the phone back with an ordinary request', async () => {
    // Situation: the counter ended the shift, then is asked to cover for
    // someone and taps "Retomar contagem".
    const device = await createDevice();
    await device.queue.syncOnce();
    await endShift(device.queue);

    await resumeShift(device.queue);
    await device.queue.flush();
    await device.type(41);
    await device.queue.flush();

    // Expected: the page counts as before. The requests after the goodbye do
    // not carry the field, and that is how the server learns the phone is back.
    assert.equal(device.queue.getState().turnoEncerrado, false);
    assert.equal(device.queue.getState().conexao, CONEXAO.ONLINE);
    assert.deepEqual(device.server.storedCars(), [41]);
    assert.equal('encerrando' in device.server.receivedRequests.at(-1), false);
});

test('anything recorded after the goodbye is uploaded anyway, and reopens the shift', async () => {
    // Situation: the page hides the entry controls once the shift has ended,
    // but the list of recent entries stays, with its "desfazer" buttons. The
    // counter notices a typo from five minutes ago and undoes it.
    const device = await createDevice();
    await device.type(41);
    await endShift(device.queue);

    await device.session.undo('uuid-1');
    await device.queue.flush();

    // Expected: the undo reaches the server (data is never held back because
    // of a state on the screen), and the page is honest about it: the shift
    // is open again.
    assert.equal(device.server.voidedUuids.has('uuid-1'), true);
    assert.equal(device.queue.getState().turnoEncerrado, false);
});

test('a device that was shut out cannot say goodbye', async () => {
    // Situation: the token was revoked (see sync-heartbeat.test.js) and the
    // counter taps "Encerrar meu turno".
    const device = await createDevice();
    await device.type(41);
    device.server.behaviour = 'revoked';
    await device.queue.syncOnce();

    assert.equal(await endShift(device.queue), TURNO.BLOQUEADO);
    assert.equal(device.queue.getState().turnoEncerrado, false);
});

// ---------------------------------------------------------------------------
// Closing the page without the button
// ---------------------------------------------------------------------------
//
// When a page is closed the browser allows one last small request
// ("sendBeacon"). It cannot carry the usual Authorization header, so for this
// one request the device token travels inside the JSON body, as "token".

test('closing the page with an empty queue sends the goodbye, with the token in the body', async () => {
    // Situation: everything is uploaded and the counter just closes the tab.
    const device = await createDevice();
    await device.queue.syncOnce();
    await device.type(41);
    await device.queue.flush();

    const body = closingBeaconBody(device.queue, 'token-abc');

    assert.equal(body.encerrando, true);
    assert.equal(body.token, 'token-abc');
    assert.deepEqual(body.entries, []);
    assert.deepEqual(body.voids, []);
    assert.deepEqual(body.pit_events, []);
    assert.equal(body.queue_empty, true);
    assert.equal(typeof body.device_now_ms, 'number');
});

test('closing the page with entries still waiting sends no goodbye', async () => {
    // Situation: no signal, one entry waiting, and the counter closes the
    // tab (the browser has warned them). The entry is safe on the disk and
    // will go up when the page is opened again.
    const device = await createDevice();
    device.server.behaviour = 'offline';
    await device.type(41);
    await device.queue.syncOnce();

    // Expected: nothing at all is sent. A goodbye here would tell the
    // server to stop waiting for an entry that exists.
    assert.equal(closingBeaconBody(device.queue, 'token-abc'), null);
});

test('no goodbye on closing without a token, after a goodbye, or when shut out', async () => {
    // No token: the device never got as far as being allowed to upload.
    const neverAuthorised = await createDevice();
    assert.equal(closingBeaconBody(neverAuthorised.queue, null), null);

    // Already said with the button: once is enough.
    const alreadyEnded = await createDevice();
    await alreadyEnded.queue.syncOnce();
    await endShift(alreadyEnded.queue);
    assert.equal(closingBeaconBody(alreadyEnded.queue, 'token-abc'), null);

    // Shut out: the server would refuse the token anyway.
    const shutOut = await createDevice();
    shutOut.server.behaviour = 'revoked';
    await shutOut.queue.syncOnce();
    assert.equal(closingBeaconBody(shutOut.queue, 'token-abc'), null);
});
