// PHYSICAL KEYBOARD ON THE COUNT PAGE: the wiring between the browser's key
// events and the number being typed (brief 2.2 and 2.6).
//
// `keyActionFor` (tested in entry-modes.test.js) decides what a key MEANS.
// That is not enough: two bugs lived in the wiring around it, where the only
// thing that matters is whether the browser's own reaction to the key gets
// cancelled (`event.preventDefault()`).
//
// The tests below use a pretend page: an object that remembers the listeners
// given to `addEventListener`, and pretend key events that remember whether
// `preventDefault` was called. A pretend number box "types" a digit exactly
// when a real one would: when a keydown reaches it and nobody cancelled it.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { wireCountKeys } from '../../default/js/contagem/contar-teclas.js';
import { MODO, TECLA } from '../../default/js/contagem/entry-modes.js';

/** The smallest "document" the wiring needs. */
function createFakePage() {
    const listeners = { keydown: [], keyup: [] };
    const numberField = { name: 'campo-numero', value: '' };
    const keypadKey = { name: 'tecla-7', clicks: 0 };
    const keypad = { contains: (target) => target === keypadKey };
    const somewhereElse = { name: 'body' };

    const page = {
        numberField,
        keypad,
        keypadKey,
        somewhereElse,
        mode: MODO.TECLADO,
        blocked: false,
        keysHandled: [],
        documentRef: { addEventListener: (type, listener) => listeners[type].push(listener) },

        /**
         * A key goes down while the focus is on `target`. Returns the event,
         * so the test can look at `defaultPrevented`.
         */
        keyDown(key, target, { repeat = false } = {}) {
            const event = fakeKeyEvent(key, target, repeat);
            listeners.keydown.forEach((listener) => listener(event));
            // What a browser does next, unless the page cancelled the key:
            // a digit typed into a text box appears in it...
            if (!event.defaultPrevented && target === numberField && /^[0-9]$/.test(key)) {
                numberField.value += key;
            }
            // ...and Enter "clicks" a focused button, at once.
            if (!event.defaultPrevented && target === keypadKey && key === 'Enter') {
                keypadKey.clicks++;
            }
            return event;
        },

        keyUp(key, target) {
            const event = fakeKeyEvent(key, target, false);
            listeners.keyup.forEach((listener) => listener(event));
            // Space "clicks" a focused button when it is RELEASED.
            if (!event.defaultPrevented && target === keypadKey && key === ' ') {
                keypadKey.clicks++;
            }
            return event;
        },
    };

    wireCountKeys({
        documentRef: page.documentRef,
        numberField,
        keypad,
        getMode: () => page.mode,
        isBlocked: () => page.blocked,
        onKey: (action) => page.keysHandled.push(action),
    });
    return page;
}

function fakeKeyEvent(key, target, repeat) {
    return {
        key,
        target,
        repeat,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
    };
}

// ---------------------------------------------------------------------------
// Brief 2.2: a key held down in the number box
// ---------------------------------------------------------------------------

test('holding "7" in the number box types one 7, not 777', () => {
    // SITUATION: keyboard mode on a laptop; the cursor is in the number box.
    // The counter presses 7 and holds it a moment too long. The keyboard
    // repeats the key: the browser sends more keydown events, marked
    // `repeat: true`.
    //
    // WHAT WENT WRONG: the page-wide key listener steps aside for keys typed
    // in the number box (the box does its own typing). It stepped aside for
    // the repetitions too, so the browser typed them: "777". Enter then
    // recorded car 777.
    const page = createFakePage();

    page.keyDown('7', page.numberField);
    const firstRepeat = page.keyDown('7', page.numberField, { repeat: true });
    page.keyDown('7', page.numberField, { repeat: true });

    // EXPECTED: the first press is left to the box (not cancelled); every
    // repetition is cancelled, so the box holds a single 7.
    assert.equal(page.numberField.value, '7');
    assert.equal(firstRepeat.defaultPrevented, true);
    // The page-wide listener did not type anything itself either: in the
    // box, typing is the box's job.
    assert.deepEqual(page.keysHandled, []);
});

test('a first press in the number box is not cancelled, or nothing could be typed at all', () => {
    const page = createFakePage();

    const one = page.keyDown('4', page.numberField);
    const two = page.keyDown('1', page.numberField);

    assert.equal(one.defaultPrevented, false);
    assert.equal(two.defaultPrevented, false);
    assert.equal(page.numberField.value, '41');
});

test('a held Enter in the number box is cancelled; a held Backspace keeps deleting', () => {
    const page = createFakePage();

    const heldEnter = page.keyDown('Enter', page.numberField, { repeat: true });
    const heldBackspace = page.keyDown('Backspace', page.numberField, { repeat: true });

    assert.equal(heldEnter.defaultPrevented, true);
    assert.equal(heldBackspace.defaultPrevented, false);
});

test('a digit typed while the focus is elsewhere is handled by the page, once per press', () => {
    // SITUATION: the counter tapped "desfazer"; the number box lost the
    // focus. The next car is typed blind: 4, 1 (held a little), Enter.
    const page = createFakePage();

    page.keyDown('4', page.somewhereElse);
    page.keyDown('1', page.somewhereElse);
    const repeat = page.keyDown('1', page.somewhereElse, { repeat: true });
    page.keyDown('Enter', page.somewhereElse);

    // EXPECTED: 4, 1, Enter reach the number buffer; the repetition does not.
    assert.deepEqual(page.keysHandled, [
        { tecla: TECLA.DIGITO, digito: '4' },
        { tecla: TECLA.DIGITO, digito: '1' },
        { tecla: TECLA.REGISTRAR, digito: null },
    ]);
    assert.equal(repeat.defaultPrevented, true);
});

test('"?" in a full number box is still "??": the page takes it before the box', () => {
    // SITUATION: keyboard mode, four digits already in the box (its
    // maxlength). The car crossing cannot be read: the counter types "?".
    //
    // WHAT WENT WRONG: the box was left to take the "?" like a digit and the
    // page looked for it in the text. A full box takes nothing, so no
    // "input" came and the "??" was never recorded.
    const page = createFakePage();
    page.numberField.value = '1234';

    const event = page.keyDown('?', page.numberField);

    // EXPECTED: the page records it, and the box does not also get a "?".
    assert.equal(event.defaultPrevented, true);
    assert.deepEqual(page.keysHandled, [{ tecla: TECLA.ILEGIVEL, digito: null }]);
});

// ---------------------------------------------------------------------------
// Brief 2.6: Space in keypad mode
// ---------------------------------------------------------------------------

test('Space does not press again the keypad key that was clicked last', () => {
    // SITUATION: keypad mode, used with a mouse or on a tablet with a
    // keyboard cover. The counter clicked "7"; that button has the focus.
    // The space bar gets pressed and released.
    //
    // WHAT WENT WRONG: a browser "clicks" the focused button when Space is
    // released. The 7 was typed again ("77"), or, after OK, the same car
    // was... not recorded twice (the buffer was empty) but the next digit
    // typed was doubled. Either way a key nobody meant.
    const page = createFakePage();
    page.mode = MODO.TECLADO_TELA;

    const down = page.keyDown(' ', page.keypadKey);
    const up = page.keyUp(' ', page.keypadKey);

    // EXPECTED: both halves of the key are cancelled, the button is not
    // clicked, and nothing reaches the number buffer.
    assert.equal(down.defaultPrevented, true);
    assert.equal(up.defaultPrevented, true);
    assert.equal(page.keypadKey.clicks, 0);
    assert.deepEqual(page.keysHandled, []);
});

test('Enter with the focus on a keypad key records once, through the page, not through the button', () => {
    const page = createFakePage();
    page.mode = MODO.TECLADO_TELA;

    page.keyDown('Enter', page.keypadKey);

    assert.equal(page.keypadKey.clicks, 0, 'the browser did not click the focused key');
    assert.deepEqual(page.keysHandled, [{ tecla: TECLA.REGISTRAR, digito: null }]);
});

test('Space on other controls keeps working', () => {
    // A checkbox ("Som") is switched with Space; that must not be taken away.
    const page = createFakePage();
    page.mode = MODO.TECLADO_TELA;

    assert.equal(page.keyDown(' ', page.somewhereElse).defaultPrevented, false);
    assert.equal(page.keyUp(' ', page.somewhereElse).defaultPrevented, false);
});

// ---------------------------------------------------------------------------
// When the page must not listen at all
// ---------------------------------------------------------------------------

test('after "Encerrar meu turno" keys are left alone', () => {
    const page = createFakePage();
    page.blocked = true;

    const event = page.keyDown('7', page.somewhereElse);

    assert.equal(event.defaultPrevented, false);
    assert.deepEqual(page.keysHandled, []);
});

test('in tiles mode the keyboard is left alone', () => {
    const page = createFakePage();
    page.mode = MODO.BLOCOS;

    assert.equal(page.keyDown('7', page.somewhereElse).defaultPrevented, false);
    assert.equal(page.keyDown('Enter', page.somewhereElse).defaultPrevented, false);
    assert.deepEqual(page.keysHandled, []);
});
