// The device's own log: every entry and pit event this browser ever recorded
// for one source, plus a few settings ("meta").
//
// Rules this file enforces:
//  1. Disk first. `addEntry` resolves only after the storage adapter has
//     written the record; only then does the record appear in memory, on the
//     screen and in the upload queue.
//  2. Nothing is ever deleted. An uploaded entry is MARKED sent, an undone one
//     is MARKED voided, a corrected "??" keeps `car: null` and gains the
//     number in `fix_car`. The full history stays available for the CSV export.
//  3. A record gets its sequence number the instant it is handed over, before
//     the disk is even asked. Two cars typed in the same instant therefore
//     keep the order in which they were typed, whichever write finishes first.
//
// The store keeps a copy of everything in memory (a race is at most a few
// thousand entries) so the page can read it synchronously.

function bySequence(first, second) {
    return first.seq - second.seq;
}

/**
 * Adds a record to a list that is kept in sequence order. Disk writes can
 * finish out of order, so the newest arrival is not always the last one.
 */
function insertInSequenceOrder(records, record) {
    let position = records.length;
    while (position > 0 && records[position - 1].seq > record.seq) {
        position--;
    }
    records.splice(position, 0, record);
}

/** Still to be uploaded: not confirmed by the server and not refused by it. */
function isWaiting(record) {
    return !record.sent && !record.refused;
}

/** False for a record held until this device knows the server's time. */
function hasFinalTime(record) {
    return Number.isFinite(record.ts_ms);
}

/** An undo still to be confirmed. (The undo of an entry the server refused has nothing to undo there.) */
function isUnconfirmedVoid(record) {
    return record.voided && !record.void_sent && !record.void_refused && !record.refused;
}

/**
 * A correction of a "??" ("Informar número") still to be confirmed. The
 * fields live on the entry itself, like the undo marks; an entry stored by
 * an earlier version of this page simply has none of them.
 */
function isUnconfirmedFix(record) {
    // An undone "??" has nothing left to correct: its void goes up, not its number.
    return Number.isInteger(record.fix_car) && !record.fix_sent && !record.fix_refused && !record.refused && !record.voided;
}

export class LocalStore {
    /** Use `LocalStore.open(adapter)`; the constructor alone loads nothing. */
    constructor(storageAdapter) {
        this.storage = storageAdapter;
        this.entries = [];
        this.pitEvents = [];
        this.meta = new Map();
        this.nextSequence = 1;
        // Writes of NEW facts (an entry, a pit event, an undo) that were
        // handed to the disk and have not come back yet.
        this.writesInFlight = 0;
        // uuid -> promise of the record, for records being written right now.
        this.addsInFlight = new Map();
    }

    /** Reads everything the adapter holds. This is what happens on a reload. */
    static async open(storageAdapter) {
        const store = new LocalStore(storageAdapter);
        store.entries = (await storageAdapter.getAll('entries')).sort(bySequence);
        store.pitEvents = (await storageAdapter.getAll('pit_events')).sort(bySequence);
        for (const item of await storageAdapter.getAll('meta')) {
            store.meta.set(item.key, item.value);
        }
        for (const record of store.entries.concat(store.pitEvents)) {
            store.nextSequence = Math.max(store.nextSequence, record.seq + 1);
        }
        return store;
    }

    /** False when the browser gave us no real disk (see storage-memory.js). */
    isPersistent() {
        return this.storage.persistent === true;
    }

    /**
     * True while something the counter just did is still on its way to the
     * disk. Such a record is not in any list yet, so "nothing is waiting"
     * would be a wrong conclusion at this moment (see sync-request.js).
     */
    hasWriteInFlight() {
        return this.writesInFlight > 0;
    }

    /** Runs one disk write of a new fact, counted in `writesInFlight` meanwhile. */
    async trackWrite(writeToDisk) {
        this.writesInFlight++;
        try {
            return await writeToDisk();
        } finally {
            this.writesInFlight--;
        }
    }

    // ---- entries (passes typed on the count page) ----

    /**
     * @param {object} entry `{uuid, car, typing_ms, mode}` plus the time
     *        fields ServerClock.stamp() returns. `ts_ms` is null for an entry
     *        HELD until this page has an anchor (see server-clock.js).
     * @returns {Promise<object>} the stored record, with its local marks
     */
    addEntry(entry) {
        return this.addOnce(this.entries, entry.uuid, () => this.writeNewEntry(entry));
    }

    /**
     * One uuid, one record. Adding a uuid this store already holds, or is
     * writing at this very moment, changes nothing and gives back the
     * record that is (or is about to be) there. Real key presses always
     * bring a fresh uuid; the race simulation names its records so that a
     * script run twice does not record everything twice.
     */
    addOnce(records, uuid, writeNewRecord) {
        const existing = records.find((record) => record.uuid === uuid);
        if (existing) {
            return Promise.resolve(existing);
        }
        if (!this.addsInFlight.has(uuid)) {
            const write = writeNewRecord().finally(() => this.addsInFlight.delete(uuid));
            this.addsInFlight.set(uuid, write);
        }
        return this.addsInFlight.get(uuid);
    }

    async writeNewEntry(entry) {
        const record = {
            ...entry,
            // Taken (and used up) right here, before the first `await`. A
            // number whose write then fails is simply skipped: gaps are
            // harmless, two entries sharing one number are not.
            seq: this.nextSequence++,
            sent: false,
            sent_at_ms: null,
            voided: false,
            voided_at_ms: null,
            void_sent: false,
            // Set when the server will never store this entry (see refused-records.js).
            refused: false,
            refused_reason: null,
        };
        await this.trackWrite(() => this.storage.put('entries', record.uuid, record));
        // Memory is updated only after the disk write succeeded: an entry the
        // counter can see is an entry that survives a reload.
        insertInSequenceOrder(this.entries, record);
        return record;
    }

    async markEntriesSent(uuids, sentAtMs) {
        for (const uuid of uuids) {
            const record = this.findEntry(uuid);
            if (!record || record.sent) {
                continue;
            }
            const changes = { sent: true, sent_at_ms: sentAtMs };
            await this.storage.patch('entries', uuid, changes);
            Object.assign(record, changes);
        }
    }

    /** "Desfazer": the raw entry stays; it only gains a void mark. */
    async markEntryVoided(uuid, voidedAtMs) {
        const record = this.findEntry(uuid);
        if (!record || record.voided) {
            return record || null;
        }
        const changes = { voided: true, voided_at_ms: voidedAtMs };
        await this.trackWrite(() => this.storage.patch('entries', uuid, changes));
        Object.assign(record, changes);
        return record;
    }

    async markVoidsSent(uuids) {
        for (const uuid of uuids) {
            const record = this.findEntry(uuid);
            if (!record || !record.voided || record.void_sent) {
                continue;
            }
            await this.storage.patch('entries', uuid, { void_sent: true });
            record.void_sent = true;
        }
    }

    /**
     * "Informar número" on a "??" of this device. Only an entry recorded
     * without a number can be corrected, and only while the server has not
     * refused a correction of it. A second correction replaces the first
     * (the newest counts, on the server too) and goes up again.
     * @returns {Promise<?object>} the record, or null when it cannot be corrected
     */
    async setEntryFix(uuid, car, fixedAtMs) {
        const record = this.findEntry(uuid);
        if (!record || record.car !== null || record.voided || record.refused || record.fix_refused || !Number.isInteger(car)) {
            return null;
        }
        if (record.fix_car === car) {
            // The same number again (a double tap, a replayed simulation): nothing new.
            return record;
        }
        const changes = { fix_car: car, fix_at_ms: fixedAtMs, fix_sent: false };
        await this.trackWrite(() => this.storage.patch('entries', uuid, changes));
        Object.assign(record, changes);
        return record;
    }

    /**
     * @param {Array<{uuid: string, car: number}>} fixes the corrections the
     *        server confirmed, with the number each request carried. One the
     *        counter changed again meanwhile stays in the queue: the server
     *        has the older number, not the newest.
     */
    async markFixesSent(fixes) {
        for (const { uuid, car } of fixes) {
            const record = this.findEntry(uuid);
            if (!record || record.fix_car !== car || record.fix_sent) {
                continue;
            }
            await this.storage.patch('entries', uuid, { fix_sent: true });
            record.fix_sent = true;
        }
    }

    findEntry(uuid) {
        return this.entries.find((record) => record.uuid === uuid);
    }

    /** Every entry ever recorded here, oldest first, including voided ones. */
    allEntries() {
        return this.entries.slice();
    }

    /** Entries the server still has to receive: held ones included, refused ones not. */
    unsentEntries() {
        return this.entries.filter(isWaiting);
    }

    /** The unsent entries that may go up now: the ones whose final time is known. */
    entriesReadyToSend() {
        return this.entries.filter((record) => isWaiting(record) && hasFinalTime(record));
    }

    /**
     * Voids the server has not confirmed yet. A void is only offered for
     * upload once its entry is known to be on the server, so the server never
     * receives a void for an entry it has not seen.
     */
    voidsReadyToSend() {
        return this.entries.filter((record) => record.sent && isUnconfirmedVoid(record));
    }

    /** Corrections not confirmed yet; like a void, only once the entry is on the server. */
    fixesReadyToSend() {
        return this.entries.filter((record) => record.sent && isUnconfirmedFix(record));
    }

    // ---- pit events (entries and exits on the pit and fueling page) ----

    /** @param {object} pitEvent `{uuid, car, tipo}` plus the time fields, as in addEntry */
    addPitEvent(pitEvent) {
        return this.addOnce(this.pitEvents, pitEvent.uuid, () => this.writeNewPitEvent(pitEvent));
    }

    async writeNewPitEvent(pitEvent) {
        // Same rule as addEntry: the sequence number is taken before waiting.
        const record = {
            ...pitEvent, seq: this.nextSequence++, sent: false, sent_at_ms: null, refused: false, refused_reason: null,
        };
        await this.trackWrite(() => this.storage.put('pit_events', record.uuid, record));
        insertInSequenceOrder(this.pitEvents, record);
        return record;
    }

    async markPitEventsSent(uuids, sentAtMs) {
        for (const uuid of uuids) {
            const record = this.pitEvents.find((candidate) => candidate.uuid === uuid);
            if (!record || record.sent) {
                continue;
            }
            const changes = { sent: true, sent_at_ms: sentAtMs };
            await this.storage.patch('pit_events', uuid, changes);
            Object.assign(record, changes);
        }
    }

    allPitEvents() {
        return this.pitEvents.slice();
    }

    unsentPitEvents() {
        return this.pitEvents.filter(isWaiting);
    }

    pitEventsReadyToSend() {
        return this.pitEvents.filter((record) => isWaiting(record) && hasFinalTime(record));
    }

    // ---- final times of held records (see server-clock.js) ----

    /** Entries and pit events typed while this device had no anchor, still without a time. */
    heldRecords() {
        const held = [];
        for (const [collection, records] of [['entries', this.entries], ['pit_events', this.pitEvents]]) {
            for (const record of records) {
                // (A record uploaded by an older version of this page has no
                // `ts_ms` either; the server already has its time.)
                if (!record.sent && !hasFinalTime(record)) {
                    held.push({ collection, record });
                }
            }
        }
        return held;
    }

    heldCount() {
        return this.heldRecords().length;
    }

    /**
     * Gives a held record its final time. This is the ONLY change ever made
     * to the time of a record, it happens once, and always before upload.
     * @param {string} collection 'entries' or 'pit_events'
     * @param {{ts_ms: number, time_quality: string, anchor: ?object}} time
     */
    async setFinalTime(collection, uuid, time) {
        const record = this.findRecord(collection, uuid);
        if (!record || hasFinalTime(record)) {
            return;
        }
        await this.patchRecord(collection, uuid, {
            ts_ms: time.ts_ms, time_quality: time.time_quality, anchor: time.anchor,
        });
    }

    /** Changes some local marks of a record, disk first (see refused-records.js). */
    async patchRecord(collection, uuid, changes) {
        const record = this.findRecord(collection, uuid);
        if (!record) {
            return;
        }
        await this.storage.patch(collection, uuid, changes);
        Object.assign(record, changes);
    }

    findRecord(collection, uuid) {
        const records = collection === 'entries' ? this.entries : this.pitEvents;
        return records.find((record) => record.uuid === uuid);
    }

    // ---- what the status bar shows as "na fila" ----

    /** Number of things the server has not confirmed yet (and still may). */
    pendingCount() {
        const unconfirmedVoids = this.entries.filter(isUnconfirmedVoid).length;
        const unconfirmedFixes = this.entries.filter(isUnconfirmedFix).length;
        return this.unsentEntries().length + unconfirmedVoids + unconfirmedFixes + this.unsentPitEvents().length;
    }

    // ---- meta: device token, revoked mark, last server answer ----

    getMeta(key, fallback = null) {
        return this.meta.has(key) ? this.meta.get(key) : fallback;
    }

    async setMeta(key, value) {
        await this.storage.put('meta', key, { key, value });
        this.meta.set(key, value);
    }
}
