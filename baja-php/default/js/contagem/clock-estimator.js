// Estimates how far this device's clock is from the server's clock.
//
// Phones and laptops at a race can be seconds or minutes apart. Every entry is
// sent with the raw device time plus this estimate, and the server adds them.
//
// Midpoint method: we note the device time when a request leaves (t0) and when
// its answer arrives (t1). The server's time in the answer was read somewhere
// between the two; the best single guess is the middle. So:
//
//     offset = server_time - (t0 + t1) / 2
//
// The guess can be wrong by at most half the round trip (t1 - t0), so the
// sample with the SMALLEST round trip is the most trustworthy one and is the
// one we keep.

// Extra tolerance, on top of the two samples' own uncertainty, before deciding
// that the device clock itself was changed (for example by the phone syncing
// its time over the network in the middle of the race).
const CLOCK_JUMP_MARGIN_MS = 500;

// A stored estimate older than this is not reused after a reload. A race
// lasts a few hours; an estimate from yesterday may predate the phone
// correcting its own clock overnight, and nothing on the device can tell.
export const STORED_SAMPLE_MAX_AGE_MS = 12 * 60 * 60 * 1000;

export class ClockEstimator {
    constructor() {
        this.bestSample = null;
    }

    /**
     * @param {number} sentAtDeviceMs     device time when the request left
     * @param {number} receivedAtDeviceMs device time when the answer arrived
     * @param {number} serverNowMs        server time written in the answer
     * @returns {boolean} whether this sample became the estimate in use
     */
    addSample(sentAtDeviceMs, receivedAtDeviceMs, serverNowMs) {
        const roundTripMs = receivedAtDeviceMs - sentAtDeviceMs;
        if (!Number.isFinite(serverNowMs) || !Number.isFinite(roundTripMs) || roundTripMs < 0) {
            // A negative round trip means the device clock moved backwards
            // while we waited; such a sample says nothing useful.
            return false;
        }
        const sample = {
            roundTripMs,
            offsetMs: Math.round(serverNowMs - (sentAtDeviceMs + receivedAtDeviceMs) / 2),
            measuredAtDeviceMs: receivedAtDeviceMs,
        };

        if (this.bestSample === null
            || sample.roundTripMs < this.bestSample.roundTripMs
            || this.contradictsBestSample(sample)) {
            this.bestSample = sample;
            return true;
        }
        return false;
    }

    /**
     * Each sample is right to within half its round trip. If two samples
     * disagree by more than both uncertainties together, they cannot both be
     * describing the same clock: the device clock was adjusted between them,
     * and the older sample, however fast it was, is now simply wrong.
     */
    contradictsBestSample(sample) {
        const disagreementMs = Math.abs(sample.offsetMs - this.bestSample.offsetMs);
        const combinedUncertaintyMs = (sample.roundTripMs + this.bestSample.roundTripMs) / 2;
        return disagreementMs > combinedUncertaintyMs + CLOCK_JUMP_MARGIN_MS;
    }

    /** @returns {?number} null until the first successful exchange with the server */
    offsetMs() {
        return this.bestSample === null ? null : this.bestSample.offsetMs;
    }

    /** Round trip of the sample in use (null before the first one). */
    roundTripMs() {
        return this.bestSample === null ? null : this.bestSample.roundTripMs;
    }

    /** The estimate in use, as a plain object for the local store (null when none). */
    sampleToStore() {
        return this.bestSample === null ? null : { ...this.bestSample };
    }

    /**
     * Puts back the estimate saved before a reload, so that the page does not
     * start from "unknown": the race clock is right at once and new entries
     * carry an offset, even when the page was reopened with no network.
     * From then on it is an ordinary sample: a quicker or a contradicting
     * answer from the server replaces it.
     *
     * @param {?object} storedSample what sampleToStore() returned earlier
     * @param {number}  deviceNowMs
     * @returns {boolean} whether the stored estimate is now in use
     */
    restore(storedSample, deviceNowMs) {
        if (!storedSample
            || !Number.isFinite(storedSample.offsetMs)
            || !Number.isFinite(storedSample.roundTripMs)
            || !Number.isFinite(storedSample.measuredAtDeviceMs)) {
            return false;
        }
        const ageMs = deviceNowMs - storedSample.measuredAtDeviceMs;
        // A negative age means the device clock was set back since the
        // measurement, which is exactly the case the offset cannot survive.
        if (ageMs < 0 || ageMs > STORED_SAMPLE_MAX_AGE_MS) {
            return false;
        }
        this.bestSample = {
            roundTripMs: storedSample.roundTripMs,
            offsetMs: storedSample.offsetMs,
            measuredAtDeviceMs: storedSample.measuredAtDeviceMs,
        };
        return true;
    }
}
