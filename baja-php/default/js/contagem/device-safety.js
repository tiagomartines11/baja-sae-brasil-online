// Small protections against losing work on a phone at the side of a track.
// Every function takes the browser object it needs as an argument and does
// nothing, quietly, where the browser lacks the feature.

/**
 * Asks the browser not to evict this site's storage when the device runs low
 * on space. @returns {Promise<?boolean>} null when the browser cannot be asked
 */
export async function requestPersistentStorage(navigatorRef) {
    if (!navigatorRef.storage || typeof navigatorRef.storage.persist !== 'function') {
        return null;
    }
    try {
        return await navigatorRef.storage.persist();
    } catch (refused) {
        return null;
    }
}

/**
 * Keeps the screen on while the page is visible. The browser releases the
 * lock whenever the page is hidden, so it is requested again on every return.
 */
export function keepScreenAwake(navigatorRef, documentRef) {
    if (!navigatorRef.wakeLock || typeof navigatorRef.wakeLock.request !== 'function') {
        return;
    }
    async function requestLock() {
        if (documentRef.visibilityState !== 'visible') {
            return;
        }
        try {
            await navigatorRef.wakeLock.request('screen');
        } catch (refused) {
            // Low battery mode, for example. Counting continues regardless.
        }
    }
    documentRef.addEventListener('visibilitychange', requestLock);
    requestLock();
}

/**
 * Makes the browser ask "leave this page?" while something is still waiting
 * to be uploaded. (Nothing would be lost, it is all on disk, but the entries
 * would not reach the reviewers until the page is opened again.)
 */
export function warnBeforeLeaving(windowRef, hasUnsentData) {
    windowRef.addEventListener('beforeunload', (event) => {
        if (!hasUnsentData()) {
            return;
        }
        event.preventDefault();
        // Older browsers only show the prompt when returnValue is set.
        event.returnValue = '';
    });
}

/**
 * Registers the service worker that lets the page reload without network.
 * Only available on https (or localhost); elsewhere the page still works but
 * needs the network to load.
 * @returns {Promise<boolean>} whether a registration was made
 */
export async function registerOfflineShell(navigatorRef, serviceWorkerUrl) {
    if (!navigatorRef.serviceWorker || typeof navigatorRef.serviceWorker.register !== 'function') {
        return false;
    }
    try {
        await navigatorRef.serviceWorker.register(serviceWorkerUrl);
        return true;
    } catch (registrationError) {
        return false;
    }
}
