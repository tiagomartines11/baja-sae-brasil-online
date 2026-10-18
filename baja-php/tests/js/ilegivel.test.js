// "??" ON THE COUNT PAGE: a car crossed and its number could not be read.
//
// Background: until now a counter who could not read a number had two bad
// choices: guess (a wrong car, which the review screen then has to undo)
// or type nothing (a missed pass, and its time lost). "??" records the
// crossing with its time and no car: the server matches it with what the
// other counters saw, and the counter can give the number afterwards
// ("Informar número", see correction-mode.test.js and sync-fixes.test.js).
//
// What these tests pin down: a "??" is recorded like any other pass (same
// time rules, disk first, uploaded with "car": null), it raises no number
// warning, the big flash stays within its fixed rows, every entry mode has
// a "??" control in a fixed place, and the recent list offers the
// correction on its row.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { AVISO, computeWarnings, effectiveCar } from '../../default/js/contagem/warnings.js';
import {
    NIVEL, correctionBannerText, correctionEndedFlash, fixFlash, notRecordedFlash, passFlash, undoFlash, unreadFlash,
} from '../../default/js/contagem/contar-destaque.js';
import { FIM_CORRECAO } from '../../default/js/contagem/correction-mode.js';
import { MODO, TECLA, keyActionFor, typedUnreadMark } from '../../default/js/contagem/entry-modes.js';
import { BLOCO_ILEGIVEL, TilesControl } from '../../default/js/contagem/tiles-control.js';
import { createTilesPanel } from '../../default/js/contagem/contar-blocos.js';
import { createRecentList } from '../../default/js/contagem/contar-recentes.js';
import { createPreferences } from '../../default/js/contagem/entry-modes.js';
import { RECENT_LIST_SIZE } from '../../default/js/contagem/count-session.js';
import { createDevice } from './helpers/fakes.js';

const CARS = [{ numero: 7, nome: 'Equipe Sete' }, { numero: 41, nome: 'Equipe Quarenta e Um' }];
const T = 1_760_000_000_000;

// The warning line of the big flash holds 30 characters (contar-destaque.test.js).
const SHORT_TEXT_BUDGET = 30;

// ---------------------------------------------------------------------------
// Recording
// ---------------------------------------------------------------------------

test('"??" is recorded at the press, on the disk, and uploaded with "car": null', async () => {
    // Situation: the counter presses "??" as a car crosses; the disk takes a
    // second to answer.
    const device = await createDevice();
    device.clock.advance(1000);
    const pressedAt = device.clock.serverNow();
    const pending = device.typeUnread();
    device.clock.advance(1000);
    const { entry, avisos, nomeEquipe } = await pending;

    // Expected: the time of the press (the same rule as Enter), no car, no
    // warning, no team; and on the server exactly that.
    assert.equal(entry.car, null);
    assert.equal(entry.ts_ms, pressedAt);
    assert.equal(entry.typing_ms, null);
    assert.deepEqual(avisos, []);
    assert.equal(nomeEquipe, null);

    await device.queue.syncOnce();
    const uploaded = device.server.receivedRequests[0].entries[0];
    assert.equal(uploaded.uuid, entry.uuid);
    assert.equal(uploaded.car, null);
    assert.ok('car' in uploaded, 'the field is there, null: "unread", not forgotten');
    assert.equal(uploaded.ts_ms, pressedAt);
    assert.equal(device.store.findEntry(entry.uuid).sent, true);
});

test('a "??" typed before the page knows the server time is held like any entry', async () => {
    const device = await createDevice({ anchored: false });
    const { entry } = await device.typeUnread();
    assert.equal(entry.ts_ms, null, 'held: no official time yet');
    assert.equal(device.store.heldCount(), 1);
});

test('"??" never warns: no REPETIDO for two in a row, nor for a car typed after one', async () => {
    // Situation: two unreadable cars 2 s apart, then car 7.
    const device = await createDevice();
    await device.store.setMeta('servidor', { valid_cars: CARS, janela_ms: 20_000 });
    const first = await device.typeUnread();
    device.clock.advance(2000);
    const second = await device.typeUnread();
    device.clock.advance(2000);
    const seven = await device.type(7);

    assert.deepEqual(first.avisos, []);
    assert.deepEqual(second.avisos, [], 'two "??" are two crossings, not a repeat');
    assert.deepEqual(seven.avisos, []);
    assert.deepEqual(computeWarnings({
        car: null, tsDeviceMs: T, ownEntries: [{ uuid: 'a', car: null, ts_device_ms: T - 1000, voided: false }],
        validCars: CARS, janelaMs: 20_000,
    }), [], 'nor "Número fora da lista"');
});

test('a corrected "??" counts as that car for the repeat warning', async () => {
    // Situation: a "??" corrected to 7, then 7 typed 3 s later. Its warning
    // is on THIS device's own entries only, like every warning.
    const device = await createDevice();
    await device.store.setMeta('servidor', { valid_cars: CARS, janela_ms: 20_000 });
    const unread = await device.typeUnread();
    await device.session.fixUnread(unread.entry.uuid, 7);
    device.clock.advance(3000);

    const seven = await device.type(7);

    assert.deepEqual(seven.avisos.map((aviso) => aviso.tipo), [AVISO.REPETIDO]);
    assert.equal(effectiveCar(device.store.findEntry(unread.entry.uuid)), 7);
});

// ---------------------------------------------------------------------------
// The big flash: three fixed rows, whatever it says
// ---------------------------------------------------------------------------

test('after "??" the flash says so in its fixed rows, in the warning colour', () => {
    const flash = unreadFlash();
    assert.equal(flash.numero, '??');
    assert.equal(flash.equipe, '?? registrado');
    assert.equal(flash.aviso.curta, 'Informe o número depois');
    assert.match(flash.aviso.completa[0], /^\?\? registrado — informe o número quando puder/);
    assert.equal(flash.nivel, NIVEL.AVISO);
    assert.deepEqual(passFlash({ car: null, avisos: [], nomeEquipe: null, anyListKnown: true }), flash,
        'every way of recording ends in passFlash');
});

test('the new short texts fit the single warning line, and the number row stays short', () => {
    const texts = [
        unreadFlash().aviso.curta,
        correctionEndedFlash(FIM_CORRECAO.TEMPO_ESGOTADO).aviso.curta,
    ];
    for (const text of texts) {
        assert.ok(text.length <= SHORT_TEXT_BUDGET, '"' + text + '" is ' + text.length + ' characters');
    }
    for (const flash of [unreadFlash(), notRecordedFlash(null), undoFlash(null), fixFlash({ car: 41, nomeEquipe: null })]) {
        assert.ok(flash.numero.length <= 4, 'the number row holds what a car number holds');
    }
    assert.equal(correctionEndedFlash(FIM_CORRECAO.CANCELADO).aviso, null);
});

test('a correction is not a pass: no green, its own colour, and what became of it', () => {
    assert.deepEqual(fixFlash({ car: 41, nomeEquipe: 'Equipe Quarenta e Um' }), {
        numero: '41', equipe: 'Corrigido: Equipe Quarenta e Um', aviso: null, nivel: NIVEL.CORRECAO,
    });
    assert.equal(fixFlash({ car: 99, nomeEquipe: null }).equipe, 'Corrigido: carro 99');
    assert.equal(correctionEndedFlash(FIM_CORRECAO.CANCELADO).equipe, 'Correção cancelada');
    assert.equal(correctionEndedFlash(FIM_CORRECAO.TEMPO_ESGOTADO).nivel, NIVEL.CORRECAO);
    assert.equal(undoFlash(null).equipe, 'Desfeito: ??');
    assert.equal(notRecordedFlash(null).numero, '??');
});

test('the correction banner names the entry and says what to do in the mode on screen', () => {
    const entry = { uuid: 'a', car: null, ts_ms: T };
    assert.equal(correctionBannerText(entry, MODO.BLOCOS, '14:03:07'), 'Corrigindo o registro das 14:03:07 — toque no carro');
    assert.equal(correctionBannerText(entry, MODO.TECLADO_TELA, '14:03:07'), 'Corrigindo o registro das 14:03:07 — digite o número');
    assert.equal(correctionBannerText(entry, MODO.TECLADO, '14:03:07'), 'Corrigindo o registro das 14:03:07 — digite o número');
    assert.equal(correctionBannerText({ ...entry, ts_ms: null }, MODO.BLOCOS, 'aguardando horário oficial'),
        'Corrigindo o registro sem horário — toque no carro');
});

// ---------------------------------------------------------------------------
// The "??" control in each entry mode
// ---------------------------------------------------------------------------

test('the "?" key is "??" wherever the focus is, and a held "?" is one press', () => {
    const away = { mode: MODO.TECLADO, typedInNumberBox: false };
    assert.deepEqual(keyActionFor({ key: '?' }, away), { tecla: TECLA.ILEGIVEL, digito: null });
    assert.deepEqual(keyActionFor({ key: '?' }, { mode: MODO.TECLADO_TELA, typedInNumberBox: false }),
        { tecla: TECLA.ILEGIVEL, digito: null });
    assert.equal(keyActionFor({ key: '?', repeat: true }, away).tecla, TECLA.NADA);
    // In the number box the box takes it, like a digit; the page sees a "?" in the text.
    assert.equal(keyActionFor({ key: '?' }, { mode: MODO.TECLADO, typedInNumberBox: true }), null);
    assert.equal(keyActionFor({ key: '?', repeat: true }, { mode: MODO.TECLADO, typedInNumberBox: true }).tecla, TECLA.NADA);
    assert.equal(typedUnreadMark('4?'), true);
    assert.equal(typedUnreadMark('41'), false);
    assert.equal(keyActionFor({ key: '?' }, { mode: MODO.BLOCOS, typedInNumberBox: false }), null, 'tiles have their tile');
});

function tilesControl({ oneTap = false } = {}) {
    let monoMs = 0;
    const control = new TilesControl({ preferences: createPreferences(null), monotonicNow: () => monoMs });
    control.setOneTap(oneTap);
    return { control, wait: (ms) => { monoMs += ms; } };
}

test('the "??" tile: one tap records at once with one-tap on; otherwise it is selected, then "Passou"', () => {
    const quick = tilesControl({ oneTap: true }).control;
    assert.deepEqual(quick.tapTile(null), { car: null, typingMs: null });

    const { control, wait } = tilesControl();
    assert.equal(control.tapTile(null), null, 'two taps: only selected');
    assert.equal(control.selected(), BLOCO_ILEGIVEL);
    wait(800);
    assert.deepEqual(control.tapPassou(), { car: null, typingMs: 800 });
    assert.equal(control.selected(), null);
});

/** Just enough DOM for the tiles panel and the recent list. */
function fakeNode(tag = 'div') {
    const listeners = {};
    const classes = new Set();
    const attributes = {};
    const node = {
        tag,
        type: '',
        title: '',
        hidden: false,
        disabled: false,
        checked: false,
        textContent: '',
        dataset: {},
        children: [],
        get className() { return Array.from(classes).join(' '); },
        set className(value) { classes.clear(); String(value).split(' ').filter(Boolean).forEach((name) => classes.add(name)); },
        classList: {
            add: (name) => classes.add(name),
            remove: (name) => classes.delete(name),
            toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)),
            contains: (name) => classes.has(name),
        },
        setAttribute: (name, value) => { attributes[name] = value; },
        getAttribute: (name) => attributes[name],
        appendChild(child) { node.children.push(child); },
        replaceChildren(...newChildren) { node.children = newChildren; },
        addEventListener(type, listener) { (listeners[type] = listeners[type] || []).push(listener); },
        click() { if (!node.disabled) (listeners.click || []).forEach((listener) => listener({ currentTarget: node })); },
        blur() {},
    };
    return node;
}

function withFakeDocument(run) {
    const previous = globalThis.document;
    globalThis.document = { createElement: fakeNode };
    try {
        return run();
    } finally {
        globalThis.document = previous;
    }
}

function tilesPanel({ cars, oneTap = false, correcting = () => false }) {
    const nodes = new Map();
    const element = (id) => {
        if (!nodes.has(id)) nodes.set(id, fakeNode());
        return nodes.get(id);
    };
    const passes = [];
    const corrections = [];
    const { control } = tilesControl({ oneTap });
    const panel = createTilesPanel({
        element,
        control,
        getCars: () => cars.list,
        confirm: () => true,
        onPass: (pass) => passes.push(pass),
        isCorrecting: correcting,
        onCorrect: (car) => corrections.push(car),
    });
    return { panel, element, passes, corrections };
}

test('the "??" tile is always the first tile, also with no car list and after the list changes', () => {
    withFakeDocument(() => {
        const cars = { list: [] };
        const { panel, element } = tilesPanel({ cars });
        panel.render();
        const tiles = () => element('blocos').children.map((tile) => tile.textContent);
        assert.deepEqual(tiles(), ['??'], 'there before the list arrives');

        cars.list = [{ numero: 41, nome: 'B' }, { numero: 7, nome: 'A' }];
        panel.render();
        assert.deepEqual(tiles(), ['??', '7', '41']);

        cars.list = [...cars.list, { numero: 3, nome: 'C' }];
        panel.render();
        assert.deepEqual(tiles(), ['??', '3', '7', '41'], 'the cars move; "??" never does');
    });
});

test('one tap on the "??" tile records an unread pass', () => {
    withFakeDocument(() => {
        const { panel, element, passes } = tilesPanel({ cars: { list: CARS }, oneTap: true });
        panel.render();
        element('blocos').children[0].click();
        assert.deepEqual(passes, [{ car: null, typingMs: null }]);
    });
});

test('while correcting, a car tile gives the number and records nothing; "??" and "Passou" are disabled', () => {
    withFakeDocument(() => {
        let correcting = true;
        const { panel, element, passes, corrections } = tilesPanel({
            cars: { list: CARS }, oneTap: true, correcting: () => correcting,
        });
        panel.render();
        const [unreadTile, sevenTile] = element('blocos').children;
        assert.equal(unreadTile.disabled, true, 'disabled, still in its place');
        assert.equal(element('btn-passou').disabled, true);

        sevenTile.click();
        assert.deepEqual(corrections, [7]);
        assert.deepEqual(passes, [], 'no pass, even with one tap on');

        correcting = false;
        panel.renderSelection();
        assert.equal(unreadTile.disabled, false);
        sevenTile.click();
        assert.deepEqual(passes, [{ car: 7, typingMs: null }]);
    });
});

// ---------------------------------------------------------------------------
// The recent list
// ---------------------------------------------------------------------------

function renderList(device, options = {}) {
    return withFakeDocument(() => {
        const listElement = fakeNode('ol');
        const informed = [];
        const list = createRecentList({
            listElement, session: device.session, onUndo: () => {}, onInform: (entry) => informed.push(entry.uuid), ...options,
        });
        list.render();
        const rows = listElement.children.map((row) => ({
            row,
            numero: row.children.find((child) => child.className === 'recente-numero').textContent,
            equipe: row.children.find((child) => child.className === 'recente-equipe').textContent,
            marca: (row.children.find((child) => child.className.startsWith('recente-corrigido')) || {}).textContent || null,
            botao: row.children.find((child) => child.className.startsWith('botao-informar')) || null,
        }));
        return { rows, informed, list, listElement };
    });
}

test('a "??" row shows "??" and one big "Informar número" button; a numbered row has none', async () => {
    const device = await createDevice();
    await device.store.setMeta('servidor', { valid_cars: CARS, janela_ms: 20_000 });
    await device.type(7);
    const unread = await device.typeUnread();

    const { rows, informed } = renderList(device);

    assert.equal(rows[0].numero, '??');
    assert.equal(rows[0].equipe, 'Número não lido');
    assert.equal(rows[0].botao.textContent, 'Informar número');
    assert.equal(rows[0].botao.className, 'botao-informar');
    assert.equal(rows[1].numero, '7');
    assert.equal(rows[1].botao, null);
    rows[0].botao.click();
    assert.deepEqual(informed, [unread.entry.uuid]);
});

test('after the correction the row shows the car, "corrigido", and can be corrected again', async () => {
    const device = await createDevice();
    await device.store.setMeta('servidor', { valid_cars: CARS, janela_ms: 20_000 });
    const unread = await device.typeUnread();
    await device.session.fixUnread(unread.entry.uuid, 41);

    let { rows } = renderList(device);
    assert.equal(rows[0].numero, '41');
    assert.equal(rows[0].equipe, 'Equipe Quarenta e Um');
    assert.equal(rows[0].marca, 'corrigido (na fila)');
    assert.equal(rows[0].botao.textContent, 'Corrigir número', 'smaller, but still there: the newest counts');

    await device.queue.flush();
    ({ rows } = renderList(device));
    assert.equal(rows[0].marca, 'corrigido');
});

test('an undone "??" offers no correction', async () => {
    const device = await createDevice();
    const unread = await device.typeUnread();
    await device.session.undo(unread.entry.uuid);
    const { rows } = renderList(device);
    assert.equal(rows[0].botao, null);
});

test('the row being corrected is marked, and the list redraws when the correction moves', async () => {
    const device = await createDevice();
    const first = await device.typeUnread();
    await device.typeUnread();
    let correcting = first.entry.uuid;

    const { listElement, list } = renderList(device, { correctingUuid: () => correcting });
    const marked = () => listElement.children.filter((row) => row.className.includes('recente-corrigindo')).length;
    assert.equal(marked(), 1);
    assert.ok(listElement.children[1].className.includes('recente-corrigindo'), 'the older one');

    correcting = null;
    withFakeDocument(() => list.render());
    assert.equal(marked(), 0);
});

test('a "??" still without a number stays in the list after ten more entries', async () => {
    // Situation: a "??", then a busy minute: 12 more cars. The counter
    // looks for the "??" to give its number.
    const device = await createDevice();
    const unread = await device.typeUnread();
    const corrected = await device.typeUnread();
    await device.session.fixUnread(corrected.entry.uuid, 7);
    for (let lap = 0; lap < 12; lap++) {
        device.clock.advance(5000);
        await device.type(lap % 2 === 0 ? 7 : 41);
    }

    const { rows } = renderList(device);

    // Expected: the last ten, then the waiting "??" (the corrected one is
    // done and goes with the rest).
    assert.equal(rows.length, RECENT_LIST_SIZE + 1);
    assert.equal(rows[RECENT_LIST_SIZE].row.children.find((child) => child.className === 'recente-numero').textContent, '??');
    assert.deepEqual(device.session.recentEntries().map((entry) => entry.uuid).slice(-1), [unread.entry.uuid]);
});

test('no "??" at all: the recent list is exactly what it always was', async () => {
    const device = await createDevice();
    for (let index = 0; index < 13; index++) {
        await device.type(7 + index);
    }
    const entries = device.session.recentEntries();
    assert.equal(entries.length, RECENT_LIST_SIZE);
    assert.deepEqual(entries.map((entry) => entry.carro), entries.map((entry) => entry.car));
});
