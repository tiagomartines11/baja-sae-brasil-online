// Beep and vibration after an entry. The beep is synthesised with WebAudio so
// there is no sound file to download or to miss when offline.

const TOM_REGISTRADO = { frequencyHz: 880, durationMs: 90 };
const TOM_AVISO = { frequencyHz: 440, durationMs: 220 };
const TOM_ERRO = { frequencyHz: 220, durationMs: 600 };

// Any of these counts as "the person touched the page" for a browser.
const GESTURE_EVENTS = ['pointerdown', 'touchend', 'keydown'];

/**
 * iPhones have no vibration API at all. The page uses this to hide the
 * "Vibrar" switch there instead of offering something that does nothing.
 */
export function canVibrate(navigatorRef) {
    return Boolean(navigatorRef) && typeof navigatorRef.vibrate === 'function';
}

/**
 * @param {object} options
 * @param {object} options.preferences  from createPreferences(); keys "som" and "vibrar"
 * @param {Window} options.windowRef
 */
export function createFeedback({ preferences, windowRef }) {
    let audioContext = null;

    /**
     * Browsers (iPhones strictly) only let a page make sound if the audio
     * was switched on DURING a tap or key press, in the same instant, with
     * no waiting in between. The beep itself comes too late for that: it is
     * played after the entry has been written to the disk. So the audio is
     * switched on here, by the gesture itself, and the beep finds it ready.
     */
    function unlockAudioInsideGesture() {
        try {
            if (audioContext === null) {
                const AudioContextClass = windowRef.AudioContext || windowRef.webkitAudioContext;
                if (!AudioContextClass) {
                    return;
                }
                audioContext = new AudioContextClass();
            }
            // Also after the first time: a phone call or locking the screen
            // suspends the audio again, and the next tap must wake it up.
            if (audioContext.state !== 'running') {
                audioContext.resume();
            }
        } catch (audioError) {
            // Sound is a nicety; never let it interrupt counting.
        }
    }

    for (const eventName of GESTURE_EVENTS) {
        // "capture" so the audio is ready before the page's own handler of
        // the same tap asks for a beep.
        windowRef.addEventListener(eventName, unlockAudioInsideGesture, { capture: true, passive: true });
    }

    function beep(tone) {
        if (!preferences.get('som', true)) {
            return;
        }
        try {
            // No gesture yet means no audio yet; creating it here, outside a
            // gesture, would only produce a context that stays silent.
            const context = audioContext;
            if (context === null) {
                return;
            }
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
