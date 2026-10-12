// FEEDBACK: the beep and the vibration after an entry.
//
// Background: a counter watches the track, not the phone. The beep is how
// they know an entry was taken (and a lower beep, that something is odd about
// it) without looking down.
//
// Two facts about browsers shape this:
//
//   - A page's audio (an "AudioContext") only works if it was switched on
//     DURING a tap or a key press. iPhones are strict about it.
//   - The audio API can misbehave badly. With an audio device that does not
//     answer, a page was seen frozen for 20 seconds inside the first key
//     press after a reload, and the typed number was lost in the freeze.
//
// So the rule is: FIRST the pass (time taken, disk write requested), THEN the
// audio, inside the same press; nothing ever waits for the audio; and audio
// that does not start within 400 ms is given up on for that press.
//
// `FakeWindow` below plays the browser. Its AudioContext can be told to
// misbehave: `resume()` that never answers, that fails, that answers late; a
// constructor that throws, or that "freezes the page" (the clock jumps while
// it runs, which is all a frozen page looks like from the inside).

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    createFeedback, canVibrate, recordThenPrepareSound,
    SOUND_START_DEADLINE_MS, MAX_FAILED_STARTS, FROZEN_AUDIO_REST_MS,
} from '../../default/js/contagem/feedback.js';
import { createPreferences } from '../../default/js/contagem/entry-modes.js';
import { createDevice } from './helpers/fakes.js';

// A promise nobody handles would mean some path is waiting on the audio (or
// forgot that it can fail). Every test below ends by checking this is empty.
const unhandledRejections = [];
process.on('unhandledRejection', (reason) => unhandledRejections.push(reason));

/** Lets promises that are ready run, as the browser does between two events. */
function settle() {
    return new Promise((resolve) => setImmediate(resolve));
}

/** `window.localStorage` for one pretend phone (survives a "reload" of the page). */
function createPhoneStorage() {
    const values = new Map();
    return {
        getItem: (key) => (values.has(key) ? values.get(key) : null),
        setItem: (key, value) => values.set(key, value),
    };
}

/**
 * @param {object}  [options]
 * @param {string}  [options.resume] what `resume()` does: 'ok' (starts at
 *        once), 'never' (a promise that never settles), 'fails', or 'late'
 *        (starts when the test calls `fakeWindow.finishResumes()`)
 * @param {string}  [options.constructor] 'ok', 'throws' or 'freezes' (takes 20 s)
 */
function createFakeWindow({ withVibration = true, resume = 'ok', constructor = 'ok' } = {}) {
    const pendingResumes = [];
    const fakeWindow = {
        behaviour: { resume, constructor },
        monotonicMs: 1000,
        audioContexts: [],
        constructorCalls: 0,
        resumeCalls: 0,
        vibrations: [],
        navigator: {},
        performance: { now: () => fakeWindow.monotonicMs },
        /** For the test to check, at the moment the audio is first touched, what had happened before. */
        onAudioCall: () => {},
        finishResumes() {
            pendingResumes.splice(0).forEach((finish) => finish());
        },
        beeps() {
            return fakeWindow.audioContexts.reduce((total, context) => total + context.beeps, 0);
        },
    };
    fakeWindow.AudioContext = class FakeAudioContext {
        constructor() {
            fakeWindow.onAudioCall('constructor');
            fakeWindow.constructorCalls++;
            if (fakeWindow.behaviour.constructor === 'throws') {
                throw new Error('simulated: no audio device');
            }
            if (fakeWindow.behaviour.constructor === 'freezes') {
                fakeWindow.monotonicMs += 20_000;
            }
            this.state = 'suspended';
            this.beeps = 0;
            this.currentTime = 0;
            this.destination = {};
            this.errorListeners = [];
            fakeWindow.audioContexts.push(this);
        }

        addEventListener(eventName, listener) {
            if (eventName === 'error') {
                this.errorListeners.push(listener);
            }
        }

        /** The browser telling the page that the audio device failed. */
        reportDeviceError() {
            this.state = 'closed';
            this.errorListeners.forEach((listener) => listener({ type: 'error' }));
        }

        resume() {
            fakeWindow.onAudioCall('resume');
            fakeWindow.resumeCalls++;
            const start = () => {
                this.state = 'running';
            };
            switch (fakeWindow.behaviour.resume) {
                case 'never': return new Promise(() => {});
                case 'fails': return Promise.reject(new Error('simulated: audio device error'));
                case 'late': return new Promise((resolve) => pendingResumes.push(() => { start(); resolve(); }));
                default: start(); return Promise.resolve();
            }
        }

        createOscillator() {
            const context = this;
            return { frequency: {}, connect() {}, start() { context.beeps++; }, stop() {} };
        }

        createGain() {
            return { gain: {}, connect() {} };
        }
    };
    if (withVibration) {
        fakeWindow.navigator.vibrate = (pattern) => fakeWindow.vibrations.push(pattern);
    }
    return fakeWindow;
}

/**
 * A counter's phone with the REAL store, session and feedback, and the
 * count page's own way of recording: `press(car)` is what a key press or a
 * tap ends in (contar.js `recordTypedPass`), including the beep after the
 * disk write.
 */
async function createCountingPhone(windowOptions = {}, preferences = createPreferences(null)) {
    const device = await createDevice();
    const fakeWindow = createFakeWindow(windowOptions);
    const feedback = createFeedback({ preferences, windowRef: fakeWindow, now: device.clock.now });
    async function recordPass(car) {
        const result = await device.session.recordPass({ car, typingMs: 300, mode: 'teclado' });
        feedback.registrado();
        return result;
    }
    return {
        device,
        fakeWindow,
        feedback,
        preferences,
        /** Returns what the page's handler returns; the press itself is over when this returns. */
        press: (car) => recordThenPrepareSound(() => recordPass(car), feedback),
    };
}

test('the audio is switched on inside the press, but only after the pass was taken', async () => {
    // SITUATION: the counter types car 41 and presses Enter.
    //
    // WHAT WENT WRONG: the audio was switched on by a listener that ran
    // BEFORE the page's own handler of the same key press. When the audio
    // call froze (no answer from the audio device), the page had not even
    // looked at the key yet.
    const phone = await createCountingPhone();
    const seenWhenAudioWasFirstTouched = [];
    phone.fakeWindow.onAudioCall = (call) => {
        seenWhenAudioWasFirstTouched.push({ call, writeRequested: phone.device.store.hasWriteInFlight() });
    };
    const pressedAt = phone.device.clock.serverNow();

    const recording = phone.press(41);
    // The press is over here. The audio was created and resumed inside it...
    assert.deepEqual(seenWhenAudioWasFirstTouched.map((seen) => seen.call), ['constructor', 'resume']);
    // ...and by then the disk had already been asked to write the entry.
    assert.deepEqual(seenWhenAudioWasFirstTouched.map((seen) => seen.writeRequested), [true, true]);

    const { entry } = await recording;
    await settle();

    // EXPECTED: the entry has the time of the press, and the beep was played.
    assert.equal(entry.ts_ms, pressedAt);
    assert.equal(phone.fakeWindow.beeps(), 1);
    assert.deepEqual(phone.fakeWindow.vibrations, [40]);
    assert.deepEqual(unhandledRejections, []);
});

test('audio that never answers: every pass is recorded at once, the beeps are skipped', async () => {
    // SITUATION: the phone's audio is stuck (a Bluetooth speaker that went
    // away, an audio service that hangs): `resume()` returns a promise that
    // never settles. The counter records six cars, one every two seconds.
    const phone = await createCountingPhone({ resume: 'never' });

    for (const car of [41, 7, 12, 3, 5, 9]) {
        const { entry } = await phone.press(car);
        // Recorded at once: nothing waited for the audio.
        assert.equal(entry.car, car);
        phone.fakeWindow.monotonicMs += 2000;
        phone.device.clock.advance(2000);
    }
    await settle();

    // EXPECTED: all six on the phone; no beep (and none queued to play
    // later); the vibration still works. The page tried a fresh audio
    // context a few times, one attempt at a time, then left the audio alone:
    // nothing piles up behind a device that does not answer.
    assert.deepEqual(phone.device.store.allEntries().map((entry) => entry.car), [41, 7, 12, 3, 5, 9]);
    assert.equal(phone.fakeWindow.beeps(), 0);
    assert.equal(phone.fakeWindow.vibrations.length, 6);
    assert.equal(phone.fakeWindow.resumeCalls, MAX_FAILED_STARTS);
    assert.equal(phone.fakeWindow.constructorCalls, MAX_FAILED_STARTS);
    assert.deepEqual(unhandledRejections, []);
});

test('quick presses while the audio is still starting do not stack attempts', async () => {
    // SITUATION: as above, but the counter records three cars within 300 ms
    // (a bunch at the line).
    const phone = await createCountingPhone({ resume: 'never' });

    for (const car of [41, 7, 12]) {
        await phone.press(car);
        phone.fakeWindow.monotonicMs += 100;
    }

    // EXPECTED: one attempt is under way and still within its 400 ms; the
    // other presses did not start a second and a third.
    assert.equal(phone.fakeWindow.resumeCalls, 1);
    assert.equal(phone.device.store.allEntries().length, 3);
});

test('an audio constructor that throws: the pass is recorded, and a later press tries again', async () => {
    // SITUATION: the browser refuses to create audio at this moment.
    const phone = await createCountingPhone({ constructor: 'throws' });

    await phone.press(41);
    await phone.press(7);
    assert.equal(phone.device.store.allEntries().length, 2);
    assert.equal(phone.fakeWindow.beeps(), 0);
    assert.equal(phone.fakeWindow.constructorCalls, 2, 'each press tried once');

    // The audio comes back: the next press gets it, and its beep.
    phone.fakeWindow.behaviour.constructor = 'ok';
    await phone.press(12);
    await settle();

    assert.equal(phone.fakeWindow.beeps(), 1);
    assert.deepEqual(unhandledRejections, []);
});

test('audio that fails to start is not an error anybody has to handle', async () => {
    // SITUATION: `resume()` returns a promise that is rejected.
    const phone = await createCountingPhone({ resume: 'fails' });

    await phone.press(41);
    await phone.press(7);
    await settle();

    assert.equal(phone.device.store.allEntries().length, 2);
    assert.equal(phone.fakeWindow.beeps(), 0);
    assert.deepEqual(unhandledRejections, []);
});

test('audio that starts within the deadline plays the beep; later than that, the beep is dropped', async () => {
    // SITUATION: the audio takes a moment to start (normal on a phone that
    // was idle). The entry is on the disk, and the beep asked for, before
    // the audio is running.
    const quick = await createCountingPhone({ resume: 'late' });
    await quick.press(41);
    assert.equal(quick.fakeWindow.beeps(), 0, 'not running yet');
    quick.fakeWindow.monotonicMs += SOUND_START_DEADLINE_MS - 100;
    quick.fakeWindow.finishResumes();
    await settle();
    // EXPECTED: started in time, so the counter hears the beep of that entry.
    assert.equal(quick.fakeWindow.beeps(), 1);

    // The same, but the audio starts three seconds after the press.
    //
    // WHY NOT PLAY IT ANYWAY: a beep three seconds late sounds like another
    // entry was taken. Silence is the honest answer.
    const slow = await createCountingPhone({ resume: 'late' });
    await slow.press(41);
    slow.fakeWindow.monotonicMs += 3000;
    slow.fakeWindow.finishResumes();
    await settle();
    assert.equal(slow.fakeWindow.beeps(), 0);

    // The audio is running now, so the NEXT entry beeps normally.
    await slow.press(7);
    await settle();
    assert.equal(slow.fakeWindow.beeps(), 1);
    assert.deepEqual(unhandledRejections, []);
});

test('an audio call that froze the page is not made again, not even after a reload', async () => {
    // SITUATION: `new AudioContext()` takes 20 seconds to return (seen in a
    // browser whose audio device did not answer). It cannot be interrupted;
    // the page only knows afterwards, from the clock. The counter reloads
    // the page ("it froze") and types again.
    const phoneStorage = createPhoneStorage();
    const phone = await createCountingPhone({ constructor: 'freezes' }, createPreferences(phoneStorage));

    // The pass that was being recorded when it froze is safe: its time was
    // taken and its write requested before the audio was touched.
    const pressedAt = phone.device.clock.serverNow();
    const { entry } = await phone.press(41);
    assert.equal(entry.ts_ms, pressedAt);
    // No second freeze on this page...
    await phone.press(7);
    assert.equal(phone.fakeWindow.constructorCalls, 1);

    // ...and none on the reloaded page: the phone remembers.
    const reloaded = await createCountingPhone({ constructor: 'freezes' }, createPreferences(phoneStorage));
    await reloaded.press(12);
    assert.equal(reloaded.fakeWindow.constructorCalls, 0);
    assert.deepEqual(reloaded.fakeWindow.vibrations, [40], 'vibration is not affected');

    // Ten minutes later the page may try the sound again (the speaker may
    // be back).
    const later = await createCountingPhone({}, createPreferences(phoneStorage));
    later.device.clock.advance(FROZEN_AUDIO_REST_MS + 1000);
    const afterTheRest = createFeedback({
        preferences: createPreferences(phoneStorage), windowRef: later.fakeWindow, now: later.device.clock.now,
    });
    afterTheRest.prepararSom();
    assert.equal(later.fakeWindow.constructorCalls, 1);
    assert.deepEqual(unhandledRejections, []);
});

test('when the browser reports a failed audio device, a reloaded page does not touch the audio', async () => {
    // SITUATION (reproduced in headless Chromium, which has no audio
    // device): the sound works at first. A minute later the browser reports
    // "the AudioContext encountered an error from the audio device". The
    // counter reloads the page and types a car.
    //
    // WHAT WENT WRONG: the reloaded page created a new audio context at its
    // first key press, and in that state `new AudioContext()` takes 20
    // seconds to return. The page was frozen and the number lost.
    const phoneStorage = createPhoneStorage();
    const phone = await createCountingPhone({}, createPreferences(phoneStorage));
    await phone.press(41);
    await settle();
    assert.equal(phone.fakeWindow.beeps(), 1);

    phone.fakeWindow.audioContexts[0].reportDeviceError();

    // On this page: no new context, no more beeps, counting goes on.
    await phone.press(7);
    assert.equal(phone.fakeWindow.constructorCalls, 1);
    assert.equal(phone.device.store.allEntries().length, 2);

    // On the reloaded page: the audio API is not called at all.
    const reloaded = await createCountingPhone({ constructor: 'freezes' }, createPreferences(phoneStorage));
    const { entry } = await reloaded.press(12);
    assert.equal(entry.car, 12);
    assert.equal(reloaded.fakeWindow.constructorCalls, 0);
    assert.equal(reloaded.fakeWindow.monotonicMs, 1000, 'no time was lost in a frozen call');
});

test('audio that the phone suspended is woken up by the next press that records', async () => {
    // SITUATION: a phone call comes in during the race, or the screen is
    // locked for a moment. The phone suspends the page's audio.
    const phone = await createCountingPhone();
    await phone.press(41);
    const context = phone.fakeWindow.audioContexts[0];
    context.state = 'interrupted';              // what an iPhone reports after a call

    await phone.press(7);
    await settle();

    // EXPECTED: the same audio context, resumed again inside the press.
    assert.equal(phone.fakeWindow.audioContexts.length, 1);
    assert.equal(phone.fakeWindow.resumeCalls, 2);
    assert.equal(context.beeps, 2);
});

test('a beep requested before any press is skipped quietly', () => {
    // Situation: cannot really happen (an entry needs a press), but a beep
    // must never be able to break counting. Creating the audio here,
    // outside a press, would only make a context that stays silent.
    const fakeWindow = createFakeWindow();
    const feedback = createFeedback({ preferences: createPreferences(null), windowRef: fakeWindow });

    feedback.erro();

    assert.equal(fakeWindow.audioContexts.length, 0);
});

test('with the sound switch off the audio is not touched at all, and vibration still works', async () => {
    // WHY "not at all": a counter whose phone has trouble with its audio
    // must be able to get rid of the trouble by switching "Som" off.
    const preferences = createPreferences(null);
    preferences.set('som', false);
    const phone = await createCountingPhone({}, preferences);

    await phone.press(41);

    assert.equal(phone.fakeWindow.constructorCalls, 0);
    assert.deepEqual(phone.fakeWindow.vibrations, [40]);
});

test('a phone that cannot vibrate is recognised, so the page can hide the "Vibrar" switch', async () => {
    // Situation: iPhones have no vibration for web pages at all.
    //
    // What used to go wrong: the "Vibrar" switch was shown anyway. A counter
    // would switch the sound off, rely on the vibration, and get no signal
    // of any kind after an entry.
    const iphone = createFakeWindow({ withVibration: false });
    const android = createFakeWindow({ withVibration: true });

    assert.equal(canVibrate(iphone.navigator), false);
    assert.equal(canVibrate(android.navigator), true);
    assert.equal(canVibrate(undefined), false);

    // And asking for feedback on such a phone does not fail.
    const feedback = createFeedback({ preferences: createPreferences(null), windowRef: iphone });
    feedback.prepararSom();
    await settle();
    feedback.aviso();
    assert.equal(iphone.audioContexts[0].beeps, 1);
});
