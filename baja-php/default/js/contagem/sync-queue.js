// Uploads the local log to contagem_sync.php and keeps the status bar honest.
//
// How duplicates are made impossible: the queue never invents anything. It
// only re-reads the local store, and sends whatever is not marked as sent,
// always under the uuid the entry was born with. The server stores each uuid
// once. An entry is marked sent only when the server NAMES its uuid in the
// answer; a lost answer, a timeout or an error leaves it unmarked and it simply
// goes again on the next attempt.
//
// Dependencies arrive as arguments (store, send, clock, now) so the whole thing
// runs under `node --test` with a fake server.

import { buildSyncRequest, nothingIsWaiting } from './sync-request.js';

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
const META_LAST_SYNC = 'ultimo_sync_ms';
const META_SERVER_INFO = 'servidor';
const META_CLOCK = 'relogio';
const META_SHIFT_ENDED = 'turno_encerrado';

export class SyncQueue {
    /**
     * @param {object}   options
     * @param {object}   options.store  LocalStore
     * @param {function} options.send   async (requestBody) => {status, body}; throws when the network fails
     * @param {object}   options.clock  ClockEstimator
     * @param {function} options.now    () => device time in ms
     * @param {function} [options.onChange] called with the new state after anything changes
     * @param {number}   [options.maxBatch]
     * @param {boolean}  [options.vouchesForHistory] false when the store is memory only
     */
    constructor({
        store, send, clock, now, onChange = null, maxBatch = DEFAULT_MAX_BATCH, vouchesForHistory = true,
    }) {
        this.store = store;
        this.send = send;
        this.clock = clock;
        this.now = now;
        this.onChange = onChange;
        this.maxBatch = maxBatch;
        this.vouchesForHistory = vouchesForHistory;

        this.requestInFlight = false;
        this.flushPromise = null;
        this.flushRequestedMeanwhile = false;

        // A revoked token stays revoked across reloads: the mark is on disk.
        this.conexao = store.getMeta(META_REVOKED, false) ? CONEXAO.REVOGADO : CONEXAO.CONECTANDO;
        // Device time at which the server refused the token during THIS page
        // load (null when the mark was already on disk).
        this.revokedAtMs = null;
        // So does an ended shift: reopening the page must not quietly tell
        // the server "I am counting again" before the person says so.
        this.turnoEncerrado = store.getMeta(META_SHIFT_ENDED, false) === true;
        this.outrosUsuarios = [];

        // The clock estimate saved before a reload (see applyAnswer).
        clock.restore(store.getMeta(META_CLOCK, null), now());
    }

    /** Everything the status bar needs, in one object. */
    getState() {
        return {
            conexao: this.conexao,
            pendentes: this.store.pendingCount(),
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
            now: this.now,
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
     * @returns {Promise<{resultado: string, confirmados: number, encerrado: boolean}>}
     *          `encerrado`: the server answered a request that carried "encerrando"
     */
    async syncOnce({ encerrando = false } = {}) {
        if (this.conexao === CONEXAO.REVOGADO) {
            return { resultado: RESULTADO.REVOGADO, confirmados: 0, encerrado: false };
        }
        if (!encerrando && this.mustStaySilent()) {
            return { resultado: RESULTADO.SILENCIO, confirmados: 0, encerrado: false };
        }
        if (this.requestInFlight) {
            // Two overlapping requests would be harmless for the data (uuids),
            // but would waste a weak connection.
            return { resultado: RESULTADO.OCUPADO, confirmados: 0, encerrado: false };
        }

        this.requestInFlight = true;
        try {
            if (this.turnoEncerrado && !encerrando) {
                // Something to upload appeared after the shift ended. Data
                // is never held back: it goes, and the shift is open again.
                await this.setShiftEnded(false);
            }
            const request = this.buildRequest({ encerrando });
            let response;
            // Fallback timing for a `send` that does not measure its own.
            const calledSendAtMs = this.now();
            try {
                response = await this.send(request);
            } catch (networkError) {
                return this.registerFailure();
            }
            const answerSeenAtMs = this.now();
            // The real transport reports the instants right around the
            // fetch itself. Between "calledSendAtMs" and the fetch it may
            // have spent seconds fetching a device token, and counting those
            // as travel time would spoil the clock estimate.
            const timing = {
                sentAtMs: Number.isFinite(response.sentAtMs) ? response.sentAtMs : calledSendAtMs,
                receivedAtMs: Number.isFinite(response.receivedAtMs) ? response.receivedAtMs : answerSeenAtMs,
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
            const confirmados = await this.applyAnswer(request, body, timing);
            return { resultado: RESULTADO.OK, confirmados, encerrado: request.encerrando === true };
        } finally {
            this.requestInFlight = false;
            this.notifyChange();
        }
    }

    registerFailure() {
        this.conexao = CONEXAO.OFFLINE;
        return { resultado: RESULTADO.FALHA, confirmados: 0, encerrado: false };
    }

    /**
     * The server said this token is no longer accepted: uploads stop. Nothing
     * local is touched except the mark itself. From now on the heartbeat
     * only asks, slowly, whether the device has been let back in (see
     * sync-heartbeat.js).
     */
    async registerRevoked() {
        this.conexao = CONEXAO.REVOGADO;
        this.revokedAtMs = this.now();
        await this.store.setMeta(META_REVOKED, true);
        return { resultado: RESULTADO.REVOGADO, confirmados: 0, encerrado: false };
    }

    async applyAnswer(request, body, { sentAtMs, receivedAtMs }) {
        const becameTheEstimate = this.clock.addSample(sentAtMs, receivedAtMs, body.server_now_ms);

        // Only uuids that were in THIS request and that the server named are
        // marked. Anything the server left out stays in the queue.
        const stored = new Set(body.stored_uuids);
        const voided = new Set(Array.isArray(body.voided_uuids) ? body.voided_uuids : []);
        const storedEntries = request.entries.map((entry) => entry.uuid).filter((uuid) => stored.has(uuid));
        const storedPitEvents = request.pit_events.map((event) => event.uuid).filter((uuid) => stored.has(uuid));
        const confirmedVoids = request.voids.map((item) => item.uuid).filter((uuid) => voided.has(uuid));

        await this.store.markEntriesSent(storedEntries, receivedAtMs);
        await this.store.markPitEventsSent(storedPitEvents, receivedAtMs);
        await this.store.markVoidsSent(confirmedVoids);

        // Kept on disk so that a reload without network still knows the car
        // list, the race start and the warning window.
        await this.store.setMeta(META_SERVER_INFO, {
            race: body.race || null,
            fonte: body.fonte || null,
            valid_cars: Array.isArray(body.valid_cars) ? body.valid_cars : null,
            janela_ms: Number.isFinite(body.janela_ms) ? body.janela_ms : null,
            cars_in_pit: Array.isArray(body.cars_in_pit) ? body.cars_in_pit : [],
        });
        await this.store.setMeta(META_LAST_SYNC, receivedAtMs);
        if (becameTheEstimate) {
            // So that a reload (also one with no network) starts with a
            // known offset instead of "unknown".
            await this.store.setMeta(META_CLOCK, this.clock.sampleToStore());
        }

        this.outrosUsuarios = Array.isArray(body.outros_usuarios) ? body.outros_usuarios : [];
        this.conexao = CONEXAO.ONLINE;
        return storedEntries.length + storedPitEvents.length + confirmedVoids.length;
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
                if (outcome.resultado !== RESULTADO.OK || outcome.confirmados === 0) {
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
