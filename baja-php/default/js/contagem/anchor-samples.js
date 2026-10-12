// Judging one answer of the server against the anchor in use. Pure
// arithmetic, no state: server-clock.js decides what to do with the verdicts.
//
// An ANCHOR (and a SAMPLE, which is a candidate anchor) is
// `{serverMs, monoMs, rttMs}`: "when the monotonic clock said monoMs, the
// server's clock said serverMs, give or take half of rttMs".

// A crystal in a phone may run fast or slow by a few parts in 100 000. An
// anchor therefore gets a little less certain as it ages (0.1 ms per second,
// 360 ms per hour), which lets a fresh, slightly slower answer replace a
// very old, very quick one.
const DRIFT_PER_MS = 0.0001;

// Extra tolerance before deciding that a new answer CONTRADICTS the anchor.
const CONTRADICTION_MARGIN_MS = 500;

/**
 * The midpoint method. The request left at `sentMonoMs` and the answer came
 * back at `receivedMonoMs` (our monotonic clock); the server says the
 * request reached it at `serverReceivedMs` and the answer left at
 * `serverSentMs` (its clock). The middle of the server's two times happened
 * at the middle of ours, give or take half the time spent TRAVELLING. The
 * server's own processing time is not travel: taking it out keeps a slow
 * request from looking like a slow network.
 *
 * @returns {?{serverMs: number, monoMs: number, rttMs: number}} null for
 *          readings that cannot be (an answer before its question, a server
 *          that worked longer than the whole trip took, a missing time)
 */
export function sampleFrom(sentMonoMs, receivedMonoMs, serverReceivedMs, serverSentMs) {
    const processingMs = serverSentMs - serverReceivedMs;
    const rttMs = receivedMonoMs - sentMonoMs - processingMs;
    if (!Number.isFinite(rttMs) || rttMs < 0 || processingMs < 0) {
        return null;
    }
    return {
        serverMs: (serverReceivedMs + serverSentMs) / 2,
        monoMs: (sentMonoMs + receivedMonoMs) / 2,
        rttMs,
    };
}

/** How far the sample's server time is from what the anchor predicts for that moment. */
function disagreementMs(anchor, sample) {
    const predictedServerMs = anchor.serverMs + (sample.monoMs - anchor.monoMs);
    return Math.abs(sample.serverMs - predictedServerMs);
}

function uncertaintyAt(anchor, monoMs) {
    return anchor.rttMs / 2 + Math.abs(monoMs - anchor.monoMs) * DRIFT_PER_MS;
}

/**
 * If the two disagree by more than both uncertainties together, they cannot
 * both be right: the monotonic clock lost time, and the older reading is the
 * one that is stale.
 */
export function contradictsAnchor(anchor, sample) {
    const allowedMs = uncertaintyAt(anchor, sample.monoMs) + sample.rttMs / 2 + CONTRADICTION_MARGIN_MS;
    return disagreementMs(anchor, sample) > allowedMs;
}

export function isMoreCertainThanAnchor(anchor, sample) {
    return sample.rttMs / 2 < uncertaintyAt(anchor, sample.monoMs);
}

/**
 * For an anchor the guard has doubts about (see ServerClock.checkMonotonic):
 * does this sample PROVE that the monotonic clock lost no time?
 *
 * The server's time must be what the anchor predicts, and that must hold
 * even if the sample is as wrong as its travel time allows: it must still be
 * closer to "nothing lost" than to the smallest loss the guard suspected.
 * Otherwise a short stall seen through a slow network would pass for none.
 *
 * @param {number} smallestSuspectedLossMs the smallest amount by which the
 *        system clock ran ahead of the monotonic one, among the guard's doubts
 */
export function provesNothingWasLost(anchor, sample, smallestSuspectedLossMs) {
    const worstCaseDisagreementMs = disagreementMs(anchor, sample)
        + uncertaintyAt(anchor, sample.monoMs) + sample.rttMs / 2;
    return !contradictsAnchor(anchor, sample) && worstCaseDisagreementMs < smallestSuspectedLossMs / 2;
}
