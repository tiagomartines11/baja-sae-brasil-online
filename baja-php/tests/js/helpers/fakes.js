// Stand-ins used by the tests in this folder. Nothing here is a test itself.
//
// The real page has three things we cannot have inside `node --test`: a wall
// clock, a disk (IndexedDB) and a server. Each gets a small fake that the test
// fully controls, so a test can say "the network is down now" or "ten seconds
// pass" and see exactly what the code does.

import { createMemoryStorage } from '../../../default/js/contagem/storage-memory.js';
import { LocalStore } from '../../../default/js/contagem/local-store.js';
import { ClockEstimator } from '../../../default/js/contagem/clock-estimator.js';
import { SyncQueue } from '../../../default/js/contagem/sync-queue.js';
import { CountSession } from '../../../default/js/contagem/count-session.js';

/** A clock that only moves when the test says so. */
export class FakeClock {
    constructor(startMs = 1_760_000_000_000) {
        this.currentMs = startMs;
        // Bound so it can be handed around as a plain function.
        this.now = () => this.currentMs;
    }

    advance(milliseconds) {
        this.currentMs += milliseconds;
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
 * A pretend contagem_sync.php. It follows the contract: it stores each uuid at
 * most once and answers with the uuids it now holds.
 *
 * `behaviour` is what the test changes to simulate trouble:
 *   'ok'            everything works
 *   'offline'       the request never reaches the server
 *   'answer_lost'   the server stores the data, but the answer never arrives
 *   'server_error'  the server answers HTTP 500
 *   'garbage'       the server answers 200 with something that is not the contract
 *   'revoked'       the token was revoked: HTTP 401
 */
export class FakeServer {
    constructor(deviceClock, { serverAheadMs = 0 } = {}) {
        this.deviceClock = deviceClock;
        // How far the server's clock is ahead of the device's clock.
        this.serverAheadMs = serverAheadMs;
        this.behaviour = 'ok';
        // Network delay of each leg of the trip, in ms of device clock.
        this.uploadDelayMs = 0;
        this.downloadDelayMs = 0;
        // When set, only the first N new entries of a request are stored.
        this.storeAtMostPerRequest = null;

        this.storedEntries = new Map();
        this.storedPitEvents = new Map();
        this.voidedUuids = new Set();
        // How many times each uuid was INSERTED. Must never exceed 1.
        this.insertCount = new Map();
        // Every request body that reached the server, in order.
        this.receivedRequests = [];

        this.race = { largada_ms: null, encerramento_ms: null, estado: 'aguardando' };
        this.validCars = [{ numero: 7, nome: 'Equipe Sete' }, { numero: 41, nome: 'Equipe Quarenta e Um' }];
        this.janelaMs = 20000;
        this.outrosUsuarios = [];
        this.carsInPit = [];

        this.send = (requestBody) => this.handle(requestBody);
    }

    async handle(requestBody) {
        if (this.behaviour === 'offline') {
            throw new Error('simulated: no network');
        }
        this.deviceClock.advance(this.uploadDelayMs);
        // A copy, like a real server that parsed the JSON it received.
        const request = JSON.parse(JSON.stringify(requestBody));

        if (this.behaviour === 'revoked') {
            this.deviceClock.advance(this.downloadDelayMs);
            return { status: 401, body: { erro: 'token_invalido' } };
        }
        if (this.behaviour === 'server_error') {
            return { status: 500, body: null };
        }
        if (this.behaviour === 'garbage') {
            return { status: 200, body: { hello: 'this is not the contract' } };
        }

        this.receivedRequests.push(request);
        const storedUuids = [];
        let newlyStored = 0;
        for (const entry of request.entries) {
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
            if (!this.storedPitEvents.has(pitEvent.uuid)) {
                this.storedPitEvents.set(pitEvent.uuid, pitEvent);
                this.insertCount.set(pitEvent.uuid, (this.insertCount.get(pitEvent.uuid) || 0) + 1);
            }
            storedUuids.push(pitEvent.uuid);
        }
        const voidedUuids = [];
        for (const voidRequest of request.voids) {
            if (this.storedEntries.has(voidRequest.uuid)) {
                this.voidedUuids.add(voidRequest.uuid);
                voidedUuids.push(voidRequest.uuid);
            }
        }
        const serverNowMs = this.deviceClock.now() + this.serverAheadMs;
        this.deviceClock.advance(this.downloadDelayMs);

        if (this.behaviour === 'answer_lost') {
            throw new Error('simulated: connection dropped before the answer arrived');
        }
        return {
            status: 200,
            body: {
                stored_uuids: storedUuids,
                voided_uuids: voidedUuids,
                server_now_ms: serverNowMs,
                race: this.race,
                fonte: { id: 3, nome: 'Contagem 1', tipo: 'manual' },
                valid_cars: this.validCars,
                janela_ms: this.janelaMs,
                outros_usuarios: this.outrosUsuarios,
                cars_in_pit: this.carsInPit,
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
 * One counter's phone: a disk, a clock, a store, a queue and a count session,
 * wired the way page-setup.js wires them in the browser.
 *
 * `reload()` simulates closing and reopening the page: everything in memory is
 * thrown away and rebuilt from the same disk.
 */
export async function createDevice({
    server = null, clock = new FakeClock(), disk = createMemoryStorage(), maxBatch, uuidPrefix = 'uuid',
} = {}) {
    const fakeServer = server || new FakeServer(clock);
    const device = { clock, disk, server: fakeServer, stateChanges: [] };

    async function boot() {
        device.store = await LocalStore.open(disk);
        device.clockEstimator = new ClockEstimator();
        device.queue = new SyncQueue({
            store: device.store,
            send: (body) => fakeServer.send(body),
            clock: device.clockEstimator,
            now: clock.now,
            onChange: (state) => device.stateChanges.push(state),
            ...(maxBatch ? { maxBatch } : {}),
        });
        device.session = new CountSession({
            store: device.store,
            clock: device.clockEstimator,
            now: clock.now,
            generateUuid: device.generateUuid,
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
    device.reload = boot;
    /** The counter types a car number and presses Enter. */
    device.type = (car, mode = 'teclado') => device.session.recordPass({ car, typingMs: 500, mode });
    await boot();
    return device;
}
