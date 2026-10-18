// Tiles mode ("Blocos"), with no DOM in sight: what a tap on a car tile and a
// tap on "Passou" mean.
//
// TWO TAPS (the default, and how counters are trained): tap the car as it
// approaches; it is shown large as selected, and tapping another car simply
// changes the selection. Then tap the large "Passou" button as the car
// crosses the line. The pass is recorded at that second tap, and its time is
// the time of that second tap.
//
// ONE TAP (the switch "Um toque marca a passagem", off by default): a tap on
// a tile records the pass at once. Faster, but a slip of the finger is a
// recorded pass, so the page asks before switching it on.

// THE "??" TILE is always the first one. It works like a car tile whose car
// is "not readable": selected and then "Passou", or at once with one tap.
// The pass is then recorded with no number (car null).

const PREFERENCE_ONE_TAP = 'um_toque';

/** What `selected()` returns while the "??" tile is selected. */
export const BLOCO_ILEGIVEL = 'ilegivel';

export class TilesControl {
    /**
     * @param {object}   options
     * @param {object}   options.preferences  from createPreferences(): the switch is remembered per device
     * @param {function} options.monotonicNow () => ms, to measure how long the car was selected
     */
    constructor({ preferences, monotonicNow }) {
        this.preferences = preferences;
        this.monotonicNow = monotonicNow;
        this.selectedCar = null;
        this.selectedAtMs = null;
    }

    oneTap() {
        return this.preferences.get(PREFERENCE_ONE_TAP, false) === true;
    }

    /** The page calls this only after the person confirmed switching it ON. */
    setOneTap(enabled) {
        this.preferences.set(PREFERENCE_ONE_TAP, enabled === true);
        this.clearSelection();
    }

    /**
     * @returns {?(number|string)} the car waiting for "Passou", BLOCO_ILEGIVEL
     *          for "??" (always null in one-tap mode)
     */
    selected() {
        return this.selectedCar;
    }

    clearSelection() {
        this.selectedCar = null;
        this.selectedAtMs = null;
    }

    /**
     * @param {?number} car null for the "??" tile
     * @returns {?{car: ?number, typingMs: ?number}} the pass to record NOW
     *          (one-tap mode), or null when the tap only selected the car
     */
    tapTile(car) {
        if (this.oneTap()) {
            return { car, typingMs: null };
        }
        this.selectedCar = car === null ? BLOCO_ILEGIVEL : car;
        this.selectedAtMs = this.monotonicNow();
        return null;
    }

    /**
     * @returns {?{car: ?number, typingMs: number}} the pass to record NOW, or
     *          null when no car is selected (a stray tap records nothing).
     *          `typingMs` is how long the car was selected before it
     *          crossed, the tiles' equivalent of the typing time.
     */
    tapPassou() {
        if (this.selectedCar === null) {
            return null;
        }
        const pass = {
            car: this.selectedCar === BLOCO_ILEGIVEL ? null : this.selectedCar,
            typingMs: Math.max(0, Math.round(this.monotonicNow() - this.selectedAtMs)),
        };
        // One selection, one pass: a second tap on "Passou" must not count
        // the same car again.
        this.clearSelection();
        return pass;
    }
}
