// The timer behind the upload queue.
//
// Every 5 seconds it asks the queue to talk to the server, even with nothing
// to send. That is both the retry after a failure and what lets the server
// see that this source is alive. (The queue itself decides whether it is
// allowed to speak: see SyncQueue.mustStaySilent.)
//
// After the server has refused this device's token ("revogado") the same
// timer does something else, and much more slowly: it asks for a NEW token.
// The server issues one only to a logged-in user who is allowed on this
// source, so the request succeeds exactly when a controller has linked the
// counter again. The page then heals by itself: no reload, and the entries
// that were waiting go up.

import { CONEXAO } from './sync-queue.js';

export const HEARTBEAT_INTERVAL_MS = 5000;

// How often a revoked device asks whether it has been let back in. Slow on
// purpose: "revoked" is a decision by a person, and it rarely changes.
export const REVOKED_RETRY_INTERVAL_MS = 30000;

export class SyncHeartbeat {
    /**
     * @param {object}   options
     * @param {object}   options.queue  SyncQueue
     * @param {function} options.now    () => device time in ms
     * @param {function} [options.renewToken] async () => true when the server
     *        issued a NEW token (the device-token flow); without it a
     *        revoked device simply stays revoked
     */
    constructor({ queue, now, renewToken = null }) {
        this.queue = queue;
        this.now = now;
        this.renewToken = renewToken;

        this.timer = null;
        this.clearIntervalFn = null;
        this.recoveryInFlight = false;
        this.lastRecoveryAttemptMs = null;
    }

    /** Starts the timer and does the first round at once. */
    start(setIntervalFn = setInterval, clearIntervalFn = clearInterval, intervalMs = HEARTBEAT_INTERVAL_MS) {
        this.stop();
        this.clearIntervalFn = clearIntervalFn;
        this.timer = setIntervalFn(() => this.tick(), intervalMs);
        this.tick();
    }

    stop() {
        if (this.timer !== null) {
            this.clearIntervalFn(this.timer);
            this.timer = null;
        }
    }

    tick() {
        if (this.queue.conexao === CONEXAO.REVOGADO) {
            this.tryToRecoverFromRevocation().catch(() => {
                // Stays revoked; the next slow attempt may do better.
            });
            return;
        }
        this.queue.requestSync();
    }

    /**
     * @param {object}  [options]
     * @param {boolean} [options.force] skip the 30 s pause (a person pressed "try again")
     * @returns {Promise<boolean>} whether uploads are running again
     */
    async tryToRecoverFromRevocation({ force = false } = {}) {
        if (this.queue.conexao !== CONEXAO.REVOGADO || this.renewToken === null || this.recoveryInFlight) {
            return false;
        }
        const nowMs = this.now();
        if (!force && this.isTooSoonToAskAgain(nowMs)) {
            return false;
        }
        this.lastRecoveryAttemptMs = nowMs;
        this.recoveryInFlight = true;
        let newTokenIssued = false;
        try {
            newTokenIssued = (await this.renewToken()) === true;
        } catch (stillRefused) {
            newTokenIssued = false;
        } finally {
            this.recoveryInFlight = false;
        }
        if (!newTokenIssued) {
            return false;
        }
        await this.queue.resumeWithNewToken();
        this.queue.requestSync();
        return true;
    }

    /**
     * The pause runs from the last attempt, or from the refusal itself when
     * it happened while this page was open. A page that OPENS already revoked
     * has neither, and asks at once: the person may have reopened it exactly
     * because they were told they are linked again.
     */
    isTooSoonToAskAgain(nowMs) {
        const pauseStartsMs = [this.lastRecoveryAttemptMs, this.queue.revokedAtMs]
            .filter((timestampMs) => timestampMs !== null);
        // Math.abs: a device clock that jumped backwards must not turn the
        // pause into "never again".
        return pauseStartsMs.some((startMs) => Math.abs(nowMs - startMs) < REVOKED_RETRY_INTERVAL_MS);
    }
}
