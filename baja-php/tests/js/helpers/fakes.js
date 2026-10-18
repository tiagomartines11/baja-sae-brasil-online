// Stand-ins used by the tests in this folder. Nothing here is a test itself.
//
// The real page has things we cannot have inside `node --test`: two clocks
// (the system clock and the monotonic one), a disk (IndexedDB) and a server.
// Each gets a small fake that the test fully controls, so a test can say "the
// network is down now", "ten seconds pass" or "somebody sets the phone's
// clock back an hour" and see exactly what the code does.

import { createMemoryStorage } from '../../../default/js/contagem/storage-memory.js';
import { LocalStore } from '../../../default/js/contagem/local-store.js';
import { ServerClock } from '../../../default/js/contagem/server-clock.js';
import { SyncQueue } from '../../../default/js/contagem/sync-queue.js';
import { runClockGuard } from '../../../default/js/contagem/clock-guard.js';
import { SyncHeartbeat } from '../../../default/js/contagem/sync-heartbeat.js';
import { createTokenSource } from '../../../default/js/contagem/device-token.js';
import { recordingBlockReason } from '../../../default/js/contagem/access.js';
import { CountSession } from '../../../default/js/contagem/count-session.js';
import { PitSession } from '../../../default/js/contagem/pit-session.js';

/**
 * Time that only moves when the test says so. It holds the three clocks of
 * the story:
 *
 *   serverNow()     the TRUE time, which is also the server's clock;
 *   now()           the phone's system clock (`Date.now()`): true time plus
 *                   an error the test can set and change;
 *   monotonicNow()  the phone's monotonic clock (`performance.now()`):
 *                   counts from the page load, cannot be set, but can be
 *                   made to stand still as it does on a sleeping phone.
 */
export class FakeClock {
    constructor(startMs = 1_760_000_000_000) {
        this.trueMs = startMs;
        this.wallErrorMs = 0;
        // True time the monotonic clock failed to count.
        this.monotonicLostMs = 0;
        // Bound so they can be handed around as plain functions.
        this.now = () => this.trueMs + this.wallErrorMs;
        this.serverNow = () => this.trueMs;
        this.monotonicNow = this.newMonotonicClock();
    }

    /** Time passes normally: all three clocks move together. */
    advance(milliseconds) {
        this.trueMs += milliseconds;
    }

    /** The phone's system clock is wrong by this much from now on (positive = ahead). */
    setWallClockError(milliseconds) {
        this.wallErrorMs = milliseconds;
    }

    /** Somebody (or the network) changes the phone's system clock, right now. */
    stepWallClock(milliseconds) {
        this.wallErrorMs += milliseconds;
    }

    /** The phone sleeps: time passes, but its monotonic clock stands still. */
    sleepWithStalledMonotonic(milliseconds) {
        this.trueMs += milliseconds;
        this.monotonicLostMs += milliseconds;
    }

    /** A page load gets its own monotonic clock, which starts near zero. */
    newMonotonicClock() {
        const pageOpenedAt = this.trueMs - this.monotonicLostMs - 1500;
        return () => this.trueMs - this.monotonicLostMs - pageOpenedAt;
    }
}

/** Uuids that are easy to read in a failing test: "uuid-1", "uuid-2", ... */
export function createUuidSequence(prefix = 'uuid') {
    let counter = 0;
    return () => prefix + '-' + (++counter);
}

/**
 * Wraps a storage adapter so a test can make the "disk" refuse writes, as a
 * full or broken phone storage would.
 */
export function createFlakyStorage(innerStorage) {
    const flaky = {
        failWrites: false,
        persistent: innerStorage.persistent,
        getAll: (collection) => innerStorage.getAll(collection),
        async put(collection, key, record) {
            if (flaky.failWrites) {
                throw new Error('simulated: disk is full');
            }
            return innerStorage.put(collection, key, record);
        },
        async patch(collection, key, changes) {
            if (flaky.failWrites) {
                throw new Error('simulated: disk is full');
            }
            return innerStorage.patch(collection, key, changes);
        },
    };
    return flaky;
}

/**
 * A disk on which a test decides WHEN a write finishes. While `holdWrites`
 * is true, every write of an entry or pit event waits until the test calls
 * `finishNextWrite()` (oldest first) or `finishWrite(n)` (the n-th one still
 * waiting, starting at 0). That is how a test freezes the instant "the
 * counter pressed Enter but the phone's storage has not answered yet".
 */
export function createSlowStorage(innerStorage) {
    const waiting = [];
    const slow = {
        holdWrites: false,
        persistent: innerStorage.persistent,
        getAll: (collection) => innerStorage.getAll(collection),
        put: (collection, key, record) => write(collection, () => innerStorage.put(collection, key, record)),
        patch: (collection, key, changes) => write(collection, () => innerStorage.patch(collection, key, changes)),
        async finishWrite(position) {
            const [doTheWrite] = waiting.splice(position, 1);
            await doTheWrite();
        },
        finishNextWrite() {
            return slow.finishWrite(0);
        },
    };
    function write(collection, doTheWrite) {
        // Only the counter's own records are held back. The page's settings
        // ("meta": last answer of the server, clock estimate) go straight
        // through, so an upload can run to its end during the frozen instant.
        if (!slow.holdWrites || collection === 'meta') {
            return doTheWrite();
        }
        return new Promise((resolve, reject) => {
            waiting.push(() => doTheWrite().then(resolve, reject));
        });
    }
    return slow;
}

/**
 * A pretend `navigator.locks`, shared by every "tab" of one pretend browser.
 * It follows the two rules of the real thing that matter here: a lock has
 * one holder at a time, and it is given up when the holder's tab closes.
 * `closeTab(tab)` is the test closing a tab.
 */
export class FakeBrowserLocks {
    constructor() {
        this.holders = new Map();   // lock name -> tab that holds it
        this.queues = new Map();    // lock name -> [{tab, grant}] waiting in line
    }

    /** The `navigator.locks` object as one particular tab sees it. */
    forTab(tab) {
        return {
            request: (name, optionsOrCallback, maybeCallback) => {
                const callback = maybeCallback || optionsOrCallback;
                const options = maybeCallback ? optionsOrCallback : {};
                if (!this.holders.has(name)) {
                    this.holders.set(name, tab);
                    return Promise.resolve(callback({ name }));
                }
                if (options.ifAvailable) {
                    return Promise.resolve(callback(null));
                }
                return new Promise((resolve) => {
                    const queue = this.queues.get(name) || [];
                    queue.push({ tab, grant: () => resolve(callback({ name })) });
                    this.queues.set(name, queue);
                });
            },
        };
    }

    closeTab(tab) {
        for (const [name, holder] of this.holders) {
            if (holder !== tab) {
                continue;
            }
            this.holders.delete(name);
            const next = (this.queues.get(name) || []).shift();
            if (next) {
                this.holders.set(name, next.tab);
                next.grant();
            }
        }
    }
}

/**
 * A pretend contagem_sync.php. It follows the contract: it stores each uuid at
 * most once and answers with the uuids it now holds.
 *
 * `behaviour` is what the test changes to simulate trouble:
 *   'ok'            everything works
 *   'offline'       the request never reaches the server
 *   'answer_lost'   the server stores the data, but the answer never arrives
 *   'server_error'  the server answers HTTP 500
 *   'garbage'       the server answers 200 with something that is not the contract
 *   'revoked'       the token was revoked: HTTP 401 with {"erro": "token_invalido"}
 *   'no_permission' the token is fine but its user may not record here any
 *                   more: HTTP 403 with {"erro": "sem_permissao"}, nothing stored
 *   'other_401'     HTTP 401 that is NOT the server's verdict on the token
 *                   (what a hotel or track Wi-Fi login portal answers)
 */
export class FakeServer {
    constructor(deviceClock) {
        this.deviceClock = deviceClock;
        this.behaviour = 'ok';
        // Network delay of each leg of the trip, in ms of true time.
        this.uploadDelayMs = 0;
        this.downloadDelayMs = 0;
        // Time the server itself spends on a request, between reading its
        // clock on arrival and reading it again just before answering.
        this.processingDelayMs = 0;
        // False plays an older server, whose answer has `server_now_ms` only.
        this.sendsBothTimes = true;
        // Anything else a test wants in the answer (newer fields of the contract).
        this.extraAnswerFields = {};
        // When set, only the first N new entries of a request are stored.
        this.storeAtMostPerRequest = null;

        this.storedEntries = new Map();
        this.storedPitEvents = new Map();
        this.voidedUuids = new Set();
        // "Informar número": uuid -> the car of the newest accepted correction.
        this.fixes = new Map();
        // Every correction accepted, in order (to see that a resend is harmless).
        this.fixLog = [];
        // False plays a server from before corrections existed: it ignores
        // `fixes` and answers neither `fixed_uuids` nor `fixes_recusados`.
        this.answersFixes = true;
        // False plays a server that names only the corrections it refuses
        // (`fixes_recusados`, the one list the shared contract fixes).
        this.answersFixedUuids = true;
        // How many times each uuid was INSERTED. Must never exceed 1.
        this.insertCount = new Map();
        // Every request body that reached the server, in order.
        this.receivedRequests = [];

        this.race = { largada_ms: null, encerramento_ms: null, estado: 'aguardando' };
        this.validCars = [{ numero: 7, nome: 'Equipe Sete' }, { numero: 41, nome: 'Equipe Quarenta e Um' }];
        this.janelaMs = 20000;
        this.outrosUsuarios = [];
        this.carsInPit = [];
        this.unclassifiedCars = [];
        this.carsInFuel = [];
        this.abastecimento = false;
        this.simulacao = false;
        // uuid -> reason: records this server names in `refused` and never stores.
        this.refusedUuids = new Map();
        // Records this server silently drops: neither stored nor refused
        // (the kind of server bug the client's safety net exists for).
        this.ignoredUuids = new Set();
        // Set to false to play a server that does not close the device
        // although the request asked for it.
        this.closesWhenAsked = true;

        this.send = (requestBody) => this.handle(requestBody);
    }

    async handle(requestBody) {
        if (this.behaviour === 'offline') {
            throw new Error('simulated: no network');
        }
        this.deviceClock.advance(this.uploadDelayMs);
        const serverReceivedMs = this.deviceClock.serverNow();
        // A copy, like a real server that parsed the JSON it received.
        const request = JSON.parse(JSON.stringify(requestBody));

        if (this.behaviour === 'revoked') {
            this.deviceClock.advance(this.downloadDelayMs);
            return { status: 401, body: { erro: 'token_invalido' } };
        }
        if (this.behaviour === 'no_permission') {
            this.deviceClock.advance(this.downloadDelayMs);
            return { status: 403, body: { erro: 'sem_permissao' } };
        }
        if (this.behaviour === 'other_401') {
            return { status: 401, body: null };
        }
        if (this.behaviour === 'server_error') {
            return { status: 500, body: null };
        }
        if (this.behaviour === 'garbage') {
            return { status: 200, body: { hello: 'this is not the contract' } };
        }

        this.receivedRequests.push(request);
        const storedUuids = [];
        const refused = [];
        const isRefused = (record) => {
            if (record.mode === 'simulacao' && !this.simulacao) {
                this.refusedUuids.set(record.uuid, 'simulacao_nao_permitida');
            }
            if (this.refusedUuids.has(record.uuid)) {
                refused.push({ uuid: record.uuid, motivo: this.refusedUuids.get(record.uuid) });
                return true;
            }
            return this.ignoredUuids.has(record.uuid);
        };
        let newlyStored = 0;
        for (const entry of request.entries) {
            if (isRefused(entry)) {
                continue;
            }
            if (!this.storedEntries.has(entry.uuid)) {
                if (this.storeAtMostPerRequest !== null && newlyStored >= this.storeAtMostPerRequest) {
                    continue;
                }
                this.storedEntries.set(entry.uuid, entry);
                this.insertCount.set(entry.uuid, (this.insertCount.get(entry.uuid) || 0) + 1);
                newlyStored++;
            }
            storedUuids.push(entry.uuid);
        }
        for (const pitEvent of request.pit_events) {
            if (isRefused(pitEvent)) {
                continue;
            }
            if (!this.storedPitEvents.has(pitEvent.uuid)) {
                this.storedPitEvents.set(pitEvent.uuid, pitEvent);
                this.insertCount.set(pitEvent.uuid, (this.insertCount.get(pitEvent.uuid) || 0) + 1);
            }
            storedUuids.push(pitEvent.uuid);
        }
        const voidedUuids = [];
        for (const voidRequest of request.voids) {
            if (this.storedEntries.has(voidRequest.uuid) && !this.ignoredUuids.has('void:' + voidRequest.uuid)) {
                this.voidedUuids.add(voidRequest.uuid);
                voidedUuids.push(voidRequest.uuid);
            }
        }
        // The contract: a correction is applied only to an entry of this
        // device stored without a number; any other is answered in
        // `fixes_recusados`. The newest correction of an entry counts.
        const fixedUuids = [];
        const refusedFixes = [];
        for (const fix of this.answersFixes ? (request.fixes || []) : []) {
            const entry = this.storedEntries.get(fix.uuid);
            if (entry && entry.car === null && Number.isInteger(fix.car)) {
                if (this.fixes.get(fix.uuid) !== fix.car) {
                    this.fixes.set(fix.uuid, fix.car);
                    this.fixLog.push({ ...fix });
                }
                fixedUuids.push(fix.uuid);
            } else {
                refusedFixes.push(fix.uuid);
            }
        }
        this.deviceClock.advance(this.processingDelayMs);
        const serverSentMs = this.deviceClock.serverNow();
        this.deviceClock.advance(this.downloadDelayMs);

        if (this.behaviour === 'answer_lost') {
            throw new Error('simulated: connection dropped before the answer arrived');
        }
        return {
            status: 200,
            body: {
                stored_uuids: storedUuids,
                voided_uuids: voidedUuids,
                ...(this.answersFixes && this.answersFixedUuids ? { fixed_uuids: fixedUuids } : {}),
                ...(this.answersFixes ? { fixes_recusados: refusedFixes } : {}),
                server_now_ms: serverReceivedMs,
                ...(this.sendsBothTimes ? { server_received_ms: serverReceivedMs, server_sent_ms: serverSentMs } : {}),
                ...this.extraAnswerFields,
                race: this.race,
                fonte: { id: 3, nome: 'Contagem 1', tipo: 'manual' },
                valid_cars: this.validCars,
                janela_ms: this.janelaMs,
                outros_usuarios: this.outrosUsuarios,
                cars_in_pit: this.carsInPit,
                unclassified_cars: this.unclassifiedCars,
                cars_in_fuel: this.carsInFuel,
                abastecimento: this.abastecimento,
                simulacao: this.simulacao,
                encerrado: request.encerrando === true && this.closesWhenAsked,
                refused,
            },
        };
    }

    /** Car numbers the server holds, in the order they arrived. */
    storedCars() {
        return Array.from(this.storedEntries.values(), (entry) => entry.car);
    }

    /** The highest number of times any single uuid was inserted (1 = no duplicates). */
    worstInsertCount() {
        return Math.max(0, ...this.insertCount.values());
    }
}

/**
 * One counter's phone: a disk, clocks, a store, a queue and a count session,
 * wired the way page-setup.js wires them in the browser.
 *
 * `reload()` simulates closing and reopening the page: everything in memory is
 * thrown away and rebuilt from the same disk, and the monotonic clock starts
 * again, as `performance.now()` does. (`reload` is `boot`: it takes the same
 * optional `{anchored}`.)
 */
export async function createDevice({
    server = null, clock = new FakeClock(), disk = createMemoryStorage(), maxBatch, uuidPrefix = 'uuid',
    vouchesForHistory = true, renewToken = null, requestToken = null, anchored = true, shellRefused = false,
} = {}) {
    const fakeServer = server || new FakeServer(clock);
    const device = { clock, disk, server: fakeServer, stateChanges: [], pageLoads: 0, tokenProblem: null };

    async function boot({ anchored: bootAnchored = false } = {}) {
        device.pageLoads++;
        device.store = await LocalStore.open(disk);
        device.serverClock = new ServerClock({
            now: clock.now,
            monotonicNow: clock.newMonotonicClock(),
            loadId: uuidPrefix + '-load-' + device.pageLoads,
        });
        device.queue = new SyncQueue({
            store: device.store,
            send: (body) => fakeServer.send(body),
            clock: device.serverClock,
            onChange: (state) => device.stateChanges.push(state),
            vouchesForHistory,
            ...(maxBatch ? { maxBatch } : {}),
        });
        // The 5-second timer. Tests that need it call device.heartbeat.start()
        // with a pretend setInterval, or device.heartbeat.tick() directly.
        // With `requestToken` the device gets the REAL token source, wired
        // as page-setup.js wires it: `device.tokenProblem` is what the
        // page's warning banner is drawn from.
        if (requestToken !== null) {
            device.tokenSource = createTokenSource({
                store: device.store,
                requestToken,
                usuario: 'equipe1',
                monotonicNow: device.serverClock.monotonicNow,
                onOutcome: (motivo) => {
                    device.tokenProblem = motivo;
                },
            });
        }
        device.heartbeat = new SyncHeartbeat({
            queue: device.queue,
            now: device.serverClock.monotonicNow,
            renewToken: requestToken !== null ? () => device.tokenSource.renew() : renewToken,
        });
        // As the page does on opening (see SyncQueue.prepare).
        await device.queue.prepare();
        if (bootAnchored) {
            device.pretendFirstAnswerArrived();
        }
        // The same phone can play the pit page: same store, clock and queue.
        // As page-setup.js: may this page record? `device.shellRefused` plays
        // the offline copy served in place of a refused page (access.js).
        const recordingBlocked = () => recordingBlockReason({
            shellRefused: device.shellRefused, queueState: device.queue.getState(),
        });
        device.recordingBlocked = recordingBlocked;
        device.pit = new PitSession({
            store: device.store, clock: device.serverClock, generateUuid: device.generateUuid, recordingBlocked,
        });
        device.session = new CountSession({
            recordingBlocked,
            store: device.store,
            clock: device.serverClock,
            generateUuid: device.generateUuid,
            getUnclassifiedCars: () => {
                const serverInfo = device.store.getMeta('servidor', null);
                return serverInfo ? serverInfo.unclassified_cars : null;
            },
            getValidCars: () => {
                const serverInfo = device.store.getMeta('servidor', null);
                return serverInfo ? serverInfo.valid_cars : null;
            },
            getJanelaMs: () => {
                const serverInfo = device.store.getMeta('servidor', null);
                return serverInfo ? serverInfo.janela_ms : null;
            },
        });
    }

    // Created once, outside boot(): uuids keep counting up across reloads.
    device.generateUuid = createUuidSequence(uuidPrefix);
    device.shellRefused = shellRefused;
    device.reload = boot;
    /**
     * Gives the page its anchor without a request showing up in the fake
     * server's log: "the heartbeat sent when the page opened has already
     * been answered, instantly". Most tests are about something else and
     * start from there. Tests about the time BEFORE the first answer pass
     * `anchored: false` to createDevice(); a `reload()` never has an anchor.
     */
    device.pretendFirstAnswerArrived = () => {
        const monoMs = device.serverClock.monotonicNow();
        device.serverClock.addSample(monoMs, monoMs, clock.serverNow());
    };
    /** The page's once-a-second timer firing once (see page-setup.js). */
    device.runClockGuardTimer = () => runClockGuard(device.serverClock, device.queue);
    /** The counter types a car number and presses Enter. */
    device.type = (car, mode = 'teclado') => device.session.recordPass({ car, typingMs: 500, mode });
    /** The counter presses "??": a car crossed, its number could not be read. */
    device.typeUnread = (mode = 'teclado_tela') => device.session.recordPass({ car: null, typingMs: null, mode });
    await boot({ anchored });
    return device;
}
