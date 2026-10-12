// SERVER CLOCK: telling the server's time without trusting the phone's clock.
//
// Background: every pass is stored with the time it happened, and the three
// counters' times are compared to decide which entries describe the same
// crossing. Phones at a race track have clocks that are minutes or days
// wrong, and that get changed in the middle of the race. So the page never
// times a pass with the phone's SYSTEM clock (`Date.now()`).
//
// It uses the MONOTONIC clock (`performance.now()`): milliseconds since the
// page was opened. Nobody can set it; it only moves forward. Every answer
// from the server gives an ANCHOR ("monotonic M was server time S"), and a
// pass at monotonic m happened at server time S + (m - M).
//
// These tests drive a ServerClock directly with two pretend clocks. The
// whole-phone stories (wrong date, offline reload...) are in
// time-scenarios.test.js.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    ServerClock, ESTADO_RELOGIO, MONOTONIC_GUARD_TOLERANCE_MS, PERSISTED_ANCHOR_MAX_AGE_MS,
} from '../../default/js/contagem/server-clock.js';
import { elapsedBetween } from '../../default/js/contagem/record-elapsed.js';
import { FakeClock } from './helpers/fakes.js';

function createClock(fakeClock = new FakeClock(), loadId = 'load-1') {
    const serverClock = new ServerClock({
        now: fakeClock.now, monotonicNow: fakeClock.newMonotonicClock(), loadId,
    });
    return { fakeClock, serverClock };
}

/** One exchange with the server: `upMs` on the way there, `downMs` on the way back. */
function exchange({ fakeClock, serverClock }, upMs, downMs) {
    const sentMonoMs = serverClock.monotonicNow();
    fakeClock.advance(upMs);
    const serverNowMs = fakeClock.serverNow();
    fakeClock.advance(downMs);
    return serverClock.addSample(sentMonoMs, serverClock.monotonicNow(), serverNowMs);
}

// ---------------------------------------------------------------------------
// The anchor
// ---------------------------------------------------------------------------

test('before the first answer there is no server time at all', () => {
    const { serverClock } = createClock();

    // Expected: null, not "the phone's clock for now". A page that showed
    // or used the phone's time here would be right only by luck.
    assert.equal(serverClock.hasAnchor(), false);
    assert.equal(serverClock.serverNowMs(), null);
});

test('after one answer the server time is known and keeps running', () => {
    // Situation: the request takes 40 ms each way.
    const world = createClock();

    exchange(world, 40, 40);

    // Expected: exactly the server's time (the trip was symmetric, so the
    // midpoint guess is exact), and still exact 10 seconds later.
    assert.equal(world.serverClock.serverNowMs(), world.fakeClock.serverNow());
    world.fakeClock.advance(10_000);
    assert.equal(world.serverClock.serverNowMs(), world.fakeClock.serverNow());
});

test('the error is never more than half the round trip', () => {
    // Situation: the worst case for the midpoint method. The request takes
    // 300 ms to arrive and the answer comes back instantly.
    const world = createClock();

    exchange(world, 300, 0);

    // Expected: off by exactly half the round trip (150 ms), never more.
    const errorMs = world.serverClock.serverNowMs() - world.fakeClock.serverNow();
    assert.equal(Math.abs(errorMs), 150);
    assert.equal(world.serverClock.roundTripMs(), 300);
});

test('the answer with the shortest round trip wins', () => {
    // Situation: a slow, lopsided exchange first (error up to 1 s), then a
    // quick one, then another slow one.
    const world = createClock();
    exchange(world, 2000, 0);
    assert.equal(world.serverClock.roundTripMs(), 2000);

    const quickBecameAnchor = exchange(world, 20, 20);
    const slowBecameAnchor = exchange(world, 0, 1500);

    // Expected: the quick answer replaces the slow one; the later slow one
    // does not replace the quick one.
    assert.equal(quickBecameAnchor, true);
    assert.equal(slowBecameAnchor, false);
    assert.equal(world.serverClock.roundTripMs(), 40);
    assert.equal(world.serverClock.serverNowMs(), world.fakeClock.serverNow());
});

test('a very old quick answer gives way to a fresh, slightly slower one', () => {
    // SITUATION: the best answer of the day (20 ms) came at the start. Four
    // hours later answers take 60 ms.
    //
    // WHY IT MATTERS: the monotonic clock of a phone is not perfect; over
    // hours it can drift by a few hundred milliseconds. An anchor therefore
    // gets a little less certain as it ages (0.1 ms per second).
    const world = createClock();
    exchange(world, 10, 10);
    world.fakeClock.advance(4 * 60 * 60 * 1000);

    const freshBecameAnchor = exchange(world, 30, 30);

    // EXPECTED: after 4 hours the old anchor's uncertainty (10 ms + 1440 ms)
    // is far above the fresh one's (30 ms), so the fresh one is taken.
    assert.equal(freshBecameAnchor, true);
    assert.equal(world.serverClock.roundTripMs(), 60);
});

test('an answer that contradicts the anchor replaces it, however slow it was', () => {
    // SITUATION: the monotonic clock lost 30 seconds without the guard
    // seeing it (the checks below make this very unlikely; this is the
    // second line of defence). The next answer says the server's clock is
    // 30 s ahead of what the anchor predicts.
    const world = createClock();
    exchange(world, 5, 5);
    world.fakeClock.monotonicLostMs += 30_000;
    world.fakeClock.trueMs += 30_000;
    // Tell the guard nothing happened, as if it had not run in between.
    world.serverClock.lastCheck = { monoMs: world.serverClock.monotonicNow(), deviceMs: world.fakeClock.now() };

    const becameAnchor = exchange(world, 200, 200);

    // EXPECTED: both cannot be right (they differ by far more than both
    // uncertainties), and the old one is the stale one.
    assert.equal(becameAnchor, true);
    assert.equal(world.serverClock.serverNowMs(), world.fakeClock.serverNow());
});

test('nonsense from the network is not taken as an anchor', () => {
    const { serverClock } = createClock();

    assert.equal(serverClock.addSample(1000, 900, 1_760_000_000_000), false, 'answer before the question');
    assert.equal(serverClock.addSample(1000, 1100, undefined), false, 'no server time in the answer');
    assert.equal(serverClock.hasAnchor(), false);
});

// ---------------------------------------------------------------------------
// Stamping a pass
// ---------------------------------------------------------------------------

test('a pass is stamped with the server time of the key press, whatever the phone clock says', () => {
    // Situation: the phone's system clock is 3 days ahead (and was when the
    // page was opened).
    const wrongPhone = new FakeClock();
    wrongPhone.setWallClockError(3 * 24 * 60 * 60 * 1000);
    const world = createClock(wrongPhone);
    exchange(world, 30, 30);
    world.fakeClock.advance(7000);

    const time = world.serverClock.stamp();

    // Expected: the final time is the true one. The phone's own reading is
    // kept beside it, untouched, as a witness for the audit trail.
    assert.equal(time.ts_ms, world.fakeClock.serverNow());
    assert.equal(time.time_quality, 'exato');
    assert.equal(time.ts_device_ms, world.fakeClock.serverNow() + 3 * 24 * 60 * 60 * 1000);
    // The anchor that was used: its server time, its round trip, how old it was.
    assert.deepEqual(time.anchor, { server_ms: world.fakeClock.serverNow() - 7030, rtt_ms: 60, age_ms: 7030 });
});

test('a pass typed before any anchor is HELD, and gets its exact time when the anchor arrives', () => {
    // SITUATION: the page was opened with no signal. A car passes; 25
    // seconds later the first answer from the server arrives.
    const world = createClock();
    const passedAt = world.fakeClock.serverNow();
    const held = world.serverClock.stamp();
    assert.equal(held.ts_ms, null, 'no time yet: the entry is held, not guessed');
    assert.equal(world.serverClock.resolveHeld(held), null, 'and stays held while there is no anchor');

    world.fakeClock.advance(25_000);
    exchange(world, 30, 30);

    // EXPECTED: the anchor tells what the server's clock read 25 seconds
    // (of monotonic clock) earlier. The pass is exact; only its upload was late.
    const time = world.serverClock.resolveHeld(held);
    assert.equal(time.ts_ms, passedAt);
    assert.equal(time.time_quality, 'exato');
    assert.ok(time.anchor.age_ms < 0, 'the anchor is YOUNGER than the pass: negative age');
});

test('a given time is used as it is (the race simulation)', () => {
    // Situation: the simulation says "this car crossed at T", and T is not
    // now. No clock of this phone has a say in that.
    const world = createClock();
    const intendedMs = world.fakeClock.serverNow() - 90_000;

    const beforeAnyAnchor = world.serverClock.stampAt(intendedMs);
    exchange(world, 10, 10);
    const withAnAnchor = world.serverClock.stampAt(intendedMs + 0.4);

    assert.equal(beforeAnyAnchor.ts_ms, intendedMs);
    assert.equal(beforeAnyAnchor.time_quality, 'exato');
    assert.equal(beforeAnyAnchor.anchor, null);
    assert.equal(withAnAnchor.ts_ms, intendedMs, 'whole milliseconds only');
});

// ---------------------------------------------------------------------------
// The guard: a monotonic clock that stood still (brief 6.5)
// ---------------------------------------------------------------------------

test('while both clocks advance together, the guard leaves the anchor alone', () => {
    const world = createClock();
    exchange(world, 10, 10);

    for (let second = 0; second < 60; second++) {
        world.fakeClock.advance(1000);
        assert.equal(world.serverClock.checkMonotonic(), false);
    }
    // A background tab whose timers fire once a minute is fine as well: the
    // two clocks still agree on how long it was.
    world.fakeClock.advance(60_000);
    assert.equal(world.serverClock.checkMonotonic(), false);
    assert.equal(world.serverClock.hasAnchor(), true);
});

test('a monotonic clock that stood still makes the anchor suspect, and the next answer replaces it', () => {
    // SITUATION: the phone sleeps for a minute. On some phones
    // `performance.now()` does not count that minute.
    //
    // WHAT WOULD GO WRONG: every pass typed afterwards would be stamped one
    // minute early, silently, for the rest of the race.
    const world = createClock();
    exchange(world, 10, 10);
    world.fakeClock.sleepWithStalledMonotonic(60_000);

    const doubtArose = world.serverClock.checkMonotonic();

    // EXPECTED: the system clock says 60 s went by, the monotonic clock says
    // none did. Either it stood still or the system clock was set forward;
    // the page cannot know. The anchor is SUSPECT: still shown (here it is
    // a minute behind), not used for any record, not vouched for.
    assert.equal(doubtArose, true);
    assert.equal(world.serverClock.estado(), ESTADO_RELOGIO.VERIFICANDO);
    assert.equal(world.serverClock.serverNowMs(), world.fakeClock.serverNow() - 60_000);
    assert.equal(world.serverClock.trustedServerNowMs(), null);
    assert.equal(world.serverClock.anchorToPersist(), null);
    assert.equal(world.serverClock.stamp().ts_ms, null);

    // The next answer: the server is a minute ahead of what the anchor
    // predicts. The monotonic clock did stand still; a new anchor is taken.
    const becameAnchor = exchange(world, 10, 10);
    assert.equal(becameAnchor, true);
    assert.equal(world.serverClock.estado(), ESTADO_RELOGIO.OK);
    assert.equal(world.serverClock.serverNowMs(), world.fakeClock.serverNow());
});

test('a system clock set forward makes the anchor suspect, and the next answer restores it', () => {
    // SITUATION: the best anchor of the day (4 ms round trip). Somebody
    // sets the phone's clock one hour ahead. To the guard this looks
    // exactly like the test above. A pass is typed, then a slow answer
    // (400 ms round trip) arrives.
    const world = createClock();
    exchange(world, 2, 2);
    world.fakeClock.advance(5000);
    world.fakeClock.stepWallClock(3_600_000);
    assert.equal(world.serverClock.checkMonotonic(), true);
    world.fakeClock.advance(3000);
    const passedAt = world.fakeClock.serverNow();
    const held = world.serverClock.stamp();
    assert.equal(held.ts_ms, null, 'held while in doubt');
    assert.equal(world.serverClock.resolveHeld(held), null, 'and not resolved from a suspect anchor');

    const becameAnchor = exchange(world, 200, 200);

    // EXPECTED: the server's time is what the anchor predicted, so the
    // monotonic clock lost nothing. The good anchor stays in use (the slow
    // answer confirmed it, it does not replace it), and the pass typed
    // during the doubt gets its time from the monotonic clock: exact.
    assert.equal(becameAnchor, false);
    assert.equal(world.serverClock.estado(), ESTADO_RELOGIO.OK);
    assert.equal(world.serverClock.roundTripMs(), 4);
    const time = world.serverClock.resolveHeld(held);
    assert.equal(time.ts_ms, passedAt);
    assert.equal(time.time_quality, 'exato');
});

test('a system clock that went BACKWARDS, or ran slow, is none of the guard\'s business', () => {
    // WHY: the monotonic clock can lose time but never jumps ahead. So when
    // the system clock moved LESS than the monotonic one, it is the system
    // clock that was changed, and no pass is timed with it.
    //
    // WHAT WENT WRONG BEFORE: the guard compared the two with Math.abs and
    // threw the anchor away for a step in either direction.
    const world = createClock();
    exchange(world, 10, 10);

    for (const stepMs of [-2100, -2100, -3_600_000, -86_400_000]) {
        world.fakeClock.advance(1000);
        world.fakeClock.stepWallClock(stepMs);
        assert.equal(world.serverClock.checkMonotonic(), false);
    }

    // EXPECTED: same anchor, same stretch of monotonic clock, right time.
    assert.equal(world.serverClock.estado(), ESTADO_RELOGIO.OK);
    assert.equal(world.serverClock.segment, 0);
    assert.equal(world.serverClock.serverNowMs(), world.fakeClock.serverNow());
    const time = world.serverClock.stamp();
    assert.equal(time.ts_ms, world.fakeClock.serverNow());
    assert.equal(time.time_quality, 'exato');
});

test('a short stall seen through a slow network is not mistaken for "nothing happened"', () => {
    // SITUATION: the monotonic clock lost 2.5 s (just over the guard's
    // tolerance). The answer that must settle it took 3 s, all of it on the
    // way back, so by itself it cannot place the server's time to better
    // than 1.5 s. It reads only 1 s away from what the anchor predicts,
    // which the usual "do these two contradict each other?" test accepts.
    const world = createClock();
    exchange(world, 5, 5);
    world.fakeClock.sleepWithStalledMonotonic(2500);
    world.serverClock.checkMonotonic();

    exchange(world, 0, 3000);

    // EXPECTED: this answer cannot tell "lost 2.5 s" from "lost nothing",
    // so the anchor is replaced. Keeping it would shift every later pass by
    // 2.5 s for good; replacing it costs at most the new answer's own
    // uncertainty, until a quicker answer comes.
    assert.equal(world.serverClock.estado(), ESTADO_RELOGIO.OK);
    assert.equal(world.serverClock.roundTripMs(), 3000);
    assert.ok(Math.abs(world.serverClock.serverNowMs() - world.fakeClock.serverNow()) <= 1500);
});

test('with no anchor yet, the clock says so in its own state', () => {
    const world = createClock();
    assert.equal(world.serverClock.estado(), ESTADO_RELOGIO.SEM_ANCORA);
    // A doubt with nothing to doubt: still "no anchor", not "checking".
    world.fakeClock.sleepWithStalledMonotonic(60_000);
    world.serverClock.checkMonotonic();
    assert.equal(world.serverClock.estado(), ESTADO_RELOGIO.SEM_ANCORA);
    exchange(world, 10, 10);
    assert.equal(world.serverClock.estado(), ESTADO_RELOGIO.OK);
});

test('the time the server spent on the request is not counted as travel', () => {
    // SITUATION: 30 ms there, 500 ms of work on the server, 30 ms back. The
    // answer carries both of the server's readings.
    const world = createClock();
    const sentMonoMs = world.serverClock.monotonicNow();
    world.fakeClock.advance(30);
    const serverReceivedMs = world.fakeClock.serverNow();
    world.fakeClock.advance(500);
    const serverSentMs = world.fakeClock.serverNow();
    world.fakeClock.advance(30);

    world.serverClock.addSample(sentMonoMs, world.serverClock.monotonicNow(), serverReceivedMs, serverSentMs);

    // EXPECTED: the middle of the server's two readings is paired with the
    // middle of ours: exact here. The round trip is the 60 ms of travel.
    assert.equal(world.serverClock.serverNowMs(), world.fakeClock.serverNow());
    assert.equal(world.serverClock.roundTripMs(), 60);
    // Readings that cannot be (the answer left before the request arrived,
    // or the server worked longer than the whole trip took) are not used.
    const other = createClock();
    assert.equal(other.serverClock.addSample(0, 100, 5000, 4000), false);
    assert.equal(other.serverClock.addSample(0, 100, 5000, 9000), false);
});

test('a disagreement inside the tolerance is ignored', () => {
    // The system clock is nudged all the time by the network (a few ms).
    const world = createClock();
    exchange(world, 10, 10);
    world.fakeClock.stepWallClock(MONOTONIC_GUARD_TOLERANCE_MS - 1);

    assert.equal(world.serverClock.checkMonotonic(), false);
});

test('the guard also runs at the key press itself', () => {
    // SITUATION: the phone wakes up and the counter types a car within the
    // same second, before the once-a-second check has had its turn.
    const world = createClock();
    exchange(world, 10, 10);
    world.fakeClock.sleepWithStalledMonotonic(60_000);

    const time = world.serverClock.stamp();

    // EXPECTED: the pass is held instead of being stamped a minute early.
    assert.equal(time.ts_ms, null);
});

test('a pass held across a stall can only be estimated, and says so', () => {
    // SITUATION: no anchor yet; a pass is typed (held). Then the phone
    // sleeps with a stalled monotonic clock, wakes, and syncs.
    const world = createClock();
    const passedAt = world.fakeClock.serverNow();
    const held = world.serverClock.stamp();
    world.fakeClock.sleepWithStalledMonotonic(120_000);
    world.serverClock.checkMonotonic();
    exchange(world, 10, 10);

    const time = world.serverClock.resolveHeld(held);

    // EXPECTED: the monotonic readings before and after the stall cannot be
    // subtracted. The system clock bridges the gap (here it happens to be
    // right), and the time is marked "estimado" so a reviewer looks at it.
    assert.equal(time.time_quality, 'estimado');
    assert.equal(time.ts_ms, passedAt);
});

// ---------------------------------------------------------------------------
// Records left without a time by an earlier page load (brief 6.4)
// ---------------------------------------------------------------------------

test('a pass from an earlier page load is estimated from the remembered anchor', () => {
    // SITUATION: the page synced (the anchor was written to the disk), lost
    // the signal, a pass was typed by a page load that never got an anchor,
    // and the page was reloaded. The phone's clock is 90 s slow throughout.
    const fakeClock = new FakeClock();
    fakeClock.setWallClockError(-90_000);
    const first = createClock(fakeClock, 'load-1');
    exchange(first, 10, 10);
    const remembered = first.serverClock.anchorToPersist();
    fakeClock.advance(60_000);
    const passedAt = fakeClock.serverNow();
    const leftover = { ts_ms: null, ts_device_ms: fakeClock.now(), page_load: 'load-2', mono_ms: 500, clock_segment: 0 };

    const third = createClock(fakeClock, 'load-3');
    third.serverClock.restorePersisted(remembered);
    const time = third.serverClock.resolveEarlierLoad(leftover);

    // EXPECTED: "the server said S when the system clock said D; the pass
    // was typed 60 s of system clock later". Right as long as nobody
    // changed the system clock in between, which the page cannot know: so
    // the time is marked "estimado".
    assert.equal(time.ts_ms, passedAt);
    assert.equal(time.time_quality, 'estimado');
    assert.equal(time.anchor.age_ms, 60_000);
});

test('with no usable remembered anchor the raw phone time goes up, marked unknown', () => {
    const fakeClock = new FakeClock();
    const { serverClock } = createClock(fakeClock, 'load-2');
    const leftover = { ts_ms: null, ts_device_ms: fakeClock.now(), page_load: 'load-1' };
    const unknown = { ts_ms: leftover.ts_device_ms, time_quality: 'desconhecido', anchor: null };

    // Never synced on this phone: nothing was remembered.
    assert.deepEqual(serverClock.resolveEarlierLoad(leftover), unknown);

    // Remembered more than 12 hours before the pass: the phone may have
    // corrected its clock overnight.
    serverClock.restorePersisted({
        server_ms: fakeClock.serverNow() - PERSISTED_ANCHOR_MAX_AGE_MS - 1000,
        device_ms: fakeClock.now() - PERSISTED_ANCHOR_MAX_AGE_MS - 1000,
        rtt_ms: 20,
    });
    assert.deepEqual(serverClock.resolveEarlierLoad(leftover), unknown);

    // Remembered "after" the pass: the system clock was set back since.
    serverClock.restorePersisted({ server_ms: fakeClock.serverNow(), device_ms: fakeClock.now() + 5000, rtt_ms: 20 });
    assert.deepEqual(serverClock.resolveEarlierLoad(leftover), unknown);

    // Something unreadable on the disk.
    serverClock.restorePersisted({ offsetMs: 12 });
    assert.deepEqual(serverClock.resolveEarlierLoad(leftover), unknown);
});

// ---------------------------------------------------------------------------
// Time between two records of the same phone
// ---------------------------------------------------------------------------

test('the time between two entries is read from the best clock they share', () => {
    // Used for the "same car again too soon" warning.
    const exactA = { ts_ms: 100_000, ts_device_ms: 5, page_load: 'x', clock_segment: 0, mono_ms: 10 };
    const exactB = { ts_ms: 104_000, ts_device_ms: 999_999, page_load: 'y', clock_segment: 0, mono_ms: 20 };
    const heldA = { ts_ms: null, ts_device_ms: 50_000, page_load: 'x', clock_segment: 0, mono_ms: 1000 };
    const heldB = { ts_ms: null, ts_device_ms: 3_650_000, page_load: 'x', clock_segment: 0, mono_ms: 9000 };
    const heldLater = { ts_ms: null, ts_device_ms: 60_000, page_load: 'z', clock_segment: 0, mono_ms: 1 };

    // Both have a final time: that is the truth.
    assert.equal(elapsedBetween(exactA, exactB), 4000);
    // Both held in the same page load: the monotonic clock (8 s), although
    // the system clock was moved by an hour between them.
    assert.equal(elapsedBetween(heldA, heldB), 8000);
    // Nothing better in common: the system clock.
    assert.equal(elapsedBetween(heldA, heldLater), 10_000);
});
