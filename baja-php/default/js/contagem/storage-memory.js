// In-memory storage adapter with the same interface as the IndexedDB one.
//
// Two uses:
//  - tests: one adapter object plays the role of the phone's disk, and a
//    "reload" is simulated by building a new LocalStore over the same adapter;
//  - last resort in a browser that refuses IndexedDB (the page then shows a
//    permanent warning, because nothing survives a reload).
//
// Records are copied on the way in and on the way out, exactly like a real
// database: changing an object after saving it must not change what is stored.

export const COLLECTIONS = ['entries', 'pit_events', 'meta'];

function copyOf(record) {
    return JSON.parse(JSON.stringify(record));
}

export function createMemoryStorage() {
    const collections = new Map();
    for (const name of COLLECTIONS) {
        collections.set(name, new Map());
    }

    function collection(name) {
        const records = collections.get(name);
        if (!records) {
            throw new Error('Unknown collection: ' + name);
        }
        return records;
    }

    return {
        /** False: memory does not survive a reload. The IndexedDB adapter says true. */
        persistent: false,

        async getAll(name) {
            return Array.from(collection(name).values(), copyOf);
        },

        /** Inserts the record, or replaces the one stored under the same key. */
        async put(name, key, record) {
            collection(name).set(String(key), copyOf(record));
        },

        /**
         * Changes only the given fields of an existing record. Used for the
         * "sent" and "voided" marks so that two marks made close together
         * never overwrite each other.
         */
        async patch(name, key, changes) {
            const records = collection(name);
            const existing = records.get(String(key));
            if (!existing) {
                throw new Error('Record not found: ' + name + '/' + key);
            }
            records.set(String(key), { ...existing, ...copyOf(changes) });
        },
    };
}
