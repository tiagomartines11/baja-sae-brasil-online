// Beep and vibration after an entry. The beep is synthesised with WebAudio so
// there is no sound file to download or to miss when offline.

const TOM_REGISTRADO = { frequencyHz: 880, durationMs: 90 };
const TOM_AVISO = { frequencyHz: 440, durationMs: 220 };
const TOM_ERRO = { frequencyHz: 220, durationMs: 600 };

/**
 * @param {object} options
 * @param {object} options.preferences  from createPreferences(); keys "som" and "vibrar"
 * @param {Window} options.windowRef
 */
export function createFeedback({ preferences, windowRef }) {
    let audioContext = null;

    function audio() {
        // Created on first use, which is always inside a tap or key press:
        // browsers refuse to start audio before the user interacted.
        if (audioContext === null) {
            const AudioContextClass = windowRef.AudioContext || windowRef.webkitAudioContext;
            if (!AudioContextClass) {
                return null;
            }
            audioContext = new AudioContextClass();
        }
        if (audioContext.state === 'suspended') {
            audioContext.resume();
        }
        return audioContext;
    }

    function beep(tone) {
        if (!preferences.get('som', true)) {
            return;
        }
        try {
            const context = audio();
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
            if (typeof windowRef.navigator.vibrate === 'function') {
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
