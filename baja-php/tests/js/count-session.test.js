// COUNT SESSION: what happens, step by step, when the counter presses Enter.
//
// The required order is:
//   1. take the timestamp, immediately;
//   2. write the entry to the phone's disk and wait until it is really there;
//   3. only then show it and put it in the upload queue.
// These tests pin that order down, together with "desfazer" and the list of
// recent entries.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createMemoryStorage } from '../../default/js/contagem/storage-memory.js';
import { MODO } from '../../default/js/contagem/entry-modes.js';
import { createDevice, createFlakyStorage, FakeClock } from './helpers/fakes.js';

test('the time of an entry is the moment Enter was pressed, not the moment the disk finished', async () => {
    // Situation: an old, slow phone needs 300 ms to write to its disk. Car 41
    // crosses the line and the counter presses Enter at that instant.
    const clock = new FakeClock();
    const slowDisk = createMemoryStorage();
    const fastPut = slowDisk.put;
    slowDisk.put = async (collection, key, record) => {
        clock.advance(300);
        return fastPut(collection, key, record);
    };
    const device = await createDevice({ clock, disk: slowDisk });
    const pressedEnterAt = clock.now();

    const { entry } = await device.type(41);

    // Expected: the entry carries the time of the key press. 300 ms would be
    // enough to put two cars in the wrong order.
    assert.equal(entry.ts_device_ms, pressedEnterAt);
    assert.equal(clock.now(), pressedEnterAt + 300, 'the slow write did happen');
});

test('an entry appears in the list and in the queue only after it is on the disk', async () => {
    // Situation: we stop the disk in the middle of the write and look at what
    // the page would show at that moment.
    const disk = createMemoryStorage();
    const realPut = disk.put;
    let letTheDiskFinish;
    const diskIsBusy = new Promise((resolve) => { letTheDiskFinish = resolve; });
    disk.put = async (collection, key, record) => {
        if (collection === 'entries') {
            await diskIsBusy;
        }
        return realPut(collection, key, record);
    };
    const device = await createDevice({ disk });

    const recording = device.type(41);
    await Promise.resolve();

    // While the disk is still writing: nothing shown, nothing queued.
    assert.equal(device.session.recentEntries().length, 0);
    assert.equal(device.queue.getState().pendentes, 0);

    letTheDiskFinish();
    await recording;

    // Once written: shown and queued.
    assert.equal(device.session.recentEntries().length, 1);
    assert.equal(device.queue.getState().pendentes, 1);
});

test('when the disk refuses the entry, the counter is told and nothing is uploaded', async () => {
    // Situation: storage full. The counter types 41.
    const disk = createFlakyStorage(createMemoryStorage());
    const device = await createDevice({ disk });
    disk.failWrites = true;

    // Expected: recordPass fails (the page shows "NÃO REGISTRADO"), the recent
    // list stays empty, and the upload queue has nothing to send.
    await assert.rejects(() => device.type(41));
    assert.equal(device.session.recentEntries().length, 0);
    disk.failWrites = false;
    await device.queue.syncOnce();
    assert.equal(device.server.storedEntries.size, 0);
});

test('each entry records how it was entered and how long the typing took', async () => {
    // Situation: one entry with the on-screen keypad (0.9 s from first digit
    // to Enter), one by tapping a tile (nothing is typed, so no typing time).
    const device = await createDevice();

    await device.session.recordPass({ car: 41, typingMs: 900, mode: MODO.TECLADO_TELA });
    await device.session.recordPass({ car: 7, typingMs: null, mode: MODO.BLOCOS });
    await device.queue.syncOnce();

    // Expected: both facts reach the server; reviewers use them to judge how
    // much to trust an entry's timing.
    const uploaded = device.server.receivedRequests[0].entries;
    assert.deepEqual(uploaded.map((entry) => [entry.car, entry.typing_ms, entry.mode]), [
        [41, 900, 'teclado_tela'],
        [7, null, 'blocos'],
    ]);
});

test('the result of an entry carries the team name for the big flash', async () => {
    // Situation: the phone knows the car list; the counter types 41.
    const device = await createDevice();
    await device.queue.syncOnce();

    const result = await device.type(41);

    assert.equal(result.nomeEquipe, 'Equipe Quarenta e Um');
    assert.deepEqual(result.avisos, []);
});

test('the recent list shows the last 10 entries, newest first', async () => {
    // Situation: twelve cars have passed.
    const device = await createDevice();
    for (let car = 1; car <= 12; car++) {
        await device.type(car);
    }

    const recent = device.session.recentEntries();

    // Expected: cars 12 down to 3. Cars 1 and 2 scrolled off the list (they
    // are still in the log and in the export, just not on screen).
    assert.deepEqual(recent.map((entry) => entry.car), [12, 11, 10, 9, 8, 7, 6, 5, 4, 3]);
    assert.equal(device.store.allEntries().length, 12);
});

test('"desfazer" marks the entry as undone but keeps it, on screen and on disk', async () => {
    // Situation: the counter typed 41, then 7, and realises 41 was wrong.
    const device = await createDevice();
    await device.type(41);
    await device.type(7);

    await device.session.undo('uuid-1');

    // Expected: still two rows in the recent list; the undone one is flagged
    // (the page draws it struck through) and keeps its number.
    const recent = device.session.recentEntries();
    assert.deepEqual(recent.map((entry) => [entry.car, entry.voided]), [[7, false], [41, true]]);

    await device.reload();
    assert.equal(device.store.findEntry('uuid-1').voided, true, 'the undo survives a reload');
    assert.equal(device.store.findEntry('uuid-1').car, 41);
});

test('tapping "desfazer" twice on the same entry is harmless', async () => {
    // Situation: a nervous double tap on the button.
    const device = await createDevice();
    await device.type(41);
    await device.queue.syncOnce();
    device.clock.advance(1000);
    await device.session.undo('uuid-1');
    const firstUndoAt = device.store.findEntry('uuid-1').voided_at_ms;
    device.clock.advance(1000);

    await device.session.undo('uuid-1');
    await device.queue.flush();

    // Expected: one undo, at the time of the first tap, uploaded once.
    assert.equal(device.store.findEntry('uuid-1').voided_at_ms, firstUndoAt);
    const requestsWithVoid = device.server.receivedRequests.filter((request) => request.voids.length > 0);
    assert.equal(requestsWithVoid.length, 1);
});

test('undoing something that does not exist does nothing', async () => {
    const device = await createDevice();
    await device.type(41);

    const result = await device.session.undo('no-such-uuid');

    assert.equal(result, null);
    assert.equal(device.store.findEntry('uuid-1').voided, false);
});
