// Opens the device's storage at page start, patiently.
//
// IndexedDB occasionally fails to open at the first try, or never answers at
// all (a browser still starting up, another tab in the middle of closing, a
// phone under memory pressure). Giving up at once would be the worst possible
// reaction: the page would start with an EMPTY memory-only log while the real
// records sit on the disk, unseen and unsent. So the open is tried a few
// times, each with a time limit, and memory is only the last resort.

export const ARMAZENAMENTO = {
    DISCO: 'disco',               // the normal case: records survive a reload
    SEM_SUPORTE: 'sem_suporte',   // this browser has no IndexedDB at all
    NAO_ABRIU: 'nao_abriu',       // it has, but the stored records could not be opened
};

const DEFAULT_ATTEMPTS = 3;
const DEFAULT_ATTEMPT_TIMEOUT_MS = 4000;
const DEFAULT_PAUSE_BETWEEN_ATTEMPTS_MS = 500;

function pause(milliseconds, setTimeoutFn) {
    return new Promise((resolve) => setTimeoutFn(resolve, milliseconds));
}

/**
 * One attempt with a time limit. Resolves with the storage, or with null
 * when the attempt failed or took too long.
 */
function openOnce(openPersistent, timeoutMs, setTimeoutFn, clearTimeoutFn) {
    return new Promise((resolve) => {
        let gaveUp = false;
        const timer = setTimeoutFn(() => {
            gaveUp = true;
            resolve(null);
        }, timeoutMs);

        // Wrapped in a promise chain so that an open that throws at once is
        // treated like one that fails later.
        Promise.resolve().then(openPersistent).then((storage) => {
            if (gaveUp) {
                // Too late: the page has moved on. An open connection that
                // nobody uses would block a later attempt, so it is closed.
                if (storage && typeof storage.close === 'function') {
                    storage.close();
                }
                return;
            }
            clearTimeoutFn(timer);
            resolve(storage);
        }, () => {
            if (!gaveUp) {
                clearTimeoutFn(timer);
                resolve(null);
            }
        });
    });
}

/**
 * @param {object}   options
 * @param {boolean}  options.persistentIsSupported whether the browser has IndexedDB
 * @param {function} options.openPersistent        async () => storage adapter (may reject or hang)
 * @param {function} options.createMemory          () => memory storage adapter
 * @param {number}   [options.attempts]
 * @param {number}   [options.attemptTimeoutMs]
 * @param {number}   [options.pauseMs]             wait between two attempts
 * @param {function} [options.setTimeoutFn]
 * @param {function} [options.clearTimeoutFn]
 * @returns {Promise<{storage: object, armazenamento: string}>}
 */
export async function openStorageWithRetry({
    persistentIsSupported,
    openPersistent,
    createMemory,
    attempts = DEFAULT_ATTEMPTS,
    attemptTimeoutMs = DEFAULT_ATTEMPT_TIMEOUT_MS,
    pauseMs = DEFAULT_PAUSE_BETWEEN_ATTEMPTS_MS,
    setTimeoutFn = setTimeout,
    clearTimeoutFn = clearTimeout,
}) {
    if (!persistentIsSupported) {
        return { storage: createMemory(), armazenamento: ARMAZENAMENTO.SEM_SUPORTE };
    }
    for (let attempt = 1; attempt <= attempts; attempt++) {
        const storage = await openOnce(openPersistent, attemptTimeoutMs, setTimeoutFn, clearTimeoutFn);
        if (storage !== null) {
            return { storage, armazenamento: ARMAZENAMENTO.DISCO };
        }
        if (attempt < attempts) {
            await pause(pauseMs, setTimeoutFn);
        }
    }
    // Counting must still be possible. The page says clearly that the
    // stored records could not be opened, and stays quiet towards the server
    // (see SyncQueue.mustStaySilent) because it cannot speak for them.
    return { storage: createMemory(), armazenamento: ARMAZENAMENTO.NAO_ABRIU };
}
