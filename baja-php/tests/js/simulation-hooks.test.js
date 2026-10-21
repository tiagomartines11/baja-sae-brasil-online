// WHAT THE RACE SIMULATION NEEDS FROM THE COUNT AND PIT PAGES, and the
// newer parts of the upload contract.
//
// Background: the simulation (simulacao*.js, not tested here) plays a whole
// race through the pages' own recording functions. Two things about it are
// unlike a person typing:
//
//   - it can be started twice (a reloaded tab, the command typed again), and
//     must then NOT record the race a second time. So it names each record
//     itself (`uuid`), and a name seen before records nothing;
//   - a "replay" injects passes that lie in the PAST. The page must then not
//     tell the server "you have everything up to now" between two bursts.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { TIPO_PARADA } from '../../default/js/contagem/pit-session.js';
import { SERVER_MAX_RECORDS_PER_REQUEST } from '../../default/js/contagem/sync-request.js';
import { describeBanner } from '../../default/js/contagem/status-messages.js';
import { ARMAZENAMENTO } from '../../default/js/contagem/storage-open.js';
import { createMemoryStorage } from '../../default/js/contagem/storage-memory.js';
import { createDevice, createSlowStorage } from './helpers/fakes.js';

/** A device on a prova that allows simulation. */
async function createSimulationDevice(options = {}) {
    const device = await createDevice(options);
    device.server.simulacao = true;
    return device;
}

function simulatedPass(device, car, tsMs, uuid) {
    return device.session.recordPass({ car, typingMs: null, mode: 'simulacao', tsMs, uuid });
}

// ---------------------------------------------------------------------------
// A record under a given uuid is recorded once
// ---------------------------------------------------------------------------

test('a simulated pass is stored under the uuid the simulation gave it', async () => {
    const device = await createSimulationDevice();
    const crossedAt = device.clock.serverNow() - 60_000;

    const { entry } = await simulatedPass(device, 41, crossedAt, 'sim-42-f3-000017');
    await device.queue.flush();

    assert.equal(entry.uuid, 'sim-42-f3-000017');
    assert.equal(device.server.storedEntries.get('sim-42-f3-000017').ts_ms, crossedAt);
});

test('the same uuid a second time records nothing and returns the entry already there', async () => {
    // SITUATION: the tab running the simulation is reloaded half-way and
    // the same command is typed again. The script is the same (same seed),
    // so the first half of the race is "recorded" a second time.
    //
    // WHAT WENT WRONG: every pass got a fresh uuid, so the server stored
    // the first half twice: every car had double laps.
    const device = await createSimulationDevice();
    const crossedAt = device.clock.serverNow() - 60_000;
    const first = await simulatedPass(device, 41, crossedAt, 'sim-a');
    await device.queue.flush();

    await device.reload();
    const again = await simulatedPass(device, 41, crossedAt, 'sim-a');
    await device.queue.flush();

    // EXPECTED: one entry on the phone, one on the server, and the caller
    // gets the stored record back (with no warnings: nothing was typed).
    assert.equal(device.store.allEntries().length, 1);
    assert.equal(again.entry.seq, first.entry.seq);
    assert.deepEqual(again.avisos, []);
    assert.equal(device.server.storedEntries.size, 1);
    assert.equal(device.server.worstInsertCount(), 1);
});

test('the same uuid twice in the same instant is still one entry', async () => {
    // SITUATION: the command is typed twice in a row. The second call
    // arrives while the disk has not finished writing the first.
    const disk = createSlowStorage(createMemoryStorage());
    const device = await createSimulationDevice({ disk });
    disk.holdWrites = true;

    const first = simulatedPass(device, 41, device.clock.serverNow(), 'sim-a');
    const second = device.store.addEntry({ uuid: 'sim-a', car: 41, typing_ms: null, mode: 'simulacao', ts_ms: 1 });
    await disk.finishNextWrite();
    const [firstResult, secondRecord] = await Promise.all([first, second]);

    // EXPECTED: one write, one record; both callers hold the same record.
    assert.equal(device.store.allEntries().length, 1);
    assert.equal(secondRecord, firstResult.entry);
});

test('a pit event under a given uuid is recorded once, too', async () => {
    const device = await createSimulationDevice({ uuidPrefix: 'pit' });
    const enteredAt = device.clock.serverNow() - 30_000;
    const event = { car: 12, tipo: TIPO_PARADA.BOX_ENTRADA, tsMs: enteredAt, mode: 'simulacao', uuid: 'sim-box-1' };

    const first = await device.pit.recordEvent(event);
    const again = await device.pit.recordEvent(event);
    await device.queue.flush();

    assert.equal(first.uuid, 'sim-box-1');
    assert.equal(again, first);
    assert.equal(device.store.allPitEvents().length, 1);
    assert.equal(device.server.storedPitEvents.size, 1);
});

test('a real pass never passes a uuid and always gets a new one', async () => {
    const device = await createDevice();

    await device.type(41);
    await device.type(41);

    assert.deepEqual(device.store.allEntries().map((entry) => entry.uuid), ['uuid-1', 'uuid-2']);
});

// ---------------------------------------------------------------------------
// The "synced through" mark during a replay
// ---------------------------------------------------------------------------

test('while a replay runs the page never says "you have everything up to now"', async () => {
    // SITUATION: a replay plays an hour-long race in two minutes. Between
    // two bursts of passes the queue is empty for a moment.
    //
    // WHY IT MATTERS: `queue_empty: true` moves this source's "synced
    // through" mark on the server to NOW. Every pass the replay injects
    // afterwards lies before that mark: the server would have treated the
    // source as complete for a stretch of race it had not received yet.
    const device = await createSimulationDevice();
    device.queue.suprimirMarcaDeFila(true);

    await simulatedPass(device, 41, device.clock.serverNow() - 3_000_000, 'sim-a');
    await device.queue.flush();
    device.clock.advance(5000);
    await device.queue.syncOnce();                  // a heartbeat with nothing to send

    // EXPECTED: everything was uploaded, yet no request made the promise.
    assert.equal(device.queue.getState().pendentes, 0);
    assert.ok(device.server.receivedRequests.length >= 3);
    assert.deepEqual(new Set(device.server.receivedRequests.map((request) => request.queue_empty)), new Set([false]));

    // The replay ends and switches it off: the next heartbeat says it.
    device.queue.suprimirMarcaDeFila(false);
    await device.queue.syncOnce();
    assert.equal(device.server.receivedRequests.at(-1).queue_empty, true);
});

// ---------------------------------------------------------------------------
// Newer parts of the upload contract
// ---------------------------------------------------------------------------

test('a request never carries more records than the server processes', async () => {
    // SITUATION: the server processes at most 500 entries of a request and
    // leaves the rest unacknowledged. A page configured with a larger batch
    // (or a future change of the default) must not send more.
    const device = await createSimulationDevice({ maxBatch: 5000 });
    device.server.behaviour = 'offline';
    for (let number = 0; number < 620; number++) {
        await simulatedPass(device, 41, device.clock.serverNow() - 1_000_000 + number * 1000, 'sim-' + number);
    }
    device.server.behaviour = 'ok';

    await device.queue.flush();

    // EXPECTED: 500 in the first request, the remaining 120 in the second.
    assert.equal(SERVER_MAX_RECORDS_PER_REQUEST, 500);
    assert.deepEqual(device.server.receivedRequests.slice(0, 2).map((request) => request.entries.length), [500, 120]);
    assert.equal(device.server.storedEntries.size, 620);
});

test('a fueling event refused because fueling is switched off is explained in words', async () => {
    // SITUATION: the controller switches the fueling station off while a
    // marshal's phone still has a fueling event waiting. The server answers
    // it in `refused` with the reason "abastecimento_desligado".
    const device = await createDevice({ uuidPrefix: 'pit' });
    await device.pit.recordEvent({ car: 7, tipo: TIPO_PARADA.ABASTECIMENTO_ENTRADA });
    device.server.refusedUuids.set('pit-1', 'abastecimento_desligado');

    await device.queue.flush();

    // EXPECTED: out of the queue, kept on the phone, and the banner says
    // why in Portuguese, not with the server's code word.
    assert.equal(device.queue.getState().pendentes, 0);
    assert.equal(device.store.allPitEvents()[0].refused_reason, 'abastecimento_desligado');
    const banner = describeBanner(device.queue.getState(), { tokenProblem: null, armazenamento: ARMAZENAMENTO.DISCO });
    assert.match(banner.avisos[0], /o abastecimento está desligado nesta prova/);
    assert.doesNotMatch(banner.avisos[0], /abastecimento_desligado/);
});

test('what the simulation reads about the prova is kept from the last answer', async () => {
    // The simulation reads these two from `armazenamento.getMeta('servidor')`:
    // whether the prova publishes to the official results, and what counts
    // as a car's first pass.
    const device = await createDevice();
    device.server.extraAnswerFields = { publica: false, passagem_inicial: 'largada' };
    await device.queue.syncOnce();
    assert.equal(device.store.getMeta('servidor').publica, false);
    assert.equal(device.store.getMeta('servidor').passagem_inicial, 'largada');

    // An older server says neither: taken as "publishes" (the careful
    // reading) and "not known".
    device.server.extraAnswerFields = {};
    await device.queue.syncOnce();
    assert.equal(device.store.getMeta('servidor').publica, true);
    assert.equal(device.store.getMeta('servidor').passagem_inicial, null);
});
