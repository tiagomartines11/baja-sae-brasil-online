// Records the server will not store ("recusado pelo servidor", brief 2.1).
//
// Why this exists: the upload queue sends a record again and again until the
// server names its uuid. If the server never does, that one record used to
// keep the whole phone "not finished" for ever: the queue never emptied, the
// server never learned how far this counter had synced, and the shift could
// not be ended. So a record is taken OUT OF THE QUEUE (never out of the
// store) in two cases:
//
//   1. the server says so, in the `refused` list of its answer, with a reason;
//   2. safety net: several successful answers in a row failed to mention it.
//
// A refused record stays on the phone, on the screen and in the CSV export.

/** Reason used by the safety net, when the server gave none. */
export const MOTIVO_SEM_CONFIRMACAO = 'sem_confirmacao';

// How many successful answers may skip a record before the queue gives up.
// With one answer every 5 seconds this is under half a minute.
export const MISSES_BEFORE_GIVING_UP = 5;

/** Reason kept on a "??" correction the server answered with `fixes_recusados`. */
export const MOTIVO_CORRECAO_RECUSADA = 'correcao_recusada';

function marksFor(collection, refused, reason) {
    if (collection === 'voids') {
        return { void_refused: refused, void_refused_reason: reason };
    }
    if (collection === 'fixes') {
        return { fix_refused: refused, fix_refused_reason: reason };
    }
    return { refused, refused_reason: reason };
}

/** The undo of an entry ('voids') and the correction of a "??" ('fixes') are marks on the entry itself. */
function storeCollectionOf(collection) {
    return collection === 'voids' || collection === 'fixes' ? 'entries' : collection;
}

/**
 * @param {object} store      LocalStore
 * @param {string} collection 'entries', 'pit_events', 'voids' or 'fixes'
 */
export function markRefused(store, collection, uuid, reason) {
    return store.patchRecord(storeCollectionOf(collection), uuid, marksFor(collection, true, reason));
}

/** @returns {Array<{collection: string, record: object, motivo: string}>} */
export function refusedRecords(store) {
    const refused = [];
    for (const record of store.allEntries()) {
        if (record.refused) {
            refused.push({ collection: 'entries', record, motivo: record.refused_reason });
        }
        if (record.void_refused) {
            refused.push({ collection: 'voids', record, motivo: record.void_refused_reason });
        }
        // A correction the server turned down is shown on its row of the
        // recent list ("correção recusada"), not in the banner: the entry
        // itself is on the server, and the reviewer still sees it as "??".
        // Only one the safety net gave up on is here, for "Tentar de novo".
        if (record.fix_refused && record.fix_refused_reason === MOTIVO_SEM_CONFIRMACAO) {
            refused.push({ collection: 'fixes', record, motivo: record.fix_refused_reason });
        }
    }
    for (const record of store.allPitEvents()) {
        if (record.refused) {
            refused.push({ collection: 'pit_events', record, motivo: record.refused_reason });
        }
    }
    return refused;
}

/**
 * "Tentar de novo": puts back in the queue what the safety net gave up on.
 * Records the server refused WITH a reason stay refused; asking again would
 * get the same answer.
 * @returns {Promise<number>} how many went back
 */
export async function retryUnconfirmed(store) {
    let count = 0;
    for (const { collection, record, motivo } of refusedRecords(store)) {
        if (motivo !== MOTIVO_SEM_CONFIRMACAO) {
            continue;
        }
        await store.patchRecord(storeCollectionOf(collection), record.uuid, marksFor(collection, false, null));
        count++;
    }
    return count;
}

/**
 * Counts, per record, the successful answers that skipped it. Kept in memory
 * only: a reload starts every count again, which is one more honest try.
 */
export class UnacknowledgedTracker {
    constructor(missesBeforeGivingUp = MISSES_BEFORE_GIVING_UP) {
        this.missesBeforeGivingUp = missesBeforeGivingUp;
        this.misses = new Map();
    }

    /**
     * @param {string}   collection   'entries', 'pit_events', 'voids' or 'fixes'
     * @param {string[]} sentUuids    in the order they were in the request
     * @param {Set}      acknowledged the uuids the answer named
     * @returns {string[]} uuids that have now been skipped too many times
     */
    noteAnswer(collection, sentUuids, acknowledged) {
        let lastAcknowledgedPosition = -1;
        sentUuids.forEach((uuid, position) => {
            if (acknowledged.has(uuid)) {
                lastAcknowledgedPosition = position;
            }
        });
        // A server may store only the first part of a big request and leave
        // the rest for the next one. Records AFTER the last stored one were
        // not skipped, they were simply not reached. A record is "skipped"
        // when the server went past it, or stored nothing at all.
        const nothingStored = lastAcknowledgedPosition === -1;
        const givenUp = [];
        sentUuids.forEach((uuid, position) => {
            const key = collection + ':' + uuid;
            if (acknowledged.has(uuid)) {
                this.misses.delete(key);
                return;
            }
            if (!nothingStored && position > lastAcknowledgedPosition) {
                return;
            }
            const misses = (this.misses.get(key) || 0) + 1;
            this.misses.set(key, misses);
            if (misses >= this.missesBeforeGivingUp) {
                this.misses.delete(key);
                givenUp.push(uuid);
            }
        });
        return givenUp;
    }
}
