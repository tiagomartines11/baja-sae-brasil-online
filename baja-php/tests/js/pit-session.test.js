// PIT LOG: "Entrou" and "Saiu".
//
// Background: one volunteer stands at the pit lane and records every car
// that enters and every car that leaves. The page shows the cars that are in
// the pit lane right now. That list comes from the server (`cars_in_pit`), but
// the server only knows the events it has received. The page therefore adds
// the events still waiting on this phone, so that the list is right the
// instant a button is tapped, with or without network.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createMemoryStorage } from '../../default/js/contagem/storage-memory.js';
import { LocalStore } from '../../default/js/contagem/local-store.js';
import { ClockEstimator } from '../../default/js/contagem/clock-estimator.js';
import { SyncQueue } from '../../default/js/contagem/sync-queue.js';
import { PitSession, TIPO_BOX, mergeCarsInPit } from '../../default/js/contagem/pit-session.js';
import { buildPitEventsCsv } from '../../default/js/contagem/csv-export.js';
import { FakeClock, FakeServer, createUuidSequence } from './helpers/fakes.js';

/** A pretend pit-lane phone, wired like the real page. */
async function createPitDevice() {
    const clock = new FakeClock();
    const disk = createMemoryStorage();
    const server = new FakeServer(clock);
    const generateUuid = createUuidSequence('pit');
    const device = { clock, disk, server };
    device.reload = async () => {
        device.store = await LocalStore.open(disk);
        device.clockEstimator = new ClockEstimator();
        device.queue = new SyncQueue({
            store: device.store, send: (body) => server.send(body), clock: device.clockEstimator, now: clock.now,
        });
        device.session = new PitSession({
            store: device.store, clock: device.clockEstimator, now: clock.now, generateUuid,
        });
    };
    await device.reload();
    return device;
}

function localEvent(seq, car, tipo) {
    return { uuid: 'e' + seq, seq, car, tipo };
}

// ---------------------------------------------------------------------------
// Which cars are in the pit lane
// ---------------------------------------------------------------------------

test('with nothing waiting on the phone, the list is the server list, sorted', () => {
    assert.deepEqual(mergeCarsInPit([40, 12], []), [12, 40]);
});

test('a car that just entered shows up before the event is uploaded', () => {
    // Situation: the server says 12 and 40 are in. The marshal taps "Entrou"
    // for car 7; the upload has not happened yet.
    const carsInPit = mergeCarsInPit([12, 40], [localEvent(1, 7, TIPO_BOX.ENTRADA)]);

    assert.deepEqual(carsInPit, [7, 12, 40]);
});

test('a car that just left disappears before the event is uploaded', () => {
    // Situation: the server still lists car 12; the marshal tapped "Saiu".
    const carsInPit = mergeCarsInPit([12, 40], [localEvent(1, 12, TIPO_BOX.SAIDA)]);

    assert.deepEqual(carsInPit, [40]);
});

test('waiting events are applied in the order they happened', () => {
    // Situation, offline: car 7 enters and then leaves; car 9 leaves (the
    // marshal missed its entry) and then enters again.
    const waiting = [
        localEvent(1, 7, TIPO_BOX.ENTRADA),
        localEvent(2, 7, TIPO_BOX.SAIDA),
        localEvent(3, 9, TIPO_BOX.SAIDA),
        localEvent(4, 9, TIPO_BOX.ENTRADA),
    ];

    // Expected: 7 is out, 9 is in. The array order given to the function must
    // not matter, only the order in which the events were recorded.
    assert.deepEqual(mergeCarsInPit([], waiting), [9]);
    assert.deepEqual(mergeCarsInPit([], waiting.slice().reverse()), [9]);
});

test('"Saiu" for a car that is not listed, or "Entrou" twice, does not break the list', () => {
    // Situation: the marshal taps "Saiu" for a car nobody saw enter, and
    // "Entrou" twice for car 12.
    const waiting = [
        localEvent(1, 33, TIPO_BOX.SAIDA),
        localEvent(2, 12, TIPO_BOX.ENTRADA),
        localEvent(3, 12, TIPO_BOX.ENTRADA),
    ];

    // Expected: 12 appears once; 33 simply is not in. All three events are
    // still recorded and uploaded as typed (see the next tests); judging them
    // is the reviewers' job.
    assert.deepEqual(mergeCarsInPit([12], waiting), [12]);
});

test('before the first answer from the server the list is built from local events alone', () => {
    assert.deepEqual(mergeCarsInPit(undefined, [localEvent(1, 7, TIPO_BOX.ENTRADA)]), [7]);
});

// ---------------------------------------------------------------------------
// Recording and uploading: same mechanics as the count page
// ---------------------------------------------------------------------------

test('a pit event is uploaded in the shape the server expects', async () => {
    // Situation: car 12 enters the pit lane; good network.
    const device = await createPitDevice();
    const recorded = await device.session.recordEvent({ car: 12, tipo: TIPO_BOX.ENTRADA });

    await device.queue.syncOnce();

    // Expected: it travels in `pit_events` (not in `entries`) with the fields
    // of the contract, and is then marked as sent.
    const request = device.server.receivedRequests[0];
    assert.deepEqual(request.entries, []);
    assert.deepEqual(request.pit_events, [{
        uuid: 'pit-1', car: 12, tipo: 'box_entrada', ts_device_ms: recorded.ts_device_ms, clock_offset_ms: null,
    }]);
    assert.equal(device.store.allPitEvents()[0].sent, true);
    assert.equal(device.queue.getState().pendentes, 0);
});

test('pit events typed offline survive a reload and arrive once', async () => {
    // Situation: no signal in the pit lane. Car 12 enters, car 12 leaves, the
    // phone restarts, the signal returns.
    const device = await createPitDevice();
    device.server.behaviour = 'offline';
    await device.session.recordEvent({ car: 12, tipo: TIPO_BOX.ENTRADA });
    await device.session.recordEvent({ car: 12, tipo: TIPO_BOX.SAIDA });
    await device.queue.syncOnce();

    await device.reload();
    device.server.behaviour = 'answer_lost';
    await device.queue.syncOnce();
    device.server.behaviour = 'ok';
    await device.queue.flush();

    // Expected: both events on the server, in order, each stored once even
    // though they were sent twice.
    assert.deepEqual(Array.from(device.server.storedPitEvents.values(), (event) => event.tipo), ['box_entrada', 'box_saida']);
    assert.equal(device.server.worstInsertCount(), 1);
    assert.equal(device.queue.getState().pendentes, 0);
});

test('the list on screen combines the server answer with events still waiting', async () => {
    // Situation: the server knows car 40 is in the pit. The phone is offline;
    // the marshal records 7 entering and 40 leaving.
    const device = await createPitDevice();
    device.server.carsInPit = [40];
    await device.queue.syncOnce();
    const serverList = () => device.store.getMeta('servidor').cars_in_pit;
    assert.deepEqual(device.session.carsInPit(serverList()), [40]);

    device.server.behaviour = 'offline';
    await device.session.recordEvent({ car: 7, tipo: TIPO_BOX.ENTRADA });
    await device.session.recordEvent({ car: 40, tipo: TIPO_BOX.SAIDA });

    // Expected: 7 in, 40 out, immediately.
    assert.deepEqual(device.session.carsInPit(serverList()), [7]);

    // When the upload succeeds the server's new list takes over, and the
    // already-uploaded events are no longer applied a second time.
    device.server.behaviour = 'ok';
    device.server.carsInPit = [7];
    await device.queue.syncOnce();
    assert.deepEqual(device.session.carsInPit(serverList()), [7]);
});

test('an event type other than "entrou" or "saiu" is refused', async () => {
    // A guard against a programming mistake in the page: nothing but the two
    // known types may ever be stored or uploaded.
    const device = await createPitDevice();

    await assert.rejects(() => device.session.recordEvent({ car: 12, tipo: 'largada' }));
    assert.equal(device.store.allPitEvents().length, 0);
});

test('the pit export lists every event recorded on this phone', async () => {
    // Situation: one event uploaded, one still waiting; the marshal exports.
    const device = await createPitDevice();
    await device.session.recordEvent({ car: 12, tipo: TIPO_BOX.ENTRADA });
    await device.queue.syncOnce();
    await device.session.recordEvent({ car: 12, tipo: TIPO_BOX.SAIDA });

    const lines = buildPitEventsCsv(device.store.allPitEvents()).trim().split('\r\n');

    assert.equal(lines.length, 3, 'header plus two events');
    assert.match(lines[1], /;12;box_entrada;.*;sim$/);
    assert.match(lines[2], /;12;box_saida;.*;nao$/);
});
