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
    // "?": the "??" button, a pass whose number could not be read.
    ILEGIVEL: 'ilegivel',
    // The key must do NOTHING: not what the page would do with it, and not
    // what the browser would do with it either (the page cancels it).
    NADA: 'nada',
};

const DO_NOTHING = { tecla: TECLA.NADA, digito: null };

function isDigit(key) {
    return /^[0-9]$/.test(key);
}

/** The key that records "??" from a physical keyboard. */
export const TECLA_ILEGIVEL = '?';

/**
 * Keyboard mode: the browser's number box takes "?" like any other
 * character (a phone's keyboard sends no key code the page could catch).
 * A "?" anywhere in the box means the counter pressed "??".
 */
export function typedUnreadMark(rawText) {
    return String(rawText).includes(TECLA_ILEGIVEL);
}

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
 * @param {boolean} [context.focusOnKeypadKey] the focus is on a key of the on-screen keypad
 * @returns {?{tecla: string, digito: ?string}} null when the page must leave
 *          the key alone (the browser then does what it normally does with
 *          it); `TECLA.NADA` when the key must be cancelled altogether
 */
export function keyActionFor(key, { mode, typedInNumberBox, focusOnKeypadKey = false }) {
    if (mode !== MODO.TECLADO && mode !== MODO.TECLADO_TELA) {
        return null;
    }
    // Ctrl+1, Alt+2... are browser shortcuts, not car numbers.
    if (key.ctrlKey || key.altKey || key.metaKey) {
        return null;
    }
    // In keyboard mode the number box handles its own typing (that is also
    // the only path a phone's on-screen keyboard uses). Handling the key here
    // as well would enter every digit twice.
    if (mode === MODO.TECLADO && typedInNumberBox) {
        // ...except for a key HELD DOWN. The browser repeats it by itself,
        // and the box would fill with "777" (or a held Enter would submit
        // again and again). Leaving the key alone is not enough here: the
        // repeat has to be cancelled. A held Backspace may keep deleting.
        return key.repeat && (isDigit(key.key) || key.key === 'Enter' || key.key === TECLA_ILEGIVEL) ? DO_NOTHING : null;
    }
    // On the keypad the last key that was tapped keeps the focus, and a
    // browser "clicks" the focused button when Space is pressed: a stray
    // Space would type that digit again, or press OK again. (Space on any
    // other control, a checkbox for instance, keeps working.)
    if (key.key === ' ' || key.key === 'Spacebar') {
        return focusOnKeypadKey ? DO_NOTHING : null;
    }
    // A key held down a moment too long must not become "77" or two passes.
    if (key.repeat) {
        return isDigit(key.key) || key.key === 'Enter' || key.key === TECLA_ILEGIVEL ? DO_NOTHING : null;
    }
    if (key.key === TECLA_ILEGIVEL) {
        return { tecla: TECLA.ILEGIVEL, digito: null };
    }
    if (isDigit(key.key)) {
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
