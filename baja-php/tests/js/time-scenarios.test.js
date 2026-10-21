// TIME, WHOLE-PHONE STORIES: what time ends up on the server when the phone's
// clocks misbehave.
//
// Each test plays a counter's phone (helpers/fakes.js) against a pretend
// server and then compares the time STORED ON THE SERVER with the true time
// of the key press. `device.clock` is the pretend world's time:
//
//   serverNow()                       the true time (the server's clock)
//   setWallClockError / stepWallClock the phone's system clock being wrong / being changed
//   sleepWithStalledMonotonic         the phone's monotonic clock standing still
//
// The rule being tested (brief, section 6): the stored time of a pass is its
// true server time, to within the round-trip error of the anchor, whatever
// the phone's system clock says. In the one case where that is impossible
// the pass is stored anyway and MARKED, so that a reviewer looks at it.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { FakeClock, createDevice } from './helpers/fakes.js';

const THREE_DAYS_MS = 3 * 24 * 60 * 60 * 1000;
const ONE_HOUR_MS = 60 * 60 * 1000;

/** What the server stored for the n-th entry typed on the device (1-based). */
function storedEntry(device, number, uuidPrefix = 'uuid') {
    return device.server.storedEntries.get(uuidPrefix + '-' + number);
}

test('a phone whose clock is 3 days wrong stores the true times', async () => {
    // SITUATION: a borrowed phone, date set three days ahead (it has been
    // without a SIM card for months). The network takes 40 ms each way.
    //
    // WHAT WENT WRONG BEFORE: the page corrected the phone's time with an
    // estimated offset. That mostly worked, but an entry further than a day
    // from the server's time was refused, never acknowledged, and blocked
    // this phone's upload queue for the rest of the race.
    const clock = new FakeClock();
    clock.setWallClockError(THREE_DAYS_MS);
    const device = await createDevice({ clock, anchored: false });
    device.server.uploadDelayMs = 40;
    device.server.downloadDelayMs = 40;
    await device.queue.syncOnce();                  // the heartbeat sent when the page opens

    const truePassTimes = [];
    for (const car of [7, 41, 7]) {
        device.clock.advance(31_000);
        truePassTimes.push(device.clock.serverNow());
        await device.type(car);
    }
    await device.queue.flush();

    // EXPECTED: every stored time is the true time (the trip was symmetric,
    // so there is not even a round-trip error here), marked exact. The
    // phone's own, wrong reading travels along untouched, for the audit.
    for (const [index, trueMs] of truePassTimes.entries()) {
        const stored = storedEntry(device, index + 1);
        assert.equal(stored.ts_ms, trueMs);
        assert.equal(stored.time_quality, 'exato');
        assert.equal(stored.ts_device_ms, trueMs + THREE_DAYS_MS);
    }
    assert.equal(device.queue.getState().pendentes, 0, 'nothing is stuck in the queue');
});

test('a lopsided network shifts the stored time by at most half the round trip', async () => {
    // SITUATION: the only answer so far took 600 ms to go and 0 ms to come
    // back: the worst case for the midpoint guess.
    const device = await createDevice({ anchored: false });
    device.server.uploadDelayMs = 600;
    await device.queue.syncOnce();
    device.server.uploadDelayMs = 0;
    device.clock.advance(10_000);
    const trueMs = device.clock.serverNow();
    await device.type(41);
    await device.queue.syncOnce();

    // EXPECTED: wrong by half the round trip (300 ms) and not a millisecond
    // more; the anchor that was used says so (`rtt_ms`), for the reviewer.
    const stored = storedEntry(device, 1);
    assert.equal(Math.abs(stored.ts_ms - trueMs), 300);
    assert.equal(stored.anchor.rtt_ms, 600);
});

test('a clock stepped by an hour in the middle of the race changes nothing', async () => {
    // SITUATION: mid-race the phone joins a network and "corrects" its
    // clock by one hour (or the counter fixes the time zone by hand).
    //
    // WHAT WENT WRONG BEFORE: entries typed between the step and the next
    // good clock estimate were sent with the old offset: an hour off. They
    // matched no other counter's entries and showed up as an hour-long hole
    // in this counter's stream plus a pile of lone passes.
    const device = await createDevice();
    device.clock.advance(60_000);
    const beforeStep = device.clock.serverNow();
    await device.type(7);

    device.clock.stepWallClock(ONE_HOUR_MS);

    // Typed right after the step, before the page has synced again.
    device.clock.advance(4000);
    const rightAfterStep = device.clock.serverNow();
    await device.type(41);
    await device.queue.flush();
    device.clock.advance(30_000);
    const later = device.clock.serverNow();
    await device.type(12);
    await device.queue.flush();

    // EXPECTED: all three have their true times, all exact. (Seeing the two
    // clocks disagree, the page drops its anchor and takes a new one from
    // the next answer; the entry typed in between is held for that moment
    // and stamped from the monotonic clock, which the step never touched.)
    assert.deepEqual([1, 2, 3].map((number) => storedEntry(device, number).ts_ms), [beforeStep, rightAfterStep, later]);
    assert.deepEqual([1, 2, 3].map((number) => storedEntry(device, number).time_quality), ['exato', 'exato', 'exato']);
});

test('entries typed offline before the first sync are exact, only uploaded later', async () => {
    // SITUATION: the count page is opened from its offline copy at a corner
    // of the track with no signal. The phone's clock is 2 minutes slow. The
    // counter types three cars over a minute; then the signal comes back.
    const clock = new FakeClock();
    clock.setWallClockError(-120_000);
    const device = await createDevice({ clock, anchored: false });
    device.server.behaviour = 'offline';

    const truePassTimes = [];
    for (const car of [7, 41, 12]) {
        device.clock.advance(20_000);
        truePassTimes.push(device.clock.serverNow());
        await device.type(car);
        await device.queue.syncOnce();              // the heartbeat keeps failing
    }

    // While offline: the entries are HELD. They have no time yet, they are
    // counted as waiting, and the status bar can say why.
    assert.deepEqual(device.store.allEntries().map((entry) => entry.ts_ms), [null, null, null]);
    assert.equal(device.queue.getState().pendentes, 3);
    assert.equal(device.queue.getState().aguardandoHorario, 3);

    device.clock.advance(45_000);
    device.server.behaviour = 'ok';
    device.server.uploadDelayMs = 25;
    device.server.downloadDelayMs = 25;
    await device.queue.flush();

    // EXPECTED: the first answer gives the anchor; each held entry is
    // stamped with "anchor minus the monotonic time since its key press".
    // True times, exact, in the next request.
    assert.deepEqual([1, 2, 3].map((number) => storedEntry(device, number).ts_ms), truePassTimes);
    assert.deepEqual([1, 2, 3].map((number) => storedEntry(device, number).time_quality), ['exato', 'exato', 'exato']);
    assert.equal(device.queue.getState().pendentes, 0);
    assert.equal(device.queue.getState().aguardandoHorario, 0);
});

test('while entries are held the phone never tells the server "you have everything"', async () => {
    // SITUATION: as above, at the moment of the first answer. The first
    // request could not carry the held entries (they had no time).
    //
    // WHY IT MATTERS: `queue_empty: true` makes the server treat every car
    // missing from this counter as a real miss. Said one request too early,
    // it would charge this counter with three misses for entries that
    // arrive a moment later.
    const device = await createDevice({ anchored: false });
    await device.type(7);

    await device.queue.flush();

    // EXPECTED: first request: no entries, queue_empty false, no time
    // (`now_ms` null). Second: the entry, still false. Third: empty, true.
    const requests = device.server.receivedRequests;
    assert.deepEqual(requests.map((request) => request.entries.length), [0, 1, 0]);
    assert.deepEqual(requests.map((request) => request.queue_empty), [false, false, true]);
    assert.equal(requests[0].now_ms, null);
});

test('an offline reload: entries of the earlier page load are estimated and marked', async () => {
    // SITUATION: the phone synced, then lost the signal. The browser was
    // restarted (still no signal) and the counter typed two cars; then the
    // phone restarted AGAIN before any answer from the server. The phone's
    // clock is 5 minutes fast the whole time.
    const clock = new FakeClock();
    clock.setWallClockError(300_000);
    const device = await createDevice({ clock, anchored: false });
    await device.queue.syncOnce();                  // online once: the anchor is remembered on disk
    device.server.behaviour = 'offline';

    device.clock.advance(120_000);
    await device.reload();                          // page load 2: never hears from the server
    device.clock.advance(15_000);
    const truePassTimes = [device.clock.serverNow()];
    await device.type(7);
    device.clock.advance(20_000);
    truePassTimes.push(device.clock.serverNow());
    await device.type(41);

    device.clock.advance(60_000);
    await device.reload();                          // page load 3

    // EXPECTED, already while still offline: the page gave them their
    // (estimated) time as it opened. They are not "held" any more: nothing
    // in this page load could ever make them exact, and the counter and the
    // export should see a time, with its quality, right away.
    assert.deepEqual(device.store.allEntries().map((entry) => entry.time_quality), ['estimado', 'estimado']);
    assert.equal(device.queue.getState().aguardandoHorario, 0);

    device.server.behaviour = 'ok';
    await device.queue.flush();

    // The monotonic clock of page load 2 is gone, so only the
    // system clock can say when those keys were pressed. The page uses the
    // remembered pairing "server said S when the system clock said D" and
    // marks the result "estimado": the engine flags such passes for a
    // reviewer. Here nobody touched the system clock, so they are right.
    assert.deepEqual([1, 2].map((number) => storedEntry(device, number).ts_ms), truePassTimes);
    assert.deepEqual([1, 2].map((number) => storedEntry(device, number).time_quality), ['estimado', 'estimado']);

    // An entry typed in page load 3, after its first answer, is exact again.
    device.clock.advance(5000);
    const exactAt = device.clock.serverNow();
    await device.type(12);
    await device.queue.flush();
    assert.equal(storedEntry(device, 3).ts_ms, exactAt);
    assert.equal(storedEntry(device, 3).time_quality, 'exato');
});

test('an offline reload with a clock changed in between: stored, marked, never trusted', async () => {
    // SITUATION: as above, but between the remembered anchor and the key
    // press somebody set the phone's clock BACK by two hours. The estimate
    // "anchor plus system-clock time since" would now be negative time.
    const device = await createDevice({ anchored: false });
    await device.queue.syncOnce();
    device.server.behaviour = 'offline';
    device.clock.stepWallClock(-2 * ONE_HOUR_MS);
    await device.reload();
    device.clock.advance(10_000);
    const phoneSaid = device.clock.now();
    await device.type(7);
    await device.reload();
    device.server.behaviour = 'ok';

    await device.queue.flush();

    // EXPECTED: the entry is NOT lost and NOT refused: it goes up with the
    // raw phone time, marked "desconhecido". The server stores it, never
    // counts it automatically, and flags it. A reviewer who knows the car
    // crossed can still count it.
    const stored = storedEntry(device, 1);
    assert.equal(stored.time_quality, 'desconhecido');
    assert.equal(stored.ts_ms, phoneSaid);
    assert.equal(stored.anchor, null);
    assert.equal(device.queue.getState().pendentes, 0);
});

test('a monotonic clock that stalled while the phone slept does not shift later entries', async () => {
    // SITUATION: the counter's phone goes to sleep for 3 minutes during a
    // red flag. On this phone `performance.now()` does not count while it
    // sleeps. The counter wakes it and types a car at once; the heartbeat
    // then syncs, and another car is typed.
    //
    // WHAT WOULD GO WRONG without the guard: the page's idea of the server
    // time would be 3 minutes behind from then on. Every later pass of this
    // counter would be stored 3 minutes early, matching nobody.
    const device = await createDevice();
    device.clock.advance(10_000);
    const beforeSleep = device.clock.serverNow();
    await device.type(7);
    await device.queue.flush();

    device.clock.sleepWithStalledMonotonic(180_000);

    device.clock.advance(800);
    const rightAfterWaking = device.clock.serverNow();
    await device.type(41);                          // before any timer or sync has run
    await device.queue.flush();
    device.clock.advance(20_000);
    const later = device.clock.serverNow();
    await device.type(12);
    await device.queue.flush();

    // EXPECTED: all true, all exact. At the key press the page sees that
    // the system clock moved 3 minutes more than the monotonic clock,
    // drops its anchor, holds the entry, and stamps it from the new anchor.
    assert.deepEqual([1, 2, 3].map((number) => storedEntry(device, number).ts_ms), [beforeSleep, rightAfterWaking, later]);
    assert.deepEqual([1, 2, 3].map((number) => storedEntry(device, number).time_quality), ['exato', 'exato', 'exato']);
});

test('an answer that arrives across a stall is not used as an anchor', async () => {
    // SITUATION: the phone falls asleep while a request is in the air and
    // wakes 2 minutes later, when the (old) answer is delivered. Measured
    // on the stalled monotonic clock the trip looks instant, but the server
    // time in the answer is 2 minutes old.
    const device = await createDevice({ anchored: false });
    const originalSend = device.server.send;
    device.server.send = async (body) => {
        const answer = await originalSend(body);
        device.clock.sleepWithStalledMonotonic(120_000);
        return answer;
    };

    await device.queue.syncOnce();
    device.server.send = originalSend;

    // EXPECTED: no anchor from that answer; the next one gives a good one.
    assert.equal(device.serverClock.hasAnchor(), false);
    await device.queue.syncOnce();
    assert.equal(device.serverClock.serverNowMs(), device.clock.serverNow());
});

test('the stored time of an entry never changes once it is stored on the phone', async () => {
    // SITUATION: an entry is stamped from a mediocre anchor (slow, lopsided
    // answer). A much better answer arrives before the entry is uploaded.
    //
    // WHY: brief 6.2. The time an entry gets is final. If a better anchor
    // could "improve" entries afterwards, their times would move after they
    // had been shown, compared and possibly counted.
    const device = await createDevice({ anchored: false });
    device.server.uploadDelayMs = 800;
    await device.queue.syncOnce();
    device.server.behaviour = 'offline';
    device.clock.advance(5000);
    await device.type(7);
    const stampedAs = device.store.findEntry('uuid-1').ts_ms;

    device.server.behaviour = 'ok';
    device.server.uploadDelayMs = 5;
    device.server.downloadDelayMs = 5;
    await device.queue.flush();

    assert.equal(storedEntry(device, 1).ts_ms, stampedAs);
    assert.equal(device.store.findEntry('uuid-1').ts_ms, stampedAs);
});
