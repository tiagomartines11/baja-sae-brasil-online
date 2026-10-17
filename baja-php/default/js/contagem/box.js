// Pit lane and fueling station page controller. Same local store, queue and
// status bar as the count page. A device that has never chosen is asked
// which area and whether entry or exit; one that has opens directly in the
// mode it used last. After that every tap on a car records that event. The
// decisions are in pit-session.js and box-toques.js; this file connects
// them to the DOM.
//
// FOR THE RACE SIMULATION: while the prova allows simulation, this page puts
// one object on `window.contagemPagina`:
//
//     await window.contagemPagina.registrarParada({ car: 41, tipo: 'box_entrada', tsMs: 1760000000000 });
//
// It is the same function a tap on a car ends in (`recordEvent` below). The
// only differences are the time (`tsMs`, in SERVER time, instead of "now")
// and the mode (`modo`, 'simulacao' unless given).

import { startPage } from './page-setup.js';
import {
    PitSession, PitFlow, PASSO, AREA, SENTIDO, orderCarsForMode, partsOfTipo, tipoFor,
} from './pit-session.js';
import { generateUuid } from './uuid.js';
import { buildPitEventsCsv } from './csv-export.js';
import { createFeedback, recordThenPrepareSound } from './feedback.js';
import { NumberBuffer } from './entry-modes.js';
import { carListIsKnown, teamNameOf } from './warnings.js';
import {
    createPitLists, describeRecordedEvent, describeReopened, TEXTO_ABASTECIMENTO_DESLIGADO, TEXTO_NAO_GUARDOU,
} from './box-listas.js';
import { TapGuard, TileLayoutHold, LAYOUT_HOLD_MS } from './box-toques.js';
import { RecordingBlockedError, TEXTO_BLOQUEIO } from './access.js';

const MODO_SIMULACAO = 'simulacao';

async function main() {
    const page = await startPage({ buildExportCsv: (store) => buildPitEventsCsv(store.allPitEvents()) });
    const { store, queue, clock, monotonicNow, preferences } = page;
    const element = (id) => document.getElementById(id);

    const session = new PitSession({ store, clock, generateUuid, recordingBlocked: page.recordingBlocked });
    const flow = new PitFlow({ preferences, fuelingEnabled: page.fuelingEnabled() });
    // Creates the page's audio now, while the page is still loading: never
    // inside a tap (see feedback.js).
    const feedback = createFeedback({ preferences, windowRef: window, monotonicNow });
    const buffer = new NumberBuffer();
    const field = element('campo-numero');
    const tapGuard = new TapGuard();
    const layoutHold = new TileLayoutHold();

    // Always two lines high, right above the tiles (style sheet: .box-mensagem);
    // a longer text is cut on screen and kept whole in the title.
    function showMessage(text, isProblem) {
        const message = element('box-mensagem');
        message.textContent = text;
        message.title = text;
        message.className = isProblem ? 'box-mensagem box-mensagem-erro' : 'box-mensagem';
    }

    const lists = createPitLists({
        element,
        timeZone: page.config.fuso || null,
        getTeamName: (car) => teamNameOf(car, page.validCars(), page.unclassifiedCars()) || '',
        onTapCar: (car) => recordTappedCar(car),
    });

    // ---- recording an event ----

    /**
     * Every pit and fueling event goes through here: a tap on a car, the
     * "other number" box, and the simulation.
     *
     * @param {number} car
     * @param {string} tipo  one of TIPO_PARADA.*
     * @param {object} [options]
     * @param {?number} [options.tsMs] time of the event in SERVER time; left out = right now
     * @param {?string} [options.modo] 'simulacao' for a simulated event
     * @param {?string} [options.uuid] simulation only: see PitSession.recordEvent
     */
    async function recordEvent(car, tipo, { tsMs = null, modo = null, uuid = null } = {}) {
        let record;
        try {
            // The time of the event is taken inside, first thing.
            record = await session.recordEvent({ car, tipo, tsMs, mode: modo, uuid });
        } catch (storageError) {
            if (storageError instanceof RecordingBlockedError) {
                // The banner, open at the top, says why.
                showMessage('NÃO REGISTRADO: ' + TEXTO_BLOQUEIO[storageError.motivo], true);
                throw storageError;
            }
            showMessage(TEXTO_NAO_GUARDOU(car), true);
            feedback.erro();
            throw storageError;
        }
        const anyListKnown = carListIsKnown(page.validCars()) || carListIsKnown(page.unclassifiedCars());
        const unknownNumber = anyListKnown && teamNameOf(car, page.validCars(), page.unclassifiedCars()) === null;
        showMessage(describeRecordedEvent(car, tipo, unknownNumber), unknownNumber);
        if (modo !== MODO_SIMULACAO) {
            if (unknownNumber) {
                feedback.aviso();
            } else {
                feedback.registrado();
            }
        }
        render();
        queue.requestSync();
        return record;
    }

    function recordTappedCar(car) {
        const tipo = flow.tipo();
        const nowMs = monotonicNow();
        // Only a double tap on the SAME car is dropped. Two cars entering
        // together are two taps a moment apart, and both must be recorded.
        if (page.shiftEnded() || page.recordingBlocked() !== null || tipo === null || !tapGuard.accepts(car, nowMs)) {
            return;
        }
        // The tiles keep their places for a moment, so that the next tap
        // lands on the car it was aimed at; then they are put in order.
        layoutHold.noteTap(car, nowMs);
        window.setTimeout(render, LAYOUT_HOLD_MS + 20);
        // First the event, only then anything to do with sound (feedback.js).
        recordThenPrepareSound(() => recordEvent(car, tipo), feedback).catch(() => {});
    }

    // ---- the choices: area, then entry or exit ----

    function render() {
        const passo = flow.passo();
        const remembered = flow.lembrado();
        element('box-escolha').hidden = passo === PASSO.CARROS;
        element('escolha-area').hidden = passo !== PASSO.AREA;
        element('escolha-sentido').hidden = passo !== PASSO.SENTIDO;
        element('box-registro').hidden = passo !== PASSO.CARROS;
        // The switches stay visible while recording: one person doing both
        // ends changes mode with one tap. The area switch only exists when
        // the prova logs the fueling station.
        element('chave-area').hidden = !flow.fuelingEnabled;
        for (const button of document.querySelectorAll('[data-area]')) {
            const isCurrent = button.dataset.area === flow.area;
            button.setAttribute('aria-pressed', isCurrent ? 'true' : 'false');
            button.classList.toggle('escolha-lembrada', flow.area === null && button.dataset.area === remembered.area);
        }
        for (const button of document.querySelectorAll('[data-sentido]')) {
            const isCurrent = button.dataset.sentido === flow.sentido;
            button.setAttribute('aria-pressed', isCurrent ? 'true' : 'false');
            button.classList.toggle('escolha-lembrada', flow.sentido === null && button.dataset.sentido === remembered.sentido);
        }
        if (passo !== PASSO.CARROS) {
            lists.renderRecent(session.recentEvents());
            return;
        }
        const carsInside = session.carsInside(flow.area, page.serverCarsInside(flow.area));
        const freshGroups = orderCarsForMode({
            // Classified cars only; a car that is inside is added by
            // orderCarsForMode whatever list it is (or is not) in.
            listedCars: (page.validCars() || []).map((car) => car.numero),
            carsInside,
            sentido: flow.sentido,
        });
        const layout = layoutHold.layoutFor(flow.tipo(), freshGroups, monotonicNow());
        lists.render({
            area: flow.area,
            sentido: flow.sentido,
            groups: layout.groups,
            tappedCars: layout.tappedCars,
            recentEvents: session.recentEvents(),
        });
    }

    for (const button of document.querySelectorAll('[data-area]')) {
        button.addEventListener('click', () => {
            flow.chooseArea(button.dataset.area);
            render();
        });
    }
    for (const button of document.querySelectorAll('[data-sentido]')) {
        button.addEventListener('click', () => {
            flow.chooseSentido(button.dataset.sentido);
            render();
        });
    }

    // ---- a number that has no tile (not classified, not in the list) ----

    field.addEventListener('input', () => {
        buffer.setText(field.value, monotonicNow());
        if (field.value !== buffer.text()) {
            field.value = buffer.text();
        }
    });
    element('form-box').addEventListener('submit', (event) => {
        event.preventDefault();
        const confirmed = buffer.confirm(monotonicNow());
        field.value = '';
        if (confirmed !== null) {
            recordTappedCar(confirmed.car);
        }
    });

    page.onState(() => {
        if (flow.setFuelingEnabled(page.fuelingEnabled())) {
            showMessage(TEXTO_ABASTECIMENTO_DESLIGADO, true);
        }
        render();
    });
    if (flow.abriuNoModoLembrado) {
        // No question was asked, so the page says what it assumed. The
        // heading says it too; this line adds where it came from (the
        // Entrada / Saída switch right above changes it).
        showMessage(describeReopened(flow.area, flow.sentido), false);
    }
    element('carregando').hidden = true;
    element('conteudo').hidden = false;

    // The simulation's way in (see the top of this file).
    page.exposeForSimulation({
        tipo: 'box',
        config: page.config,
        /** ServerClock: `relogio.serverNowMs()` is the official time (null until synced). */
        relogio: clock,
        /** SyncQueue: `fila.flush()` resolves when everything recorded so far was offered to the server. */
        fila: queue,
        sessao: session,
        /** LocalStore: `armazenamento.getMeta('servidor')` is the last sync answer (race start, lists). */
        armazenamento: store,
        carros: page.validCars,
        naoClassificados: page.unclassifiedCars,
        janelaMs: page.janelaMs,
        abastecimento: page.fuelingEnabled,
        AREA,
        SENTIDO,
        tipoFor,
        /**
         * @param {object}  pitEvent
         * @param {number}  pitEvent.car
         * @param {string}  pitEvent.tipo   'box_entrada' | 'box_saida' | 'abastecimento_entrada' | 'abastecimento_saida'
         * @param {?number} [pitEvent.tsMs] intended time, SERVER time in ms; left out = now
         * @param {string}  [pitEvent.modo] 'simulacao' unless given
         * @param {?string} [pitEvent.uuid] the record is stored under exactly
         *        this uuid; the same uuid a second time records nothing and
         *        returns the record already there
         * @returns {Promise<object>} the stored record
         */
        registrarParada({ car, tipo, tsMs = null, modo = MODO_SIMULACAO, uuid = null }) {
            if (partsOfTipo(tipo) === null) {
                return Promise.reject(new Error('Tipo de parada desconhecido: ' + tipo));
            }
            return recordEvent(car, tipo, { tsMs, modo, uuid });
        },
        /**
         * While true, this page never tells the server "you have everything
         * up to now" (see SyncQueue.suprimirMarcaDeFila).
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
    loading.textContent = 'Não foi possível abrir o registro de box neste aparelho (' + startupError.message
        + '). Recarregue a página; se continuar, avise o responsável pela prova.';
});
