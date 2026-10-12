// Beep and vibration after an entry. The beep is synthesised with WebAudio so
// there is no sound file to download or to miss when offline.
//
// THE RULE OF THIS FILE: sound is a nicety and a pass is not. Nothing here
// may delay, block or break the recording of a pass.
//
//   1. RECORD FIRST. The audio of a page only works if it was switched on
//      inside a tap or key press. So it is switched on inside the gesture
//      that records a pass, but AFTER the pass has its time and its disk
//      write has been requested (recordThenPrepareSound below). An audio
//      device that does not answer has been seen to freeze a page for 20
//      seconds inside `new AudioContext()`; when that came BEFORE the page's
//      own handler, the key press was lost in it.
//   2. NEVER WAIT FOR AUDIO. Nothing is awaited: not the audio starting, not
//      a beep ending. A promise from the audio API that never settles, or
//      that fails, changes nothing but the missing sound.
//   3. A DEADLINE FOR STARTING. Audio that has not started SOUND_START_DEADLINE_MS
//      after the press is given up on for that press: the beep is skipped,
//      never played late. A later press tries again, one attempt at a time.
//      After a few failures in a row, or one call that froze the page, sound
//      is left alone (see giveUp).

const TOM_REGISTRADO = { frequencyHz: 880, durationMs: 90 };
const TOM_AVISO = { frequencyHz: 440, durationMs: 220 };
const TOM_ERRO = { frequencyHz: 220, durationMs: 600 };

/** Audio must be running this soon after the press, or that press stays silent. */
export const SOUND_START_DEADLINE_MS = 400;

/** After this many attempts in a row that did not start in time, no more are made in this page load. */
export const MAX_FAILED_STARTS = 3;

/**
 * After the browser reported a failed audio device, or after an audio call
 * that itself froze the page (it cannot be interrupted, only noticed
 * afterwards), no audio call is made for this long, on this device, across
 * reloads: the first key press of a reloaded page must not freeze it.
 */
export const FROZEN_AUDIO_REST_MS = 10 * 60 * 1000;

const PREFERENCE_AUDIO_FROZE_AT = 'som_travou_em';

/**
 * iPhones have no vibration API at all. The page uses this to hide the
 * "Vibrar" switch there instead of offering something that does nothing.
 */
export function canVibrate(navigatorRef) {
    return Boolean(navigatorRef) && typeof navigatorRef.vibrate === 'function';
}

/**
 * How every key press and tap that records something must be handled:
 * first the record, then the sound. `startRecording` must take the time of
 * the pass and hand the record to the local store before it returns (its
 * promise is NOT waited for here: this must all happen inside the gesture).
 *
 * @param {function} startRecording () => Promise, e.g. () => recordPass(car, typingMs)
 * @param {object}   feedback       from createFeedback()
 * @returns {Promise} what startRecording returned
 */
export function recordThenPrepareSound(startRecording, feedback) {
    const recording = startRecording();
    feedback.prepararSom();
    return recording;
}

/**
 * @param {object}   options
 * @param {object}   options.preferences  from createPreferences(); keys "som" and "vibrar"
 * @param {Window}   options.windowRef
 * @param {function} [options.monotonicNow] () => ms, a clock that cannot be set
 * @param {function} [options.now]          () => system clock in ms
 */
export function createFeedback({
    preferences, windowRef,
    monotonicNow = () => windowRef.performance.now(),
    now = () => Date.now(),
}) {
    let audioContext = null;
    /** The one attempt to start the audio that may be under way: {startedAtMs, settled, whenSettled}. */
    let startAttempt = null;
    let failedStartsInARow = 0;
    const frozeAtMs = preferences.get(PREFERENCE_AUDIO_FROZE_AT, null);
    let gaveUp = Number.isFinite(frozeAtMs) && Math.abs(now() - frozeAtMs) < FROZEN_AUDIO_REST_MS;

    function giveUp({ rememberOnDevice }) {
        gaveUp = true;
        audioContext = null;
        startAttempt = null;
        if (rememberOnDevice) {
            preferences.set(PREFERENCE_AUDIO_FROZE_AT, now());
        }
    }

    /**
     * Runs one synchronous call of the audio API and notices when it took
     * longer than the deadline: the page was frozen meanwhile, and the same
     * call would freeze it again.
     */
    function callAudio(call) {
        const startedAtMs = monotonicNow();
        const result = call();
        if (monotonicNow() - startedAtMs > SOUND_START_DEADLINE_MS) {
            giveUp({ rememberOnDevice: true });
        }
        return result;
    }

    function startResuming() {
        const attempt = { startedAtMs: monotonicNow(), settled: false, whenSettled: null };
        startAttempt = attempt;
        // Old browsers return nothing here; new ones a promise, which may
        // fail or never settle. Either way nobody waits for it.
        const resumed = callAudio(() => audioContext.resume());
        attempt.whenSettled = Promise.resolve(resumed).then(() => {
            attempt.settled = true;
            failedStartsInARow = 0;
        }, () => {
            attempt.settled = true;
        });
    }

    /**
     * Switches the audio on. To be called inside the tap or key press that
     * records a pass, AFTER the record was handed to the store (see
     * recordThenPrepareSound). Returns at once whatever the audio does.
     */
    function prepararSom() {
        if (gaveUp || !preferences.get('som', true)) {
            return;
        }
        try {
            if (startAttempt !== null && !startAttempt.settled) {
                if (monotonicNow() - startAttempt.startedAtMs <= SOUND_START_DEADLINE_MS) {
                    // Still within its time: one attempt at a time.
                    return;
                }
                // It did not answer in time. That audio context is dropped
                // (nothing is piled onto it) and a fresh one is tried, a
                // few times at most.
                audioContext = null;
                startAttempt = null;
                failedStartsInARow++;
                if (failedStartsInARow >= MAX_FAILED_STARTS) {
                    giveUp({ rememberOnDevice: false });
                    return;
                }
            }
            if (audioContext === null) {
                const AudioContextClass = windowRef.AudioContext || windowRef.webkitAudioContext;
                if (!AudioContextClass) {
                    return;
                }
                const created = callAudio(() => new AudioContextClass());
                if (gaveUp) {
                    return;
                }
                audioContext = created;
                // The browser reports here that the audio device failed
                // under a running context. Measured in Chromium: from that
                // moment the NEXT `new AudioContext()` (on this page or on
                // the reloaded one) freezes the page for 20 seconds. So
                // the failure is remembered and no new context is made.
                if (typeof created.addEventListener === 'function') {
                    created.addEventListener('error', () => giveUp({ rememberOnDevice: true }));
                }
            }
            // Also after the first time: a phone call or locking the screen
            // suspends the audio again, and the next press must wake it up.
            if (audioContext.state !== 'running') {
                startResuming();
            }
        } catch (audioError) {
            // Sound is a nicety; never let it interrupt counting. The next
            // press simply tries again.
        }
    }

    function playNow(context, tone) {
        try {
            const oscillator = context.createOscillator();
            const volume = context.createGain();
            oscillator.type = 'square';
            oscillator.frequency.value = tone.frequencyHz;
            volume.gain.value = 0.2;
            oscillator.connect(volume);
            volume.connect(context.destination);
            oscillator.start();
            oscillator.stop(context.currentTime + tone.durationMs / 1000);
        } catch (audioError) {
            // Sound is a nicety; never let it interrupt counting.
        }
    }

    function beep(tone) {
        const context = audioContext;
        // No press has switched the audio on (or it was given up on):
        // creating it here, outside a gesture, would only produce a context
        // that stays silent.
        if (context === null || !preferences.get('som', true)) {
            return;
        }
        if (context.state === 'running') {
            playNow(context, tone);
            return;
        }
        // The audio is still starting (the press that asked for it was a
        // moment ago). The beep is played when it starts, but only if that
        // is within the deadline: a beep seconds late would be taken for
        // another entry.
        const attempt = startAttempt;
        if (attempt === null || attempt.settled) {
            return;
        }
        attempt.whenSettled.then(() => {
            const startedInTime = monotonicNow() - attempt.startedAtMs <= SOUND_START_DEADLINE_MS;
            if (startedInTime && audioContext === context && context.state === 'running') {
                playNow(context, tone);
            }
        });
    }

    function vibrate(pattern) {
        if (!preferences.get('vibrar', true)) {
            return;
        }
        try {
            if (canVibrate(windowRef.navigator)) {
                windowRef.navigator.vibrate(pattern);
            }
        } catch (vibrationError) {
            // Same as sound.
        }
    }

    return {
        prepararSom,
        registrado() {
            beep(TOM_REGISTRADO);
            vibrate(40);
        },
        aviso() {
            beep(TOM_AVISO);
            vibrate([60, 60, 60]);
        },
        erro() {
            beep(TOM_ERRO);
            vibrate([300, 100, 300]);
        },
    };
}
