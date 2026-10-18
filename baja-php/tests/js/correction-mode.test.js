// CORRECTING A "??" ON THE PHONE ("Informar número", correction-mode.js).
//
// Background: a counter who could not read a number presses "??"; the pass
// is recorded with its time and no car. When they have a moment they tap
// "Informar número" on that row and give the number.
//
// The danger is the mode itself: a counter who forgets they are correcting
// types the next car that crosses, and that car silently becomes the
// correction while its own pass is never recorded. So the mode is short,
// loud, ends after one car, and has a way out that records a crossing at
// once. These tests pin the state machine down; the page (contar.js) only
// routes keys and taps to it.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { CorrectionMode, FIM_CORRECAO, TEMPO_CORRECAO_MS } from '../../default/js/contagem/correction-mode.js';

/** Timers that only fire when the test says so. */
function fakeTimers() {
    const timers = new Map();
    let nextId = 1;
    return {
        setTimeoutFn(callback, delayMs) {
            const id = nextId++;
            timers.set(id, { callback, delayMs });
            return id;
        },
        clearTimeoutFn(id) {
            timers.delete(id);
        },
        pending: () => timers.size,
        delays: () => Array.from(timers.values(), (timer) => timer.delayMs),
        fireAll() {
            const due = Array.from(timers.values());
            timers.clear();
            due.forEach((timer) => timer.callback());
        },
    };
}

function createMode() {
    const timers = fakeTimers();
    const changes = [];
    const ends = [];
    const mode = new CorrectionMode({
        onChange: (state) => changes.push(state === null ? null : state.uuid),
        onEnd: (ended) => ends.push(ended),
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });
    return { mode, timers, changes, ends };
}

const UNREAD = { uuid: 'uuid-3', car: null, ts_ms: 1_760_000_000_000 };

test('"Informar número" opens the mode on that entry; the next car is its number, and the mode ends', () => {
    const { mode, changes, ends, timers } = createMode();
    assert.equal(mode.active(), false);

    mode.start(UNREAD);
    assert.equal(mode.active(), true);
    assert.equal(mode.uuid(), 'uuid-3');
    assert.deepEqual(changes, ['uuid-3'], 'the page draws the banner');

    const fix = mode.pressCar(41);

    // Expected: a correction, not a pass; and the mode is over at once, so
    // the car after it is an ordinary pass again.
    assert.deepEqual(fix, { uuid: 'uuid-3', car: 41 });
    assert.equal(mode.active(), false);
    assert.deepEqual(ends, [{ motivo: FIM_CORRECAO.CORRIGIDO, uuid: 'uuid-3', car: 41 }]);
    assert.deepEqual(changes, ['uuid-3', null], 'the banner goes');
    assert.equal(timers.pending(), 0, 'no timeout left behind');
    assert.equal(mode.pressCar(7), null, 'the next car is not a correction: the page records it');
});

test('with no correction open, a car is an ordinary pass', () => {
    const { mode, ends } = createMode();
    assert.equal(mode.pressCar(41), null);
    assert.deepEqual(ends, []);
});

test('"Cancelar" ends the mode with nothing corrected', () => {
    const { mode, ends, timers } = createMode();
    mode.start(UNREAD);

    mode.cancel();

    assert.equal(mode.active(), false);
    assert.deepEqual(ends, [{ motivo: FIM_CORRECAO.CANCELADO, uuid: 'uuid-3', car: null }]);
    assert.equal(timers.pending(), 0);
    mode.cancel();
    assert.equal(ends.length, 1, 'cancelling twice says it once');
});

test('after 20 s without a car the mode ends by itself and says why', () => {
    const { mode, ends, timers } = createMode();
    mode.start(UNREAD);
    assert.deepEqual(timers.delays(), [TEMPO_CORRECAO_MS]);
    assert.equal(TEMPO_CORRECAO_MS, 20000);

    timers.fireAll();

    assert.equal(mode.active(), false);
    assert.deepEqual(ends, [{ motivo: FIM_CORRECAO.TEMPO_ESGOTADO, uuid: 'uuid-3', car: null }]);
    assert.equal(mode.pressCar(41), null, 'a car typed after the timeout is a pass, not a correction');
});

test('each digit typed for the correction starts the 20 s again; with no correction it does nothing', () => {
    // A counter part-way through "4", "1" when the time ran out lost the
    // "4", and the "1" then went into a NEW pass at the next Enter.
    const { mode, ends, timers } = createMode();
    mode.touch();
    assert.equal(timers.pending(), 0, 'no correction open: no timer');

    mode.start(UNREAD);
    mode.touch();
    mode.touch();
    assert.deepEqual(timers.delays(), [TEMPO_CORRECAO_MS], 'one timer, started again, never two');
    assert.equal(mode.active(), true);

    timers.fireAll();
    assert.deepEqual(ends, [{ motivo: FIM_CORRECAO.TEMPO_ESGOTADO, uuid: 'uuid-3', car: null }]);
    mode.touch();
    assert.equal(timers.pending(), 0, 'a digit after the end starts nothing');
});

test('"Passou um carro" ends the mode so that the crossing is recorded', () => {
    // The page records a "??" at the tap first (its time is that tap), then
    // calls this; the end says PASSAGEM so the page shows that pass's flash
    // instead of "Correção cancelada".
    const { mode, ends } = createMode();
    mode.start(UNREAD);

    mode.escapeToPass();

    assert.equal(mode.active(), false);
    assert.deepEqual(ends, [{ motivo: FIM_CORRECAO.PASSAGEM, uuid: 'uuid-3', car: null }]);
});

test('"Informar número" on another row moves the correction there, with a fresh timeout', () => {
    const { mode, ends, timers } = createMode();
    mode.start(UNREAD);
    mode.start({ uuid: 'uuid-9', car: null, ts_ms: 1_760_000_050_000 });

    assert.equal(mode.uuid(), 'uuid-9');
    assert.equal(timers.pending(), 1, 'one timeout, the new one');
    assert.deepEqual(ends, [], 'nothing was corrected nor cancelled');
    assert.deepEqual(mode.pressCar(12), { uuid: 'uuid-9', car: 12 });
});

test('undoing the entry being corrected ends the correction; undoing another does not', () => {
    const { mode, ends } = createMode();
    mode.start(UNREAD);

    mode.forget('uuid-1');
    assert.equal(mode.active(), true);

    mode.forget('uuid-3');
    assert.equal(mode.active(), false);
    assert.deepEqual(ends, [{ motivo: FIM_CORRECAO.CANCELADO, uuid: 'uuid-3', car: null }]);
});

test('only a whole number is a correction', () => {
    const { mode } = createMode();
    mode.start(UNREAD);
    assert.equal(mode.pressCar(null), null, 'the "??" tile while correcting gives no number');
    assert.equal(mode.active(), true, 'and does not end the mode');
});
