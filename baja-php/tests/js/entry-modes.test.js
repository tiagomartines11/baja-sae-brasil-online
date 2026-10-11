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
    MODO, MAX_DIGITS, TECLA, NumberBuffer, createPreferences, keyActionFor, loadEntryMode, saveEntryMode,
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

// ---------------------------------------------------------------------------
// Keys pressed anywhere on the page
// ---------------------------------------------------------------------------
//
// `keyActionFor(key, where)` answers one question: "this key was just pressed
// somewhere on the page; does it belong to the car number?". `key` is what the
// browser reports about the key press. `typedInNumberBox` says whether the
// cursor was in the number box when it happened.

const SOMEWHERE_ELSE = { mode: MODO.TECLADO, typedInNumberBox: false };

test('in keyboard mode, digits typed after clicking elsewhere on the page still count', () => {
    // Situation: a counter with a laptop uses the keyboard mode. Car 41
    // passes; they typed 14 by mistake, so they click "desfazer" in the list
    // with the mouse. The cursor has now left the number box. The next car,
    // 7, is already coming, and they type 7 and Enter without looking.
    //
    // What used to go wrong: the page only listened to keys INSIDE the
    // number box. "7" and Enter went nowhere, and car 7 was not counted,
    // without any sign of it on the screen.
    assert.deepEqual(keyActionFor({ key: '7' }, SOMEWHERE_ELSE), { tecla: TECLA.DIGITO, digito: '7' });
    assert.deepEqual(keyActionFor({ key: 'Backspace' }, SOMEWHERE_ELSE), { tecla: TECLA.APAGAR, digito: null });
    assert.deepEqual(keyActionFor({ key: 'Enter' }, SOMEWHERE_ELSE), { tecla: TECLA.REGISTRAR, digito: null });
});

test('in keyboard mode, a key typed inside the number box is left to the box', () => {
    // Situation: the normal case, cursor in the number box. The box itself
    // already puts the digit into the number.
    const insideTheBox = { mode: MODO.TECLADO, typedInNumberBox: true };

    // Expected: the page-wide listener stays out of it. Otherwise every digit
    // would be entered twice and car 7 would become car 77.
    assert.equal(keyActionFor({ key: '7' }, insideTheBox), null);
    assert.equal(keyActionFor({ key: 'Enter' }, insideTheBox), null);
});

test('the on-screen keypad answers to a physical keyboard wherever the cursor is', () => {
    // Situation: keypad mode on a tablet with a keyboard attached.
    for (const typedInNumberBox of [false, true]) {
        const where = { mode: MODO.TECLADO_TELA, typedInNumberBox };
        assert.deepEqual(keyActionFor({ key: '4' }, where), { tecla: TECLA.DIGITO, digito: '4' });
        assert.deepEqual(keyActionFor({ key: 'Enter' }, where), { tecla: TECLA.REGISTRAR, digito: null });
    }
});

test('a key held down too long is counted once', () => {
    // Situation: the counter rests a finger on "7", or leans on Enter. The
    // keyboard then repeats the key many times a second, and the browser
    // marks every repetition with `repeat: true`.
    //
    // Expected: the repetitions do NOTHING (`TECLA.NADA`): they type no
    // digit and record no pass, and the page also cancels what the browser
    // itself would do with them (a held Enter "clicks" the focused button
    // again and again). Otherwise a long press would type 7777, or record
    // the same car as several passes.
    const nothing = { tecla: TECLA.NADA, digito: null };
    assert.deepEqual(keyActionFor({ key: '7', repeat: true }, SOMEWHERE_ELSE), nothing);
    assert.deepEqual(keyActionFor({ key: 'Enter', repeat: true }, SOMEWHERE_ELSE), nothing);
    assert.deepEqual(keyActionFor({ key: 'Enter', repeat: true }, { mode: MODO.TECLADO_TELA, typedInNumberBox: false }), nothing);
});

test('a key held down INSIDE the number box is cancelled too', () => {
    // SITUATION: keyboard mode, the cursor is in the number box (the normal
    // case on a laptop). The counter types "7" and keeps the key down a
    // moment too long.
    //
    // WHAT WENT WRONG: keys typed in the number box are left to the browser
    // (the box does its own typing), and that included the repetitions of a
    // held key: the box filled with "777", a car that does not exist.
    //
    // EXPECTED: the first press is still left alone (null: the box types
    // it). Every repetition of a digit or of Enter is answered with
    // `TECLA.NADA`, which makes the page cancel it. A held Backspace keeps
    // deleting, as everywhere else.
    const inTheBox = { mode: MODO.TECLADO, typedInNumberBox: true };
    assert.equal(keyActionFor({ key: '7', repeat: false }, inTheBox), null);
    assert.deepEqual(keyActionFor({ key: '7', repeat: true }, inTheBox), { tecla: TECLA.NADA, digito: null });
    assert.deepEqual(keyActionFor({ key: 'Enter', repeat: true }, inTheBox), { tecla: TECLA.NADA, digito: null });
    assert.equal(keyActionFor({ key: 'Backspace', repeat: true }, inTheBox), null);
});

test('Space on a key of the on-screen keypad does nothing', () => {
    // SITUATION: keypad mode on a laptop or a tablet with a keyboard. The
    // counter clicked "7" on the screen; that button now has the focus.
    // Then the space bar gets pressed (a resting thumb is enough).
    //
    // WHAT WENT WRONG: a browser "clicks" the focused button on Space, so
    // the 7 was typed a second time, or OK was pressed a second time.
    //
    // EXPECTED: `TECLA.NADA`, so the page cancels the key. Space on anything
    // else (the "Som" checkbox, for example) is left alone and keeps working.
    const onKeypadKey = { mode: MODO.TECLADO_TELA, typedInNumberBox: false, focusOnKeypadKey: true };
    const onACheckbox = { mode: MODO.TECLADO_TELA, typedInNumberBox: false, focusOnKeypadKey: false };
    assert.deepEqual(keyActionFor({ key: ' ' }, onKeypadKey), { tecla: TECLA.NADA, digito: null });
    assert.equal(keyActionFor({ key: ' ' }, onACheckbox), null);
});

test('browser shortcuts and other keys are not car numbers', () => {
    // Situation: Ctrl+1 switches to the first browser tab, Alt+4 may close a
    // window, and letters or the space bar mean nothing here.
    assert.equal(keyActionFor({ key: '1', ctrlKey: true }, SOMEWHERE_ELSE), null);
    assert.equal(keyActionFor({ key: '4', altKey: true }, SOMEWHERE_ELSE), null);
    assert.equal(keyActionFor({ key: '2', metaKey: true }, SOMEWHERE_ELSE), null);
    assert.equal(keyActionFor({ key: 'a' }, SOMEWHERE_ELSE), null);
    assert.equal(keyActionFor({ key: ' ' }, SOMEWHERE_ELSE), null);
});

test('in tile mode the keyboard records nothing', () => {
    // Situation: the counter chose one button per car. There is no number
    // being typed, so a key press (a phone in a pocket with a keyboard case,
    // say) must not turn into a pass.
    const tiles = { mode: MODO.BLOCOS, typedInNumberBox: false };
    assert.equal(keyActionFor({ key: '7' }, tiles), null);
    assert.equal(keyActionFor({ key: 'Enter' }, tiles), null);
});
