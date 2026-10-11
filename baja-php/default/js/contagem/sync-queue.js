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
};

export const HEARTBEAT_INTERVAL_MS = 5000;

// Upper bound per request, so that a device coming back after an hour offline
// sends several moderate requests instead of one enormous one.
const DEFAULT_MAX_BATCH = 200;

// A flush keeps sending while each request confirms something. This bound only
// protects against an endless loop if a server misbehaves.
const MAX_REQUESTS_PER_FLUSH = 50;

const META_REVOKED = 'token_revogado';
const META_LAST_SYNC = 'ultimo_sync_ms';
const META_SERVER_INFO = 'servidor';

export class SyncQueue {
    /**
     * @param {object}   options
     * @param {object}   options.store  LocalStore
     * @param {function} options.send   async (requestBody) => {status, body}; throws when the network fails
     * @param {object}   options.clock  ClockEstimator
     * @param {function} options.now    () => device time in ms
     * @param {function} [options.onChange] called with the new state after anything changes
     * @param {number}   [options.maxBatch]
     */
    constructor({ store, send, clock, now, onChange = null, maxBatch = DEFAULT_MAX_BATCH }) {
        this.store = store;
        this.send = send;
        this.clock = clock;
        this.now = now;
        this.onChange = onChange;
        this.maxBatch = maxBatch;

        this.requestInFlight = false;
        this.flushPromise = null;
        this.flushRequestedMeanwhile = false;
        this.heartbeatTimer = null;

        // A revoked token stays revoked across reloads: the mark is on disk.
        this.conexao = store.getMeta(META_REVOKED, false) ? CONEXAO.REVOGADO : CONEXAO.CONECTANDO;
        this.outrosUsuarios = [];
    }

    /** Everything the status bar needs, in one object. */
    getState() {
        return {
            conexao: this.conexao,
            pendentes: this.store.pendingCount(),
            ultimoSyncMs: this.store.getMeta(META_LAST_SYNC, null),
            servidor: this.store.getMeta(META_SERVER_INFO, null),
            outrosUsuarios: this.outrosUsuarios,
        };
    }

    notifyChange() {
        if (this.onChange) {
            this.onChange(this.getState());
        }
    }

    /** The request body, exactly as the contract of contagem_sync.php describes it. */
    buildRequest() {
        const offsetMs = this.clock.offsetMs();
        const entries = this.store.unsentEntries().slice(0, this.maxBatch).map((record) => ({
            uuid: record.uuid,
            car: record.car,
            ts_device_ms: record.ts_device_ms,
            clock_offset_ms: record.clock_offset_ms,
            typing_ms: record.typing_ms,
            mode: record.mode,
        }));
        const voids = this.store.voidsReadyToSend().slice(0, this.maxBatch).map((record) => ({ uuid: record.uuid }));
        const pitEvents = this.store.unsentPitEvents().slice(0, this.maxBatch).map((record) => ({
            uuid: record.uuid,
            car: record.car,
            tipo: record.tipo,
            ts_device_ms: record.ts_device_ms,
            clock_offset_ms: record.clock_offset_ms,
        }));

        return {
            entries,
            voids,
            pit_events: pitEvents,
            device_now_ms: this.now(),
            clock_offset_ms: offsetMs,
            // "queue_empty" tells the server "you have everything I recorded
            // up to device_now_ms", and the server then trusts this source up
            // to that moment. We only say it when it is certainly true:
            // nothing at all is waiting, and we know our clock offset (without
            // it the server could not place device_now_ms on its own clock).
            queue_empty: this.store.pendingCount() === 0 && offsetMs !== null,
        };
    }

    /**
     * One request, one answer.
     * @returns {Promise<{resultado: string, confirmados: number}>}
     */
    async syncOnce() {
        if (this.conexao === CONEXAO.REVOGADO) {
            return { resultado: RESULTADO.REVOGADO, confirmados: 0 };
        }
        if (this.requestInFlight) {
            // Two overlapping requests would be harmless for the data (uuids),
            // but would waste a weak connection.
            return { resultado: RESULTADO.OCUPADO, confirmados: 0 };
        }

        this.requestInFlight = true;
        try {
            const request = this.buildRequest();
            let response;
            try {
                response = await this.send(request);
            } catch (networkError) {
                return this.registerFailure();
            }
            const receivedAtMs = this.now();

            if (response.status === 401) {
                return await this.registerRevoked();
            }
            const body = response.body;
            if (response.status !== 200 || !body || !Array.isArray(body.stored_uuids)) {
                return this.registerFailure();
            }
            const confirmados = await this.applyAnswer(request, body, receivedAtMs);
            return { resultado: RESULTADO.OK, confirmados };
        } finally {
            this.requestInFlight = false;
            this.notifyChange();
        }
    }

    registerFailure() {
        this.conexao = CONEXAO.OFFLINE;
        return { resultado: RESULTADO.FALHA, confirmados: 0 };
    }

    /** 401: stop for good. Nothing local is touched except the mark itself. */
    async registerRevoked() {
        this.conexao = CONEXAO.REVOGADO;
        this.stopHeartbeat();
        await this.store.setMeta(META_REVOKED, true);
        return { resultado: RESULTADO.REVOGADO, confirmados: 0 };
    }

    async applyAnswer(request, body, receivedAtMs) {
        this.clock.addSample(request.device_now_ms, receivedAtMs, body.server_now_ms);

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
     * Heartbeat: one flush every 5 s even with nothing to send. It is both the
     * retry after a failure and what lets the server see this source is alive.
     */
    startHeartbeat(setIntervalFn = setInterval, clearIntervalFn = clearInterval, intervalMs = HEARTBEAT_INTERVAL_MS) {
        this.stopHeartbeat();
        if (this.conexao === CONEXAO.REVOGADO) {
            return;
        }
        this.clearIntervalFn = clearIntervalFn;
        this.heartbeatTimer = setIntervalFn(() => this.requestSync(), intervalMs);
        this.requestSync();
    }

    stopHeartbeat() {
        if (this.heartbeatTimer !== null) {
            this.clearIntervalFn(this.heartbeatTimer);
            this.heartbeatTimer = null;
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
        this.notifyChange();
    }
}
