// Facts about this device that decide whether a counter's work is safe, and
// that nobody can see from the outside. The page shows them in plain words so
// that they can be checked during the rehearsal, with time to fix:
//
//   "Armazenamento protegido"  the browser promised not to throw this site's
//                              records away when the phone runs low on space.
//   "Cópia offline"            the page itself is stored on the device, so it
//                              can be reopened with no network at all.
//   one-tab protection         shown only when it is OFF: this browser cannot
//                              stop the same count from running in two tabs.

import { ARMAZENAMENTO } from './storage-open.js';

export const COPIA_OFFLINE = {
    INSTALADA: 'instalada',
    NAO_INSTALADA: 'nao_instalada',
    PRECISA_HTTPS: 'precisa_https',
};

// Must match CACHE_PREFIX in service-worker.js.
const SHELL_CACHE_PREFIX = 'contagem-';

/**
 * @returns {Promise<?boolean>} null when the browser cannot be asked
 */
export async function readStorageProtection(navigatorRef) {
    if (!navigatorRef.storage || typeof navigatorRef.storage.persisted !== 'function') {
        return null;
    }
    try {
        return (await navigatorRef.storage.persisted()) === true;
    } catch (refused) {
        return null;
    }
}

/**
 * "Installed" needs both halves: a service worker in charge of this page
 * (otherwise nothing would answer a reload without network), and this page's
 * shell in that worker's cache (otherwise it would have nothing to answer with).
 *
 * @param {object}  options
 * @param {object}  options.navigatorRef
 * @param {?object} options.cachesRef       `window.caches` (missing without https)
 * @param {string}  options.pageUrl         this page's full URL
 * @param {boolean} options.isSecureContext `window.isSecureContext`
 * @returns {Promise<string>} one of COPIA_OFFLINE.*
 */
export async function readOfflineCopy({ navigatorRef, cachesRef, pageUrl, isSecureContext }) {
    if (!isSecureContext || !navigatorRef.serviceWorker || !cachesRef) {
        return COPIA_OFFLINE.PRECISA_HTTPS;
    }
    if (!navigatorRef.serviceWorker.controller) {
        return COPIA_OFFLINE.NAO_INSTALADA;
    }
    try {
        for (const cacheName of await cachesRef.keys()) {
            if (!cacheName.startsWith(SHELL_CACHE_PREFIX)) {
                continue;
            }
            const cache = await cachesRef.open(cacheName);
            if (await cache.match(pageUrl)) {
                return COPIA_OFFLINE.INSTALADA;
            }
        }
    } catch (cacheError) {
        // Treated as "not installed": the counter must not rely on it.
    }
    return COPIA_OFFLINE.NAO_INSTALADA;
}

/**
 * @param {object}   status
 * @param {?boolean} status.protegido     from readStorageProtection()
 * @param {string}   status.armazenamento one of ARMAZENAMENTO.*
 * @param {string}   status.copiaOffline  one of COPIA_OFFLINE.*
 * @param {boolean}  [status.semTravaDeAba] true when the browser has no Web
 *        Locks (old browsers, pages not on https): see single-tab.js
 * @returns {{armazenamento: string, copiaOffline: string, abaUnica: ?string, tudoCerto: boolean}}
 *          `abaUnica` is null when the protection works (nothing to say)
 */
export function describeDeviceStatus({ protegido, armazenamento, copiaOffline, semTravaDeAba = false }) {
    let storageText;
    if (armazenamento !== ARMAZENAMENTO.DISCO) {
        // "Protected: yes" next to a memory-only log would be a lie even if
        // the browser granted the permission.
        storageText = 'Armazenamento protegido: não (os registros estão só nesta página)';
    } else if (protegido === true) {
        storageText = 'Armazenamento protegido: sim';
    } else if (protegido === false) {
        storageText = 'Armazenamento protegido: não';
    } else {
        storageText = 'Armazenamento protegido: não (este navegador não informa)';
    }

    const offlineText = {
        [COPIA_OFFLINE.INSTALADA]: 'Cópia offline: instalada',
        [COPIA_OFFLINE.NAO_INSTALADA]: 'Cópia offline: não instalada (abra esta página uma vez com conexão)',
        [COPIA_OFFLINE.PRECISA_HTTPS]: 'Cópia offline: não instalada (precisa de https)',
    }[copiaOffline];

    return {
        armazenamento: storageText,
        copiaOffline: offlineText,
        abaUnica: semTravaDeAba
            ? 'Proteção contra duas abas: desligada neste navegador. Abra esta contagem em UMA aba só.'
            : null,
        tudoCerto: armazenamento === ARMAZENAMENTO.DISCO && protegido === true
            && copiaOffline === COPIA_OFFLINE.INSTALADA && !semTravaDeAba,
    };
}
