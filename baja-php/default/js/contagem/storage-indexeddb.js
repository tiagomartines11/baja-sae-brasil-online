// IndexedDB storage adapter: where entries really live on the counter's device.
//
// Interface (shared with storage-memory.js): getAll, put, patch. Every write
// resolves only when its transaction has COMPLETED, not merely when the request
// succeeded, and asks for strict durability, because the promise resolving is
// what allows the page to show the entry as recorded. A phone that dies one
// second later must still have it.

import { COLLECTIONS } from './storage-memory.js';

const DATABASE_VERSION = 1;

function openDatabase(indexedDbFactory, databaseName) {
    return new Promise((resolve, reject) => {
        const request = indexedDbFactory.open(databaseName, DATABASE_VERSION);
        request.onupgradeneeded = () => {
            const database = request.result;
            for (const name of COLLECTIONS) {
                if (!database.objectStoreNames.contains(name)) {
                    database.createObjectStore(name);
                }
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('IndexedDB blocked by another tab'));
    });
}

/**
 * Runs `work(objectStore)` inside one transaction and resolves with its
 * result once the transaction has been committed.
 */
function inTransaction(database, collectionName, accessMode, work) {
    return new Promise((resolve, reject) => {
        let transaction;
        try {
            transaction = database.transaction(collectionName, accessMode, { durability: 'strict' });
        } catch (error) {
            reject(error);
            return;
        }
        let result;
        transaction.oncomplete = () => resolve(result);
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error || new Error('IndexedDB transaction aborted'));
        try {
            work(transaction.objectStore(collectionName), (value) => { result = value; }, transaction);
        } catch (error) {
            transaction.abort();
            reject(error);
        }
    });
}

/**
 * @param {string} databaseName one database per (event, prova, source) so two
 *        sources counted on the same device never mix their logs.
 * @param {IDBFactory} indexedDbFactory normally `window.indexedDB`.
 */
export async function openIndexedDbStorage(databaseName, indexedDbFactory = globalThis.indexedDB) {
    if (!indexedDbFactory) {
        throw new Error('IndexedDB is not available');
    }
    const database = await openDatabase(indexedDbFactory, databaseName);

    return {
        persistent: true,

        getAll(collectionName) {
            return inTransaction(database, collectionName, 'readonly', (objectStore, setResult) => {
                const request = objectStore.getAll();
                request.onsuccess = () => setResult(request.result);
            });
        },

        put(collectionName, key, record) {
            return inTransaction(database, collectionName, 'readwrite', (objectStore) => {
                objectStore.put(record, String(key));
            });
        },

        patch(collectionName, key, changes) {
            return inTransaction(database, collectionName, 'readwrite', (objectStore, setResult, transaction) => {
                // Read and write inside the same transaction: a second tab
                // marking the same record cannot slip in between.
                const readRequest = objectStore.get(String(key));
                readRequest.onsuccess = () => {
                    if (!readRequest.result) {
                        transaction.abort();
                        return;
                    }
                    objectStore.put({ ...readRequest.result, ...changes }, String(key));
                };
            });
        },
    };
}
