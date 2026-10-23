// What happens when a counter records a pass, with no DOM in sight.
//
// The order inside `recordPass` is the heart of the count page:
//   1. take the timestamp (at Enter or at the tile tap, before anything slow);
//   2. write the entry to the local store and wait for the disk;
//   3. only then hand it back to be shown and uploaded.

import { computeWarnings, effectiveCar, teamNameOf } from './warnings.js';
import { RecordingBlockedError } from './access.js';

export const RECENT_LIST_SIZE = 10;

// A "??" still without a number stays in the recent list after it is
// older than the last 10, so that "Informar número" is still there when
// the counter has a moment. At most this many of them.
export const UNREAD_KEPT_IN_LIST = 10;

/** A "??" the counter can still give a number to. */
export function waitsForNumber(entry) {
    return entry.car === null && !entry.voided && !entry.refused && !entry.fix_refused && !Number.isInteger(entry.fix_car);
}

export class CountSession {
    /**
     * @param {object}   options
     * @param {object}   options.store          LocalStore
     * @param {object}   options.clock          ServerClock
     * @param {function} options.generateUuid   () => string
     * @param {function} options.getValidCars   () => ?[{numero, nome}] classified cars
     * @param {function} [options.getUnclassifiedCars] () => ?[{numero, nome}]
     * @param {function} options.getJanelaMs    () => ?number
     * @param {function} [options.recordingBlocked] () => ?string: a reason
     *        (access.js BLOQUEIO.*) while this page may record nothing new
     */
    constructor({
        store, clock, generateUuid, getValidCars, getUnclassifiedCars = () => null, getJanelaMs, recordingBlocked = () => null,
    }) {
        this.store = store;
        this.clock = clock;
        this.generateUuid = generateUuid;
        this.getValidCars = getValidCars;
        this.getUnclassifiedCars = getUnclassifiedCars;
        this.getJanelaMs = getJanelaMs;
        this.recordingBlocked = recordingBlocked;
        // The warnings each entry got when it was typed, for the recent
        // list: the big flash only has room for a short line of one of them.
        // Kept for this page load only; nothing else needs them.
        this.warningsByUuid = new Map();
    }

    /**
     * THE way a pass gets recorded: a key press, a tile tap and the race
     * simulation all end here.
     *
     * @param {object}  pass
     * @param {?number} pass.car       null for "??": the number could not be read
     * @param {?number} pass.typingMs  null when nothing was typed (one-tap tile, "??")
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
        // The one gate every way of recording goes through (keys, tiles, the
        // simulation): a person refused the page records nothing.
        const blockedBecause = this.recordingBlocked();
        if (blockedBecause !== null) {
            throw new RecordingBlockedError(blockedBecause);
        }
        // Before anything slow: this is the instant that counts.
        const time = Number.isFinite(tsMs) ? this.clock.stampAt(tsMs) : this.clock.stamp();
        const validCars = this.getValidCars();
        const unclassifiedCars = this.getUnclassifiedCars();

        const alreadyRecorded = uuid === null ? undefined : this.store.findEntry(uuid);
        if (alreadyRecorded) {
            return { entry: alreadyRecorded, avisos: [], nomeEquipe: teamNameOf(effectiveCar(alreadyRecorded), validCars, unclassifiedCars) };
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

        if (avisos.length > 0) {
            this.warningsByUuid.set(entry.uuid, avisos);
        }
        return { entry, avisos, nomeEquipe: teamNameOf(car, validCars, unclassifiedCars) };
    }

    /** "Desfazer": marks the entry voided; the queue then uploads the void. */
    undo(uuid) {
        return this.store.markEntryVoided(uuid, this.clock.now());
    }

    /**
     * "Informar número" on a "??" of this device: the number goes up as a
     * correction (`fixes`), never as a new pass. Takes no time: the time of
     * the pass is the one the "??" got.
     * @returns {Promise<?{entry: object, nomeEquipe: ?string}>} null when
     *          that entry cannot be corrected (not a "??", refused before).
     *          Rejects like recordPass: blocked, or the disk refused it.
     */
    async fixUnread(uuid, car) {
        const blockedBecause = this.recordingBlocked();
        if (blockedBecause !== null) {
            throw new RecordingBlockedError(blockedBecause);
        }
        const entry = await this.store.setEntryFix(uuid, car, this.clock.now());
        if (entry === null) {
            return null;
        }
        return { entry, nomeEquipe: teamNameOf(car, this.getValidCars(), this.getUnclassifiedCars()) };
    }

    /**
     * The newest entries first, voided ones included (shown struck through),
     * then the older "??" still waiting for a number (see UNREAD_KEPT_IN_LIST).
     * `avisos`: the warnings the entry got when typed on this page load
     * (empty after a reload). `carro`: the number shown (null for a "??").
     */
    recentEntries(limit = RECENT_LIST_SIZE) {
        const validCars = this.getValidCars();
        const unclassifiedCars = this.getUnclassifiedCars();
        const all = this.store.allEntries();
        const newest = all.slice(-limit).reverse();
        const olderUnread = all.slice(0, Math.max(0, all.length - limit)).filter(waitsForNumber)
            .slice(-UNREAD_KEPT_IN_LIST).reverse();
        return newest.concat(olderUnread).map((entry) => {
            const carro = effectiveCar(entry);
            return {
                ...entry,
                carro,
                nomeEquipe: carro === null ? null : teamNameOf(carro, validCars, unclassifiedCars),
                avisos: this.warningsByUuid.get(entry.uuid) || [],
            };
        });
    }
}
