// LOCAL STORE: what is on the counter's phone.
//
// Background for a reader new to this: a counter types the number of every car
// that crosses the line. Each number typed is an "entry". The phone may lose
// its signal, run out of battery or have its page reloaded by accident. The
// promise of the local store is simple: once the screen showed an entry as
// recorded, that entry exists on the phone's disk and stays there forever.
//
// In these tests the "disk" is an in-memory object (createMemoryStorage). A
// "reload" is simulated by building a brand-new LocalStore on the same disk:
// everything the old store had in memory is gone, only the disk remains.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createMemoryStorage } from '../../default/js/contagem/storage-memory.js';
import { LocalStore } from '../../default/js/contagem/local-store.js';
import { createFlakyStorage, createSlowStorage } from './helpers/fakes.js';

function entry(uuid, car, tsDeviceMs = 1000) {
    return { uuid, car, ts_device_ms: tsDeviceMs, clock_offset_ms: null, typing_ms: 400, mode: 'teclado' };
}

test('an entry typed just before the page is reloaded is still there afterwards', async () => {
    // Situation: the counter types car 41 and, a moment later, the browser
    // reloads the page (a slip of the thumb, or the phone restarting).
    const disk = createMemoryStorage();
    const beforeReload = await LocalStore.open(disk);
    await beforeReload.addEntry({
        uuid: 'aaa', car: 41, ts_device_ms: 1_760_000_123_456, clock_offset_ms: -120, typing_ms: 850, mode: 'teclado_tela',
    });

    // The reload: a new store that knows nothing except what is on the disk.
    const afterReload = await LocalStore.open(disk);

    // Expected: the entry is back, with every detail exactly as typed, and it
    // is still waiting to be uploaded.
    const entries = afterReload.allEntries();
    assert.equal(entries.length, 1);
    assert.equal(entries[0].uuid, 'aaa');
    assert.equal(entries[0].car, 41);
    assert.equal(entries[0].ts_device_ms, 1_760_000_123_456);
    assert.equal(entries[0].clock_offset_ms, -120);
    assert.equal(entries[0].typing_ms, 850);
    assert.equal(entries[0].mode, 'teclado_tela');
    assert.equal(entries[0].sent, false);
    assert.equal(afterReload.unsentEntries().length, 1);
});

test('entries keep the order they were typed in, across a reload', async () => {
    // Situation: three cars pass, the page reloads, two more pass.
    const disk = createMemoryStorage();
    const beforeReload = await LocalStore.open(disk);
    await beforeReload.addEntry(entry('a', 7));
    await beforeReload.addEntry(entry('b', 41));
    await beforeReload.addEntry(entry('c', 12));

    const afterReload = await LocalStore.open(disk);
    await afterReload.addEntry(entry('d', 7));
    await afterReload.addEntry(entry('e', 99));

    // Expected: one list, in typing order. (All five entries above carry the
    // same timestamp on purpose: the order must not depend on the clock.)
    assert.deepEqual(afterReload.allEntries().map((item) => item.car), [7, 41, 12, 7, 99]);

    // And a third reload agrees.
    const thirdLoad = await LocalStore.open(disk);
    assert.deepEqual(thirdLoad.allEntries().map((item) => item.uuid), ['a', 'b', 'c', 'd', 'e']);
});

test('an entry already uploaded is remembered as uploaded after a reload', async () => {
    // Situation: two entries, the first was uploaded, then the page reloads.
    const disk = createMemoryStorage();
    const beforeReload = await LocalStore.open(disk);
    await beforeReload.addEntry(entry('a', 7));
    await beforeReload.addEntry(entry('b', 41));
    await beforeReload.markEntriesSent(['a'], 5000);

    const afterReload = await LocalStore.open(disk);

    // Expected: only the second one is still in the upload queue. If the mark
    // were forgotten the phone would upload "a" again for nothing.
    assert.deepEqual(afterReload.unsentEntries().map((item) => item.uuid), ['b']);
    assert.equal(afterReload.findEntry('a').sent, true);
    assert.equal(afterReload.findEntry('a').sent_at_ms, 5000);
    assert.equal(afterReload.pendingCount(), 1);
});

test('nothing is ever deleted: uploaded and undone entries all stay in the log', async () => {
    // Situation: the counter typed three cars. One was uploaded, one was
    // undone with "desfazer" (the counter hit the wrong key), one is waiting.
    const disk = createMemoryStorage();
    const store = await LocalStore.open(disk);
    await store.addEntry(entry('uploaded', 7));
    await store.addEntry(entry('undone', 41));
    await store.addEntry(entry('waiting', 12));
    await store.markEntriesSent(['uploaded', 'undone'], 5000);
    await store.markEntryVoided('undone', 6000);
    await store.markVoidsSent(['undone']);

    // Expected: the log still has all three. "Sent" and "voided" are marks ON
    // an entry, never a reason to remove it. This is what makes the CSV
    // export a complete record of what this person typed.
    assert.equal(store.allEntries().length, 3);
    assert.equal((await disk.getAll('entries')).length, 3);

    const undone = (await LocalStore.open(disk)).findEntry('undone');
    assert.equal(undone.car, 41, 'the undone entry keeps the number that was typed');
    assert.equal(undone.voided, true);
    assert.equal(undone.voided_at_ms, 6000);
    assert.equal(undone.void_sent, true);
});

test('if the phone cannot write to its disk, the entry is NOT shown as recorded', async () => {
    // Situation: the phone's storage is full. The counter types car 41.
    const disk = createFlakyStorage(createMemoryStorage());
    const store = await LocalStore.open(disk);
    disk.failWrites = true;

    // Expected: recording fails loudly (the page then shows "NÃO REGISTRADO")
    // instead of pretending. An entry that exists only in memory would vanish
    // on the next reload without anyone noticing, which is the worst outcome.
    await assert.rejects(() => store.addEntry(entry('a', 41)));
    assert.equal(store.allEntries().length, 0);
    assert.equal(store.pendingCount(), 0);

    // When the disk works again, typing works again and the order is intact.
    disk.failWrites = false;
    await store.addEntry(entry('b', 41));
    assert.deepEqual(store.allEntries().map((item) => item.uuid), ['b']);
});

test('if marking an entry as uploaded cannot be written, it stays in the queue', async () => {
    // Situation: the server confirmed entry "a", but at that very moment the
    // disk refuses the write of the "sent" mark.
    const disk = createFlakyStorage(createMemoryStorage());
    const store = await LocalStore.open(disk);
    await store.addEntry(entry('a', 41));
    disk.failWrites = true;

    await assert.rejects(() => store.markEntriesSent(['a'], 5000));

    // Expected: memory and disk agree that it is NOT marked. It will be sent
    // once more later, which is harmless (the server ignores a uuid it already
    // has). The opposite mistake, marked in memory but not on disk, is harmless
    // too, but disagreeing states are how hard-to-find bugs start.
    assert.equal(store.findEntry('a').sent, false);
    assert.equal((await disk.getAll('entries'))[0].sent, false);
});

test('"na fila" counts everything the server has not confirmed yet', async () => {
    // Situation: one entry waiting, one entry uploaded and then undone (the
    // undo itself still has to be uploaded), one pit event waiting.
    const store = await LocalStore.open(createMemoryStorage());
    await store.addEntry(entry('waiting', 7));
    await store.addEntry(entry('undone', 41));
    await store.markEntriesSent(['undone'], 5000);
    await store.markEntryVoided('undone', 6000);
    await store.addPitEvent({ uuid: 'pit', car: 12, tipo: 'box_entrada', ts_device_ms: 7000, clock_offset_ms: null });

    // Expected: the status bar shows 3.
    assert.equal(store.pendingCount(), 3);

    await store.markEntriesSent(['waiting'], 8000);
    await store.markVoidsSent(['undone']);
    await store.markPitEventsSent(['pit'], 8000);
    assert.equal(store.pendingCount(), 0);
});

test('an undo is only offered for upload after its entry is on the server', async () => {
    // Situation: the counter types 41 and immediately undoes it, while offline.
    const store = await LocalStore.open(createMemoryStorage());
    await store.addEntry(entry('a', 41));
    await store.markEntryVoided('a', 2000);

    // Expected: the undo waits. The server can only void an entry it knows.
    assert.equal(store.voidsReadyToSend().length, 0);
    assert.equal(store.pendingCount(), 2, 'both the entry and its undo are still pending');

    await store.markEntriesSent(['a'], 3000);
    assert.deepEqual(store.voidsReadyToSend().map((item) => item.uuid), ['a']);
});

test('pit events survive a reload exactly like entries', async () => {
    // Situation: the pit marshal registers car 12 entering, then the page reloads.
    const disk = createMemoryStorage();
    const beforeReload = await LocalStore.open(disk);
    await beforeReload.addPitEvent({ uuid: 'p1', car: 12, tipo: 'box_entrada', ts_device_ms: 7000, clock_offset_ms: 30 });

    const afterReload = await LocalStore.open(disk);

    assert.equal(afterReload.allPitEvents().length, 1);
    assert.equal(afterReload.allPitEvents()[0].tipo, 'box_entrada');
    assert.equal(afterReload.unsentPitEvents().length, 1);
});

test('the device token and other settings survive a reload', async () => {
    // Situation: the page received its upload token, then was reloaded with no
    // network. Without the token on disk it could not upload ever again
    // without a new login.
    const disk = createMemoryStorage();
    const beforeReload = await LocalStore.open(disk);
    await beforeReload.setMeta('dispositivo', { token: 'secret-token', usuario: 'equipe1' });

    const afterReload = await LocalStore.open(disk);

    assert.deepEqual(afterReload.getMeta('dispositivo'), { token: 'secret-token', usuario: 'equipe1' });
    assert.equal(afterReload.getMeta('never-set', 'fallback'), 'fallback');
});

test('what is on the disk cannot be changed by accident through an old object', async () => {
    // Situation: a programming slip elsewhere modifies the object that was
    // handed to the store. A real database keeps its own copy; so must ours,
    // otherwise the tests in this folder would prove less than they seem to.
    const disk = createMemoryStorage();
    const store = await LocalStore.open(disk);
    const original = entry('a', 41);
    await store.addEntry(original);
    original.car = 999;

    const afterReload = await LocalStore.open(disk);
    assert.equal(afterReload.findEntry('a').car, 41);
});

// ---------------------------------------------------------------------------
// Two cars in the same instant
// ---------------------------------------------------------------------------

test('two cars recorded in the same instant get different sequence numbers, in typing order', async () => {
    // Situation: two cars cross the line side by side. The counter taps tile
    // 7 and tile 41 one right after the other, faster than the phone's
    // storage answers. On top of that the storage finishes the SECOND write
    // first (it makes no promise about the order).
    //
    // What used to go wrong: the sequence number was only "taken" after the
    // disk had answered. Both entries looked at the counter while it still
    // said 1, so both were stored as number 1, and after a reload nobody
    // could tell which car had been typed first.
    const disk = createSlowStorage(createMemoryStorage());
    const store = await LocalStore.open(disk);
    disk.holdWrites = true;

    const writingCar7 = store.addEntry(entry('a', 7));
    const writingCar41 = store.addEntry(entry('b', 41));
    await disk.finishWrite(1);      // car 41 reaches the disk first...
    await disk.finishWrite(0);      // ...car 7 a moment later
    const [car7, car41] = await Promise.all([writingCar7, writingCar41]);

    // Expected: 7 is number 1 and 41 is number 2, whatever the disk did.
    assert.equal(car7.seq, 1);
    assert.equal(car41.seq, 2);
    // The list on the screen is in typing order...
    assert.deepEqual(store.allEntries().map((item) => item.car), [7, 41]);
    // ...and so is the list after a reload.
    const afterReload = await LocalStore.open(disk);
    assert.deepEqual(afterReload.allEntries().map((item) => item.car), [7, 41]);
});

test('a write that fails leaves a gap in the numbering, never a repeated number', async () => {
    // Situation: the disk refuses one entry (the counter is told "NÃO
    // REGISTRADO" and types it again). The next entries must still get
    // numbers nobody else has.
    const disk = createFlakyStorage(createMemoryStorage());
    const store = await LocalStore.open(disk);
    const first = await store.addEntry(entry('a', 7));

    disk.failWrites = true;
    await assert.rejects(() => store.addEntry(entry('b', 41)));
    disk.failWrites = false;
    const retyped = await store.addEntry(entry('c', 41));

    // Expected: 1, then 3. Number 2 was used up by the failed attempt.
    assert.deepEqual([first.seq, retyped.seq], [1, 3]);
    assert.deepEqual(store.allEntries().map((item) => item.uuid), ['a', 'c']);
});

test('the store knows when something is still on its way to the disk', async () => {
    // Situation: the counter pressed Enter; the phone's storage has not
    // answered yet. The entry is in no list at this moment (rule 1: disk
    // first), but it exists. The upload queue needs to know that, see
    // "queue_empty" in sync-queue.test.js.
    const disk = createSlowStorage(createMemoryStorage());
    const store = await LocalStore.open(disk);
    disk.holdWrites = true;
    assert.equal(store.hasWriteInFlight(), false);

    const writing = store.addEntry(entry('a', 7));
    assert.equal(store.hasWriteInFlight(), true);
    assert.equal(store.pendingCount(), 0, 'not in the list yet');

    await disk.finishNextWrite();
    await writing;
    assert.equal(store.hasWriteInFlight(), false);
    assert.equal(store.pendingCount(), 1);
});
