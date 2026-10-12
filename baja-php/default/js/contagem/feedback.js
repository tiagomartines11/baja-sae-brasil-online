// Beep and vibration after an entry. The beep is synthesised with WebAudio so
// there is no sound file to download or to miss when offline.
//
// THE RULE OF THIS FILE: sound is a nicety and a pass is not. Nothing here
// may delay, block or break the recording of a pass.
//
//   1. THE AUDIO IS CREATED WHEN THE PAGE LOADS, never in a key press or a
//      tap. `new AudioContext()` cannot be interrupted, and with an audio
//      device that does not answer it has been measured to take 20 seconds
//      to return (Chromium, after a reload). At page load that is a page
//      that opens late; inside a key press it was a frozen page in the
//      middle of counting.
//   2. IT IS SWITCHED ON BY A PRESS, AFTER THE PASS. Browsers only let a
//      page make sound once `resume()` was called inside a tap or key press.
//      That call is made inside the press that records a pass, but AFTER
//      the pass has its time and its disk write has been requested
//      (recordThenPrepareSound below).
//   3. NOTHING WAITS FOR AUDIO. Not for it to start, not for a beep to end.
//      A promise from the audio API that never settles, or that fails,
//      changes nothing but the missing sound.
//   4. A DEADLINE FOR STARTING. Audio that is not running SOUND_START_DEADLINE_MS
//      after the press is given up on for that press: the beep is skipped,
//      never played late. A later press asks again, one attempt at a time,
//      a few times at most.

const TOM_REGISTRADO = { frequencyHz: 880, durationMs: 90 };
const TOM_AVISO = { frequencyHz: 440, durationMs: 220 };
const TOM_ERRO = { frequencyHz: 220, durationMs: 600 };

/** Audio must be running this soon after the press, or that press stays silent. */
export const SOUND_START_DEADLINE_MS = 400;

/** After this many attempts in a row that did not start in time, no more are made in this page load. */
export const MAX_FAILED_STARTS = 3;

/**
 * After the browser reported a failed audio device, or after an audio call
 * that itself blocked the page (it cannot be interrupted, only noticed
 * afterwards), no audio call is made for this long, on this device, across
 * reloads: a counter who reloads again must not wait another 20 seconds for
 * the page to open.
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
 * Creates the page's audio right away (see carregarSom): call this while the
 * page is loading, before the entry controls are shown.
 *
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
    /** Whether creating the audio was tried in this page load. It is tried once. */
    let creationTried = false;
    /** The one request to start the audio that may be under way: {startedAtMs, settled, whenSettled}. */
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
     * longer than the deadline: the page was blocked meanwhile, and the same
     * call would block it again.
     */
    function callAudio(call) {
        const startedAtMs = monotonicNow();
        const result = call();
        if (monotonicNow() - startedAtMs > SOUND_START_DEADLINE_MS) {
            giveUp({ rememberOnDevice: true });
        }
        return result;
    }

    /**
     * Creates the audio context: once per page load, and ONLY from page
     * loading code (or from the "Som" switch being turned on), never from
     * the path that records a pass. It stays silent ("suspended") until a
     * press switches it on (prepararSom). With the sound switched off the
     * audio API is not touched at all: that is the counter's way out of any
     * audio trouble.
     */
    function carregarSom() {
        if (creationTried || gaveUp || !preferences.get('som', true)) {
            return;
        }
        creationTried = true;
        try {
            const AudioContextClass = windowRef.AudioContext || windowRef.webkitAudioContext;
            if (!AudioContextClass) {
                return;
            }
            const created = callAudio(() => new AudioContextClass());
            if (gaveUp) {
                return;
            }
            audioContext = created;
            // The browser reports here that the audio device failed under
            // the context. Measured in Chromium: from that moment the NEXT
            // `new AudioContext()` (that of the reloaded page) blocks for 20
            // seconds. So the failure is remembered on the device.
            if (typeof created.addEventListener === 'function') {
                created.addEventListener('error', () => giveUp({ rememberOnDevice: true }));
            }
        } catch (audioError) {
            // No sound in this page load. Counting does not depend on it.
        }
    }

    /**
     * Switches the audio on. To be called inside the tap or key press that
     * records a pass, AFTER the record was handed to the store (see
     * recordThenPrepareSound). It creates nothing and waits for nothing: it
     * asks the context made at page load to start, and returns.
     */
    function prepararSom() {
        if (gaveUp || audioContext === null || !preferences.get('som', true)) {
            return;
        }
        try {
            // Also after the first time: a phone call or locking the screen
            // suspends the audio again, and the next press must wake it up.
            if (audioContext.state === 'running') {
                return;
            }
            if (startAttempt !== null && !startAttempt.settled) {
                if (monotonicNow() - startAttempt.startedAtMs <= SOUND_START_DEADLINE_MS) {
                    // Still within its time: one request at a time.
                    return;
                }
                // It did not answer in time. Asking again is cheap, but an
                // audio device that never answers is not asked for ever.
                failedStartsInARow++;
                if (failedStartsInARow >= MAX_FAILED_STARTS) {
                    giveUp({ rememberOnDevice: false });
                    return;
                }
            }
            const attempt = { startedAtMs: monotonicNow(), settled: false, whenSettled: null };
            startAttempt = attempt;
            // Old browsers return nothing here; new ones a promise, which
            // may fail or never settle. Either way nobody waits for it.
            const resumed = callAudio(() => audioContext.resume());
            attempt.whenSettled = Promise.resolve(resumed).then(() => {
                attempt.settled = true;
                failedStartsInARow = 0;
            }, () => {
                attempt.settled = true;
            });
        } catch (audioError) {
            // Sound is a nicety; never let it interrupt counting. The next
            // press simply asks again.
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

    carregarSom();

    return {
        carregarSom,
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
