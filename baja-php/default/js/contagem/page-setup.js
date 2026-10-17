// Wiring shared by the count page and the pit page: makes sure this is the
// only tab, opens the local store, builds the upload queue, keeps the status
// bar current and switches on the safety features. Each page then adds only
// its own buttons and lists.

import { openIndexedDbStorage } from './storage-indexeddb.js';
import { createMemoryStorage } from './storage-memory.js';
import { openStorageWithRetry, ARMAZENAMENTO } from './storage-open.js';
import { claimSingleTab, ABA } from './single-tab.js';
import { LocalStore } from './local-store.js';
import { ServerClock } from './server-clock.js';
import { SyncQueue, CONEXAO } from './sync-queue.js';
import { runClockGuard } from './clock-guard.js';
import { SyncHeartbeat } from './sync-heartbeat.js';
import { createTokenSource } from './device-token.js';
import { createPageInfo } from './page-info.js';
import { generateUuid } from './uuid.js';
import { shellSaysAccessRefused, recordingBlockReason } from './access.js';
import { postJson } from './http.js';
import { createStatusBar } from './status-bar.js';
import { createPreferences } from './entry-modes.js';
import { downloadCsv, exportFileName } from './csv-export.js';
import { keepScreenAwake, registerOfflineShell, requestPersistentStorage, warnBeforeLeaving } from './device-safety.js';
import { wireShiftButtons, wireDeviceStatus } from './page-extras.js';

const CLOCK_REFRESH_MS = 250;
// How often the monotonic clock is checked against the system clock.
const CLOCK_GUARD_MS = 1000;

// The one global this feature puts on `window`, and only while the prova
// allows simulation: the race simulation finds the page's own recording
// function there (see exposeForSimulation below).
const SIMULATION_GLOBAL = 'contagemPagina';

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
        return claim.resultado;
    }
    const loading = documentRef.getElementById('carregando');
    const originalText = loading.textContent;
    loading.textContent = TEXTO_OUTRA_ABA;
    loading.classList.add('carregando-bloqueado');
    await claim.whenFree();
    loading.textContent = originalText;
    loading.classList.remove('carregando-bloqueado');
    return ABA.UNICA;
}

/**
 * @param {object}   options
 * @param {function} options.buildExportCsv (store) => CSV text for "Exportar meus dados"
 * @returns {Promise<object>} what the page controllers need
 */
export async function startPage({ buildExportCsv, windowRef = window, documentRef = document }) {
    const config = readConfig(documentRef);
    // The system clock: a witness only. No time of a pass is built on it
    // (see server-clock.js).
    const now = () => Date.now();
    // The clock passes ARE timed with: cannot be set, only moves forward.
    const monotonicNow = () => windowRef.performance.now();
    // One database per event + prova + source: counting on source 1 in the
    // morning and source 2 in the afternoon keeps two separate logs.
    const databaseName = ['contagem', config.evento_id, config.prova_id, config.fonte.id].join('-');

    const abaUnica = await waitToBeTheOnlyTab(windowRef, documentRef, databaseName);

    const indexedDbFactory = indexedDbOrNull(windowRef);
    const opened = await openStorageWithRetry({
        persistentIsSupported: indexedDbFactory !== null,
        openPersistent: () => openIndexedDbStorage(databaseName, indexedDbFactory),
        createMemory: createMemoryStorage,
    });
    const store = await LocalStore.open(opened.storage);
    const clock = new ServerClock({ now, monotonicNow, loadId: generateUuid() });
    const info = createPageInfo(store, config);
    const preferences = createPreferences(localStorageOrNull(windowRef));
    const statusBar = createStatusBar(documentRef, config);
    const stateListeners = [];
    // Set when this is the offline copy, served because the server refused
    // the page to whoever is using the browser now (see access.js).
    const shellRefused = shellSaysAccessRefused(documentRef);
    const problems = { tokenProblem: null, armazenamento: opened.armazenamento, bloqueio: null };
    const recordingBlocked = () => recordingBlockReason({ shellRefused, queueState: queue.getState() });
    const storedOnDisk = opened.armazenamento === ARMAZENAMENTO.DISCO;
    const fetchFn = windowRef.fetch.bind(windowRef);

    const tokenSource = createTokenSource({
        store,
        requestToken: () => postJson(fetchFn, config.endpoints.dispositivo),
        usuario: config.usuario,
        monotonicNow,
        onOutcome: (motivo) => {
            problems.tokenProblem = motivo;
        },
    });

    async function send(requestBody) {
        const { token } = await tokenSource.get();
        // postJson reads the monotonic clock right around the fetch itself
        // and hands both instants back; the anchor is built from them.
        return postJson(fetchFn, config.endpoints.sync, { token, body: requestBody, now: monotonicNow });
    }

    /** Set by the page controller: what the simulation may call (see exposeForSimulation). */
    let simulationApi = null;

    // The simulation itself (simulacao.js) is loaded only where it is
    // allowed, and only once. It is optional: a failed import (for example
    // an offline copy that does not have it) changes nothing for counting.
    let simulationModule = null;
    let simulationModuleAsked = false;

    function installSimulationModule() {
        if (simulationModule !== null) {
            simulationModule.instalarSimulacao(windowRef);
            return;
        }
        if (simulationModuleAsked) {
            return;
        }
        simulationModuleAsked = true;
        import('./simulacao.js').then((loaded) => {
            simulationModule = loaded;
            // The setting may have been switched off while the file loaded.
            if (windowRef[SIMULATION_GLOBAL] === simulationApi) {
                loaded.instalarSimulacao(windowRef);
            }
        }).catch(() => {
            simulationModuleAsked = false;
        });
    }

    function updateSimulationGlobal() {
        if (simulationApi === null) {
            return;
        }
        // The simulation records through the page; where the page may not
        // record, there is no simulation either.
        if (info.simulationAllowed() && recordingBlocked() === null) {
            if (windowRef[SIMULATION_GLOBAL] !== simulationApi) {
                windowRef[SIMULATION_GLOBAL] = simulationApi;
                installSimulationModule();
            }
        } else if (windowRef[SIMULATION_GLOBAL] === simulationApi) {
            delete windowRef[SIMULATION_GLOBAL];
            if (simulationModule !== null) {
                simulationModule.removerSimulacao(windowRef);
            }
        }
    }

    function renderStatus() {
        const state = queue.getState();
        problems.bloqueio = recordingBlocked();
        // The style sheet hides the entry controls under this class; the
        // recent list, the export and the uploads stay.
        documentRef.body.classList.toggle('somente-leitura', problems.bloqueio !== null);
        statusBar.render(state, problems, info.simulationAllowed());
        statusBar.renderClock(state, clock.serverNowMs(), clock.estado());
        shiftButtons.render(state);
        updateSimulationGlobal();
        for (const listener of stateListeners) {
            listener(state);
        }
    }

    const queue = new SyncQueue({
        store,
        send,
        clock,
        onChange: renderStatus,
        // A memory-only page cannot see what this device recorded before, so
        // it never tells the server "you have everything" and sends no
        // heartbeats; it only uploads what is typed into it.
        vouchesForHistory: storedOnDisk,
    });
    const heartbeat = new SyncHeartbeat({
        queue,
        // Monotonic: the 30-second pause must not depend on a clock that can be set.
        now: monotonicNow,
        renewToken: () => tokenSource.renew(),
    });
    // Records an earlier page load left without a time get one now, before
    // anything is shown or uploaded (brief 6.4).
    await queue.prepare();

    const shiftButtons = wireShiftButtons({ windowRef, documentRef, queue, store, syncUrl: config.endpoints.sync });

    renderStatus();
    windowRef.setInterval(
        () => statusBar.renderClock(queue.getState(), clock.serverNowMs(), clock.estado()),
        CLOCK_REFRESH_MS,
    );
    // A phone whose monotonic clock may have stood still while it slept
    // gets its anchor marked suspect here, and asks the server at once
    // (instead of at the next heartbeat) to settle it.
    windowRef.setInterval(() => runClockGuard(clock, queue), CLOCK_GUARD_MS);

    heartbeat.start(windowRef.setInterval.bind(windowRef), windowRef.clearInterval.bind(windowRef));
    if (!storedOnDisk) {
        // No heartbeat will ask for the token here, and a counter should
        // learn about an expired session before the first car, not after.
        tokenSource.get().catch(() => {}).finally(renderStatus);
    }

    // Do not wait up to 5 s for the heartbeat when the network comes back or
    // the counter returns to the page.
    windowRef.addEventListener('online', () => queue.requestSync());
    documentRef.addEventListener('visibilitychange', () => {
        if (documentRef.visibilityState === 'visible') {
            // Coming back from the background is exactly when the phone may
            // have slept; check the clocks before the first tap.
            runClockGuard(clock, queue);
            queue.requestSync();
        }
    });

    keepScreenAwake(windowRef.navigator, documentRef);
    warnBeforeLeaving(windowRef, () => store.pendingCount() > 0);
    const offlineShellRegistered = registerOfflineShell(windowRef.navigator, config.endpoints.service_worker);
    const persistenceAsked = requestPersistentStorage(windowRef.navigator);
    wireDeviceStatus({
        windowRef,
        documentRef,
        armazenamento: opened.armazenamento,
        abaUnica,
        refreshAfter: [offlineShellRegistered, persistenceAsked],
    });

    documentRef.getElementById('btn-exportar').addEventListener('click', () => {
        const fileName = exportFileName([config.evento_id, config.prova_id, config.fonte.nome], now());
        downloadCsv(documentRef, fileName, buildExportCsv(store));
    });
    // "Tentar de novo" retries in place. It must never reload the page: a
    // reload is exactly what loses records that are not on the disk.
    documentRef.getElementById('btn-reconectar').addEventListener('click', () => {
        // A person asked: skip the pauses that only spare the server.
        tokenSource.forgetRefusal();
        if (queue.getState().conexao === CONEXAO.REVOGADO) {
            heartbeat.tryToRecoverFromRevocation({ force: true }).finally(renderStatus);
        } else {
            // Also puts back what the safety net had given up on (brief 2.1).
            queue.retryRefused().finally(() => queue.requestSync());
        }
    });

    return {
        config,
        store,
        queue,
        clock,
        now,
        monotonicNow,
        preferences,
        ...info,
        /** True after "Encerrar meu turno": the page must not record anything. */
        /** ?string, a reason (access.js BLOQUEIO.*) while this page may record nothing new. */
        recordingBlocked,
        shiftEnded() {
            return queue.turnoEncerrado;
        },
        /** Registers a function called with the queue state after every change. */
        onState(listener) {
            stateListeners.push(listener);
            listener(queue.getState());
        },
        /**
         * Makes the page's recording function reachable from outside the
         * module, for the race simulation, as `window.contagemPagina`. The
         * global exists ONLY while the prova allows simulation
         * (`contagem.permitir_simulacao`); it appears and disappears with
         * the setting, without a reload.
         *
         * @param {object} api what to expose; each page documents its own
         */
        exposeForSimulation(api) {
            simulationApi = api;
            updateSimulationGlobal();
        },
    };
}
