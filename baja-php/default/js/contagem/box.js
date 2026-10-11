// Pit log page controller: pick a car, then "Entrou" or "Saiu".
// Same local store, queue and status bar as the count page.

import { startPage } from './page-setup.js';
import { PitSession, TIPO_BOX } from './pit-session.js';
import { generateUuid } from './uuid.js';
import { buildPitEventsCsv } from './csv-export.js';
import { createFeedback } from './feedback.js';
import { NumberBuffer } from './entry-modes.js';
import { teamNameOf } from './warnings.js';
import { formatTimeOfDay } from './race-clock.js';

const ROTULO_TIPO = {
    [TIPO_BOX.ENTRADA]: 'entrou',
    [TIPO_BOX.SAIDA]: 'saiu',
};

function textNode(tagName, className, text) {
    const node = document.createElement(tagName);
    node.className = className;
    node.textContent = text;
    return node;
}

async function main() {
    const page = await startPage({ buildExportCsv: (store) => buildPitEventsCsv(store.allPitEvents()) });
    const { store, queue, clock, now, preferences } = page;
    const element = (id) => document.getElementById(id);

    const session = new PitSession({ store, clock, now, generateUuid });
    const feedback = createFeedback({ preferences, windowRef: window });
    const buffer = new NumberBuffer();
    const field = element('campo-numero');
    let renderedSignature = null;

    function selectCar(carNumber) {
        buffer.setText(String(carNumber), now());
        field.value = buffer.text();
        renderSelection();
    }

    function renderSelection() {
        const hasNumber = !buffer.isEmpty();
        element('btn-entrou').disabled = !hasNumber;
        element('btn-saiu').disabled = !hasNumber;
        const teamName = hasNumber ? teamNameOf(parseInt(buffer.text(), 10), page.validCars()) : null;
        element('box-equipe').textContent = hasNumber ? (teamName || 'Número desconhecido') : ' ';
    }

    function carButton(carNumber, className) {
        const button = textNode('button', className, String(carNumber));
        button.type = 'button';
        button.title = teamNameOf(carNumber, page.validCars()) || '';
        button.addEventListener('click', () => selectCar(carNumber));
        return button;
    }

    function serverCarsInPit() {
        const serverInfo = store.getMeta('servidor', null);
        return serverInfo ? serverInfo.cars_in_pit : [];
    }

    function renderLists() {
        const carsInPit = session.carsInPit(serverCarsInPit());
        const validCars = (page.validCars() || []).slice().sort((first, second) => first.numero - second.numero);
        const recentEvents = session.recentEvents();
        // Redrawn only when something visible changed (see contar-recentes.js).
        const signature = JSON.stringify([
            carsInPit,
            validCars.map((car) => car.numero),
            recentEvents.map((pitEvent) => [pitEvent.uuid, pitEvent.sent]),
        ]);
        if (signature === renderedSignature) {
            return;
        }
        renderedSignature = signature;

        const inPitList = element('lista-no-box');
        inPitList.replaceChildren();
        for (const carNumber of carsInPit) {
            inPitList.appendChild(carButton(carNumber, 'bloco bloco-no-box'));
        }
        element('no-box-vazio').hidden = carsInPit.length > 0;

        const tiles = element('blocos');
        tiles.replaceChildren();
        for (const car of validCars) {
            tiles.appendChild(carButton(car.numero, 'bloco'));
        }

        const recentList = element('lista-recentes');
        recentList.replaceChildren();
        for (const pitEvent of recentEvents) {
            const item = document.createElement('li');
            item.className = 'recente';
            item.appendChild(textNode('span', 'recente-numero', String(pitEvent.car)));
            item.appendChild(textNode('span', 'recente-equipe', ROTULO_TIPO[pitEvent.tipo]));
            item.appendChild(textNode('span', 'recente-hora', formatTimeOfDay(pitEvent.ts_device_ms)));
            item.appendChild(textNode('span', 'recente-estado', pitEvent.sent ? 'enviado' : 'na fila'));
            recentList.appendChild(item);
        }
    }

    async function recordEvent(tipo) {
        // Time is taken inside recordEvent, at the tap, before the disk write.
        const confirmed = buffer.confirm(now());
        if (confirmed === null) {
            return;
        }
        field.value = '';
        renderSelection();
        const message = element('box-mensagem');
        try {
            await session.recordEvent({ car: confirmed.car, tipo });
        } catch (storageError) {
            message.textContent = 'NÃO REGISTRADO: o aparelho não conseguiu guardar o carro ' + confirmed.car + '.';
            message.className = 'box-mensagem box-mensagem-erro';
            feedback.erro();
            return;
        }
        message.textContent = 'Carro ' + confirmed.car + ' ' + ROTULO_TIPO[tipo] + '.';
        message.className = 'box-mensagem';
        feedback.registrado();
        renderLists();
        queue.requestSync();
    }

    field.addEventListener('input', () => {
        buffer.setText(field.value, now());
        if (field.value !== buffer.text()) {
            field.value = buffer.text();
        }
        renderSelection();
    });
    element('form-box').addEventListener('submit', (event) => event.preventDefault());
    element('btn-entrou').addEventListener('click', () => recordEvent(TIPO_BOX.ENTRADA));
    element('btn-saiu').addEventListener('click', () => recordEvent(TIPO_BOX.SAIDA));

    page.onState(renderLists);
    renderSelection();
    element('carregando').hidden = true;
    element('conteudo').hidden = false;
}

main().catch((startupError) => {
    const loading = document.getElementById('carregando');
    loading.hidden = false;
    loading.textContent = 'Não foi possível abrir o registro de box neste aparelho (' + startupError.message
        + '). Recarregue a página; se continuar, avise o responsável pela prova.';
});
