// The device's own log: every entry and pit event this browser ever recorded
// for one source, plus a few settings ("meta").
//
// Rules this file enforces:
//  1. Disk first. `addEntry` resolves only after the storage adapter has
//     written the record; only then does the record appear in memory, on the
//     screen and in the upload queue.
//  2. Nothing is ever deleted. An uploaded entry is MARKED sent, an undone one
//     is MARKED voided. The full history stays available for the CSV export.
//
// The store keeps a copy of everything in memory (a race is at most a few
// thousand entries) so the page can read it synchronously.

function bySequence(first, second) {
    return first.seq - second.seq;
}

export class LocalStore {
    /** Use `LocalStore.open(adapter)`; the constructor alone loads nothing. */
    constructor(storageAdapter) {
        this.storage = storageAdapter;
        this.entries = [];
        this.pitEvents = [];
        this.meta = new Map();
        this.nextSequence = 1;
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

    // ---- entries (passes typed on the count page) ----

    /**
     * @param {{uuid: string, car: number, ts_device_ms: number,
     *          clock_offset_ms: ?number, typing_ms: ?number, mode: string}} entry
     * @returns {Promise<object>} the stored record, with its local marks
     */
    async addEntry(entry) {
        const record = {
            ...entry,
            seq: this.nextSequence,
            sent: false,
            sent_at_ms: null,
            voided: false,
            voided_at_ms: null,
            void_sent: false,
        };
        await this.storage.put('entries', record.uuid, record);
        // Memory is updated only after the disk write succeeded: an entry the
        // counter can see is an entry that survives a reload.
        this.nextSequence++;
        this.entries.push(record);
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
        await this.storage.patch('entries', uuid, changes);
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

    findEntry(uuid) {
        return this.entries.find((record) => record.uuid === uuid);
    }

    /** Every entry ever recorded here, oldest first, including voided ones. */
    allEntries() {
        return this.entries.slice();
    }

    unsentEntries() {
        return this.entries.filter((record) => !record.sent);
    }

    /**
     * Voids the server has not confirmed yet. A void is only offered for
     * upload once its entry is known to be on the server, so the server never
     * receives a void for an entry it has not seen.
     */
    voidsReadyToSend() {
        return this.entries.filter((record) => record.voided && !record.void_sent && record.sent);
    }

    // ---- pit events ("Entrou" / "Saiu" on the pit page) ----

    /**
     * @param {{uuid: string, car: number, tipo: string, ts_device_ms: number,
     *          clock_offset_ms: ?number}} pitEvent
     */
    async addPitEvent(pitEvent) {
        const record = { ...pitEvent, seq: this.nextSequence, sent: false, sent_at_ms: null };
        await this.storage.put('pit_events', record.uuid, record);
        this.nextSequence++;
        this.pitEvents.push(record);
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
        return this.pitEvents.filter((record) => !record.sent);
    }

    // ---- what the status bar shows as "na fila" ----

    /** Number of things the server has not confirmed yet. */
    pendingCount() {
        const unconfirmedVoids = this.entries.filter((record) => record.voided && !record.void_sent).length;
        return this.unsentEntries().length + unconfirmedVoids + this.unsentPitEvents().length;
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
