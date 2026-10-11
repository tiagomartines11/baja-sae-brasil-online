// Count page controller: connects buttons, keys and lists to CountSession.
// All decisions live in the modules it imports; this file only moves data
// between them and the DOM.

import { startPage } from './page-setup.js';
import { CountSession } from './count-session.js';
import { generateUuid } from './uuid.js';
import { buildEntriesCsv } from './csv-export.js';
import { createFeedback, canVibrate } from './feedback.js';
import { carListIsKnown } from './warnings.js';
import { createRecentList } from './contar-recentes.js';
import {
    MODO, MODOS, TECLA, NumberBuffer, keyActionFor, loadEntryMode, saveEntryMode,
} from './entry-modes.js';

const FLASH_CLASS = 'destaque-ativo';
const KEYPAD_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'apagar', '0', 'registrar'];

async function main() {
    const page = await startPage({ buildExportCsv: (store) => buildEntriesCsv(store.allEntries()) });
    const { store, queue, clock, now, preferences } = page;
    const element = (id) => document.getElementById(id);

    const session = new CountSession({
        store,
        clock,
        now,
        generateUuid,
        getValidCars: page.validCars,
        getJanelaMs: page.janelaMs,
    });
    const feedback = createFeedback({ preferences, windowRef: window });
    const buffer = new NumberBuffer();
    const deviceHasTouch = window.matchMedia('(pointer: coarse)').matches;
    let currentMode = loadEntryMode(preferences, deviceHasTouch);

    const recentList = createRecentList({
        listElement: element('lista-recentes'),
        session,
        onUndo: async (uuid) => {
            await session.undo(uuid);
            recentList.render();
            queue.requestSync();
        },
    });

    // ---- recording a pass ----

    function showFlash(numberText, teamText, warningTexts, isProblem) {
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
        flash.classList.toggle('destaque-problema', isProblem);
        // Removing and re-adding the class restarts the CSS animation, so two
        // identical entries in a row still flash twice.
        flash.classList.remove(FLASH_CLASS);
        void flash.offsetWidth;
        flash.classList.add(FLASH_CLASS);
    }

    async function recordPass(car, typingMs) {
        let result;
        try {
            result = await session.recordPass({ car, typingMs, mode: currentMode });
        } catch (storageError) {
            // The only case in which a typed number is NOT kept. It must be
            // impossible to miss.
            showFlash(String(car), 'NÃO REGISTRADO', ['O aparelho não conseguiu guardar. Digite de novo.'], true);
            feedback.erro();
            return;
        }
        const warningTexts = result.avisos.map((aviso) => aviso.mensagem);
        // With no car list at all, "unknown number" would be shown for every
        // car; the page then simply shows no team name.
        const nameWhenNotFound = carListIsKnown(page.validCars()) ? 'Número desconhecido' : '';
        showFlash(String(car), result.nomeEquipe || nameWhenNotFound, warningTexts, warningTexts.length > 0);
        if (warningTexts.length > 0) {
            feedback.aviso();
        } else {
            feedback.registrado();
        }
        recentList.render();
        queue.requestSync();
    }

    function confirmBuffer() {
        if (page.shiftEnded()) {
            // The entry controls are hidden; a stray Enter must not record.
            return;
        }
        const confirmed = buffer.confirm(now());
        renderBuffer();
        if (confirmed !== null) {
            recordPass(confirmed.car, confirmed.typingMs);
        }
    }

    function renderBuffer() {
        element('visor-numero').textContent = buffer.isEmpty() ? ' ' : buffer.text();
        const field = element('campo-numero');
        if (field.value !== buffer.text()) {
            field.value = buffer.text();
        }
    }

    // ---- mode 1: system keyboard ----
    // Typing inside the number box is handled here. Keys pressed while the
    // focus is anywhere else are handled by the page-wide listener below.

    element('campo-numero').addEventListener('input', (event) => {
        buffer.setText(event.target.value, now());
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
            buffer.pressDigit(key, now());
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
    // After a tap on "desfazer", on a switch or on the page background the
    // number box no longer has the focus. The next car is typed without
    // looking, so those keys must still count.

    document.addEventListener('keydown', (event) => {
        if (page.shiftEnded()) {
            return;
        }
        const action = keyActionFor(event, {
            mode: currentMode,
            typedInNumberBox: event.target === element('campo-numero'),
        });
        if (action === null) {
            return;
        }
        // Stops the key from also doing what it normally does where the
        // focus is: Enter would "click" the button that was tapped last.
        event.preventDefault();
        if (action.tecla === TECLA.DIGITO) {
            pressKeypadKey(action.digito);
        } else {
            pressKeypadKey(action.tecla);
        }
        if (currentMode === MODO.TECLADO) {
            // Back to the box, so the following keys take the normal path.
            element('campo-numero').focus();
        }
    });

    // ---- mode 3: one tile per car ----

    let renderedTilesSignature = null;

    function renderTiles() {
        const cars = (page.validCars() || []).slice().sort((first, second) => first.numero - second.numero);
        const signature = cars.map((car) => car.numero).join(',');
        if (signature === renderedTilesSignature) {
            return;
        }
        renderedTilesSignature = signature;
        const container = element('blocos');
        container.replaceChildren();
        for (const car of cars) {
            const tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'bloco';
            tile.textContent = String(car.numero);
            tile.title = car.nome;
            tile.addEventListener('click', () => recordPass(car.numero, null));
            container.appendChild(tile);
        }
        element('blocos-vazio').hidden = cars.length > 0;
    }

    // ---- switching modes ----

    function applyMode(mode) {
        currentMode = mode;
        buffer.clear();
        renderBuffer();
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
        renderTiles();
        recentList.render();
    });
    applyMode(currentMode);
    element('carregando').hidden = true;
    element('conteudo').hidden = false;
    if (currentMode === MODO.TECLADO) {
        element('campo-numero').focus();
    }
}

main().catch((startupError) => {
    const loading = document.getElementById('carregando');
    loading.hidden = false;
    loading.textContent = 'Não foi possível abrir a contagem neste aparelho (' + startupError.message
        + '). Recarregue a página; se continuar, avise o responsável pela prova.';
});
