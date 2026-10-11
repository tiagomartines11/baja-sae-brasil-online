// FEEDBACK: the beep and the vibration after an entry.
//
// Background: a counter watches the track, not the phone. The beep is how
// they know an entry was taken (and a lower beep, that something is odd about
// it) without looking down.
//
// Browsers do not let a page make sound whenever it likes. The page's audio
// (an "AudioContext") only works if it was switched on DURING a tap or a key
// press, in the very same instant. iPhones are strict about it: switched on a
// moment later, even as a direct consequence of the tap, it stays silent for
// good and reports no error.
//
// `FakeWindow` below plays the browser: `gesture('pointerdown')` is the
// person touching the screen, and the fake AudioContext records when it was
// created and resumed.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createFeedback, canVibrate } from '../../default/js/contagem/feedback.js';
import { createPreferences } from '../../default/js/contagem/entry-modes.js';

function createFakeWindow({ withVibration = true } = {}) {
    const listeners = new Map();
    const fakeWindow = {
        audioContexts: [],
        vibrations: [],
        // True only while a tap or key press is being delivered to the page.
        insideGesture: false,
        navigator: {},
        addEventListener(eventName, handler) {
            listeners.set(eventName, (listeners.get(eventName) || []).concat(handler));
        },
        gesture(eventName) {
            fakeWindow.insideGesture = true;
            for (const handler of listeners.get(eventName) || []) {
                handler({ type: eventName });
            }
            fakeWindow.insideGesture = false;
        },
    };
    fakeWindow.AudioContext = class FakeAudioContext {
        constructor() {
            this.state = 'suspended';
            this.createdInsideGesture = fakeWindow.insideGesture;
            this.resumedInsideGesture = [];
            this.beeps = 0;
            this.currentTime = 0;
            this.destination = {};
            fakeWindow.audioContexts.push(this);
        }

        resume() {
            this.resumedInsideGesture.push(fakeWindow.insideGesture);
            this.state = 'running';
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

test('the audio is switched on by the first touch itself, not later by the beep', async () => {
    // Situation: an iPhone. The counter taps the tile of car 41. The page
    // writes the entry to the phone's storage, which takes a moment, and
    // beeps when that is done.
    //
    // What used to go wrong: the audio was created at the time of the beep,
    // that is AFTER the wait for the storage, when the tap was already over.
    // On iPhones the page then never made a sound.
    const fakeWindow = createFakeWindow();
    const feedback = createFeedback({ preferences: createPreferences(null), windowRef: fakeWindow });
    assert.equal(fakeWindow.audioContexts.length, 0, 'nothing before the person touches the page');

    fakeWindow.gesture('pointerdown');          // the tap on the tile
    await new Promise((resolve) => setImmediate(resolve));   // the storage is writing...
    feedback.registrado();                      // ...done: beep

    // Expected: exactly one audio context, created and resumed while the tap
    // was being delivered, and the beep played on it.
    assert.equal(fakeWindow.audioContexts.length, 1);
    const context = fakeWindow.audioContexts[0];
    assert.equal(context.createdInsideGesture, true);
    assert.deepEqual(context.resumedInsideGesture, [true]);
    assert.equal(context.beeps, 1);
});

test('a key press switches the audio on just like a touch', () => {
    // Situation: a laptop; the first thing the counter does is type a digit.
    const fakeWindow = createFakeWindow();
    createFeedback({ preferences: createPreferences(null), windowRef: fakeWindow });

    fakeWindow.gesture('keydown');

    assert.equal(fakeWindow.audioContexts.length, 1);
    assert.equal(fakeWindow.audioContexts[0].createdInsideGesture, true);
});

test('audio that the phone suspended is woken up by the next touch', () => {
    // Situation: a phone call comes in during the race, or the screen is
    // locked for a moment. The phone suspends the page's audio.
    const fakeWindow = createFakeWindow();
    createFeedback({ preferences: createPreferences(null), windowRef: fakeWindow });
    fakeWindow.gesture('pointerdown');
    const context = fakeWindow.audioContexts[0];
    context.state = 'interrupted';              // what an iPhone reports after a call

    fakeWindow.gesture('pointerdown');

    // Expected: the same audio context, resumed again inside the touch.
    assert.equal(fakeWindow.audioContexts.length, 1);
    assert.deepEqual(context.resumedInsideGesture, [true, true]);
});

test('a beep requested before any touch is skipped quietly', () => {
    // Situation: cannot really happen (an entry needs a touch), but a beep
    // must never be able to break counting.
    const fakeWindow = createFakeWindow();
    const feedback = createFeedback({ preferences: createPreferences(null), windowRef: fakeWindow });

    feedback.erro();

    assert.equal(fakeWindow.audioContexts.length, 0);
});

test('with the sound switch off there is no beep, and vibration still works', () => {
    const fakeWindow = createFakeWindow();
    const preferences = createPreferences(null);
    preferences.set('som', false);
    const feedback = createFeedback({ preferences, windowRef: fakeWindow });
    fakeWindow.gesture('pointerdown');

    feedback.registrado();

    assert.equal(fakeWindow.audioContexts[0].beeps, 0);
    assert.deepEqual(fakeWindow.vibrations, [40]);
});

test('a phone that cannot vibrate is recognised, so the page can hide the "Vibrar" switch', () => {
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
    iphone.gesture('pointerdown');
    feedback.aviso();
    assert.equal(iphone.audioContexts[0].beeps, 1);
});
