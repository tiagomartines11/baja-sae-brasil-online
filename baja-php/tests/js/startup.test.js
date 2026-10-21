// STARTUP: the two things the page settles before it lets anybody count.
//
//   1. Is this the only tab open on this source, on this device?
//   2. Can the records stored on this device be opened?
//
// Both protect the same promise the page makes to the server with every
// upload: "this is everything this device has recorded". A second tab, or a
// page that could not open the device's storage, does not know everything the
// device has recorded, and must not say so.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { claimSingleTab, ABA } from '../../default/js/contagem/single-tab.js';
import { openStorageWithRetry, ARMAZENAMENTO } from '../../default/js/contagem/storage-open.js';
import { createMemoryStorage } from '../../default/js/contagem/storage-memory.js';
import { RESULTADO } from '../../default/js/contagem/sync-queue.js';
import { createDevice, FakeBrowserLocks, FakeClock, FakeServer } from './helpers/fakes.js';

// ---------------------------------------------------------------------------
// One tab per device and source
// ---------------------------------------------------------------------------
//
// `FakeBrowserLocks` plays the browser's "Web Locks": a lock has one holder
// at a time and is given up when the holder's tab is closed.

const LOCK_OF_SOURCE_1 = 'contagem-26BR-END-1';

test('the second tab on the same source is told another tab is open, and does not start', async () => {
    // Situation: a counter opens the count page twice by accident (a tap on
    // a bookmark while the page is already open in another tab). Both tabs
    // read the same records from the phone's disk, but each keeps its own
    // copy in memory.
    //
    // What used to go wrong: both tabs ran. A car typed in tab A was unknown
    // to tab B, yet tab B went on telling the server every 5 seconds "you
    // have everything this phone recorded". The server believed it and
    // charged the counter with misses for entries that were merely waiting
    // in tab A.
    const browser = new FakeBrowserLocks();
    const disk = createMemoryStorage();
    const clock = new FakeClock();
    const server = new FakeServer(clock);

    // What page-setup.js does at startup, for each tab: claim first, and
    // build the store and the upload queue only if the claim succeeded.
    async function openTab(tabName) {
        const claim = await claimSingleTab(browser.forTab(tabName), LOCK_OF_SOURCE_1);
        const device = claim.resultado === ABA.OUTRA_ABERTA
            ? null
            : await createDevice({ disk, clock, server, uuidPrefix: tabName });
        return { claim, device };
    }

    const tabA = await openTab('tab-a');
    const tabB = await openTab('tab-b');

    // Expected: tab A counts; tab B has no store and no queue at all, so
    // there is nothing in it that could talk to the server.
    assert.equal(tabA.claim.resultado, ABA.UNICA);
    assert.equal(tabB.claim.resultado, ABA.OUTRA_ABERTA);
    assert.equal(tabB.device, null);

    await tabA.device.type(41);
    await tabA.device.queue.flush();
    assert.deepEqual(server.storedCars(), [41]);
});

test('when the first tab is closed, the waiting tab takes over and sees everything', async () => {
    // Situation: as above; then the counter closes tab A and stays on tab B.
    const browser = new FakeBrowserLocks();
    const disk = createMemoryStorage();
    const clock = new FakeClock();
    const server = new FakeServer(clock);
    server.behaviour = 'offline';

    await claimSingleTab(browser.forTab('tab-a'), LOCK_OF_SOURCE_1);
    const tabA = await createDevice({ disk, clock, server, uuidPrefix: 'tab-a' });
    await tabA.type(41);                        // waiting on the disk, not uploaded

    const claimOfTabB = await claimSingleTab(browser.forTab('tab-b'), LOCK_OF_SOURCE_1);
    let tabBMayStart = false;
    const waiting = claimOfTabB.whenFree().then(() => {
        tabBMayStart = true;
    });
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(tabBMayStart, false, 'still waiting while tab A is open');

    browser.closeTab('tab-a');
    await waiting;

    // Expected: tab B starts only now, and because it opens the store AFTER
    // tab A is gone, it finds the entry tab A left behind and uploads it.
    assert.equal(tabBMayStart, true);
    const tabB = await createDevice({ disk, clock, server, uuidPrefix: 'tab-b' });
    server.behaviour = 'ok';
    await tabB.queue.flush();
    assert.deepEqual(server.storedCars(), [41]);
});

test('tabs on different sources do not block each other', async () => {
    // Situation: one laptop, two windows: "Contagem 1" and the pit log.
    // These are different sources with separate records.
    const browser = new FakeBrowserLocks();

    const countPage = await claimSingleTab(browser.forTab('tab-a'), 'contagem-26BR-END-1');
    const pitPage = await claimSingleTab(browser.forTab('tab-b'), 'contagem-26BR-END-4');

    assert.equal(countPage.resultado, ABA.UNICA);
    assert.equal(pitPage.resultado, ABA.UNICA);
});

test('a browser without Web Locks carries on as before', async () => {
    // Situation: an old browser, or a page served without https: there
    // `navigator.locks` does not exist.
    const noLocks = await claimSingleTab(undefined, LOCK_OF_SOURCE_1);
    // Or it exists and refuses to work.
    const brokenLocks = await claimSingleTab({
        request: () => Promise.reject(new Error('simulated: SecurityError')),
    }, LOCK_OF_SOURCE_1);

    // Expected: the page starts. Counting without this protection is far
    // better than not counting.
    assert.equal(noLocks.resultado, ABA.SEM_TRAVA);
    assert.equal(brokenLocks.resultado, ABA.SEM_TRAVA);
    await noLocks.whenFree();
});

// ---------------------------------------------------------------------------
// Opening the device's storage
// ---------------------------------------------------------------------------
//
// `openPersistent` stands for opening IndexedDB, the browser's database on
// the device. Timers are replaced so that "4 seconds pass" takes no time.

/** setTimeout/clearTimeout that fire at once, in the order they were set. */
function instantTimers() {
    return {
        setTimeoutFn: (callback) => setImmediate(callback),
        clearTimeoutFn: (timer) => clearImmediate(timer),
    };
}

test('a storage that fails to open at first is tried again before giving up', async () => {
    // Situation: the phone has 30 unsent entries on its disk. The counter
    // reloads the page; the first attempt to open the storage fails (the
    // browser was still closing the old page's connection).
    //
    // What used to go wrong: one failure was enough to fall back to an empty
    // memory-only log. The page showed "na fila: 0" and reported an empty
    // queue to the server, while the 30 entries sat on the disk unseen.
    const realDisk = { ...createMemoryStorage(), persistent: true };
    let attempts = 0;

    const opened = await openStorageWithRetry({
        persistentIsSupported: true,
        openPersistent: async () => {
            attempts++;
            if (attempts < 3) {
                throw new Error('simulated: could not open');
            }
            return realDisk;
        },
        createMemory: createMemoryStorage,
        ...instantTimers(),
    });

    // Expected: third time lucky, and the page runs on the real disk.
    assert.equal(attempts, 3);
    assert.equal(opened.armazenamento, ARMAZENAMENTO.DISCO);
    assert.equal(opened.storage, realDisk);
});

test('an open that never answers is abandoned after a time limit, and tried again', async () => {
    // Situation: the first attempt hangs forever (it happens: the browser
    // neither opens the database nor reports an error). The second works.
    const realDisk = { ...createMemoryStorage(), persistent: true };
    let attempts = 0;

    const opened = await openStorageWithRetry({
        persistentIsSupported: true,
        openPersistent: () => {
            attempts++;
            return attempts === 1 ? new Promise(() => {}) : Promise.resolve(realDisk);
        },
        createMemory: createMemoryStorage,
        ...instantTimers(),
    });

    // Expected: the page did not hang on "Abrindo…" for ever.
    assert.equal(attempts, 2);
    assert.equal(opened.armazenamento, ARMAZENAMENTO.DISCO);
});

test('a connection that arrives after the page stopped waiting is closed again', async () => {
    // Situation: the first attempt answers, but only after its time limit.
    // By then the page is already on its second attempt. A forgotten open
    // connection could block that one.
    let closedLateConnections = 0;
    const lateDisk = { ...createMemoryStorage(), close: () => { closedLateConnections++; } };
    const realDisk = { ...createMemoryStorage(), persistent: true };
    let deliverLate = null;
    let attempts = 0;

    const opened = await openStorageWithRetry({
        persistentIsSupported: true,
        openPersistent: () => {
            attempts++;
            if (attempts === 1) {
                return new Promise((resolve) => { deliverLate = () => resolve(lateDisk); });
            }
            return Promise.resolve(realDisk);
        },
        createMemory: createMemoryStorage,
        ...instantTimers(),
    });
    deliverLate();
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(opened.storage, realDisk);
    assert.equal(closedLateConnections, 1);
});

test('when the storage cannot be opened at all, the page says so and counts in memory', async () => {
    // Situation: every attempt fails.
    let attempts = 0;
    const opened = await openStorageWithRetry({
        persistentIsSupported: true,
        openPersistent: async () => {
            attempts++;
            throw new Error('simulated: could not open');
        },
        createMemory: createMemoryStorage,
        ...instantTimers(),
    });

    // Expected: three attempts, then memory, flagged as "the stored records
    // could not be opened". That is a different situation from a browser
    // that has no storage at all, and the page words its warning accordingly
    // (see status-messages.test.js).
    assert.equal(attempts, 3);
    assert.equal(opened.armazenamento, ARMAZENAMENTO.NAO_ABRIU);
    assert.equal(opened.storage.persistent, false);

    const noStorageAtAll = await openStorageWithRetry({
        persistentIsSupported: false,
        openPersistent: async () => assert.fail('must not be tried'),
        createMemory: createMemoryStorage,
    });
    assert.equal(noStorageAtAll.armazenamento, ARMAZENAMENTO.SEM_SUPORTE);
});

test('a page counting in memory sends no heartbeat and never claims the server has everything', async () => {
    // Situation: the storage could not be opened, so this page started with
    // an empty log in memory. On the disk there may be entries from before
    // the reload that this page cannot see.
    //
    // What used to go wrong: the page behaved normally. Its heartbeat said
    // "queue_empty: true" every 5 seconds, which the server reads as "this
    // device has uploaded everything it recorded". That was false.
    const device = await createDevice({ vouchesForHistory: false });

    for (let tick = 0; tick < 3; tick++) {
        device.clock.advance(5000);
        device.heartbeat.tick();
        await device.queue.flush();
    }
    const quiet = await device.queue.syncOnce();

    // Expected: with nothing typed, nothing is sent at all.
    assert.equal(quiet.resultado, RESULTADO.SILENCIO);
    assert.equal(device.server.receivedRequests.length, 0);

    // What the counter types into this page is still uploaded...
    await device.type(41);
    await device.queue.flush();
    await device.queue.flush();
    assert.deepEqual(device.server.storedCars(), [41]);

    // ...but no request ever carries the claim, not even after the upload.
    assert.ok(device.server.receivedRequests.length >= 1);
    for (const request of device.server.receivedRequests) {
        assert.equal(request.queue_empty, false);
    }
});
