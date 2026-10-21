// Two small pieces of page wiring shared by the count page and the pit page:
// the "Encerrar meu turno" buttons and the two device status lines in the
// footer. The decisions are in shift-control.js and device-status.js; this
// file only connects them to the DOM.

import { endShift, resumeShift, closingBeaconBody, TURNO } from './shift-control.js';
import { storedUsableToken } from './device-token.js';
import { describeDeviceStatus, readOfflineCopy, readStorageProtection } from './device-status.js';
import { ABA } from './single-tab.js';

const DEVICE_STATUS_REFRESH_MS = 10000;

const TEXTO_ENCERRANDO = 'Enviando o que falta e avisando o servidor…';

// Asked before anything is sent: the button sits beside "Exportar", and a
// counter who ends the shift by mistake stops being waited for by the server.
const PERGUNTA_ENCERRAR = 'Encerrar o seu turno neste aparelho?\n\n'
    + 'Ele envia o que falta e avisa o servidor que você parou de contar. '
    + 'Use só quando você realmente parar.';

function textAfterEndShift(result, pendingCount) {
    if (result === TURNO.PENDENTE) {
        const what = pendingCount > 0
            ? 'ainda há ' + pendingCount + ' registro(s) na fila'
            : 'um registro ainda estava sendo gravado';
        return 'O turno NÃO foi encerrado: ' + what + '. Tente de novo quando houver conexão.';
    }
    if (result === TURNO.SEM_CONEXAO) {
        return 'O turno NÃO foi encerrado: sem conexão para avisar o servidor. Tente de novo.';
    }
    if (result === TURNO.SEM_CONFIRMACAO) {
        return 'O turno NÃO foi encerrado: o servidor respondeu, mas não confirmou o encerramento. '
            + 'Tente de novo; se continuar, avise o responsável pela prova.';
    }
    if (result === TURNO.BLOQUEADO) {
        return 'O turno NÃO foi encerrado: este aparelho está com o envio bloqueado. '
            + 'Procure o responsável pela prova.';
    }
    return '';
}

/**
 * @param {object} options
 * @param {object} options.queue   SyncQueue
 * @param {object} options.store   LocalStore
 * @param {string} options.syncUrl contagem_sync.php, for the goodbye on page close
 * @returns {{render: function}} `render(state)` must be called after every queue change
 */
export function wireShiftButtons({ windowRef, documentRef, queue, store, syncUrl }) {
    const element = (id) => documentRef.getElementById(id);
    const endButton = element('btn-encerrar-turno');
    const resumeButton = element('btn-retomar-turno');
    const message = element('turno-mensagem');
    const endedPanel = element('turno-encerrado');

    function showMessage(text) {
        message.textContent = text;
        message.hidden = text === '';
    }

    endButton.addEventListener('click', async () => {
        if (!windowRef.confirm(PERGUNTA_ENCERRAR)) {
            return;
        }
        endButton.disabled = true;
        showMessage(TEXTO_ENCERRANDO);
        let result;
        try {
            result = await endShift(queue);
        } catch (unexpectedError) {
            result = TURNO.SEM_CONEXAO;
        }
        endButton.disabled = false;
        showMessage(textAfterEndShift(result, store.pendingCount()));
    });

    resumeButton.addEventListener('click', async () => {
        resumeButton.disabled = true;
        try {
            await resumeShift(queue);
        } finally {
            resumeButton.disabled = false;
        }
    });

    // Best effort for a page closed without the button. "pagehide" is the
    // last event a page reliably gets, and sendBeacon the only request a
    // browser still delivers from it. A reload fires it too; the freshly
    // loaded page then talks to the server normally and is "back" at once.
    windowRef.addEventListener('pagehide', () => {
        if (typeof windowRef.navigator.sendBeacon !== 'function') {
            return;
        }
        const body = closingBeaconBody(queue, storedUsableToken(store));
        if (body !== null) {
            windowRef.navigator.sendBeacon(syncUrl, JSON.stringify(body));
        }
    });

    return {
        render(state) {
            endedPanel.hidden = !state.turnoEncerrado;
            // The style sheet hides the entry controls under this class, so
            // that nobody keeps typing into a page that said goodbye.
            documentRef.body.classList.toggle('turno-encerrado-ativo', state.turnoEncerrado);
            if (state.turnoEncerrado) {
                showMessage('');
            }
        },
    };
}

/**
 * Keeps "Armazenamento protegido" and "Cópia offline" current.
 *
 * @param {object}    options
 * @param {string}    options.armazenamento one of ARMAZENAMENTO.*
 * @param {string}    options.abaUnica      one of ABA.*: how the one-tab check ended
 * @param {Promise[]} options.refreshAfter  things whose completion may change
 *        the answer (the storage request, the service worker registration)
 */
export function wireDeviceStatus({ windowRef, documentRef, armazenamento, abaUnica, refreshAfter }) {
    const storageLine = documentRef.getElementById('st-armazenamento');
    const offlineLine = documentRef.getElementById('st-copia-offline');
    const singleTabLine = documentRef.getElementById('st-aba-unica');

    async function refresh() {
        const description = describeDeviceStatus({
            armazenamento,
            semTravaDeAba: abaUnica === ABA.SEM_TRAVA,
            protegido: await readStorageProtection(windowRef.navigator),
            copiaOffline: await readOfflineCopy({
                navigatorRef: windowRef.navigator,
                cachesRef: windowRef.caches,
                pageUrl: windowRef.location.href,
                isSecureContext: windowRef.isSecureContext === true,
            }),
        });
        storageLine.textContent = description.armazenamento;
        offlineLine.textContent = description.copiaOffline;
        singleTabLine.textContent = description.abaUnica || '';
        singleTabLine.hidden = description.abaUnica === null;
        storageLine.parentElement.classList.toggle('estado-aparelho-ok', description.tudoCerto);
    }

    function refreshQuietly() {
        refresh().catch(() => {
            // The lines keep their previous text.
        });
    }

    refreshQuietly();
    for (const pending of refreshAfter) {
        pending.then(refreshQuietly, refreshQuietly);
    }
    // The service worker finishes installing in the background, some
    // seconds after the page. "controllerchange" is the moment it takes
    // charge; the timer covers everything that has no event of its own.
    if (windowRef.navigator.serviceWorker) {
        windowRef.navigator.serviceWorker.addEventListener('controllerchange', refreshQuietly);
    }
    windowRef.setInterval(refreshQuietly, DEVICE_STATUS_REFRESH_MS);
}
