// The three ways of entering a car number, the per-device preferences, and the
// small digit buffer behind the keyboard modes.

export const MODO = {
    TECLADO: 'teclado',            // system keyboard: digits then Enter
    TECLADO_TELA: 'teclado_tela',  // large on-screen keypad
    BLOCOS: 'blocos',              // one large button per car
};

export const MODOS = [MODO.TECLADO, MODO.TECLADO_TELA, MODO.BLOCOS];

export const ROTULO_MODO = {
    [MODO.TECLADO]: 'Teclado',
    [MODO.TECLADO_TELA]: 'Teclado na tela',
    [MODO.BLOCOS]: 'Blocos',
};

// Car numbers at Baja events have at most three digits; four leaves slack
// without letting a stuck key produce an absurd number.
export const MAX_DIGITS = 4;

const PREFERENCE_PREFIX = 'contagem.';

/**
 * Preferences belong to the device, not to a source: a volunteer who likes
 * the keypad wants it on every counter opened on that phone. `storageLike` is
 * `window.localStorage`; when it is missing or blocked the choice simply lasts
 * until the page is closed.
 */
export function createPreferences(storageLike) {
    const sessionValues = new Map();

    return {
        get(name, fallback) {
            if (sessionValues.has(name)) {
                return sessionValues.get(name);
            }
            try {
                const raw = storageLike ? storageLike.getItem(PREFERENCE_PREFIX + name) : null;
                return raw === null || raw === undefined ? fallback : JSON.parse(raw);
            } catch (storageError) {
                return fallback;
            }
        },
        set(name, value) {
            sessionValues.set(name, value);
            try {
                if (storageLike) {
                    storageLike.setItem(PREFERENCE_PREFIX + name, JSON.stringify(value));
                }
            } catch (storageError) {
                // Private browsing or full storage: keep the in-page value.
            }
        },
    };
}

/** Touch devices start on the keypad, devices with a real keyboard on typing. */
export function loadEntryMode(preferences, deviceHasTouch) {
    const saved = preferences.get('modo', null);
    if (MODOS.includes(saved)) {
        return saved;
    }
    return deviceHasTouch ? MODO.TECLADO_TELA : MODO.TECLADO;
}

export function saveEntryMode(preferences, mode) {
    if (MODOS.includes(mode)) {
        preferences.set('modo', mode);
    }
}

/**
 * Digits typed so far, and when the first one was typed. The time between the
 * first digit and Enter is uploaded as `typing_ms`: the reviewer can use it to
 * tell a calm entry from a rushed one.
 */
export class NumberBuffer {
    constructor() {
        this.digits = '';
        this.firstDigitAtMs = null;
    }

    text() {
        return this.digits;
    }

    isEmpty() {
        return this.digits === '';
    }

    pressDigit(digit, nowMs) {
        if (!/^[0-9]$/.test(String(digit)) || this.digits.length >= MAX_DIGITS) {
            return;
        }
        if (this.digits === '') {
            this.firstDigitAtMs = nowMs;
        }
        this.digits += String(digit);
    }

    /** For the system keyboard, where the browser's input box holds the text. */
    setText(rawText, nowMs) {
        const cleaned = String(rawText).replace(/[^0-9]/g, '').slice(0, MAX_DIGITS);
        if (cleaned === '') {
            this.clear();
            return;
        }
        if (this.digits === '') {
            this.firstDigitAtMs = nowMs;
        }
        this.digits = cleaned;
    }

    backspace() {
        this.digits = this.digits.slice(0, -1);
        if (this.digits === '') {
            this.firstDigitAtMs = null;
        }
    }

    clear() {
        this.digits = '';
        this.firstDigitAtMs = null;
    }

    /**
     * Enter was pressed. Returns what to record and empties the buffer, or
     * returns null when there is nothing to record.
     * @returns {?{car: number, typingMs: number}}
     */
    confirm(nowMs) {
        if (this.digits === '') {
            return null;
        }
        const confirmed = {
            car: parseInt(this.digits, 10),
            typingMs: Math.max(0, nowMs - this.firstDigitAtMs),
        };
        this.clear();
        return confirmed;
    }
}

export const TECLA = {
    DIGITO: 'digito',
    APAGAR: 'apagar',
    REGISTRAR: 'registrar',
};

/**
 * Decides what a key pressed ANYWHERE on the page means for the number being
 * typed. Both keyboard modes need this: a counter who taps "desfazer", a
 * checkbox or the page background moves the focus out of the number box, and
 * the next car must still be typed blind, without looking for the box again.
 *
 * @param {object}  key
 * @param {string}  key.key      `KeyboardEvent.key`
 * @param {boolean} [key.repeat] true for the auto-repeat of a key held down
 * @param {boolean} [key.ctrlKey]
 * @param {boolean} [key.altKey]
 * @param {boolean} [key.metaKey]
 * @param {object}  context
 * @param {string}  context.mode             one of MODO.*
 * @param {boolean} context.typedInNumberBox the key went into the number box itself
 * @returns {?{tecla: string, digito: ?string}} null when the page must leave the key alone
 */
export function keyActionFor(key, { mode, typedInNumberBox }) {
    if (mode !== MODO.TECLADO && mode !== MODO.TECLADO_TELA) {
        return null;
    }
    // In keyboard mode the number box handles its own typing (that is also
    // the only path a phone's on-screen keyboard uses). Handling the key here
    // as well would enter every digit twice.
    if (mode === MODO.TECLADO && typedInNumberBox) {
        return null;
    }
    // A key held down a moment too long must not become "77" or two passes.
    if (key.repeat) {
        return null;
    }
    // Ctrl+1, Alt+2... are browser shortcuts, not car numbers.
    if (key.ctrlKey || key.altKey || key.metaKey) {
        return null;
    }
    if (/^[0-9]$/.test(key.key)) {
        return { tecla: TECLA.DIGITO, digito: key.key };
    }
    if (key.key === 'Backspace') {
        return { tecla: TECLA.APAGAR, digito: null };
    }
    if (key.key === 'Enter') {
        return { tecla: TECLA.REGISTRAR, digito: null };
    }
    return null;
}
