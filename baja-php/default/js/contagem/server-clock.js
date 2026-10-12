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
// when a request leaves and when its answer arrives, and the server writes
// in its answer when the request arrived and when the answer left. The
// middle of the server's two times happened at the middle of ours, give or
// take half the time spent travelling. So the answer with the SHORTEST trip
// gives the best anchor and is the one kept.
//
// The monotonic clock has one known fault: on some phones it stands still
// while the device sleeps. It can LOSE time; it never jumps ahead. The guard
// (checkMonotonic) watches for that, with the system clock as its witness.
// The system clock's reading also goes along with each entry for the audit
// trail, and it is the fallback for the two cases nothing else can cover
// (see resolveHeld and resolveEarlierLoad).

import { sampleFrom, contradictsAnchor, isMoreCertainThanAnchor, provesNothingWasLost } from './anchor-samples.js';

export const QUALIDADE_TEMPO = {
    EXATO: 'exato',                // from an anchor of this page load
    ESTIMADO: 'estimado',          // with the help of the system clock
    DESCONHECIDO: 'desconhecido',  // raw system clock; nothing to correct it with
};

/** What estado() answers; the status bar words the official time from it. */
export const ESTADO_RELOGIO = {
    SEM_ANCORA: 'sem_ancora',    // this page load has not heard from the server yet
    OK: 'ok',
    VERIFICANDO: 'verificando',  // the anchor is in doubt until the next answer
};

// A remembered anchor older than this (relative to the entry) is not used.
export const PERSISTED_ANCHOR_MAX_AGE_MS = 12 * 60 * 60 * 1000;

// The system clock may run ahead of the monotonic clock by this much between
// two checks before the guard suspects that the monotonic clock stood still.
export const MONOTONIC_GUARD_TOLERANCE_MS = 2000;

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
        // Goes up each time the guard suspects the monotonic clock stood
        // still. Two monotonic readings can be subtracted without a second
        // thought only inside one segment.
        this.segment = 0;
        // The oldest segment the anchor is good for: from there to now the
        // monotonic clock is known to have run without a gap.
        this.anchorGoodFromSegment = 0;
        // Not null while the anchor is SUSPECT (see checkMonotonic).
        /** @type {?{smallestExcessMs: number}} */
        this.suspicion = null;
        /** The anchor remembered from an earlier page load (see restorePersisted). */
        this.persisted = null;
        this.lastCheck = { monoMs: monotonicNow(), deviceMs: now() };
    }

    hasAnchor() {
        return this.anchor !== null;
    }

    /** Whether a time can be worked out right now: an anchor, and no doubt about it. */
    canStamp() {
        return this.anchor !== null && this.suspicion === null;
    }

    /** @returns {string} one of ESTADO_RELOGIO.* */
    estado() {
        if (this.anchor === null) {
            return ESTADO_RELOGIO.SEM_ANCORA;
        }
        return this.suspicion === null ? ESTADO_RELOGIO.OK : ESTADO_RELOGIO.VERIFICANDO;
    }

    /**
     * The server's time right now, FOR SHOWING: null until the first anchor
     * of this page load. While the anchor is suspect this keeps answering
     * from it (the screen says "verificando"); if the monotonic clock did
     * stand still, the answer is behind the truth by that much, never ahead.
     * @returns {?number}
     */
    serverNowMs() {
        return this.anchor === null ? null : this.serverTimeAt(this.monotonicNow());
    }

    /** The server's time right now, FOR RECORDING AND PROMISING: null also while the anchor is suspect. */
    trustedServerNowMs() {
        return this.canStamp() ? this.serverNowMs() : null;
    }

    serverTimeAt(monoMs) {
        return Math.round(this.anchor.serverMs + (monoMs - this.anchor.monoMs));
    }

    /** Travel time of the answer behind the anchor in use (null when none). */
    roundTripMs() {
        return this.anchor === null ? null : this.anchor.rttMs;
    }

    /**
     * @param {number} sentMonoMs       monotonic time when the request left
     * @param {number} receivedMonoMs   monotonic time when the answer arrived
     * @param {number} serverReceivedMs server time when the request reached it
     * @param {number} [serverSentMs]   server time when the answer left; a
     *        server that writes one time only is taken to answer instantly
     * @returns {boolean} whether this answer became the anchor in use
     */
    addSample(sentMonoMs, receivedMonoMs, serverReceivedMs, serverSentMs = serverReceivedMs) {
        const sample = sampleFrom(sentMonoMs, receivedMonoMs, serverReceivedMs, serverSentMs);
        if (sample === null) {
            return false;
        }
        if (this.anchor === null) {
            return this.takeNewAnchor(sample);
        }
        if (this.suspicion !== null) {
            return this.settleSuspicion(sample);
        }
        if (contradictsAnchor(this.anchor, sample) || isMoreCertainThanAnchor(this.anchor, sample)) {
            this.anchor = sample;
            return true;
        }
        return false;
    }

    /** An anchor that owes nothing to an earlier one: good from the current segment on. */
    takeNewAnchor(sample) {
        this.anchor = sample;
        this.anchorGoodFromSegment = this.segment;
        this.suspicion = null;
        return true;
    }

    /**
     * The first answer after the guard's doubt decides it. The question is
     * "did the monotonic clock lose time?", and the server's clock answers:
     *
     *   - the server's time is what the suspect anchor predicts: nothing was
     *     lost (it was the system clock that jumped ahead). The anchor is
     *     trusted again, for everything since before the doubt;
     *   - it is not, or this answer travelled too slowly to tell: the
     *     monotonic clock is taken to have stood still. This answer becomes
     *     a new anchor, good only from the doubt onwards. Replacing a good
     *     anchor costs a little precision for a while; keeping a stale one
     *     would cost every later pass a constant error.
     */
    settleSuspicion(sample) {
        if (!provesNothingWasLost(this.anchor, sample, this.suspicion.smallestExcessMs)) {
            return this.takeNewAnchor(sample);
        }
        this.suspicion = null;
        if (isMoreCertainThanAnchor(this.anchor, sample)) {
            this.anchor = sample;
            return true;
        }
        return false;
    }

    /**
     * The guard. Called every second, when the page comes back to the front,
     * around every request and before every stamp: compares how far each
     * clock moved since the last call. They normally agree to a few
     * milliseconds. When they do not:
     *
     *   - the system clock moved LESS than the monotonic one, or went back:
     *     the system clock was changed. The monotonic clock cannot run
     *     ahead, so nothing the page times with was touched: do nothing.
     *   - the system clock moved MORE: either the monotonic clock stood
     *     still (a phone that slept) or the system clock was set forward.
     *     From here the two look the same. The anchor becomes SUSPECT: no
     *     time is worked out from it (new records are held) until the next
     *     answer from the server settles it (see settleSuspicion).
     *
     * @returns {boolean} true when a doubt arose just now; the caller should
     *          ask the server at once instead of waiting for the heartbeat
     */
    checkMonotonic() {
        const monoMs = this.monotonicNow();
        const deviceMs = this.now();
        const excessMs = (deviceMs - this.lastCheck.deviceMs) - (monoMs - this.lastCheck.monoMs);
        this.lastCheck = { monoMs, deviceMs };
        if (!(excessMs > MONOTONIC_GUARD_TOLERANCE_MS)) {
            return false;
        }
        // Records made from now on are on the far side of a possible gap.
        this.segment++;
        if (this.anchor !== null) {
            const earlierExcessMs = this.suspicion === null ? Infinity : this.suspicion.smallestExcessMs;
            this.suspicion = { smallestExcessMs: Math.min(excessMs, earlierExcessMs) };
        }
        // With no anchor there is nothing to doubt, and nothing to settle it
        // with later: the first anchor will be good from this segment on.
        return true;
    }

    /**
     * The time fields of a record made RIGHT NOW (the Enter press, the tap).
     * With a trusted anchor the final time is known at once. Without one the
     * record is HELD: `ts_ms` is null, and resolveHeld() fills it in later
     * from the monotonic reading taken here.
     */
    stamp() {
        this.checkMonotonic();
        const monoMs = this.monotonicNow();
        const witness = this.witnessFields(monoMs);
        if (!this.canStamp()) {
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
     * The final time of a record HELD in this page load.
     *
     * @returns {?object} `{ts_ms, time_quality, anchor}`, or null while
     *          there is no anchor, or the one there is is suspect
     */
    resolveHeld(record) {
        if (!this.canStamp()) {
            return null;
        }
        if (record.clock_segment >= this.anchorGoodFromSegment) {
            // No gap in the monotonic clock between the record and the
            // anchor: exact, the record simply happened a known number of
            // milliseconds before (or after) it.
            return this.exactTimeAt(record.mono_ms);
        }
        // The monotonic clock stood still between the record and the
        // anchor, so the two readings cannot be subtracted. The system
        // clock bridges the gap, and the result says so.
        const ageMs = record.ts_device_ms - this.now();
        return {
            ts_ms: this.serverNowMs() + ageMs,
            time_quality: QUALIDADE_TEMPO.ESTIMADO,
            anchor: this.anchorDetails(ageMs),
        };
    }

    /**
     * The final time of a record made in an EARLIER page load that never got
     * an anchor: typed with no network, then the page was reloaded or the
     * phone restarted, still with no network. The monotonic clock of that
     * page load is gone, so only the system clock is left.
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
     *          null while there is no anchor worth remembering
     */
    anchorToPersist() {
        if (!this.canStamp()) {
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
