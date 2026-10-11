// What happens when a counter records a pass, with no DOM in sight.
//
// The order inside `recordPass` is the heart of the count page:
//   1. take the timestamp (at Enter or at the tile tap, before anything slow);
//   2. write the entry to the local store and wait for the disk;
//   3. only then hand it back to be shown and uploaded.

import { computeWarnings, teamNameOf } from './warnings.js';

export const RECENT_LIST_SIZE = 10;

export class CountSession {
    /**
     * @param {object}   options
     * @param {object}   options.store          LocalStore
     * @param {object}   options.clock          ClockEstimator
     * @param {function} options.now            () => device time in ms
     * @param {function} options.generateUuid   () => string
     * @param {function} options.getValidCars   () => ?[{numero, nome}]
     * @param {function} options.getJanelaMs    () => ?number
     */
    constructor({ store, clock, now, generateUuid, getValidCars, getJanelaMs }) {
        this.store = store;
        this.clock = clock;
        this.now = now;
        this.generateUuid = generateUuid;
        this.getValidCars = getValidCars;
        this.getJanelaMs = getJanelaMs;
    }

    /**
     * @param {object}  pass
     * @param {number}  pass.car
     * @param {?number} pass.typingMs  null for a tile tap (nothing was typed)
     * @param {string}  pass.mode      one of MODO.*
     * @returns {Promise<{entry: object, avisos: Array, nomeEquipe: ?string}>}
     *          Rejects when the entry could NOT be written to the device; the
     *          caller must then tell the counter it was not recorded.
     */
    async recordPass({ car, typingMs, mode }) {
        const tsDeviceMs = this.now();
        const validCars = this.getValidCars();

        // Warnings look at the entries that existed BEFORE this one.
        const avisos = computeWarnings({
            car,
            tsDeviceMs,
            ownEntries: this.store.allEntries(),
            validCars,
            janelaMs: this.getJanelaMs(),
        });

        const entry = await this.store.addEntry({
            uuid: this.generateUuid(),
            car,
            ts_device_ms: tsDeviceMs,
            // Null until the first answer from the server; the server then
            // applies this device's first later estimate. Never filled in
            // afterwards on the device: the entry is stored as it was made.
            clock_offset_ms: this.clock.offsetMs(),
            typing_ms: typingMs,
            mode,
        });

        return { entry, avisos, nomeEquipe: teamNameOf(car, validCars) };
    }

    /** "Desfazer": marks the entry voided; the queue then uploads the void. */
    undo(uuid) {
        return this.store.markEntryVoided(uuid, this.now());
    }

    /** The newest entries first, voided ones included (shown struck through). */
    recentEntries(limit = RECENT_LIST_SIZE) {
        const validCars = this.getValidCars();
        return this.store.allEntries().slice(-limit).reverse().map((entry) => ({
            ...entry,
            nomeEquipe: teamNameOf(entry.car, validCars),
        }));
    }
}
