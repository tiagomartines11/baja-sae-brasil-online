// Uploads the local log to contagem_sync.php and keeps the status bar honest.
//
// How duplicates are made impossible: the queue never invents anything. It
// only re-reads the local store, and sends whatever is not marked as sent,
// always under the uuid the entry was born with. The server stores each uuid
// once. An entry is marked sent only when the server NAMES its uuid in the
// answer; a lost answer, a timeout or an error leaves it unmarked and it simply
// goes again on the next attempt.
//
// Dependencies arrive as arguments (store, send, clock) so the whole thing
// runs under `node --test` with a fake server. What is done with a good
// answer is in sync-answer.js.

import { buildSyncRequest, nothingIsWaiting } from './sync-request.js';
import { applySyncAnswer, META_LAST_SYNC, META_SERVER_INFO, META_ANCHOR } from './sync-answer.js';
import { resolveHeldRecords, resolveRecordsOfEarlierLoads } from './held-records.js';
import { refusedRecords, retryUnconfirmed, UnacknowledgedTracker } from './refused-records.js';

export const CONEXAO = {
    CONECTANDO: 'conectando',
    ONLINE: 'online',
    OFFLINE: 'offline',
    REVOGADO: 'revogado',
};

export const RESULTADO = {
    OK: 'ok',
    FALHA: 'falha',
    REVOGADO: 'revogado',
    OCUPADO: 'ocupado',
    // Nothing was sent because this page has nothing it is allowed to say
    // (shift ended, or memory-only storage with nothing waiting).
    SILENCIO: 'silencio',
};

// The one answer that means "this token is no longer accepted".
const ERRO_TOKEN_INVALIDO = 'token_invalido';

// Upper bound per request, so that a device coming back after an hour offline
// sends several moderate requests instead of one enormous one.
const DEFAULT_MAX_BATCH = 200;

// A flush keeps sending while each request confirms something. This bound only
// protects against an endless loop if a server misbehaves.
const MAX_REQUESTS_PER_FLUSH = 50;

const META_REVOKED = 'token_revogado';
const META_SHIFT_ENDED = 'turno_encerrado';

function nothingDone(resultado) {
    return { resultado, confirmados: 0, carimbados: 0, encerrado: false };
}

export class SyncQueue {
    /**
     * @param {object}   options
     * @param {object}   options.store  LocalStore
     * @param {function} options.send   async (requestBody) => {status, body, sentMonoMs?, receivedMonoMs?};
     *        throws when the network fails
     * @param {object}   options.clock  ServerClock
     * @param {function} [options.onChange] called with the new state after anything changes
     * @param {number}   [options.maxBatch]
     * @param {boolean}  [options.vouchesForHistory] false when the store is memory only
     */
    constructor({
        store, send, clock, onChange = null, maxBatch = DEFAULT_MAX_BATCH, vouchesForHistory = true,
    }) {
        this.store = store;
        this.send = send;
        this.clock = clock;
        this.onChange = onChange;
        this.maxBatch = maxBatch;
        this.vouchesForHistory = vouchesForHistory;

        this.requestInFlight = false;
        this.flushPromise = null;
        this.flushRequestedMeanwhile = false;

        // A revoked token stays revoked across reloads: the mark is on disk.
        this.conexao = store.getMeta(META_REVOKED, false) ? CONEXAO.REVOGADO : CONEXAO.CONECTANDO;
        // Monotonic time at which the server refused the token during THIS
        // page load (null when the mark was already on disk).
        this.revokedAtMs = null;
        // So does an ended shift: reopening the page must not quietly tell
        // the server "I am counting again" before the person says so.
        this.turnoEncerrado = store.getMeta(META_SHIFT_ENDED, false) === true;
        this.outrosUsuarios = [];
        this.unacknowledged = new UnacknowledgedTracker();

        // The anchor remembered from an earlier page load. It is used for one
        // thing only: see prepare().
        clock.restorePersisted(store.getMeta(META_ANCHOR, null));
        this.prepared = null;
    }

    /**
     * Must finish before the first request (syncOnce waits for it). Records
     * that an EARLIER page load left without a time get one now, estimated
     * from the system clock and marked as such (brief 6.4).
     */
    prepare() {
        if (this.prepared === null) {
            this.prepared = resolveRecordsOfEarlierLoads(this.store, this.clock);
        }
        return this.prepared;
    }

    /** Everything the status bar needs, in one object. */
    getState() {
        return {
            conexao: this.conexao,
            pendentes: this.store.pendingCount(),
            // Typed before this page knew the server's time; they go up
            // right after the next answer (already counted in `pendentes`).
            aguardandoHorario: this.store.heldCount(),
            recusados: refusedRecords(this.store).map((refused) => refused.motivo),
            ultimoSyncMs: this.store.getMeta(META_LAST_SYNC, null),
            servidor: this.store.getMeta(META_SERVER_INFO, null),
            outrosUsuarios: this.outrosUsuarios,
            turnoEncerrado: this.turnoEncerrado,
        };
    }

    notifyChange() {
        if (this.onChange) {
            this.onChange(this.getState());
        }
    }

    buildRequest({ encerrando = false } = {}) {
        return buildSyncRequest({
            store: this.store,
            clock: this.clock,
            maxBatch: this.maxBatch,
            vouchesForHistory: this.vouchesForHistory,
            encerrando,
        });
    }

    /**
     * Whether this page should stay quiet. An empty request is itself a
     * statement ("I am here, counting, and this is all I have"), so it is
     * not sent after the shift ended, nor from a memory-only page, which
     * cannot know what this device recorded before. Data is never held back:
     * as soon as something is waiting, it goes.
     */
    mustStaySilent() {
        return (this.turnoEncerrado || !this.vouchesForHistory) && nothingIsWaiting(this.store);
    }

    /**
     * One request, one answer.
     * @param {object}  [options]
     * @param {boolean} [options.encerrando] ask the server to stop waiting for
     *        this device (only honoured when nothing is left to upload)
     * @returns {Promise<{resultado: string, confirmados: number, carimbados: number, encerrado: boolean}>}
     *          `carimbados`: held records that got their time from this answer.
     *          `encerrado`: the SERVER said it closed this device (brief 2.3).
     */
    async syncOnce({ encerrando = false } = {}) {
        if (this.conexao === CONEXAO.REVOGADO) {
            return nothingDone(RESULTADO.REVOGADO);
        }
        if (!encerrando && this.mustStaySilent()) {
            return nothingDone(RESULTADO.SILENCIO);
        }
        if (this.requestInFlight) {
            // Two overlapping requests would be harmless for the data (uuids),
            // but would waste a weak connection.
            return nothingDone(RESULTADO.OCUPADO);
        }

        this.requestInFlight = true;
        try {
            await this.prepare();
            // A record typed while the previous request was in the air may
            // have missed that answer's anchor by an instant.
            await resolveHeldRecords(this.store, this.clock);
            if (this.turnoEncerrado && !encerrando) {
                // Something to upload appeared after the shift ended. Data
                // is never held back: it goes, and the shift is open again.
                await this.setShiftEnded(false);
            }
            const request = this.buildRequest({ encerrando });
            let response;
            const segmentAtSend = this.clock.segment;
            // Fallback timing for a `send` that does not measure its own.
            const calledSendAtMs = this.clock.monotonicNow();
            try {
                response = await this.send(request);
            } catch (networkError) {
                return this.registerFailure();
            }
            const answerSeenAtMs = this.clock.monotonicNow();
            // A phone that slept while the request was in the air: the two
            // monotonic readings around it then say nothing about the trip.
            this.clock.checkMonotonic();
            // The real transport reports the monotonic instants right around
            // the fetch itself. Between "calledSendAtMs" and the fetch it may
            // have spent seconds fetching a device token, and counting those
            // as travel time would spoil the anchor.
            const timing = {
                sentMonoMs: Number.isFinite(response.sentMonoMs) ? response.sentMonoMs : calledSendAtMs,
                receivedMonoMs: Number.isFinite(response.receivedMonoMs) ? response.receivedMonoMs : answerSeenAtMs,
                usable: this.clock.segment === segmentAtSend,
            };

            const body = response.body;
            if (response.status === 401 && body && body.erro === ERRO_TOKEN_INVALIDO) {
                return await this.registerRevoked();
            }
            // Every other answer that is not a proper 200, INCLUDING any
            // other 401 (a proxy or a captive Wi-Fi portal can produce one),
            // is trouble on the way, not a decision about this device.
            if (response.status !== 200 || !body || !Array.isArray(body.stored_uuids)) {
                return this.registerFailure();
            }
            const applied = await applySyncAnswer({
                store: this.store, clock: this.clock, tracker: this.unacknowledged, request, body, timing,
            });
            this.outrosUsuarios = Array.isArray(body.outros_usuarios) ? body.outros_usuarios : [];
            this.conexao = CONEXAO.ONLINE;
            // Only the server's own word counts: a request that carried
            // "encerrando" and got a 200 proves nothing by itself.
            return { resultado: RESULTADO.OK, ...applied, encerrado: body.encerrado === true };
        } finally {
            this.requestInFlight = false;
            this.notifyChange();
        }
    }

    registerFailure() {
        this.conexao = CONEXAO.OFFLINE;
        return nothingDone(RESULTADO.FALHA);
    }

    /**
     * The server said this token is no longer accepted: uploads stop. Nothing
     * local is touched except the mark itself. From now on the heartbeat
     * only asks, slowly, whether the device has been let back in (see
     * sync-heartbeat.js).
     */
    async registerRevoked() {
        this.conexao = CONEXAO.REVOGADO;
        this.revokedAtMs = this.clock.monotonicNow();
        await this.store.setMeta(META_REVOKED, true);
        return nothingDone(RESULTADO.REVOGADO);
    }

    /**
     * Sends until nothing more gets confirmed. After the last entries are
     * confirmed one more request goes out; that one carries queue_empty.
     * Safe to call at any time and from anywhere: calls made while a flush is
     * running are folded into it.
     */
    flush() {
        if (this.flushPromise !== null) {
            // The running flush does one more pass when it finishes, and the
            // caller waits for that same flush.
            this.flushRequestedMeanwhile = true;
            return this.flushPromise;
        }
        this.flushPromise = this.runFlush().finally(() => {
            this.flushPromise = null;
        });
        return this.flushPromise;
    }

    async runFlush() {
        do {
            this.flushRequestedMeanwhile = false;
            for (let requests = 0; requests < MAX_REQUESTS_PER_FLUSH; requests++) {
                const outcome = await this.syncOnce();
                // Another request is worth it while the last one moved
                // something: confirmed records, or gave held ones their time
                // (they could not be in that request and go up in the next).
                const madeProgress = outcome.confirmados > 0 || outcome.carimbados > 0;
                if (outcome.resultado !== RESULTADO.OK || !madeProgress) {
                    break;
                }
            }
        } while (this.flushRequestedMeanwhile && this.conexao !== CONEXAO.REVOGADO);
    }

    /** Fire-and-forget flush for event handlers (new entry, back online...). */
    requestSync() {
        this.flush().catch(() => {
            // A storage error while marking is not fatal: the entry stays
            // unmarked and is sent again by the next heartbeat.
        });
        this.notifyChange();
    }

    /**
     * "Tentar de novo" for records the safety net gave up on (brief 2.1):
     * they go back in the queue for another round of attempts.
     */
    async retryRefused() {
        if (await retryUnconfirmed(this.store) > 0) {
            this.requestSync();
        }
    }

    /**
     * Called only after the server issued a NEW device token (which needs a
     * logged-in user who is still allowed on this source). The entries that
     * were waiting are then uploaded under the new token.
     */
    async resumeWithNewToken() {
        await this.store.setMeta(META_REVOKED, false);
        this.conexao = CONEXAO.CONECTANDO;
        this.revokedAtMs = null;
        this.notifyChange();
    }

    /**
     * Marks the shift as ended or open again (shift-control.js has the whole
     * procedure). While it is ended, mustStaySilent() keeps every heartbeat
     * from reaching the server. The mark is on disk: it survives a reload.
     */
    async setShiftEnded(ended) {
        await this.store.setMeta(META_SHIFT_ENDED, ended);
        this.turnoEncerrado = ended;
        this.notifyChange();
    }
}
