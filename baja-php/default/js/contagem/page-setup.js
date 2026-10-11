// Wiring shared by the count page and the pit page: makes sure this is the
// only tab, opens the local store, builds the upload queue, keeps the status
// bar current and switches on the safety features. Each page then adds only
// its own buttons and lists.

import { openIndexedDbStorage } from './storage-indexeddb.js';
import { createMemoryStorage } from './storage-memory.js';
import { openStorageWithRetry, ARMAZENAMENTO } from './storage-open.js';
import { claimSingleTab, ABA } from './single-tab.js';
import { LocalStore } from './local-store.js';
import { ClockEstimator } from './clock-estimator.js';
import { SyncQueue, CONEXAO } from './sync-queue.js';
import { SyncHeartbeat } from './sync-heartbeat.js';
import { obtainDeviceToken } from './device-token.js';
import { postJson } from './http.js';
import { createStatusBar } from './status-bar.js';
import { createPreferences } from './entry-modes.js';
import { downloadCsv, exportFileName } from './csv-export.js';
import { keepScreenAwake, registerOfflineShell, requestPersistentStorage, warnBeforeLeaving } from './device-safety.js';
import { wireShiftButtons, wireDeviceStatus } from './page-extras.js';

const CLOCK_REFRESH_MS = 250;

const TEXTO_OUTRA_ABA = 'Esta contagem já está aberta em outra aba deste aparelho. Use aquela aba. '
    + 'Se ela for fechada, esta aqui abre sozinha.';

function readConfig(documentRef) {
    return JSON.parse(documentRef.getElementById('contagem-config').textContent);
}

function localStorageOrNull(windowRef) {
    try {
        return windowRef.localStorage;
    } catch (blocked) {
        return null;
    }
}

/** Some privacy settings make the mere mention of `indexedDB` throw. */
function indexedDbOrNull(windowRef) {
    try {
        return windowRef.indexedDB || null;
    } catch (blocked) {
        return null;
    }
}

/**
 * Returns only when this tab is the one allowed to run. A second tab on the
 * same source stops here, before the store is opened and before any queue
 * exists, shows why, and continues by itself once the first tab is closed.
 */
async function waitToBeTheOnlyTab(windowRef, documentRef, lockName) {
    const claim = await claimSingleTab(windowRef.navigator.locks, lockName);
    if (claim.resultado !== ABA.OUTRA_ABERTA) {
        return;
    }
    const loading = documentRef.getElementById('carregando');
    const originalText = loading.textContent;
    loading.textContent = TEXTO_OUTRA_ABA;
    loading.classList.add('carregando-bloqueado');
    await claim.whenFree();
    loading.textContent = originalText;
    loading.classList.remove('carregando-bloqueado');
}

/**
 * @param {object}   options
 * @param {function} options.buildExportCsv (store) => CSV text for "Exportar meus dados"
 * @returns {Promise<object>} what the page controllers need
 */
export async function startPage({ buildExportCsv, windowRef = window, documentRef = document }) {
    const config = readConfig(documentRef);
    const now = () => Date.now();
    // One database per event + prova + source: counting on source 1 in the
    // morning and source 2 in the afternoon keeps two separate logs.
    const databaseName = ['contagem', config.evento_id, config.prova_id, config.fonte.id].join('-');

    await waitToBeTheOnlyTab(windowRef, documentRef, databaseName);

    const indexedDbFactory = indexedDbOrNull(windowRef);
    const opened = await openStorageWithRetry({
        persistentIsSupported: indexedDbFactory !== null,
        openPersistent: () => openIndexedDbStorage(databaseName, indexedDbFactory),
        createMemory: createMemoryStorage,
    });
    const store = await LocalStore.open(opened.storage);
    const clock = new ClockEstimator();
    const preferences = createPreferences(localStorageOrNull(windowRef));
    const statusBar = createStatusBar(documentRef, config);
    const stateListeners = [];
    const problems = { tokenProblem: null, armazenamento: opened.armazenamento };
    const storedOnDisk = opened.armazenamento === ARMAZENAMENTO.DISCO;
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
        // postJson notes the device time right around the fetch itself and
        // hands both instants back; the clock estimate is built from them.
        return postJson(fetchFn, config.endpoints.sync, { token, body: requestBody, now });
    }

    function renderStatus() {
        const state = queue.getState();
        statusBar.render(state, problems);
        statusBar.renderClock(state, now(), clock.offsetMs());
        shiftButtons.render(state);
        for (const listener of stateListeners) {
            listener(state);
        }
    }

    const queue = new SyncQueue({
        store,
        send,
        clock,
        now,
        onChange: renderStatus,
        // A memory-only page cannot see what this device recorded before, so
        // it never tells the server "you have everything" and sends no
        // heartbeats; it only uploads what is typed into it.
        vouchesForHistory: storedOnDisk,
    });
    const heartbeat = new SyncHeartbeat({
        queue,
        now,
        // True only when the server issued a NEW token, which it does for a
        // logged-in user who is (again) allowed on this source.
        renewToken: async () => (await getToken()).issuedNow,
    });

    const shiftButtons = wireShiftButtons({ windowRef, documentRef, queue, store, syncUrl: config.endpoints.sync });

    // Drawn at once, clock included: after a reload with no network the race
    // start and the clock offset are already known from the disk, and the
    // counter must not look at "--:--:--" while waiting for the first timer.
    renderStatus();
    windowRef.setInterval(() => statusBar.renderClock(queue.getState(), now(), clock.offsetMs()), CLOCK_REFRESH_MS);

    heartbeat.start(windowRef.setInterval.bind(windowRef), windowRef.clearInterval.bind(windowRef));
    if (!storedOnDisk) {
        // No heartbeat will ask for the token here, and a counter should
        // learn about an expired session before the first car, not after.
        getToken().catch(() => {}).finally(renderStatus);
    }

    // Do not wait up to 5 s for the heartbeat when the network comes back or
    // the counter returns to the page.
    windowRef.addEventListener('online', () => queue.requestSync());
    documentRef.addEventListener('visibilitychange', () => {
        if (documentRef.visibilityState === 'visible') {
            queue.requestSync();
        }
    });

    keepScreenAwake(windowRef.navigator, documentRef);
    warnBeforeLeaving(windowRef, () => store.pendingCount() > 0);
    const offlineShellRegistered = registerOfflineShell(windowRef.navigator, config.endpoints.service_worker);
    const persistenceAsked = requestPersistentStorage(windowRef.navigator);
    wireDeviceStatus({
        windowRef, documentRef, armazenamento: opened.armazenamento, refreshAfter: [offlineShellRegistered, persistenceAsked],
    });

    documentRef.getElementById('btn-exportar').addEventListener('click', () => {
        const fileName = exportFileName([config.evento_id, config.prova_id, config.fonte.nome], now());
        downloadCsv(documentRef, fileName, buildExportCsv(store));
    });
    // "Tentar de novo" retries in place. It must never reload the page: a
    // reload is exactly what loses records that are not on the disk.
    documentRef.getElementById('btn-reconectar').addEventListener('click', () => {
        if (queue.getState().conexao === CONEXAO.REVOGADO) {
            heartbeat.tryToRecoverFromRevocation({ force: true }).finally(renderStatus);
        } else {
            queue.requestSync();
        }
    });

    return {
        config,
        store,
        queue,
        clock,
        now,
        preferences,
        /** True after "Encerrar meu turno": the page must not record anything. */
        shiftEnded() {
            return queue.turnoEncerrado;
        },
        /** Registers a function called with the queue state after every change. */
        onState(listener) {
            stateListeners.push(listener);
            listener(queue.getState());
        },
        /** Car list: the server's when it has sent one, else the one in the page. */
        validCars() {
            const serverInfo = store.getMeta('servidor', null);
            const serverCars = serverInfo ? serverInfo.valid_cars : null;
            return Array.isArray(serverCars) && serverCars.length > 0 ? serverCars : config.carros;
        },
        janelaMs() {
            const serverInfo = store.getMeta('servidor', null);
            return serverInfo && Number.isFinite(serverInfo.janela_ms) ? serverInfo.janela_ms : config.janela_ms;
        },
    };
}
