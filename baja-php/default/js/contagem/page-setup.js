// Wiring shared by the count page and the pit page: opens the local store,
// builds the upload queue, keeps the status bar current and switches on the
// safety features. Each page then adds only its own buttons and lists.

import { openIndexedDbStorage } from './storage-indexeddb.js';
import { createMemoryStorage } from './storage-memory.js';
import { LocalStore } from './local-store.js';
import { ClockEstimator } from './clock-estimator.js';
import { SyncQueue, CONEXAO } from './sync-queue.js';
import { obtainDeviceToken } from './device-token.js';
import { postJson } from './http.js';
import { createStatusBar } from './status-bar.js';
import { createPreferences } from './entry-modes.js';
import { downloadCsv, exportFileName } from './csv-export.js';
import {
    keepScreenAwake, registerOfflineShell, requestPersistentStorage, warnBeforeLeaving,
} from './device-safety.js';

const CLOCK_REFRESH_MS = 250;

function readConfig(documentRef) {
    return JSON.parse(documentRef.getElementById('contagem-config').textContent);
}

async function openStorage(config) {
    // One database per event + prova + source: counting on source 1 in the
    // morning and source 2 in the afternoon keeps two separate logs.
    const databaseName = ['contagem', config.evento_id, config.prova_id, config.fonte.id].join('-');
    try {
        return await openIndexedDbStorage(databaseName);
    } catch (indexedDbError) {
        // Counting must still be possible; the status bar shows a permanent
        // warning because nothing survives a reload in this situation.
        return createMemoryStorage();
    }
}

function localStorageOrNull(windowRef) {
    try {
        return windowRef.localStorage;
    } catch (blocked) {
        return null;
    }
}

/**
 * @param {object}   options
 * @param {function} options.buildExportCsv (store) => CSV text for "Exportar meus dados"
 * @returns {Promise<object>} what the page controllers need
 */
export async function startPage({ buildExportCsv, windowRef = window, documentRef = document }) {
    const config = readConfig(documentRef);
    const now = () => Date.now();
    const store = await LocalStore.open(await openStorage(config));
    const clock = new ClockEstimator();
    const preferences = createPreferences(localStorageOrNull(windowRef));
    const statusBar = createStatusBar(documentRef, config);
    const stateListeners = [];
    const problems = { tokenProblem: null, storagePersistent: store.isPersistent() };
    const fetchFn = windowRef.fetch.bind(windowRef);

    function requestToken() {
        return postJson(fetchFn, config.endpoints.dispositivo);
    }

    async function getToken() {
        try {
            const result = await obtainDeviceToken({ store, requestToken, usuario: config.usuario });
            problems.tokenProblem = null;
            return result;
        } catch (tokenError) {
            problems.tokenProblem = tokenError.motivo || null;
            throw tokenError;
        }
    }

    async function send(requestBody) {
        const { token } = await getToken();
        return postJson(fetchFn, config.endpoints.sync, { token, body: requestBody });
    }

    const queue = new SyncQueue({
        store,
        send,
        clock,
        now,
        onChange: (state) => {
            statusBar.render(state, problems);
            for (const listener of stateListeners) {
                listener(state);
            }
        },
    });

    // A token revoked earlier may have been replaced by the organisers linking
    // this person again. One attempt per page load; it only succeeds for a
    // logged-in user the server still allows on this source.
    async function tryToRecoverFromRevocation() {
        if (queue.getState().conexao !== CONEXAO.REVOGADO) {
            return;
        }
        try {
            const result = await getToken();
            if (result.issuedNow) {
                await queue.resumeWithNewToken();
            }
        } catch (stillRefused) {
            // Stays revoked; the banner explains what to do.
        }
    }

    statusBar.render(queue.getState(), problems);
    windowRef.setInterval(() => statusBar.renderClock(queue.getState(), now(), clock.offsetMs()), CLOCK_REFRESH_MS);

    tryToRecoverFromRevocation().finally(() => queue.startHeartbeat(
        windowRef.setInterval.bind(windowRef),
        windowRef.clearInterval.bind(windowRef),
    ));

    // Do not wait up to 5 s for the heartbeat when the network comes back or
    // the counter returns to the page.
    windowRef.addEventListener('online', () => queue.requestSync());
    documentRef.addEventListener('visibilitychange', () => {
        if (documentRef.visibilityState === 'visible') {
            queue.requestSync();
        }
    });

    requestPersistentStorage(windowRef.navigator);
    keepScreenAwake(windowRef.navigator, documentRef);
    warnBeforeLeaving(windowRef, () => store.pendingCount() > 0);
    registerOfflineShell(windowRef.navigator, config.endpoints.service_worker);

    documentRef.getElementById('btn-exportar').addEventListener('click', () => {
        const fileName = exportFileName([config.evento_id, config.prova_id, config.fonte.nome], now());
        downloadCsv(documentRef, fileName, buildExportCsv(store));
    });
    documentRef.getElementById('btn-reconectar').addEventListener('click', () => windowRef.location.reload());

    return {
        config,
        store,
        queue,
        clock,
        now,
        preferences,
        /** Registers a function called with the queue state after every change. */
        onState(listener) {
            stateListeners.push(listener);
            listener(queue.getState());
        },
        /** Car list: the server's when we have one, else the one in the page. */
        validCars() {
            const serverInfo = store.getMeta('servidor', null);
            return serverInfo && Array.isArray(serverInfo.valid_cars) ? serverInfo.valid_cars : config.carros;
        },
        janelaMs() {
            const serverInfo = store.getMeta('servidor', null);
            return serverInfo && Number.isFinite(serverInfo.janela_ms) ? serverInfo.janela_ms : config.janela_ms;
        },
    };
}
