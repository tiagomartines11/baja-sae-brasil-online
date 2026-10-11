// Tells the time of the SERVER without trusting this device's system clock.
//
// A phone's clock can be minutes or days wrong, and it can be changed in the
// middle of a race (by hand, or by the phone syncing with the network). So
// the system clock (`Date.now()`) takes no part in timing a pass. Instead:
//
//   - every browser has a second clock, `performance.now()`: milliseconds
//     since the page was opened. It cannot be set, it only moves forward, and
//     no time setting touches it. It is called the MONOTONIC clock here.
//   - every successful exchange with the server gives an ANCHOR: "when the
//     monotonic clock said M, the server's clock said S".
//   - a pass typed when the monotonic clock says m happened at server time
//     S + (m - M).
//
// How the anchor is measured (midpoint method): we note the monotonic time
// when a request leaves and when its answer arrives. The server read its
// clock somewhere in between; the best single guess is the middle, and the
// guess is wrong by at most half the round trip. So the answer with the
// SHORTEST round trip gives the best anchor and is the one kept.
//
// The system clock is kept only as a witness: its reading goes along with
// each entry for the audit trail, and it is the fallback for the one case
// nothing else can cover (see resolveEarlierLoad).

export const QUALIDADE_TEMPO = {
    EXATO: 'exato',                // from an anchor of this page load
    ESTIMADO: 'estimado',          // from the system clock and a remembered anchor
    DESCONHECIDO: 'desconhecido',  // raw system clock; nothing to correct it with
};

// A remembered anchor older than this (relative to the entry) is not used.
export const PERSISTED_ANCHOR_MAX_AGE_MS = 12 * 60 * 60 * 1000;

// On some phones the monotonic clock stops while the device sleeps. When the
// two clocks disagree by more than this about how much time went by, the
// anchor can no longer be trusted.
export const MONOTONIC_GUARD_TOLERANCE_MS = 2000;

// A crystal in a phone may run fast or slow by a few parts in 100 000. An
// anchor therefore gets a little less certain as it ages (0.1 ms per second,
// 360 ms per hour), which lets a fresh, slightly slower answer replace a
// very old, very quick one.
const DRIFT_PER_MS = 0.0001;

// Extra tolerance before deciding that a new answer CONTRADICTS the anchor.
const CONTRADICTION_MARGIN_MS = 500;

export class ServerClock {
    /**
     * @param {object}   options
     * @param {function} options.now          () => system clock in ms (`Date.now`)
     * @param {function} options.monotonicNow () => monotonic clock in ms (`performance.now`)
     * @param {string}   options.loadId       identifies THIS page load; a reload gets another
     */
    constructor({ now, monotonicNow, loadId }) {
        this.now = now;
        this.monotonicNow = monotonicNow;
        this.loadId = loadId;
        /** @type {?{serverMs: number, monoMs: number, rttMs: number}} */
        this.anchor = null;
        // Goes up each time the monotonic clock is caught misbehaving. Two
        // monotonic readings can only be subtracted inside one segment.
        this.segment = 0;
        /** The anchor remembered from an earlier page load (see restorePersisted). */
        this.persisted = null;
        this.lastCheck = { monoMs: monotonicNow(), deviceMs: now() };
    }

    hasAnchor() {
        return this.anchor !== null;
    }

    /** @returns {?number} the server's time right now; null until the first anchor of this page load */
    serverNowMs() {
        return this.anchor === null ? null : this.serverTimeAt(this.monotonicNow());
    }

    serverTimeAt(monoMs) {
        return Math.round(this.anchor.serverMs + (monoMs - this.anchor.monoMs));
    }

    /** Round trip of the anchor in use (null when none). */
    roundTripMs() {
        return this.anchor === null ? null : this.anchor.rttMs;
    }

    /**
     * @param {number} sentMonoMs     monotonic time when the request left
     * @param {number} receivedMonoMs monotonic time when the answer arrived
     * @param {number} serverNowMs    server time written in the answer
     * @returns {boolean} whether this answer became the anchor in use
     */
    addSample(sentMonoMs, receivedMonoMs, serverNowMs) {
        const rttMs = receivedMonoMs - sentMonoMs;
        if (!Number.isFinite(serverNowMs) || !Number.isFinite(rttMs) || rttMs < 0) {
            return false;
        }
        const candidate = { serverMs: serverNowMs, monoMs: (sentMonoMs + receivedMonoMs) / 2, rttMs };
        if (this.anchor === null || this.isBetterThanAnchor(candidate)) {
            this.anchor = candidate;
            return true;
        }
        return false;
    }

    isBetterThanAnchor(candidate) {
        const ageMs = candidate.monoMs - this.anchor.monoMs;
        const anchorUncertaintyMs = this.anchor.rttMs / 2 + Math.abs(ageMs) * DRIFT_PER_MS;
        const candidateUncertaintyMs = candidate.rttMs / 2;
        // What the anchor says the server's clock should read at that moment.
        const predictedServerMs = this.anchor.serverMs + ageMs;
        const disagreementMs = Math.abs(candidate.serverMs - predictedServerMs);
        // If the two disagree by more than both uncertainties together, they
        // cannot both be right: the monotonic clock misbehaved without the
        // guard noticing, and the older reading is the one that is stale.
        if (disagreementMs > anchorUncertaintyMs + candidateUncertaintyMs + CONTRADICTION_MARGIN_MS) {
            return true;
        }
        return candidateUncertaintyMs < anchorUncertaintyMs;
    }

    /**
     * The guard of brief 6.5. Called every second and before every stamp:
     * compares how far each clock moved since the last call. They normally
     * agree to a few milliseconds. When they do not, one of them jumped or
     * stood still, and we cannot tell which, so the anchor is thrown away
     * and the next sync takes a new one.
     *
     * @returns {boolean} true when the anchor was discarded just now
     */
    checkMonotonic() {
        const monoMs = this.monotonicNow();
        const deviceMs = this.now();
        const monoAdvanceMs = monoMs - this.lastCheck.monoMs;
        const deviceAdvanceMs = deviceMs - this.lastCheck.deviceMs;
        this.lastCheck = { monoMs, deviceMs };
        if (Math.abs(deviceAdvanceMs - monoAdvanceMs) <= MONOTONIC_GUARD_TOLERANCE_MS) {
            return false;
        }
        this.anchor = null;
        this.segment++;
        return true;
    }

    /**
     * The time fields of a record made RIGHT NOW (the Enter press, the tap).
     * With an anchor the final time is known at once. Without one the record
     * is HELD: `ts_ms` is null, and resolveHeld() fills it in later from the
     * monotonic reading taken here.
     */
    stamp() {
        this.checkMonotonic();
        const monoMs = this.monotonicNow();
        const witness = this.witnessFields(monoMs);
        if (this.anchor === null) {
            return { ...witness, ts_ms: null, time_quality: null, anchor: null };
        }
        return { ...witness, ...this.exactTimeAt(monoMs) };
    }

    /**
     * The time fields of a record whose time is GIVEN, already in server
     * time (the race simulation: "this car crossed at 14:03:22.100").
     * Never held: the time does not depend on any clock of this device.
     */
    stampAt(serverMs) {
        const monoMs = this.monotonicNow();
        return {
            ...this.witnessFields(monoMs),
            ts_ms: Math.round(serverMs),
            time_quality: QUALIDADE_TEMPO.EXATO,
            anchor: this.anchor === null ? null : this.anchorDetails(monoMs - this.anchor.monoMs),
        };
    }

    /** What is kept beside the time for the audit trail and for resolving later. */
    witnessFields(monoMs) {
        return { ts_device_ms: this.now(), mono_ms: monoMs, clock_segment: this.segment, page_load: this.loadId };
    }

    exactTimeAt(monoMs) {
        return {
            ts_ms: this.serverTimeAt(monoMs),
            time_quality: QUALIDADE_TEMPO.EXATO,
            anchor: this.anchorDetails(monoMs - this.anchor.monoMs),
        };
    }

    /** The `anchor` object of the upload contract. `ageMs` is negative for a record older than the anchor. */
    anchorDetails(ageMs) {
        return {
            server_ms: Math.round(this.anchor.serverMs),
            rtt_ms: Math.round(this.anchor.rttMs),
            age_ms: Math.round(ageMs),
        };
    }

    /** Whether the record was made in this page load and still has no time. */
    isHeldHere(record) {
        return record.ts_ms === null && record.page_load === this.loadId;
    }

    /**
     * The final time of a record HELD in this page load (brief 6.3).
     *
     * @returns {?object} `{ts_ms, time_quality, anchor}`, or null while there is still no anchor
     */
    resolveHeld(record) {
        if (this.anchor === null) {
            return null;
        }
        if (record.clock_segment === this.segment) {
            // Same stretch of monotonic clock as the anchor: exact, the
            // record simply happened a known number of milliseconds earlier.
            return this.exactTimeAt(record.mono_ms);
        }
        // The monotonic clock misbehaved between the record and the anchor,
        // so the two readings cannot be subtracted. The system clock bridges
        // the gap, and the result says so.
        const ageMs = record.ts_device_ms - this.now();
        return {
            ts_ms: this.serverNowMs() + ageMs,
            time_quality: QUALIDADE_TEMPO.ESTIMADO,
            anchor: this.anchorDetails(ageMs),
        };
    }

    /**
     * The final time of a record made in an EARLIER page load that never got
     * an anchor (brief 6.4): typed with no network, then the page was
     * reloaded or the phone restarted, still with no network. The monotonic
     * clock of that page load is gone, so only the system clock is left.
     */
    resolveEarlierLoad(record) {
        const remembered = this.persisted;
        const ageMs = remembered === null ? null : record.ts_device_ms - remembered.device_ms;
        // A negative age means the system clock was set back since the
        // anchor was remembered: exactly what the estimate cannot survive.
        if (ageMs === null || !Number.isFinite(ageMs) || ageMs < 0 || ageMs > PERSISTED_ANCHOR_MAX_AGE_MS) {
            return { ts_ms: record.ts_device_ms, time_quality: QUALIDADE_TEMPO.DESCONHECIDO, anchor: null };
        }
        return {
            ts_ms: Math.round(remembered.server_ms + ageMs),
            time_quality: QUALIDADE_TEMPO.ESTIMADO,
            anchor: { server_ms: remembered.server_ms, rtt_ms: remembered.rtt_ms, age_ms: ageMs },
        };
    }

    /**
     * What to write to the disk after each sync: "the server said S when
     * this device's SYSTEM clock said D". It is all a later page load can use.
     * @returns {?{server_ms: number, device_ms: number, rtt_ms: number}}
     */
    anchorToPersist() {
        if (this.anchor === null) {
            return null;
        }
        return { server_ms: this.serverNowMs(), device_ms: this.now(), rtt_ms: Math.round(this.anchor.rttMs) };
    }

    /** @param {?object} stored what anchorToPersist() returned in an earlier page load */
    restorePersisted(stored) {
        const usable = stored
            && Number.isFinite(stored.server_ms)
            && Number.isFinite(stored.device_ms)
            && Number.isFinite(stored.rtt_ms);
        this.persisted = usable ? { server_ms: stored.server_ms, device_ms: stored.device_ms, rtt_ms: stored.rtt_ms } : null;
    }
}

/**
 * Milliseconds between two records of this device (positive when `later` is
 * the later one), read from the best clock the two have in common. It is
 * used for the "same car again too soon" warning, nothing else.
 *
 *   - both have a final server time: that is the truth;
 *   - both made in the same stretch of the same page load: the monotonic clock;
 *   - same page load, but the guard fired in between (one of the two clocks
 *     jumped, and nobody can tell which): the SHORTER of the two readings.
 *     A warning shown for nothing costs a glance; a double entry that gets
 *     no warning costs a reviewer's time later;
 *   - otherwise: the system clock.
 */
export function elapsedBetween(earlier, later) {
    if (Number.isFinite(earlier.ts_ms) && Number.isFinite(later.ts_ms)) {
        return later.ts_ms - earlier.ts_ms;
    }
    const deviceMs = later.ts_device_ms - earlier.ts_device_ms;
    const samePageLoad = earlier.page_load !== undefined && earlier.page_load === later.page_load
        && Number.isFinite(earlier.mono_ms) && Number.isFinite(later.mono_ms);
    if (!samePageLoad) {
        return deviceMs;
    }
    const monoMs = later.mono_ms - earlier.mono_ms;
    if (earlier.clock_segment === later.clock_segment) {
        return monoMs;
    }
    return Math.abs(monoMs) <= Math.abs(deviceMs) ? monoMs : deviceMs;
}
