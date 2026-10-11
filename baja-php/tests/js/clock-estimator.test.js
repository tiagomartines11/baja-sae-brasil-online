// CLOCK: how the phone finds out how wrong its own clock is.
//
// Background: three counters use three different devices, and their clocks
// can differ by seconds or minutes. To compare "who saw car 41 when", every
// time has to be expressed on ONE clock: the server's. The phone therefore
// keeps an estimate called the offset:
//
//     server time  =  phone time  +  offset
//
// It measures the offset on every upload: note the phone time when the request
// leaves (t0) and when the answer arrives (t1). The server's time written in
// the answer was read at some moment between t0 and t1, and the best single
// guess is the middle of the two ("midpoint"). The guess can be off by at most
// half of the round trip (t1 - t0). So a quick answer gives a precise estimate
// and a slow one a vague estimate, and the phone keeps the quickest it has seen.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { ClockEstimator } from '../../default/js/contagem/clock-estimator.js';
import { createDevice, FakeClock, FakeServer } from './helpers/fakes.js';

test('before any answer from the server the offset is unknown (null), not zero', () => {
    // Situation: the page has just opened.
    const estimator = new ClockEstimator();

    // Expected: null. Zero would be a claim ("my clock is exactly right")
    // that nobody has checked yet.
    assert.equal(estimator.offsetMs(), null);
    assert.equal(estimator.roundTripMs(), null);
});

test('the offset is the server time minus the midpoint of the round trip', () => {
    // Situation: the phone's clock is 2000 ms BEHIND the server.
    //   request leaves at phone time 10_000
    //   answer arrives at phone time 10_200  (round trip 200 ms)
    //   the server read its clock halfway: phone time 10_100 = server time 12_100
    const estimator = new ClockEstimator();
    estimator.addSample(10_000, 10_200, 12_100);

    // Expected: +2000 (add 2000 to a phone time to get the server time).
    assert.equal(estimator.offsetMs(), 2000);
    assert.equal(estimator.roundTripMs(), 200);
});

test('a phone whose clock is ahead of the server gets a negative offset', () => {
    // Situation: the phone is 90 seconds AHEAD. Midpoint 50_050 on the phone
    // is 50_050 - 90_000 on the server.
    const estimator = new ClockEstimator();
    estimator.addSample(50_000, 50_100, 50_050 - 90_000);

    assert.equal(estimator.offsetMs(), -90_000);
});

test('of several measurements, the one with the smallest round trip is kept', () => {
    // Situation: the true offset is +2000. Three uploads measure it:
    //   1. a slow one  (round trip 3000 ms) whose answer was delayed, so the
    //      midpoint guess is off: it measures +2900;
    //   2. a quick one (round trip   80 ms): measures +2010;
    //   3. a medium one(round trip  900 ms): measures +1700.
    const estimator = new ClockEstimator();

    estimator.addSample(100_000, 103_000, 104_400);
    assert.equal(estimator.offsetMs(), 2900, 'the first sample is all we have, so it is used');

    estimator.addSample(110_000, 110_080, 112_050);
    assert.equal(estimator.offsetMs(), 2010, 'a quicker sample replaces a slower one');

    const replaced = estimator.addSample(120_000, 120_900, 122_150);
    // Expected: the medium sample is ignored. It is not "newer is better":
    // a slower answer is a vaguer measurement, whenever it was taken.
    assert.equal(replaced, false);
    assert.equal(estimator.offsetMs(), 2010);
    assert.equal(estimator.roundTripMs(), 80);
});

test('a later sample with the same round trip does not replace the one in use', () => {
    // Situation: two equally quick answers that differ by a few milliseconds.
    // Switching back and forth would make entry times jitter for no gain.
    const estimator = new ClockEstimator();
    estimator.addSample(1000, 1100, 3050);
    estimator.addSample(5000, 5100, 7060);

    assert.equal(estimator.offsetMs(), 2000);
});

test('a measurement that makes no sense is ignored', () => {
    // Situation: the phone's clock jumped BACKWARDS while a request was in the
    // air, so the answer seems to arrive before the request left. Or the
    // server's answer has no usable time in it.
    const estimator = new ClockEstimator();

    assert.equal(estimator.addSample(10_000, 9_000, 12_000), false);
    assert.equal(estimator.addSample(10_000, 10_100, undefined), false);
    assert.equal(estimator.addSample(10_000, 10_100, Number.NaN), false);

    // Expected: still "unknown", rather than an estimate built on nonsense.
    assert.equal(estimator.offsetMs(), null);
});

test('if the phone clock itself is changed mid-race, the old estimate is dropped', () => {
    // Situation: the estimate in use came from a very quick answer (40 ms).
    // Then the phone corrects its own clock by one hour (it finally got the
    // time from the mobile network). Every later measurement now says the
    // offset is one hour different.
    const estimator = new ClockEstimator();
    estimator.addSample(100_000, 100_040, 102_020);
    assert.equal(estimator.offsetMs(), 2000);

    const oneHourMs = 3_600_000;
    const replaced = estimator.addSample(200_000 + oneHourMs, 200_600 + oneHourMs, 202_300);

    // Expected: the new sample wins although its round trip (600 ms) is
    // larger. Each sample can be wrong by at most half its round trip (20 ms
    // and 300 ms here); two samples an hour apart cannot both be right, so the
    // clock must have changed, and the old sample describes a clock that no
    // longer exists.
    assert.equal(replaced, true);
    assert.equal(estimator.offsetMs(), 2000 - oneHourMs);
});

test('two samples that disagree only within their own uncertainty are NOT treated as a clock change', () => {
    // Situation: quick sample says +2000 (round trip 100). A slow sample (round
    // trip 2000) says +2800. The slow one may be off by up to 1000 ms, so the
    // two are perfectly compatible; nothing happened to the clock.
    const estimator = new ClockEstimator();
    estimator.addSample(100_000, 100_100, 102_050);
    estimator.addSample(200_000, 202_000, 203_800);

    assert.equal(estimator.offsetMs(), 2000);
});

// ---------------------------------------------------------------------------
// The estimator at work inside the upload queue
// ---------------------------------------------------------------------------

test('entries typed before the first contact with the server carry a null offset', async () => {
    // Situation: the counter opens the page with no network and starts typing
    // straight away. The phone has never compared its clock to the server's.
    const clock = new FakeClock();
    const server = new FakeServer(clock, { serverAheadMs: 2000 });
    const device = await createDevice({ server, clock });
    server.behaviour = 'offline';

    const { entry } = await device.type(41);

    // Expected: the entry says honestly "offset unknown". The server will
    // apply this phone's first later estimate when it loads the entry.
    assert.equal(entry.clock_offset_ms, null);

    server.behaviour = 'ok';
    await device.queue.syncOnce();
    assert.equal(server.storedEntries.get('uuid-1').clock_offset_ms, null);
});

test('an entry keeps the offset it was typed with, even after a better estimate exists', async () => {
    // Situation: car 7 is typed before the first contact (null offset) and is
    // still waiting when the phone learns its offset. Then car 41 is typed.
    const clock = new FakeClock();
    const server = new FakeServer(clock, { serverAheadMs: 2000 });
    const device = await createDevice({ server, clock });
    server.behaviour = 'offline';
    await device.type(7);

    server.behaviour = 'ok';
    server.storeAtMostPerRequest = 0;       // the server answers, but confirms no entry yet
    await device.queue.syncOnce();
    await device.type(41);
    server.storeAtMostPerRequest = null;
    await device.queue.syncOnce();

    // Expected: car 7 goes up exactly as it was recorded, null offset
    // included. The phone never rewrites a stored entry; fixing up old times
    // is the server's job, done in one place. Car 41 carries the estimate.
    assert.equal(server.storedEntries.get('uuid-1').clock_offset_ms, null);
    assert.equal(server.storedEntries.get('uuid-2').clock_offset_ms, 2000);
});

test('every request reports the phone time and the current offset estimate', async () => {
    // Situation: the server is 2000 ms ahead; each leg of the trip takes 50 ms.
    const clock = new FakeClock();
    const server = new FakeServer(clock, { serverAheadMs: 2000 });
    server.uploadDelayMs = 50;
    server.downloadDelayMs = 50;
    const device = await createDevice({ server, clock });

    const sentAt = clock.now();
    await device.queue.syncOnce();
    await device.queue.syncOnce();

    // Expected: the first request has no estimate yet; its answer produces
    // one (+2000, measured over a 100 ms round trip); the second request
    // carries it.
    assert.equal(server.receivedRequests[0].device_now_ms, sentAt);
    assert.equal(server.receivedRequests[0].clock_offset_ms, null);
    assert.equal(server.receivedRequests[1].clock_offset_ms, 2000);
    assert.equal(device.clockEstimator.roundTripMs(), 100);
});

test('a slow, lopsided connection gives a worse estimate that a later quick one replaces', async () => {
    // Situation: true offset +2000. On the first upload the request takes
    // 50 ms but the answer takes 1950 ms to come back (round trip 2000).
    // The midpoint method assumes both legs took the same time, so its guess
    // is wrong by 950 ms. Later the connection is quick: 20 ms each way.
    const clock = new FakeClock();
    const server = new FakeServer(clock, { serverAheadMs: 2000 });
    const device = await createDevice({ server, clock });

    server.uploadDelayMs = 50;
    server.downloadDelayMs = 1950;
    await device.queue.syncOnce();
    assert.equal(device.clockEstimator.offsetMs(), 2000 - 950, 'vague first estimate');

    server.uploadDelayMs = 20;
    server.downloadDelayMs = 20;
    await device.queue.syncOnce();

    // Expected: the quick exchange replaces the slow one and is exact.
    assert.equal(device.clockEstimator.offsetMs(), 2000);

    // And an entry typed now carries the good estimate.
    const { entry } = await device.type(41);
    assert.equal(entry.clock_offset_ms, 2000);
});

test('after a reload the offset is unknown again until the first answer', async () => {
    // Situation: the phone knew its offset; the page is reloaded with no
    // network; the counter types a car.
    const clock = new FakeClock();
    const server = new FakeServer(clock, { serverAheadMs: 2000 });
    const device = await createDevice({ server, clock });
    await device.queue.syncOnce();

    server.behaviour = 'offline';
    await device.reload();
    const { entry } = await device.type(41);

    // Expected: null. The estimate is not carried across reloads on purpose:
    // a reload is exactly when the phone's clock may have been changed (a
    // restart, a new battery), and "unknown" is safer than "stale".
    assert.equal(entry.clock_offset_ms, null);
});
