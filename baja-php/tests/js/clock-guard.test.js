// THE CLOCK GUARD, WHOLE-PHONE STORIES: what happens to the times of passes
// when one of the phone's two clocks misbehaves in the middle of a race.
//
// Background (see server-clock.js): passes are timed with the MONOTONIC clock
// (`performance.now()`), tied to the server's time by an ANCHOR. The system
// clock (`Date.now()`) is only a witness. Once a second, and at every key
// press, a GUARD compares how far each clock moved:
//
//   - the system clock moved LESS than the monotonic one, or went backwards:
//     somebody changed the system clock. Nothing the page times with was
//     touched, so nothing happens.
//   - the system clock moved MORE (by over 2 s): either the monotonic clock
//     stood still (a sleeping phone), or the system clock was set forward.
//     The page cannot tell which. The anchor becomes SUSPECT, new entries
//     are held, and the next answer from the server settles it.
//
// Each test plays a counter's phone (helpers/fakes.js) and compares the time
// STORED ON THE SERVER with the true time of the key press.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { ESTADO_RELOGIO } from '../../default/js/contagem/server-clock.js';
import { describeOfficialTime } from '../../default/js/contagem/race-clock.js';
import { createDevice } from './helpers/fakes.js';

const TEN_MINUTES_MS = 10 * 60 * 1000;

/**
 * Lets `durationMs` pass on a phone with the page open: the guard runs once
 * a second and the heartbeat every 5 seconds, as the page's timers do.
 * `everySecond(elapsedMs)` is where the test makes things happen.
 */
async function keepPageOpen(device, durationMs, everySecond = async () => {}) {
    for (let elapsedMs = 1000; elapsedMs <= durationMs; elapsedMs += 1000) {
        device.clock.advance(1000);
        await everySecond(elapsedMs);
        device.runClockGuardTimer();
        if (elapsedMs % 5000 === 0) {
            await device.queue.flush();
        }
    }
}

function storedEntries(device) {
    return Array.from(device.server.storedEntries.values());
}

/** How far each stored time is from the true time of its key press, in ms. */
function errorsMs(device, trueTimesByUuid) {
    return storedEntries(device).map((stored) => Math.abs(stored.ts_ms - trueTimesByUuid.get(stored.uuid)));
}

/** Types a car and remembers the true time of the key press. */
async function typeAndRemember(device, car, trueTimesByUuid) {
    const trueMs = device.clock.serverNow();
    const { entry } = await device.type(car);
    trueTimesByUuid.set(entry.uuid, trueMs);
}

test('a system clock that keeps stepping back while offline changes no time', async () => {
    // SITUATION (measured on a real page in round 3's review): the phone
    // synced, then lost the signal for 10 minutes. Meanwhile its system
    // clock is stepped back by 2.1 s every 30 s (a phone fighting with a
    // bad time source). The monotonic clock runs steadily. The counter
    // types a car every 20 s.
    //
    // WHAT WENT WRONG: the guard treated ANY disagreement of more than 2 s
    // as "the anchor is lost". Every step threw the anchor away. 28 of 30
    // entries went up as "estimado", wrong by up to 40 s (the steps added
    // up), and the official clock on screen was blank almost throughout.
    const device = await createDevice({ anchored: false });
    await device.queue.syncOnce();
    device.server.behaviour = 'offline';
    const trueTimes = new Map();
    const clockTexts = new Set();

    await keepPageOpen(device, TEN_MINUTES_MS, async (elapsedMs) => {
        if (elapsedMs % 30_000 === 0) {
            device.clock.stepWallClock(-2100);
        }
        if (elapsedMs % 20_000 === 0) {
            await typeAndRemember(device, 41, trueTimes);
        }
        clockTexts.add(device.serverClock.estado());
        assert.equal(device.serverClock.serverNowMs(), device.clock.serverNow(), 'the official clock stays right');
    });

    device.server.behaviour = 'ok';
    await device.queue.flush();

    // EXPECTED: a system clock that went backwards says nothing about the
    // monotonic clock. All 30 entries are exact and right, and the page
    // never stopped showing the official time.
    assert.equal(storedEntries(device).length, 30);
    assert.deepEqual(new Set(storedEntries(device).map((stored) => stored.time_quality)), new Set(['exato']));
    assert.ok(Math.max(...errorsMs(device, trueTimes)) <= 1, 'largest error: ' + Math.max(...errorsMs(device, trueTimes)) + ' ms');
    assert.deepEqual(Array.from(clockTexts), [ESTADO_RELOGIO.OK]);
    assert.equal(device.store.heldCount(), 0);
});

test('a system clock stepped back, then a reload with no network: still exact', async () => {
    // SITUATION: synced, then offline. The system clock is stepped back
    // three times; the counter types two cars. Then the browser is
    // restarted, still offline, and two more cars are typed before the
    // signal returns.
    //
    // WHAT WENT WRONG: the first two cars were held (their anchor had been
    // thrown away by the guard). After the reload only the system clock
    // could date them, and it had been changed: "estimado", seconds off.
    const device = await createDevice({ anchored: false });
    await device.queue.syncOnce();
    device.server.behaviour = 'offline';
    const trueTimes = new Map();

    await keepPageOpen(device, 90_000, async (elapsedMs) => {
        if (elapsedMs % 30_000 === 0) {
            device.clock.stepWallClock(-2100);
        }
        if (elapsedMs === 40_000 || elapsedMs === 80_000) {
            await typeAndRemember(device, 7, trueTimes);
        }
    });
    // Stamped at the key press, from the anchor: final before the reload.
    assert.deepEqual(device.store.allEntries().map((entry) => entry.time_quality), ['exato', 'exato']);

    await device.reload();
    await keepPageOpen(device, 60_000, async (elapsedMs) => {
        if (elapsedMs === 20_000 || elapsedMs === 50_000) {
            await typeAndRemember(device, 41, trueTimes);
        }
    });
    device.server.behaviour = 'ok';
    await device.queue.flush();

    // EXPECTED: the first two were final already; the last two were held
    // by the new page load and stamped from its first anchor. All exact.
    assert.equal(storedEntries(device).length, 4);
    assert.deepEqual(storedEntries(device).map((stored) => stored.time_quality), ['exato', 'exato', 'exato', 'exato']);
    assert.ok(Math.max(...errorsMs(device, trueTimes)) <= 1);
});

test('a system clock set FORWARD: entries are held, the clock says "verificando", and all come out exact', async () => {
    // SITUATION: synced with a very quick answer (4 ms round trip), then
    // offline. The counter fixes the phone's time zone by hand: the system
    // clock jumps one hour ahead. Two cars are typed; then the signal
    // returns, over a slow connection (300 ms each way).
    //
    // WHY THE PAGE CANNOT DECIDE ALONE: "the system clock moved an hour more
    // than the monotonic clock" is also exactly what a phone that slept for
    // an hour looks like.
    const device = await createDevice({ anchored: false });
    device.server.uploadDelayMs = 2;
    device.server.downloadDelayMs = 2;
    await device.queue.syncOnce();
    device.server.behaviour = 'offline';
    const trueTimes = new Map();

    await keepPageOpen(device, 10_000);
    device.clock.stepWallClock(60 * 60 * 1000);
    await keepPageOpen(device, 30_000, async (elapsedMs) => {
        if (elapsedMs === 10_000 || elapsedMs === 25_000) {
            await typeAndRemember(device, 12, trueTimes);
        }
    });

    // While in doubt: the official clock keeps running from the anchor in
    // doubt (here it is in fact right), marked "verificando"; the entries
    // wait; and the server is not promised anything.
    assert.equal(device.serverClock.estado(), ESTADO_RELOGIO.VERIFICANDO);
    assert.equal(device.serverClock.serverNowMs(), device.clock.serverNow());
    assert.match(describeOfficialTime(device.serverClock.serverNowMs(), 'UTC', device.serverClock.estado()), /^\d\d:\d\d:\d\d \(verificando\)$/);
    assert.equal(device.queue.getState().aguardandoHorario, 2);
    assert.equal(device.queue.buildRequest().now_ms, null);
    assert.equal(device.queue.buildRequest().queue_empty, false);

    device.server.behaviour = 'ok';
    device.server.uploadDelayMs = 300;
    device.server.downloadDelayMs = 300;
    await device.queue.flush();

    // EXPECTED: the server's time is what the anchor predicted, so the
    // monotonic clock never stood still. The anchor is restored (the old,
    // quick one: the slow answer only confirmed it), and the two entries
    // are stamped from the monotonic reading of their key press: exact.
    assert.equal(device.serverClock.estado(), ESTADO_RELOGIO.OK);
    assert.equal(device.serverClock.roundTripMs(), 4, 'the good anchor was kept');
    assert.deepEqual(storedEntries(device).map((stored) => stored.time_quality), ['exato', 'exato']);
    assert.ok(Math.max(...errorsMs(device, trueTimes)) <= 1);
});

test('a monotonic clock that really stood still: new anchor, and later entries are right', async () => {
    // SITUATION: synced, one car typed. Then the signal drops and the phone
    // sleeps for 3 minutes; on this phone `performance.now()` does not
    // count while it sleeps. Awake again, still offline, the counter types
    // two cars. Then the signal returns.
    const device = await createDevice({ anchored: false });
    await device.queue.syncOnce();
    const trueTimes = new Map();
    await keepPageOpen(device, 10_000);
    await typeAndRemember(device, 7, trueTimes);
    await device.queue.flush();
    device.server.behaviour = 'offline';

    device.clock.sleepWithStalledMonotonic(180_000);
    await keepPageOpen(device, 30_000, async (elapsedMs) => {
        if (elapsedMs === 10_000 || elapsedMs === 25_000) {
            await typeAndRemember(device, 41, trueTimes);
        }
    });

    // While in doubt the clock on screen is the old anchor's, here 3
    // minutes behind, and it SAYS it is being checked. Nothing is stamped
    // from it.
    assert.equal(device.serverClock.estado(), ESTADO_RELOGIO.VERIFICANDO);
    assert.equal(device.serverClock.serverNowMs(), device.clock.serverNow() - 180_000);
    assert.equal(device.queue.getState().aguardandoHorario, 2);

    device.server.behaviour = 'ok';
    await device.queue.flush();

    // EXPECTED: the server's time is 3 minutes ahead of what the anchor
    // predicted: the monotonic clock did stand still. A new anchor is taken.
    // The entries typed after waking were held in the new stretch of
    // monotonic clock, so they are exact; the one from before was final.
    assert.equal(device.serverClock.estado(), ESTADO_RELOGIO.OK);
    assert.equal(device.serverClock.serverNowMs(), device.clock.serverNow());
    assert.deepEqual(storedEntries(device).map((stored) => stored.time_quality), ['exato', 'exato', 'exato']);
    assert.ok(Math.max(...errorsMs(device, trueTimes)) <= 1);
});

test('entries held from BEFORE a real stall are the only ones marked "estimado"', async () => {
    // SITUATION: the page is opened with no signal, so it has no anchor.
    // A car is typed (held). The phone sleeps 2 minutes with a stalled
    // monotonic clock. Awake, another car is typed (held). Then the first
    // answer arrives.
    const device = await createDevice({ anchored: false });
    device.server.behaviour = 'offline';
    const trueTimes = new Map();
    await keepPageOpen(device, 5000);
    await typeAndRemember(device, 7, trueTimes);

    device.clock.sleepWithStalledMonotonic(120_000);
    await keepPageOpen(device, 5000);
    await typeAndRemember(device, 41, trueTimes);
    device.server.behaviour = 'ok';
    await device.queue.flush();

    // EXPECTED: the monotonic readings on the two sides of the sleep cannot
    // be subtracted. The entry from before it is dated with the help of
    // the system clock and says so; the entry from after it is exact.
    assert.deepEqual(storedEntries(device).map((stored) => [stored.car, stored.time_quality]), [[7, 'estimado'], [41, 'exato']]);
    assert.ok(Math.max(...errorsMs(device, trueTimes)) <= 1);
});

test('a step forward and then steps back, before any answer: one check settles it', async () => {
    // SITUATION: offline. The system clock is set 5 minutes ahead (the
    // anchor becomes suspect), then stepped back twice by a few seconds.
    // A car is typed after each change.
    const device = await createDevice({ anchored: false });
    await device.queue.syncOnce();
    device.server.behaviour = 'offline';
    const trueTimes = new Map();

    await keepPageOpen(device, 5000);
    device.clock.stepWallClock(300_000);
    await keepPageOpen(device, 5000);
    await typeAndRemember(device, 7, trueTimes);
    device.clock.stepWallClock(-3000);
    await keepPageOpen(device, 5000);
    await typeAndRemember(device, 41, trueTimes);
    device.clock.stepWallClock(-4000);
    await keepPageOpen(device, 5000);
    await typeAndRemember(device, 12, trueTimes);
    // And forward once more: a second doubt on top of the first.
    device.clock.stepWallClock(60_000);
    await keepPageOpen(device, 5000);
    await typeAndRemember(device, 3, trueTimes);

    // The steps back do not clear the doubt (only the server can), and do
    // not add to it.
    assert.equal(device.serverClock.estado(), ESTADO_RELOGIO.VERIFICANDO);

    device.server.behaviour = 'ok';
    await device.queue.flush();

    // EXPECTED: the monotonic clock never stood still, and one answer
    // proves it for the whole stretch: every entry held since the FIRST
    // doubt is exact, not only those since the last one.
    assert.deepEqual(storedEntries(device).map((stored) => stored.time_quality), ['exato', 'exato', 'exato', 'exato']);
    assert.ok(Math.max(...errorsMs(device, trueTimes)) <= 1);
});

test('when a doubt arises the page asks the server at once, not at the next heartbeat', async () => {
    // SITUATION: online, quiet. The phone wakes from a 40 s sleep with a
    // stalled monotonic clock. The once-a-second timer notices.
    const device = await createDevice({ anchored: false });
    await device.queue.syncOnce();
    const requestsBefore = device.server.receivedRequests.length;
    device.clock.sleepWithStalledMonotonic(40_000);
    device.clock.advance(1000);

    const doubtArose = device.runClockGuardTimer();
    // Wait for the upload the timer itself started, if it started one.
    // (The test must not start one of its own: that is what is being tested.)
    await device.queue.flushPromise;

    // EXPECTED: a request went out because of the doubt, and its answer
    // settled it: "verificando" lasts one round trip, not up to 5 seconds.
    assert.equal(doubtArose, true);
    assert.ok(device.server.receivedRequests.length > requestsBefore);
    assert.equal(device.serverClock.estado(), ESTADO_RELOGIO.OK);
    assert.equal(device.serverClock.serverNowMs(), device.clock.serverNow());
});

test('the answer that settles the doubt uses the time the server spent on the request', async () => {
    // SITUATION: the server takes 800 ms to process a big upload. It now
    // writes two times in its answer: when the request arrived and when
    // the answer left (`server_received_ms`, `server_sent_ms`).
    //
    // WHAT WOULD GO WRONG with one time only: the 800 ms would count as
    // travel time. The page would believe the server read its clock in the
    // middle of a 900 ms trip, and be wrong by up to 450 ms.
    const device = await createDevice({ anchored: false });
    device.server.uploadDelayMs = 50;
    device.server.processingDelayMs = 800;
    device.server.downloadDelayMs = 50;

    await device.queue.syncOnce();

    // EXPECTED: exact (the network was symmetric), and the round trip kept
    // for the audit is the 100 ms of travel, not 900.
    assert.equal(device.serverClock.serverNowMs(), device.clock.serverNow());
    assert.equal(device.serverClock.roundTripMs(), 100);
});

test('a server that still sends only one time is understood as before', async () => {
    const device = await createDevice({ anchored: false });
    device.server.sendsBothTimes = false;
    device.server.uploadDelayMs = 40;
    device.server.downloadDelayMs = 40;

    await device.queue.syncOnce();

    assert.equal(device.serverClock.serverNowMs(), device.clock.serverNow());
    assert.equal(device.serverClock.roundTripMs(), 80);
});
