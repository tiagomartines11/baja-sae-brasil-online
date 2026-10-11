// SYNC QUEUE: getting entries from the phone to the server.
//
// Background: the phone keeps every entry on its own disk and uploads when it
// can. Networks at a race track are bad, so the upload must cope with three
// kinds of trouble:
//   - the request never reaches the server;
//   - the request reaches the server, but the ANSWER is lost on the way back
//     (the nasty one: the phone cannot tell it from the first kind);
//   - the server answers, but confirms only part of what was sent.
// Whatever happens, the server must end up with every entry exactly once.
//
// The trick is that every entry is given a unique id (uuid) when it is typed,
// the server stores each uuid at most once, and the phone marks an entry as
// "sent" only when the server's answer names that uuid.
//
// `createDevice()` (helpers/fakes.js) builds one pretend phone with a pretend
// server. `device.type(41)` is the counter typing 41 and pressing Enter.
// `device.queue.syncOnce()` is one upload attempt (in the browser a timer does
// this every 5 seconds).

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { CONEXAO, RESULTADO } from '../../default/js/contagem/sync-queue.js';
import { buildEntriesCsv } from '../../default/js/contagem/csv-export.js';
import { createMemoryStorage } from '../../default/js/contagem/storage-memory.js';
import { createDevice, createSlowStorage } from './helpers/fakes.js';

// ---------------------------------------------------------------------------
// The normal case
// ---------------------------------------------------------------------------

test('an entry is uploaded in the shape the server expects and then marked as sent', async () => {
    // Situation: good network. The counter types car 41.
    const device = await createDevice();
    device.clock.advance(1000);
    await device.type(41, 'teclado_tela');

    const outcome = await device.queue.syncOnce();

    // Expected: the request carries exactly the fields of the upload contract.
    assert.equal(outcome.resultado, RESULTADO.OK);
    const request = device.server.receivedRequests[0];
    assert.deepEqual(request.entries, [{
        uuid: 'uuid-1',
        car: 41,
        ts_device_ms: device.store.findEntry('uuid-1').ts_device_ms,
        clock_offset_ms: null,
        typing_ms: 500,
        mode: 'teclado_tela',
    }]);
    assert.deepEqual(request.voids, []);
    assert.deepEqual(request.pit_events, []);
    assert.equal(typeof request.device_now_ms, 'number');

    // ...and afterwards nothing is waiting any more.
    assert.equal(device.store.findEntry('uuid-1').sent, true);
    assert.equal(device.queue.getState().pendentes, 0);
    assert.equal(device.queue.getState().conexao, CONEXAO.ONLINE);
});

// ---------------------------------------------------------------------------
// Failures and retries: never lose, never duplicate
// ---------------------------------------------------------------------------

test('with no network the entry waits, and is uploaded once when the network returns', async () => {
    // Situation: the counter is in a spot with no signal and types car 41.
    // Three upload attempts fail. Then the signal comes back.
    const device = await createDevice();
    await device.type(41);
    device.server.behaviour = 'offline';

    for (let attempt = 0; attempt < 3; attempt++) {
        const outcome = await device.queue.syncOnce();
        assert.equal(outcome.resultado, RESULTADO.FALHA);
    }

    // While offline: the entry is still queued and the bar says "sem conexão".
    assert.equal(device.queue.getState().pendentes, 1);
    assert.equal(device.queue.getState().conexao, CONEXAO.OFFLINE);
    assert.equal(device.server.storedEntries.size, 0);

    device.server.behaviour = 'ok';
    await device.queue.syncOnce();

    // Expected: the server has car 41 exactly once.
    assert.deepEqual(device.server.storedCars(), [41]);
    assert.equal(device.server.worstInsertCount(), 1);
    assert.equal(device.queue.getState().pendentes, 0);
});

test('when the answer is lost the entry is sent again, and the server still has it only once', async () => {
    // Situation: the request DID reach the server and was stored, but the
    // connection dropped before the answer came back. From the phone's point
    // of view this looks exactly like "no network".
    const device = await createDevice();
    await device.type(41);
    device.server.behaviour = 'answer_lost';

    const firstOutcome = await device.queue.syncOnce();

    // The phone cannot know the server has it, so it must keep it queued.
    assert.equal(firstOutcome.resultado, RESULTADO.FALHA);
    assert.equal(device.server.storedEntries.size, 1, 'the server stored it');
    assert.equal(device.store.findEntry('uuid-1').sent, false, 'the phone does not know that');

    device.server.behaviour = 'ok';
    await device.queue.syncOnce();

    // Expected: the same entry travelled twice under the same uuid; the server
    // recognised it and kept a single copy. The car is NOT counted twice.
    assert.equal(device.server.receivedRequests.length, 2);
    assert.equal(device.server.receivedRequests[0].entries[0].uuid, device.server.receivedRequests[1].entries[0].uuid);
    assert.equal(device.server.storedEntries.size, 1);
    assert.equal(device.server.worstInsertCount(), 1);
    assert.equal(device.store.findEntry('uuid-1').sent, true);
});

test('a long stretch of mixed failures ends with every entry on the server exactly once', async () => {
    // Situation: a realistic bad afternoon. The counter keeps typing while the
    // network goes through every kind of trouble in turn.
    const device = await createDevice();
    const troubles = ['offline', 'answer_lost', 'server_error', 'ok', 'garbage', 'answer_lost', 'offline', 'ok'];
    const typedCars = [];

    for (let round = 0; round < troubles.length; round++) {
        const car = 10 + round;
        typedCars.push(car);
        await device.type(car);
        device.server.behaviour = troubles[round];
        await device.queue.syncOnce();
        device.clock.advance(5000);
    }
    device.server.behaviour = 'ok';
    await device.queue.flush();

    // Expected: same cars, same order, no car twice, nothing left in the queue.
    assert.deepEqual(device.server.storedCars(), typedCars);
    assert.equal(device.server.worstInsertCount(), 1);
    assert.equal(device.queue.getState().pendentes, 0);
});

test('a server error or a nonsense answer leaves the entry in the queue', async () => {
    // Situation: the server is misbehaving (HTTP 500), then answers "200 OK"
    // with a body that does not follow the contract (a proxy's error page,
    // for example).
    const device = await createDevice();
    await device.type(41);

    device.server.behaviour = 'server_error';
    assert.equal((await device.queue.syncOnce()).resultado, RESULTADO.FALHA);
    device.server.behaviour = 'garbage';
    assert.equal((await device.queue.syncOnce()).resultado, RESULTADO.FALHA);

    // Expected: "200" alone is never taken as proof. Only a list of stored
    // uuids is. The entry is still waiting.
    assert.equal(device.store.findEntry('uuid-1').sent, false);
    assert.equal(device.queue.getState().pendentes, 1);
});

test('entries waiting on the disk are uploaded after a page reload', async () => {
    // Situation: two entries typed with no network, then the phone's battery
    // dies. Later the phone is charged and the page opened again, with network.
    const device = await createDevice();
    device.server.behaviour = 'offline';
    await device.type(7);
    await device.type(41);
    await device.queue.syncOnce();

    await device.reload();
    device.server.behaviour = 'ok';
    await device.queue.syncOnce();

    // Expected: both arrive, in order, once.
    assert.deepEqual(device.server.storedCars(), [7, 41]);
    assert.equal(device.server.worstInsertCount(), 1);
});

// ---------------------------------------------------------------------------
// Partial acknowledgement
// ---------------------------------------------------------------------------

test('only the entries the server names are marked as sent (partial acknowledgement)', async () => {
    // Situation: three entries go up in one request, but the server confirms
    // only the first two (say it hit a limit, or the third failed to save).
    const device = await createDevice();
    await device.type(7);
    await device.type(41);
    await device.type(12);
    device.server.storeAtMostPerRequest = 2;

    const outcome = await device.queue.syncOnce();

    // Expected: two marked, the third still queued. Marking all three because
    // "the request succeeded" would lose car 12 for good.
    assert.equal(outcome.confirmados, 2);
    assert.equal(device.store.findEntry('uuid-1').sent, true);
    assert.equal(device.store.findEntry('uuid-2').sent, true);
    assert.equal(device.store.findEntry('uuid-3').sent, false);
    assert.equal(device.queue.getState().pendentes, 1);

    // The next request carries ONLY the unconfirmed one...
    device.server.storeAtMostPerRequest = null;
    await device.queue.syncOnce();
    assert.deepEqual(device.server.receivedRequests[1].entries.map((item) => item.car), [12]);

    // ...and in the end the server has all three, once each.
    assert.deepEqual(device.server.storedCars(), [7, 41, 12]);
    assert.equal(device.server.worstInsertCount(), 1);
});

test('a uuid the server names but that was not in the request changes nothing', async () => {
    // Situation: the counter types car 7. The server's answer (wrongly, or
    // because of some mix-up) lists a uuid that the phone never sent, and does
    // not list the one it did send.
    const device = await createDevice();
    await device.type(7);
    device.server.send = async () => ({
        status: 200,
        body: { stored_uuids: ['some-other-uuid'], voided_uuids: [], server_now_ms: device.clock.now() },
    });

    const outcome = await device.queue.syncOnce();

    // Expected: nothing gets marked on the strength of an unrelated uuid.
    assert.equal(outcome.confirmados, 0);
    assert.equal(device.store.findEntry('uuid-1').sent, false);
});

test('an entry typed while an upload is in the air is not marked by that upload', async () => {
    // Situation: the phone is uploading car 7. Before the answer arrives, the
    // counter types car 41.
    const device = await createDevice();
    await device.type(7);

    const originalSend = device.server.send;
    device.server.send = async (body) => {
        await device.type(41);
        return originalSend(body);
    };
    await device.queue.syncOnce();
    device.server.send = originalSend;

    // Expected: car 7 is confirmed; car 41 was not part of that request, so it
    // is still queued, and it goes up with the next request.
    assert.equal(device.store.findEntry('uuid-1').sent, true);
    assert.equal(device.store.findEntry('uuid-2').sent, false);
    await device.queue.syncOnce();
    assert.deepEqual(device.server.storedCars(), [7, 41]);
});

test('a big backlog goes up in several moderate requests, without duplicates', async () => {
    // Situation: an hour without network left 5 entries waiting, and this
    // phone is set to send at most 2 per request (200 in real life).
    const device = await createDevice({ maxBatch: 2 });
    for (const car of [1, 2, 3, 4, 5]) {
        await device.type(car);
    }

    // flush() = "keep sending while the server keeps confirming things".
    await device.queue.flush();

    // Expected: requests of 2, 2 and 1 entries, then one last empty request
    // that tells the server "that was everything" (see the heartbeat tests).
    assert.deepEqual(device.server.receivedRequests.map((request) => request.entries.length), [2, 2, 1, 0]);
    assert.deepEqual(device.server.storedCars(), [1, 2, 3, 4, 5]);
    assert.equal(device.server.worstInsertCount(), 1);
});

test('two upload attempts never overlap', async () => {
    // Situation: the 5-second timer fires while the previous upload is still
    // waiting for a slow answer.
    const device = await createDevice();
    await device.type(41);

    const [first, second] = await Promise.all([device.queue.syncOnce(), device.queue.syncOnce()]);

    // Expected: the second attempt steps aside. One request reached the server.
    assert.equal(first.resultado, RESULTADO.OK);
    assert.equal(second.resultado, RESULTADO.OCUPADO);
    assert.equal(device.server.receivedRequests.length, 1);
});

// ---------------------------------------------------------------------------
// Undo ("desfazer")
// ---------------------------------------------------------------------------

test('undoing an uploaded entry uploads a void; the entry itself stays everywhere', async () => {
    // Situation: the counter typed 41 but the car was 14. They tap "desfazer".
    const device = await createDevice();
    await device.type(41);
    await device.queue.syncOnce();

    await device.session.undo('uuid-1');
    assert.equal(device.queue.getState().pendentes, 1, 'the undo is waiting to be uploaded');
    await device.queue.syncOnce();

    // Expected: the server received a void for that uuid. The raw entry is
    // still on the server and on the phone: an undo is a new fact, not an eraser.
    assert.deepEqual(device.server.receivedRequests[1].voids, [{ uuid: 'uuid-1' }]);
    assert.equal(device.server.voidedUuids.has('uuid-1'), true);
    assert.equal(device.server.storedEntries.has('uuid-1'), true);
    assert.equal(device.store.findEntry('uuid-1').void_sent, true);
    assert.equal(device.queue.getState().pendentes, 0);
});

test('an entry typed and undone while offline goes up as an entry first, then as a void', async () => {
    // Situation: no network. The counter types 41, sees the mistake, undoes it.
    const device = await createDevice();
    device.server.behaviour = 'offline';
    await device.type(41);
    await device.session.undo('uuid-1');
    await device.queue.syncOnce();

    device.server.behaviour = 'ok';
    await device.queue.flush();

    // Expected: first request carries the entry and no void (the server cannot
    // void what it has not seen); the second carries the void.
    assert.equal(device.server.receivedRequests[0].entries.length, 1);
    assert.deepEqual(device.server.receivedRequests[0].voids, []);
    assert.deepEqual(device.server.receivedRequests[1].voids, [{ uuid: 'uuid-1' }]);
    assert.equal(device.server.voidedUuids.has('uuid-1'), true);
    assert.equal(device.queue.getState().pendentes, 0);
});

test('a void the server does not confirm is sent again', async () => {
    // Situation: the void goes up but the answer is lost.
    const device = await createDevice();
    await device.type(41);
    await device.queue.syncOnce();
    await device.session.undo('uuid-1');

    device.server.behaviour = 'answer_lost';
    await device.queue.syncOnce();
    assert.equal(device.store.findEntry('uuid-1').void_sent, false);

    device.server.behaviour = 'ok';
    await device.queue.syncOnce();

    // Expected: sent twice, confirmed once, and then never again.
    assert.equal(device.store.findEntry('uuid-1').void_sent, true);
    await device.queue.syncOnce();
    assert.deepEqual(device.server.receivedRequests.at(-1).voids, []);
});

// ---------------------------------------------------------------------------
// Revoked token (HTTP 401)
// ---------------------------------------------------------------------------

test('a revoked token stops uploads for good but keeps every entry on the phone', async () => {
    // Situation: the organiser removed this counter from the source (or
    // revoked this phone) while two entries were still waiting to upload.
    const device = await createDevice();
    await device.type(7);
    await device.queue.syncOnce();
    await device.type(41);
    await device.type(12);
    device.server.behaviour = 'revoked';

    const outcome = await device.queue.syncOnce();

    // Expected: the page shows a clear "revogado" state...
    assert.equal(outcome.resultado, RESULTADO.REVOGADO);
    assert.equal(device.queue.getState().conexao, CONEXAO.REVOGADO);

    // ...all three entries are still on the phone, two of them still unsent...
    assert.deepEqual(device.store.allEntries().map((item) => item.car), [7, 41, 12]);
    assert.equal(device.queue.getState().pendentes, 2);

    // ...and "Exportar meus dados" still produces all of them, so the
    // organiser can take the file and enter them by hand.
    const csv = buildEntriesCsv(device.store.allEntries());
    assert.equal(csv.trim().split('\r\n').length, 1 + 3, 'header plus three entries');
});

test('after a revocation the phone stops knocking on the server', async () => {
    // Situation: same as above; then the 5-second timer keeps firing, and the
    // server would even accept the token again.
    const device = await createDevice();
    await device.type(41);
    device.server.behaviour = 'revoked';
    await device.queue.syncOnce();
    device.server.behaviour = 'ok';

    const later = await device.queue.syncOnce();
    await device.queue.flush();

    // Expected: no request at all. "Revoked" is a decision by a person, not a
    // network hiccup; retrying would be wrong.
    assert.equal(later.resultado, RESULTADO.REVOGADO);
    assert.equal(device.server.receivedRequests.length, 0);
    assert.equal(device.store.findEntry('uuid-1').sent, false);
});

test('a 401 that is not the server\'s own "token_invalido" is treated as a network problem', async () => {
    // Situation: the counter's phone joins the track's Wi-Fi, and that Wi-Fi
    // wants a login page accepted first. Until then IT answers every request,
    // with HTTP 401 and a page of its own. Our server never saw the request.
    //
    // What used to go wrong: any 401 was read as "this device was removed
    // from the count". The page stopped uploading for good, and only a reload
    // plus a new login could bring it back, in the middle of a race.
    const device = await createDevice();
    await device.type(41);
    device.server.behaviour = 'other_401';

    const outcome = await device.queue.syncOnce();

    // Expected: an ordinary failure. Nothing is marked as revoked...
    assert.equal(outcome.resultado, RESULTADO.FALHA);
    assert.equal(device.queue.getState().conexao, CONEXAO.OFFLINE);
    assert.equal(device.store.getMeta('token_revogado', false), false);

    // ...and as soon as the real server answers, the entry goes up.
    device.server.behaviour = 'ok';
    await device.queue.syncOnce();
    assert.deepEqual(device.server.storedCars(), [41]);
});

test('other 401 bodies from our own server do not revoke the device either', async () => {
    // Situation: the server answers 401 with {"erro": "token_ausente"}: the
    // Authorization header got lost on the way (some proxies strip it). That
    // says nothing about whether this device is still allowed.
    const device = await createDevice();
    await device.type(41);
    device.server.send = async () => ({ status: 401, body: { erro: 'token_ausente' } });

    const outcome = await device.queue.syncOnce();

    // Expected: only the exact answer {"erro": "token_invalido"} revokes.
    assert.equal(outcome.resultado, RESULTADO.FALHA);
    assert.equal(device.queue.getState().conexao, CONEXAO.OFFLINE);
});

test('the revoked state survives a reload, and the counter can still type', async () => {
    // Situation: after the revocation the counter reloads the page, hoping it
    // helps, and keeps typing numbers.
    const device = await createDevice();
    await device.type(41);
    device.server.behaviour = 'revoked';
    await device.queue.syncOnce();

    await device.reload();
    device.server.behaviour = 'ok';
    await device.type(12);
    await device.queue.syncOnce();

    // Expected: still revoked after the reload, nothing uploaded, and the new
    // entry was stored locally all the same (it will be in the export).
    assert.equal(device.queue.getState().conexao, CONEXAO.REVOGADO);
    assert.equal(device.server.receivedRequests.length, 0);
    assert.deepEqual(device.store.allEntries().map((item) => item.car), [41, 12]);
});

test('uploads resume only after the server has issued a new token', async () => {
    // Situation: the organiser links the counter again. On the next page load
    // the server issues a fresh token (not shown here; see device-token tests)
    // and the page calls resumeWithNewToken().
    const device = await createDevice();
    await device.type(41);
    device.server.behaviour = 'revoked';
    await device.queue.syncOnce();
    device.server.behaviour = 'ok';

    await device.queue.resumeWithNewToken();
    await device.queue.flush();

    // Expected: the entry that had been stuck finally arrives, once.
    assert.deepEqual(device.server.storedCars(), [41]);
    assert.equal(device.queue.getState().conexao, CONEXAO.ONLINE);
    assert.equal(device.store.getMeta('token_revogado'), false);
});

// ---------------------------------------------------------------------------
// "queue_empty" (the 5-second heartbeat itself is in sync-heartbeat.test.js)
// ---------------------------------------------------------------------------

test('queue_empty is true only when the server really has everything', async () => {
    // Background: "queue_empty: true" is a promise to the server: "you now
    // have every entry I recorded up to this moment". The server uses it to
    // decide that a car missing from this counter was really missed, and not
    // just still on its way. A false promise would create false alarms.
    const device = await createDevice();
    await device.queue.syncOnce();          // first contact: learns the clock offset
    await device.type(41);

    await device.queue.syncOnce();          // carries the entry
    await device.queue.syncOnce();          // nothing left

    // Expected: false while the entry travels, true once nothing is waiting.
    assert.equal(device.server.receivedRequests[1].entries.length, 1);
    assert.equal(device.server.receivedRequests[1].queue_empty, false);
    assert.equal(device.server.receivedRequests[2].entries.length, 0);
    assert.equal(device.server.receivedRequests[2].queue_empty, true);
});

test('queue_empty stays false while an undo is still waiting', async () => {
    // Situation: every entry is uploaded, but a "desfazer" has not gone up yet
    // (its upload attempt failed).
    const device = await createDevice();
    await device.type(41);
    await device.queue.flush();
    await device.session.undo('uuid-1');
    device.server.behaviour = 'answer_lost';
    await device.queue.syncOnce();
    device.server.behaviour = 'ok';

    await device.queue.syncOnce();

    // Expected: the request that carries the void does not claim to be complete.
    assert.equal(device.server.receivedRequests.at(-1).voids.length, 1);
    assert.equal(device.server.receivedRequests.at(-1).queue_empty, false);
});

test('queue_empty stays false while an entry is still being written to the phone', async () => {
    // Situation: a car crosses the line, the counter presses Enter, and in
    // that very instant the 5-second timer fires. The entry already has its
    // time (the Enter press) but the phone's storage has not answered yet,
    // so the entry is in no list: the queue LOOKS empty.
    //
    // What used to go wrong: the request said "queue_empty: true, I have told
    // you everything up to now". The server believed it, saw the other two
    // counters' entries for that car and none from this one, and charged
    // this counter with a miss, for an entry that arrived a second later.
    const disk = createSlowStorage(createMemoryStorage());
    const device = await createDevice({ disk });
    await device.queue.syncOnce();              // first contact: learns the clock offset

    disk.holdWrites = true;
    const typing = device.type(41);             // Enter pressed; the disk has not answered
    await device.queue.syncOnce();

    // Expected: no entry in the request (it is not on the disk yet), and no
    // claim of completeness either.
    assert.deepEqual(device.server.receivedRequests[1].entries, []);
    assert.equal(device.server.receivedRequests[1].queue_empty, false);

    // Once the disk has answered, everything is as usual.
    await disk.finishNextWrite();
    await typing;
    disk.holdWrites = false;
    await device.queue.flush();
    assert.deepEqual(device.server.storedCars(), [41]);
    assert.equal(device.server.receivedRequests.at(-1).queue_empty, true);
});

test('queue_empty stays false while an undo is still being written to the phone', async () => {
    // Situation: the same instant, but what the counter just did was tap
    // "desfazer". Until the disk has answered, the undo is not in the queue,
    // yet the server does not have "everything" this counter did.
    const innerDisk = createMemoryStorage();
    const fastDevice = await createDevice({ disk: innerDisk });
    await fastDevice.type(41);
    await fastDevice.queue.flush();

    const slowDisk = createSlowStorage(innerDisk);
    const device = await createDevice({ disk: slowDisk, server: fastDevice.server, clock: fastDevice.clock });
    await device.queue.syncOnce();
    assert.equal(device.server.receivedRequests.at(-1).queue_empty, true, 'all quiet before the undo');

    slowDisk.holdWrites = true;
    const undoing = device.session.undo('uuid-1');
    await device.queue.syncOnce();

    // Expected: no claim of completeness while the undo is on its way.
    assert.equal(device.server.receivedRequests.at(-1).queue_empty, false);
    await slowDisk.finishNextWrite();
    await undoing;
});

test('the very first request never claims queue_empty, because the clock offset is unknown', async () => {
    // Situation: the page has just opened. It has never heard from the server,
    // so it does not know how wrong the phone's clock is. "Everything up to
    // device time X" would be meaningless to the server.
    const device = await createDevice();

    await device.queue.syncOnce();
    await device.queue.syncOnce();

    // Expected: first request says false and carries a null offset; the
    // second, made after one answer, knows the offset and says true.
    assert.equal(device.server.receivedRequests[0].clock_offset_ms, null);
    assert.equal(device.server.receivedRequests[0].queue_empty, false);
    assert.equal(typeof device.server.receivedRequests[1].clock_offset_ms, 'number');
    assert.equal(device.server.receivedRequests[1].queue_empty, true);
});

test('after the last entry is confirmed, one more request reports the empty queue at once', async () => {
    // Situation: the counter types car 41 with a good network. Without the
    // follow-up request the server would only learn "nothing more is coming"
    // at the next heartbeat, up to 5 seconds later.
    const device = await createDevice();
    await device.queue.syncOnce();
    await device.type(41);

    await device.queue.flush();

    // Expected: [entry request, empty-queue request], back to back.
    const afterFirstContact = device.server.receivedRequests.slice(1);
    assert.deepEqual(afterFirstContact.map((request) => request.queue_empty), [false, true]);
});

// ---------------------------------------------------------------------------
// What the status bar shows
// ---------------------------------------------------------------------------

test('the status bar state follows what really happened', async () => {
    // Situation: page opens, uploads fine, loses the network, recovers.
    const device = await createDevice();
    assert.equal(device.queue.getState().conexao, CONEXAO.CONECTANDO);
    assert.equal(device.queue.getState().ultimoSyncMs, null);

    device.clock.advance(1000);
    await device.queue.syncOnce();
    const firstSyncAt = device.clock.now();
    assert.equal(device.queue.getState().conexao, CONEXAO.ONLINE);
    assert.equal(device.queue.getState().ultimoSyncMs, firstSyncAt);

    device.clock.advance(5000);
    device.server.behaviour = 'offline';
    await device.type(41);
    await device.queue.syncOnce();

    // Expected while offline: state says so, one entry waiting, and "último
    // envio" still shows the LAST SUCCESSFUL time, not the failed attempt.
    assert.equal(device.queue.getState().conexao, CONEXAO.OFFLINE);
    assert.equal(device.queue.getState().pendentes, 1);
    assert.equal(device.queue.getState().ultimoSyncMs, firstSyncAt);

    // Every change was announced to the page (that is how the bar redraws).
    assert.ok(device.stateChanges.length >= 2);
});

test('names of other people on the same source are passed through; nothing else about them', async () => {
    // Situation: a handover. capitao2 opened the same counter on another phone.
    const device = await createDevice();
    device.server.outrosUsuarios = ['capitao2'];

    await device.queue.syncOnce();

    // Expected: the name is available for the status bar. (The server answer
    // contains no entries of other people at all, so there is nothing else
    // that could leak to this counter.)
    assert.deepEqual(device.queue.getState().outrosUsuarios, ['capitao2']);

    device.server.outrosUsuarios = [];
    await device.queue.syncOnce();
    assert.deepEqual(device.queue.getState().outrosUsuarios, []);
});

test('the car list and race start from the last answer are still known after an offline reload', async () => {
    // Situation: the phone synced once, then the page is reloaded in a spot
    // with no signal. The counter still needs team names, the "unknown
    // number" warning and the race clock.
    const device = await createDevice();
    device.server.race = { largada_ms: 1_759_990_000_000, encerramento_ms: null, estado: 'correndo' };
    await device.queue.syncOnce();

    device.server.behaviour = 'offline';
    await device.reload();

    const remembered = device.queue.getState().servidor;
    assert.equal(remembered.race.largada_ms, 1_759_990_000_000);
    assert.equal(remembered.janela_ms, 20000);
    assert.deepEqual(remembered.valid_cars.map((car) => car.numero), [7, 41]);
});
