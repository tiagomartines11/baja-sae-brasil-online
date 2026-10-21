// Taps on the pit page's car tiles, with no DOM in sight: which taps record,
// and when the tiles may be put in a new order.
//
// The problem both classes solve: after an event the tapped car belongs to
// the other group ("inside" / "outside"), and if the tiles were re-ordered at
// once, every tile after it would slide under the marshal's finger. Two cars
// entering the pit lane together is normal, and the second tap, aimed at the
// second car a moment before, would land on a third one. (Rebuilding the
// tiles also loses a tap that is half-way: finger down on the old tile,
// finger up on its replacement, and the browser reports no click at all.)
//
// So the tiles STAY WHERE THEY ARE for a moment after each tap (TileLayoutHold),
// and the only tap that is ignored is a second one on the SAME car right
// after the first (TapGuard): a double tap, not a second car.

// A second tap on the same car within this time is the same finger again.
export const DOUBLE_TAP_MS = 350;

// How long after the last tap the tiles keep their places.
export const LAYOUT_HOLD_MS = 1200;

export class TapGuard {
    constructor() {
        this.lastCar = null;
        this.lastTapAtMs = -Infinity;
    }

    /**
     * @param {number} car
     * @param {number} nowMs monotonic time of the tap
     * @returns {boolean} false for a double tap on the same car, which must
     *          not record; true for every other tap, however soon it comes
     */
    accepts(car, nowMs) {
        if (car === this.lastCar && nowMs - this.lastTapAtMs < DOUBLE_TAP_MS) {
            return false;
        }
        this.lastCar = car;
        this.lastTapAtMs = nowMs;
        return true;
    }
}

export class TileLayoutHold {
    constructor() {
        this.shownGroups = null;
        this.shownMode = null;
        this.holdUntilMs = -Infinity;
        this.tappedCars = new Set();
    }

    /** A car was tapped: nothing moves until LAYOUT_HOLD_MS after the LAST tap. */
    noteTap(car, nowMs) {
        this.tappedCars.add(car);
        this.holdUntilMs = nowMs + LAYOUT_HOLD_MS;
    }

    isHolding(nowMs) {
        return nowMs < this.holdUntilMs;
    }

    /**
     * The groups of tiles to draw right now.
     *
     * @param {string} mode        area and direction on screen; a change of
     *        mode is the marshal's own doing and is drawn at once
     * @param {{primeiros: number[], outros: number[]}} freshGroups what the
     *        events recorded so far say (orderCarsForMode)
     * @param {number} nowMs       monotonic time
     * @returns {{groups: object, tappedCars: number[]}} `tappedCars`: cars
     *          tapped during the hold, still drawn in their old place; the
     *          page marks them so the tap is seen to have worked
     */
    layoutFor(mode, freshGroups, nowMs) {
        const keepPlaces = this.shownGroups !== null && mode === this.shownMode && this.isHolding(nowMs);
        if (!keepPlaces) {
            this.shownGroups = freshGroups;
            this.shownMode = mode;
            this.tappedCars.clear();
        }
        return { groups: this.shownGroups, tappedCars: Array.from(this.tappedCars) };
    }
}
