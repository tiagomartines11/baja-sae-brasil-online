// ENTRY MODES: the three ways of typing a car number, and the digit buffer.
//
//   "teclado"       the device's own keyboard: digits, then Enter
//   "teclado_tela"  a big keypad drawn on the page
//   "blocos"        one big button per car; a tap records the pass
//
// The counter picks one and the phone remembers it. "preferences" stands for
// the browser's localStorage, which survives closing the page.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    MODO, MAX_DIGITS, NumberBuffer, createPreferences, loadEntryMode, saveEntryMode,
} from '../../default/js/contagem/entry-modes.js';

/** A minimal localStorage: getItem/setItem over a Map. */
function createFakeLocalStorage() {
    const values = new Map();
    return {
        getItem: (key) => (values.has(key) ? values.get(key) : null),
        setItem: (key, value) => values.set(key, String(value)),
    };
}

// ---------------------------------------------------------------------------
// Remembering the mode
// ---------------------------------------------------------------------------

test('the chosen mode is remembered the next time the page opens', () => {
    // Situation: the counter switches to tiles, closes the page, opens it again.
    const browserStorage = createFakeLocalStorage();
    saveEntryMode(createPreferences(browserStorage), MODO.BLOCOS);

    const afterReopening = createPreferences(browserStorage);

    assert.equal(loadEntryMode(afterReopening, true), MODO.BLOCOS);
});

test('with nothing remembered, a touch device starts on the keypad and a PC on the keyboard', () => {
    const nothingSaved = createPreferences(createFakeLocalStorage());

    assert.equal(loadEntryMode(nothingSaved, true), MODO.TECLADO_TELA);
    assert.equal(loadEntryMode(nothingSaved, false), MODO.TECLADO);
});

test('a remembered value that is not a real mode is ignored', () => {
    // Situation: an old version of the page saved a mode name that no longer
    // exists.
    const browserStorage = createFakeLocalStorage();
    browserStorage.setItem('contagem.modo', JSON.stringify('holograma'));
    const preferences = createPreferences(browserStorage);

    assert.equal(loadEntryMode(preferences, false), MODO.TECLADO);

    saveEntryMode(preferences, 'holograma');
    assert.equal(loadEntryMode(preferences, false), MODO.TECLADO, 'and an invalid mode is never saved');
});

test('when the browser blocks its storage the choice still works until the page closes', () => {
    // Situation: private browsing on some phones makes localStorage throw.
    const blockedStorage = {
        getItem() { throw new Error('blocked'); },
        setItem() { throw new Error('blocked'); },
    };
    const preferences = createPreferences(blockedStorage);

    saveEntryMode(preferences, MODO.BLOCOS);

    assert.equal(loadEntryMode(preferences, false), MODO.BLOCOS);
    assert.equal(createPreferences(null).get('som', true), true, 'no storage at all: defaults apply');
});

test('sound and vibration switches are remembered too', () => {
    const browserStorage = createFakeLocalStorage();
    createPreferences(browserStorage).set('som', false);

    assert.equal(createPreferences(browserStorage).get('som', true), false);
    assert.equal(createPreferences(browserStorage).get('vibrar', true), true);
});

// ---------------------------------------------------------------------------
// The digit buffer behind both keyboard modes
// ---------------------------------------------------------------------------

test('digits build a number, and Enter hands it over and empties the buffer', () => {
    // Situation: the counter presses 4, then 1, then Enter.
    const buffer = new NumberBuffer();
    buffer.pressDigit('4', 1000);
    buffer.pressDigit('1', 1300);
    assert.equal(buffer.text(), '41');

    const confirmed = buffer.confirm(1900);

    // Expected: car 41, and 900 ms between the first digit and Enter.
    assert.deepEqual(confirmed, { car: 41, typingMs: 900 });
    assert.equal(buffer.isEmpty(), true);
});

test('Enter with nothing typed records nothing', () => {
    // Situation: the counter hits Enter twice by accident.
    const buffer = new NumberBuffer();

    assert.equal(buffer.confirm(1000), null);
});

test('backspace removes the last digit', () => {
    // Situation: 4, 2 (wrong), backspace, 1, Enter.
    const buffer = new NumberBuffer();
    buffer.pressDigit('4', 1000);
    buffer.pressDigit('2', 1200);
    buffer.backspace();
    buffer.pressDigit('1', 1500);

    assert.deepEqual(buffer.confirm(2000), { car: 41, typingMs: 1000 });
});

test('erasing everything restarts the typing time', () => {
    // Situation: the counter types 9, erases it, waits ten seconds for the
    // next car, then types 7 and Enter. The typing time is about the 7.
    const buffer = new NumberBuffer();
    buffer.pressDigit('9', 1000);
    buffer.backspace();
    buffer.pressDigit('7', 11_000);

    assert.deepEqual(buffer.confirm(11_400), { car: 7, typingMs: 400 });
});

test('a stuck key cannot produce an absurdly long number', () => {
    const buffer = new NumberBuffer();
    for (let press = 0; press < 10; press++) {
        buffer.pressDigit('7', 1000 + press);
    }

    assert.equal(buffer.text().length, MAX_DIGITS);
});

test('anything that is not a digit is ignored', () => {
    const buffer = new NumberBuffer();
    buffer.pressDigit('a', 1000);
    buffer.pressDigit('-', 1000);
    buffer.pressDigit('12', 1000);

    assert.equal(buffer.isEmpty(), true);
});

test('text from the system keyboard is cleaned down to digits', () => {
    // Situation: in "teclado" mode the browser's own input box holds the text.
    // A phone keyboard may let a space or a dot through.
    const buffer = new NumberBuffer();
    buffer.setText('4', 1000);
    buffer.setText('4 1.', 1400);

    assert.equal(buffer.text(), '41');
    assert.deepEqual(buffer.confirm(2000), { car: 41, typingMs: 1000 });
});

test('leading zeros do not create a different car', () => {
    // Situation: the counter types 007 for car 7.
    const buffer = new NumberBuffer();
    buffer.setText('007', 1000);

    assert.equal(buffer.confirm(1500).car, 7);
});
