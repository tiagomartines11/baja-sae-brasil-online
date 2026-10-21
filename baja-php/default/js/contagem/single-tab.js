// One tab per device and source.
//
// Two tabs of the same count page share one database but not their memory.
// Each would upload on its own schedule, and each would tell the server "you
// have everything" without knowing what the other tab has just recorded. The
// counter, meanwhile, sees a list of recent entries that is missing half of
// them. So only one tab may run; any other one says so and waits.
//
// The browser's Web Locks do the bookkeeping: a lock belongs to one tab and
// is released by the browser itself when that tab is closed or crashes, so a
// stale lock can never shut everybody out.

export const ABA = {
    UNICA: 'unica',                 // this tab holds the lock
    OUTRA_ABERTA: 'outra_aberta',   // another tab holds it; this one must not start
    SEM_TRAVA: 'sem_trava',         // the browser has no Web Locks: carry on as before
};

/**
 * Tries to become THE tab for `lockName`, without waiting.
 *
 * @param {?LockManager} lockManager normally `navigator.locks` (missing on old
 *        browsers and on pages not served over https)
 * @param {string} lockName one name per event + prova + source
 * @returns {Promise<{resultado: string, whenFree: function}>}
 *          `whenFree()` waits until the other tab is gone and this tab holds
 *          the lock (resolves at once when it already does)
 */
export async function claimSingleTab(lockManager, lockName) {
    if (!lockManager || typeof lockManager.request !== 'function') {
        return { resultado: ABA.SEM_TRAVA, whenFree: () => Promise.resolve() };
    }

    // A lock is held for as long as the function given to request() has not
    // finished. Returning a promise that never resolves means "until this
    // tab is closed".
    const untilTheTabCloses = new Promise(() => {});

    let gotIt;
    try {
        gotIt = await new Promise((resolve, reject) => {
            lockManager.request(lockName, { ifAvailable: true }, (lock) => {
                resolve(lock !== null);
                return lock !== null ? untilTheTabCloses : undefined;
            }).catch(reject);
        });
    } catch (lockError) {
        // A browser that has the API but refuses to use it here.
        return { resultado: ABA.SEM_TRAVA, whenFree: () => Promise.resolve() };
    }

    if (gotIt) {
        return { resultado: ABA.UNICA, whenFree: () => Promise.resolve() };
    }
    return {
        resultado: ABA.OUTRA_ABERTA,
        whenFree: () => new Promise((resolve) => {
            // Without "ifAvailable" the request queues up behind the other
            // tab and is granted when that tab goes away.
            lockManager.request(lockName, () => {
                resolve();
                return untilTheTabCloses;
            }).catch(() => {
                // Could not even wait in line; the message simply stays.
            });
        }),
    };
}
