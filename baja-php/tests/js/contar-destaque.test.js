// THE BIG FLASH OF THE COUNT PAGE NEVER CHANGES SIZE (field note N5, 26SE).
//
// Background: the flash sits right above the keypad and the car tiles. Each
// warning used to add a paragraph to it: a "repeated" warning made it about
// 75 px taller, one keypad row, and moved every key and tile down under the
// counter's thumb. The next tap landed on the car BELOW the one aimed at;
// with "um toque" that is a wrong car recorded at once. Two warnings, or a
// long team name, moved it further.
//
// So the flash has three rows of fixed height: the number, one line of team
// name, ONE short line of warning. These tests pin down what goes on those
// lines; the sizes themselves are in the style sheet (.destaque) and were
// measured in a real browser at 360 px wide (docs/contagem.md, "Choices
// worth knowing about").
// The whole sentence of every warning is still there: on the entry's row in
// the recent list, and read out by screen readers.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    NIVEL, createFlash, instructionFlash, notRecordedFlash, passFlash, undoFlash,
} from '../../default/js/contagem/contar-destaque.js';
import { AVISO, computeWarnings, mostSeriousWarning } from '../../default/js/contagem/warnings.js';
import { MODO } from '../../default/js/contagem/entry-modes.js';
import { TEXTO_BLOQUEIO, BLOQUEIO } from '../../default/js/contagem/access.js';
import { createRecentList } from '../../default/js/contagem/contar-recentes.js';
import { createDevice } from './helpers/fakes.js';

const CARS = [{ numero: 15, nome: 'Equipe Quinze' }, { numero: 7, nome: 'Equipe Sete' }];
const UNCLASSIFIED = [{ numero: 90, nome: 'Equipe Noventa' }];
const T = 1_760_000_000_000;

/** The warnings for `car`, typed now; `typedBefore`: it was also typed 3 s ago. */
function warningsFor(car, typedBefore = false) {
    return computeWarnings({
        car,
        tsDeviceMs: T,
        ownEntries: typedBefore ? [{ uuid: 'x', car, ts_device_ms: T - 3000, voided: false }] : [],
        validCars: CARS,
        unclassifiedCars: UNCLASSIFIED,
        janelaMs: 20_000,
    });
}

// The warning line holds about 290 px of 17 px bold text on a 360 px phone:
// measured, the longest short text below takes 275 px ("REPETIDO há 19 s:
// desfazer?"). 30 characters is the budget a new text must keep to.
const SHORT_TEXT_BUDGET = 30;

// ---------------------------------------------------------------------------
// Short texts
// ---------------------------------------------------------------------------

test('every warning has a short form for the flash and keeps its whole sentence', () => {
    // Situation: the three warnings there are. 14 is not a car; 90 is at
    // the event but not classified; 15 was typed 3 s ago.
    const unknown = warningsFor(14)[0];
    const unclassified = warningsFor(90)[0];
    const repeated = warningsFor(15, true)[0];

    assert.equal(unknown.curta, 'Número fora da lista');
    assert.equal(unclassified.curta, 'NÃO CLASSIF.: avise o resp.');
    assert.equal(repeated.curta, 'REPETIDO há 3 s: desfazer?');
    // The whole sentences are unchanged: the recent list shows them.
    assert.match(unknown.mensagem, /Número 14 não está na lista de carros/);
    assert.match(unclassified.mensagem, /^CARRO NÃO CLASSIFICADO.*avise o responsável/);
    assert.match(repeated.mensagem, /há 3 s\. Se foi engano, toque em desfazer/);
});

test('the short texts fit the single warning line', () => {
    // Situation: the longest forms there are: a repeat after 19 s (the
    // window is 20 s), and the two "NÃO REGISTRADO" cases.
    const longestRepeat = computeWarnings({
        car: 15, tsDeviceMs: T, ownEntries: [{ uuid: 'x', car: 15, ts_device_ms: T - 19_400, voided: false }],
        validCars: CARS, janelaMs: 20_000,
    })[0];
    const texts = [
        longestRepeat.curta, warningsFor(14)[0].curta, warningsFor(90)[0].curta,
        notRecordedFlash(15).aviso.curta, notRecordedFlash(15, TEXTO_BLOQUEIO[BLOQUEIO.SEM_PERMISSAO]).aviso.curta,
    ];

    for (const text of texts) {
        assert.ok(text.length <= SHORT_TEXT_BUDGET, '"' + text + '" is ' + text.length + ' characters');
    }
});

// ---------------------------------------------------------------------------
// Two warnings: one line, the most serious one
// ---------------------------------------------------------------------------

test('with two warnings the flash shows the most serious one', () => {
    // Situation: an unclassified car typed twice; a number off the list
    // typed twice. The keyboard modes allow both.
    assert.equal(mostSeriousWarning(warningsFor(90, true)).tipo, AVISO.NAO_CLASSIFICADO);
    assert.equal(mostSeriousWarning(warningsFor(14, true)).tipo, AVISO.NUMERO_DESCONHECIDO);
    assert.equal(mostSeriousWarning(warningsFor(15, true)).tipo, AVISO.REPETIDO);
    assert.equal(mostSeriousWarning([]), null);
    // The order the warnings come in does not matter.
    assert.equal(mostSeriousWarning([...warningsFor(90, true)].reverse()).tipo, AVISO.NAO_CLASSIFICADO);
});

test('the flash of a pass with two warnings has one short line, both sentences and the error colour', () => {
    const avisos = warningsFor(90, true);

    const flash = passFlash({ car: 90, avisos, nomeEquipe: 'Equipe Noventa', anyListKnown: true });

    assert.equal(flash.numero, '90');
    assert.equal(flash.equipe, 'Equipe Noventa');
    assert.equal(flash.aviso.curta, 'NÃO CLASSIF.: avise o resp.');
    assert.deepEqual(flash.aviso.completa, avisos.map((aviso) => aviso.mensagem));
    assert.equal(flash.aviso.completa.length, 2);
    assert.equal(flash.nivel, NIVEL.ERRO);
});

test('a clean pass, a repeat and a number off the list get their own colour', () => {
    const clean = passFlash({ car: 15, avisos: [], nomeEquipe: 'Equipe Quinze', anyListKnown: true });
    const repeated = passFlash({ car: 15, avisos: warningsFor(15, true), nomeEquipe: 'Equipe Quinze', anyListKnown: true });
    const unknown = passFlash({ car: 14, avisos: warningsFor(14), nomeEquipe: null, anyListKnown: true });
    const noList = passFlash({ car: 14, avisos: [], nomeEquipe: null, anyListKnown: false });

    assert.deepEqual([clean.nivel, clean.aviso], [NIVEL.OK, null]);
    assert.deepEqual([repeated.nivel, repeated.aviso.curta], [NIVEL.AVISO, 'REPETIDO há 3 s: desfazer?']);
    assert.deepEqual([unknown.nivel, unknown.equipe], [NIVEL.AVISO, 'Número desconhecido']);
    // Without any car list, every number would be "unknown": no name at all.
    assert.equal(noList.equipe, '');
});

test('"NÃO REGISTRADO" says so on the team line and keeps the reason whole', () => {
    const storage = notRecordedFlash(15);
    const blocked = notRecordedFlash(15, TEXTO_BLOQUEIO[BLOQUEIO.SEM_PERMISSAO]);

    assert.deepEqual([storage.numero, storage.equipe, storage.nivel], ['15', 'NÃO REGISTRADO', NIVEL.ERRO]);
    assert.equal(storage.aviso.curta, 'Digite de novo');
    assert.deepEqual(storage.aviso.completa, ['O aparelho não conseguiu guardar. Digite de novo.']);
    assert.equal(blocked.equipe, 'NÃO REGISTRADO');
    assert.deepEqual(blocked.aviso.completa, [TEXTO_BLOQUEIO[BLOQUEIO.SEM_PERMISSAO]]);
});

// ---------------------------------------------------------------------------
// The instruction follows the entry mode
// ---------------------------------------------------------------------------

test('before the first pass the flash says what to do in the mode on screen', () => {
    // Background: 98% of the entries at 26SE were tiles, and the flash said
    // "Digite o número do carro que passou" to all of them.
    assert.equal(instructionFlash(MODO.TECLADO).equipe, 'Digite o número');
    assert.equal(instructionFlash(MODO.TECLADO_TELA).equipe, 'Digite o número');
    assert.equal(instructionFlash(MODO.BLOCOS).equipe, 'Toque no carro');
    assert.equal(instructionFlash(MODO.BLOCOS).aviso, null);
});

// ---------------------------------------------------------------------------
// Drawing: one pretend element per row
// ---------------------------------------------------------------------------

function fakeElement() {
    const classes = new Set();
    return {
        textContent: '',
        title: '',
        offsetWidth: 0,
        classes,
        classList: {
            add: (name) => classes.add(name),
            remove: (name) => classes.delete(name),
            toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)),
            contains: (name) => classes.has(name),
        },
    };
}

function createFakeFlash() {
    const elements = {
        box: fakeElement(), number: fakeElement(), team: fakeElement(),
        warning: fakeElement(), warningShort: fakeElement(), warningFull: fakeElement(),
    };
    return { elements, flash: createFlash(elements) };
}

test('the flash shows the short line, and the whole sentences for title and screen readers', () => {
    const { elements, flash } = createFakeFlash();
    const avisos = warningsFor(14, true);

    flash.show(passFlash({ car: 14, avisos, nomeEquipe: null, anyListKnown: true }));

    assert.equal(elements.number.textContent, '14');
    assert.equal(elements.warningShort.textContent, 'Número fora da lista');
    const both = avisos.map((aviso) => aviso.mensagem).join(' ');
    assert.equal(elements.warningFull.textContent, both);
    assert.equal(elements.warning.title, both);
    assert.ok(!elements.warning.classes.has('destaque-aviso-vazio'));
    assert.ok(elements.box.classes.has('destaque-problema'));
    assert.ok(elements.box.classes.has('destaque-ativo'), 'it flashes');
});

test('"desfazer" replaces the warning of the entry it undid', () => {
    // Situation: 15 is typed twice by mistake; the flash says REPETIDO. The
    // counter taps "desfazer". Before: the flash kept saying REPETIDO (and
    // its colour) until the next pass, about an entry that no longer counts.
    const { elements, flash } = createFakeFlash();
    flash.show(passFlash({ car: 15, avisos: warningsFor(15, true), nomeEquipe: 'Equipe Quinze', anyListKnown: true }));

    flash.show(undoFlash(15));

    assert.equal(elements.number.textContent, '15');
    assert.equal(elements.team.textContent, 'Desfeito: carro 15');
    assert.equal(elements.warningShort.textContent, '');
    assert.equal(elements.warningFull.textContent, '');
    assert.ok(elements.warning.classes.has('destaque-aviso-vazio'), 'the warning row stays, empty');
    assert.ok(!elements.box.classes.has('destaque-problema'));
    assert.ok(!elements.box.classes.has('destaque-erro'));
    assert.ok(elements.box.classes.has('destaque-desfeito'));
    assert.ok(!elements.box.classes.has('destaque-ativo'), 'no green "registered" flash for an undo');
});

test('changing mode changes the instruction, but never hides a pass on screen', () => {
    const { elements, flash } = createFakeFlash();
    assert.ok(flash.showsInstruction(), 'nothing shown yet');
    flash.show(instructionFlash(MODO.TECLADO));
    flash.show(instructionFlash(MODO.BLOCOS));
    assert.equal(elements.team.textContent, 'Toque no carro');

    flash.show(passFlash({ car: 7, avisos: [], nomeEquipe: 'Equipe Sete', anyListKnown: true }));

    assert.ok(!flash.showsInstruction(), 'contar.js asks this before putting the instruction back');
});

// ---------------------------------------------------------------------------
// The whole sentence: on the entry's row in the recent list
// ---------------------------------------------------------------------------

function fakeDocumentNode(tag) {
    return {
        tag,
        className: '',
        textContent: '',
        children: [],
        appendChild(child) { this.children.push(child); },
        replaceChildren() { this.children = []; },
        addEventListener() {},
    };
}

test('the recent list shows each warning in full on its entry, and drops it once undone', async () => {
    const device = await createDevice();
    await device.store.setMeta('servidor', { valid_cars: CARS, unclassified_cars: UNCLASSIFIED, janela_ms: 20_000 });
    await device.type(15);
    device.clock.advance(3000);
    const repeated = await device.type(15);
    const previousDocument = globalThis.document;
    globalThis.document = { createElement: fakeDocumentNode };
    try {
        const listElement = fakeDocumentNode('ol');
        const list = createRecentList({ listElement, session: device.session, onUndo: () => {} });

        list.render();
        const rowWarnings = (row) => row.children.filter((child) => child.className.startsWith('recente-aviso'));
        const [newest, older] = listElement.children;
        assert.deepEqual(rowWarnings(newest).map((child) => child.textContent), [repeated.avisos[0].mensagem]);
        assert.deepEqual(rowWarnings(older), [], 'the first 15 had no warning');

        await device.session.undo(repeated.entry.uuid);
        list.render();
        assert.deepEqual(rowWarnings(listElement.children[0]), [], 'an undone entry no longer warns');
    } finally {
        globalThis.document = previousDocument;
    }
});
