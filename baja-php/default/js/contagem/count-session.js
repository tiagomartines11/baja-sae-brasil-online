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
     * @param {object}   options.clock          ServerClock
     * @param {function} options.generateUuid   () => string
     * @param {function} options.getValidCars   () => ?[{numero, nome}] classified cars
     * @param {function} [options.getUnclassifiedCars] () => ?[{numero, nome}]
     * @param {function} options.getJanelaMs    () => ?number
     */
    constructor({ store, clock, generateUuid, getValidCars, getUnclassifiedCars = () => null, getJanelaMs }) {
        this.store = store;
        this.clock = clock;
        this.generateUuid = generateUuid;
        this.getValidCars = getValidCars;
        this.getUnclassifiedCars = getUnclassifiedCars;
        this.getJanelaMs = getJanelaMs;
    }

    /**
     * THE way a pass gets recorded: a key press, a tile tap and the race
     * simulation all end here.
     *
     * @param {object}  pass
     * @param {number}  pass.car
     * @param {?number} pass.typingMs  null when nothing was typed (one-tap tile)
     * @param {string}  pass.mode      one of MODO.* ('simulacao' for a simulated pass)
     * @param {?number} [pass.tsMs]    the time of the pass, in SERVER time, when
     *        it is not "right now" (the simulation). Leave it out for a real pass.
     * @param {?string} [pass.uuid]    the simulation names its records, so
     *        that a script run twice records each pass once: a pass whose
     *        uuid this device already holds is NOT recorded again, and the
     *        entry it already has is returned (with no warnings). Leave it
     *        out for a real pass.
     * @returns {Promise<{entry: object, avisos: Array, nomeEquipe: ?string}>}
     *          Rejects when the entry could NOT be written to the device; the
     *          caller must then tell the counter it was not recorded.
     */
    async recordPass({ car, typingMs, mode, tsMs = null, uuid = null }) {
        // Before anything slow: this is the instant that counts.
        const time = Number.isFinite(tsMs) ? this.clock.stampAt(tsMs) : this.clock.stamp();
        const validCars = this.getValidCars();
        const unclassifiedCars = this.getUnclassifiedCars();

        const alreadyRecorded = uuid === null ? undefined : this.store.findEntry(uuid);
        if (alreadyRecorded) {
            return { entry: alreadyRecorded, avisos: [], nomeEquipe: teamNameOf(alreadyRecorded.car, validCars, unclassifiedCars) };
        }

        // Warnings look at the entries that existed BEFORE this one.
        const avisos = computeWarnings({
            car,
            time,
            ownEntries: this.store.allEntries(),
            validCars,
            unclassifiedCars,
            janelaMs: this.getJanelaMs(),
        });

        // `time.ts_ms` is the final time and is never changed afterwards. It
        // is null only when this page has no anchor yet; the entry is then
        // held and gets its time once (see held-records.js).
        const entry = await this.store.addEntry({
            uuid: uuid === null ? this.generateUuid() : uuid,
            car,
            typing_ms: typingMs,
            mode,
            ...time,
        });

        return { entry, avisos, nomeEquipe: teamNameOf(car, validCars, unclassifiedCars) };
    }

    /** "Desfazer": marks the entry voided; the queue then uploads the void. */
    undo(uuid) {
        return this.store.markEntryVoided(uuid, this.clock.now());
    }

    /** The newest entries first, voided ones included (shown struck through). */
    recentEntries(limit = RECENT_LIST_SIZE) {
        const validCars = this.getValidCars();
        const unclassifiedCars = this.getUnclassifiedCars();
        return this.store.allEntries().slice(-limit).reverse().map((entry) => ({
            ...entry,
            nomeEquipe: teamNameOf(entry.car, validCars, unclassifiedCars),
        }));
    }
}
