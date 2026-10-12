// Count page controller: connects buttons, keys and lists to CountSession.
// All decisions live in the modules it imports; this file only moves data
// between them and the DOM.
//
// FOR THE RACE SIMULATION: while the prova allows simulation, this page puts
// one object on `window.contagemPagina`:
//
//     await window.contagemPagina.registrarPassagem({ car: 41, tsMs: 1760000000000 });
//
// It is the same function a key press ends in (`recordPass` below): local
// store, big flash, recent list, upload queue. The only differences are the
// time (`tsMs`, in SERVER time, instead of "now") and the mode (`modo`,
// 'simulacao' unless given). See exposeForSimulation at the end of main().

import { startPage } from './page-setup.js';
import { CountSession } from './count-session.js';
import { generateUuid } from './uuid.js';
import { buildEntriesCsv } from './csv-export.js';
import { createFeedback, canVibrate, recordThenPrepareSound } from './feedback.js';
import { carListIsKnown } from './warnings.js';
import { createRecentList } from './contar-recentes.js';
import { wireCountKeys } from './contar-teclas.js';
import { createTilesPanel } from './contar-blocos.js';
import { TilesControl } from './tiles-control.js';
import {
    MODO, MODOS, TECLA, NumberBuffer, loadEntryMode, saveEntryMode,
} from './entry-modes.js';

const FLASH_CLASS = 'destaque-ativo';
const KEYPAD_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'apagar', '0', 'registrar'];
const MODO_SIMULACAO = 'simulacao';

async function main() {
    const page = await startPage({ buildExportCsv: (store) => buildEntriesCsv(store.allEntries()) });
    const { store, queue, clock, monotonicNow, preferences } = page;
    const element = (id) => document.getElementById(id);

    const session = new CountSession({
        store,
        clock,
        generateUuid,
        getValidCars: page.validCars,
        getUnclassifiedCars: page.unclassifiedCars,
        getJanelaMs: page.janelaMs,
    });
    const feedback = createFeedback({ preferences, windowRef: window, monotonicNow });
    const buffer = new NumberBuffer();
    const deviceHasTouch = window.matchMedia('(pointer: coarse)').matches;
    let currentMode = loadEntryMode(preferences, deviceHasTouch);

    const recentList = createRecentList({
        listElement: element('lista-recentes'),
        session,
        timeZone: page.config.fuso || null,
        onUndo: async (uuid) => {
            await session.undo(uuid);
            recentList.render();
            queue.requestSync();
        },
    });

    // ---- recording a pass ----

    /** @param {string} level '' (fine), 'aviso' (look again) or 'erro' (something is wrong) */
    function showFlash(numberText, teamText, warningTexts, level) {
        const flash = element('destaque');
        element('destaque-numero').textContent = numberText;
        element('destaque-equipe').textContent = teamText;
        const warningsBox = element('destaque-avisos');
        warningsBox.replaceChildren();
        for (const text of warningTexts) {
            const line = document.createElement('p');
            line.textContent = text;
            warningsBox.appendChild(line);
        }
        flash.classList.toggle('destaque-problema', level === 'aviso');
        flash.classList.toggle('destaque-erro', level === 'erro');
        // Removing and re-adding the class restarts the CSS animation, so two
        // identical entries in a row still flash twice.
        flash.classList.remove(FLASH_CLASS);
        void flash.offsetWidth;
        flash.classList.add(FLASH_CLASS);
    }

    /**
     * Every pass goes through here: Enter, the keypad's OK, a tile, "Passou",
     * and the simulation.
     *
     * @param {number}  car
     * @param {?number} typingMs
     * @param {object}  [options]
     * @param {?number} [options.tsMs] time of the pass in SERVER time; left out = right now
     * @param {string}  [options.modo] how it was entered; left out = the mode on screen
     * @param {?string} [options.uuid] simulation only: see CountSession.recordPass
     */
    async function recordPass(car, typingMs, { tsMs = null, modo = currentMode, uuid = null } = {}) {
        const simulated = modo === MODO_SIMULACAO;
        let result;
        try {
            // The time of the pass is taken inside, first thing.
            result = await session.recordPass({ car, typingMs, mode: modo, tsMs, uuid });
        } catch (storageError) {
            // The only case in which a typed number is NOT kept. It must be
            // impossible to miss.
            showFlash(String(car), 'NÃO REGISTRADO', ['O aparelho não conseguiu guardar. Digite de novo.'], 'erro');
            feedback.erro();
            throw storageError;
        }
        const warningTexts = result.avisos.map((aviso) => aviso.mensagem);
        const serious = result.avisos.some((aviso) => aviso.grave);
        // With no car list at all, "unknown number" would be shown for every
        // car; the page then simply shows no team name.
        const anyListKnown = carListIsKnown(page.validCars()) || carListIsKnown(page.unclassifiedCars());
        const nameWhenNotFound = anyListKnown ? 'Número desconhecido' : '';
        const level = serious ? 'erro' : (warningTexts.length > 0 ? 'aviso' : '');
        showFlash(String(car), result.nomeEquipe || nameWhenNotFound, warningTexts, level);
        // A simulated race is hundreds of passes a minute; it stays silent.
        if (!simulated) {
            if (serious) {
                feedback.erro();
            } else if (warningTexts.length > 0) {
                feedback.aviso();
            } else {
                feedback.registrado();
            }
        }
        recentList.render();
        queue.requestSync();
        return result;
    }

    /** For event handlers: the failure was already shown by recordPass. */
    function recordTypedPass(pass) {
        if (page.shiftEnded()) {
            // The entry controls are hidden; a stray Enter must not record.
            return;
        }
        // First the pass (its time is taken and its disk write requested
        // before recordPass returns), only then anything to do with sound.
        recordThenPrepareSound(() => recordPass(pass.car, pass.typingMs), feedback).catch(() => {});
    }

    function confirmBuffer() {
        const confirmed = page.shiftEnded() ? null : buffer.confirm(monotonicNow());
        renderBuffer();
        if (confirmed !== null) {
            recordTypedPass({ car: confirmed.car, typingMs: Math.round(confirmed.typingMs) });
        }
    }

    function renderBuffer() {
        element('visor-numero').textContent = buffer.isEmpty() ? ' ' : buffer.text();
        const field = element('campo-numero');
        if (field.value !== buffer.text()) {
            field.value = buffer.text();
        }
    }

    // ---- mode 1: system keyboard ----
    // Typing inside the number box is handled here. Keys pressed while the
    // focus is anywhere else are handled by the page-wide listener below.

    element('campo-numero').addEventListener('input', (event) => {
        buffer.setText(event.target.value, monotonicNow());
        renderBuffer();
    });
    element('form-teclado').addEventListener('submit', (event) => {
        event.preventDefault();
        confirmBuffer();
        element('campo-numero').focus();
    });

    // ---- mode 2: on-screen keypad ----

    function pressKeypadKey(key) {
        if (key === 'registrar') {
            confirmBuffer();
            return;
        }
        if (key === 'apagar') {
            buffer.backspace();
        } else {
            buffer.pressDigit(key, monotonicNow());
        }
        renderBuffer();
    }

    const keypad = element('teclado-tela');
    for (const key of KEYPAD_KEYS) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'tecla tecla-' + (/^[0-9]$/.test(key) ? 'digito' : key);
        button.textContent = key === 'apagar' ? '⌫' : (key === 'registrar' ? 'OK' : key);
        button.setAttribute('aria-label', key === 'apagar' ? 'Apagar' : (key === 'registrar' ? 'Registrar' : key));
        button.addEventListener('click', () => pressKeypadKey(key));
        keypad.appendChild(button);
    }

    // ---- both keyboard modes: a physical keyboard works wherever the focus is ----

    wireCountKeys({
        documentRef: document,
        numberField: element('campo-numero'),
        keypad,
        getMode: () => currentMode,
        isBlocked: () => page.shiftEnded(),
        onKey(action) {
            pressKeypadKey(action.tecla === TECLA.DIGITO ? action.digito : action.tecla);
            if (currentMode === MODO.TECLADO) {
                // Back to the box, so the following keys take the normal path.
                element('campo-numero').focus();
            }
        },
    });

    // ---- mode 3: one tile per car ----

    const tiles = createTilesPanel({
        element,
        control: new TilesControl({ preferences, monotonicNow }),
        // Classified cars only; an unclassified car can still be typed.
        getCars: () => page.validCars() || [],
        confirm: (question) => window.confirm(question),
        onPass: recordTypedPass,
    });

    // ---- switching modes ----

    function applyMode(mode) {
        currentMode = mode;
        buffer.clear();
        renderBuffer();
        tiles.clearSelection();
        for (const candidate of MODOS) {
            element('modo-' + candidate).hidden = candidate !== mode;
        }
        for (const button of document.querySelectorAll('[data-modo]')) {
            button.setAttribute('aria-pressed', button.dataset.modo === mode ? 'true' : 'false');
        }
        if (mode === MODO.TECLADO) {
            element('campo-numero').focus();
        }
    }

    for (const button of document.querySelectorAll('[data-modo]')) {
        button.addEventListener('click', () => {
            saveEntryMode(preferences, button.dataset.modo);
            applyMode(button.dataset.modo);
        });
    }

    // ---- sound and vibration switches ----

    // iPhones cannot vibrate; a switch that does nothing is not offered.
    element('opt-vibrar').closest('label').hidden = !canVibrate(window.navigator);

    for (const [checkboxId, preferenceName] of [['opt-som', 'som'], ['opt-vibrar', 'vibrar']]) {
        const checkbox = element(checkboxId);
        checkbox.checked = preferences.get(preferenceName, true);
        checkbox.addEventListener('change', () => preferences.set(preferenceName, checkbox.checked));
    }

    page.onState(() => {
        tiles.render();
        recentList.render();
    });
    applyMode(currentMode);
    element('carregando').hidden = true;
    element('conteudo').hidden = false;
    if (currentMode === MODO.TECLADO) {
        element('campo-numero').focus();
    }

    // The simulation's way in (see the top of this file).
    page.exposeForSimulation({
        tipo: 'contar',
        config: page.config,
        /** ServerClock: `relogio.serverNowMs()` is the official time (null until synced). */
        relogio: clock,
        /** SyncQueue: `fila.flush()` resolves when everything typed so far was offered to the server. */
        fila: queue,
        sessao: session,
        /** LocalStore: `armazenamento.getMeta('servidor')` is the last sync answer (race start, lists). */
        armazenamento: store,
        carros: page.validCars,
        naoClassificados: page.unclassifiedCars,
        janelaMs: page.janelaMs,
        abastecimento: page.fuelingEnabled,
        /**
         * @param {object}  pass
         * @param {number}  pass.car
         * @param {?number} [pass.tsMs]     intended time, SERVER time in ms; left out = now
         * @param {string}  [pass.modo]     'simulacao' unless given
         * @param {?number} [pass.typingMs]
         * @param {?string} [pass.uuid]     the record is stored under exactly
         *        this uuid; the same uuid a second time records nothing and
         *        returns the entry already there
         * @returns {Promise<{entry: object, avisos: Array, nomeEquipe: ?string}>}
         */
        registrarPassagem({ car, tsMs = null, modo = MODO_SIMULACAO, typingMs = null, uuid = null }) {
            return recordPass(car, typingMs, { tsMs, modo, uuid });
        },
        /**
         * While true, this page never tells the server "you have everything
         * up to now" (a replay injects passes that lie in the past; see
         * SyncQueue.suprimirMarcaDeFila). Switch it off when the replay ends.
         * @param {boolean} suprimir
         */
        suprimirMarcaDeFila(suprimir) {
            queue.suprimirMarcaDeFila(suprimir);
        },
    });
}

main().catch((startupError) => {
    const loading = document.getElementById('carregando');
    loading.hidden = false;
    loading.textContent = 'Não foi possível abrir a contagem neste aparelho (' + startupError.message
        + '). Recarregue a página; se continuar, avise o responsável pela prova.';
});
