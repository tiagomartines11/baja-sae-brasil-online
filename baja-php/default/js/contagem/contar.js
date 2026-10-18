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
// `car: null` records a "??"; `corrigirPassagem({ uuid, car })` gives one
// its number, as "Informar número" does.

import { startPage } from './page-setup.js';
import { CountSession } from './count-session.js';
import { generateUuid } from './uuid.js';
import { buildEntriesCsv } from './csv-export.js';
import { createFeedback, canVibrate, recordThenPrepareSound } from './feedback.js';
import { carListIsKnown, effectiveCar } from './warnings.js';
import { RecordingBlockedError, TEXTO_BLOQUEIO } from './access.js';
import { createRecentList, timeText } from './contar-recentes.js';
import {
    correctionBannerText, correctionEndedFlash, createFlash, fixFlash, instructionFlash, notRecordedFlash,
    passFlash, undoFlash,
} from './contar-destaque.js';
import { CorrectionMode, FIM_CORRECAO } from './correction-mode.js';
import { wireCountKeys } from './contar-teclas.js';
import { createTilesPanel } from './contar-blocos.js';
import { TilesControl } from './tiles-control.js';
import {
    MODO, MODOS, TECLA, NumberBuffer, loadEntryMode, saveEntryMode, typedUnreadMark,
} from './entry-modes.js';

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
        recordingBlocked: page.recordingBlocked,
    });
    // Creates the page's audio now, while the page is still loading: never
    // inside a key press (see feedback.js).
    const feedback = createFeedback({ preferences, windowRef: window, monotonicNow });
    const buffer = new NumberBuffer();
    const deviceHasTouch = window.matchMedia('(pointer: coarse)').matches;
    let currentMode = loadEntryMode(preferences, deviceHasTouch);

    // Always the same size, whatever it says (see contar-destaque.js).
    const flash = createFlash({
        box: element('destaque'),
        number: element('destaque-numero'),
        team: element('destaque-equipe'),
        warning: element('destaque-aviso'),
        warningShort: element('destaque-aviso-curto'),
        warningFull: element('destaque-aviso-completo'),
    });

    // "Informar número" on a "??" (see correction-mode.js).
    const correction = new CorrectionMode({
        onChange: () => renderCorrection(),
        onEnd: (ended) => {
            endCorrection(ended).catch(() => {});
        },
    });

    const recentList = createRecentList({
        listElement: element('lista-recentes'),
        session,
        timeZone: page.config.fuso || null,
        correctingUuid: () => correction.uuid(),
        onInform: (entry) => startCorrection(entry),
        onUndo: async (uuid) => {
            const undone = store.findEntry(uuid);
            correction.forget(uuid);
            await session.undo(uuid);
            if (undone) {
                flash.show(undoFlash(effectiveCar(undone)));
            }
            recentList.render();
            queue.requestSync();
        },
    });

    // ---- recording a pass ----

    /**
     * Every pass goes through here: Enter, the keypad's OK, a tile, "Passou",
     * and the simulation.
     *
     * @param {?number} car  null for "??"
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
            if (storageError instanceof RecordingBlockedError) {
                // The controls are hidden; this is a key typed anyway, or
                // the simulation. Say why nothing was recorded.
                flash.show(notRecordedFlash(car, TEXTO_BLOQUEIO[storageError.motivo]));
                throw storageError;
            }
            // The only case in which a typed number is NOT kept. It must be
            // impossible to miss.
            flash.show(notRecordedFlash(car));
            feedback.erro();
            throw storageError;
        }
        const serious = result.avisos.some((aviso) => aviso.grave);
        const anyListKnown = carListIsKnown(page.validCars()) || carListIsKnown(page.unclassifiedCars());
        flash.show(passFlash({ car, avisos: result.avisos, nomeEquipe: result.nomeEquipe, anyListKnown }));
        // A simulated race is hundreds of passes a minute; it stays silent.
        if (!simulated) {
            if (serious) {
                feedback.erro();
            } else if (result.avisos.length > 0 || car === null) {
                // "??" sounds like a warning: something is left to do.
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
        if (page.shiftEnded() || page.recordingBlocked() !== null) {
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
        if (confirmed === null) {
            return;
        }
        // While correcting a "??", the number typed is its number: no pass.
        if (correction.pressCar(confirmed.car) === null) {
            recordTypedPass({ car: confirmed.car, typingMs: Math.round(confirmed.typingMs) });
        }
    }

    /**
     * "??": a car crossed and its number could not be read. Recorded at
     * once, like Enter, and whatever was typed is thrown away. (It does
     * nothing while correcting: "Passou um carro" records then.)
     */
    function pressUnread() {
        if (correction.active()) {
            return;
        }
        buffer.clear();
        renderBuffer();
        recordTypedPass({ car: null, typingMs: null });
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
        if (typedUnreadMark(event.target.value)) {
            // "?" typed in the box: the "??" button. The "?" itself goes.
            event.target.value = buffer.text();
            pressUnread();
            return;
        }
        buffer.setText(event.target.value, monotonicNow());
        renderBuffer();
    });
    element('btn-ilegivel').addEventListener('click', () => {
        pressUnread();
        element('campo-numero').focus();
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

    element('btn-ilegivel-tela').addEventListener('click', (event) => {
        event.currentTarget.blur();
        pressUnread();
    });

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
        isBlocked: () => page.shiftEnded() || page.recordingBlocked() !== null,
        onKey(action) {
            if (action.tecla === TECLA.ILEGIVEL) {
                pressUnread();
            } else {
                pressKeypadKey(action.tecla === TECLA.DIGITO ? action.digito : action.tecla);
            }
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
        isCorrecting: () => correction.active(),
        onCorrect: (car) => correction.pressCar(car),
    });

    // ---- correcting a "??" (correction-mode.js) ----

    function startCorrection(entry) {
        if (page.shiftEnded() || page.recordingBlocked() !== null) {
            return;
        }
        buffer.clear();
        renderBuffer();
        tiles.clearSelection();
        correction.start(entry);
        if (currentMode === MODO.TECLADO) {
            element('campo-numero').focus();
        }
    }

    /** The banner over the big flash, and what may be pressed meanwhile. */
    function renderCorrection() {
        const entry = correction.entry();
        // Digits typed for a correction that ended (timeout, Cancelar) must
        // not become a pass at the next Enter.
        buffer.clear();
        renderBuffer();
        element('correcao').hidden = entry === null;
        document.body.classList.toggle('corrigindo', entry !== null);
        if (entry !== null) {
            element('correcao-texto').textContent = correctionBannerText(
                entry, currentMode, timeText(entry, page.config.fuso || null),
            );
        }
        // Disabled, not hidden: nothing on the screen moves.
        for (const id of ['btn-ilegivel', 'btn-ilegivel-tela']) {
            element(id).disabled = entry !== null;
        }
        tiles.renderSelection();
        recentList.render();
    }

    async function endCorrection({ motivo, uuid, car }) {
        if (motivo === FIM_CORRECAO.PASSAGEM) {
            // The "??" of that crossing is already recorded, and on the flash.
            return;
        }
        if (motivo !== FIM_CORRECAO.CORRIGIDO) {
            flash.show(correctionEndedFlash(motivo));
            return;
        }
        await applyFix(uuid, car);
    }

    /** Stores and queues a correction. Also the simulation's way in. */
    async function applyFix(uuid, car) {
        let result;
        try {
            result = await session.fixUnread(uuid, car);
        } catch (storageError) {
            const blocked = storageError instanceof RecordingBlockedError;
            flash.show(notRecordedFlash(car, blocked ? TEXTO_BLOQUEIO[storageError.motivo] : null));
            feedback.erro();
            throw storageError;
        }
        flash.show(result === null ? correctionEndedFlash(FIM_CORRECAO.CANCELADO) : fixFlash({ car, nomeEquipe: result.nomeEquipe }));
        recentList.render();
        queue.requestSync();
        return result;
    }

    element('btn-correcao-cancelar').addEventListener('click', () => correction.cancel());
    element('btn-correcao-passou').addEventListener('click', (event) => {
        event.currentTarget.blur();
        // The crossing first (its time is this tap), then the mode ends.
        recordTypedPass({ car: null, typingMs: null });
        correction.escapeToPass();
    });

    // ---- switching modes ----

    function applyMode(mode) {
        // "Toque no carro" / "digite o número" belong to the mode the
        // correction was started in: changing mode ends it.
        correction.cancel();
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
        if (flash.showsInstruction()) {
            flash.show(instructionFlash(mode));
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
        checkbox.addEventListener('change', () => {
            preferences.set(preferenceName, checkbox.checked);
            // A page that loaded with the sound off made no audio; turning
            // "Som" on makes it here, not in the next key press.
            feedback.carregarSom();
        });
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
         * @param {?number} pass.car        null for "??"
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
         * "Informar número" on a "??" of this page, without the screen's
         * correction mode.
         * @param {object} fix
         * @param {string} fix.uuid  the "??" entry
         * @param {number} fix.car
         * @returns {Promise<?object>} null when that entry cannot be corrected
         */
        corrigirPassagem({ uuid, car }) {
            return applyFix(uuid, car);
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
