// TAPS ON THE PIT PAGE'S CAR TILES: which taps record an event, and when
// the tiles may move.
//
// Background: on the pit page one tap on a car is one event. After the tap
// the car belongs to the other group of tiles ("inside" / "outside"). If the
// tiles were re-ordered at once, the tile next to it would slide under the
// marshal's finger.
//
// The first answer to that (round 3) was to ignore ANY tap within 350 ms of
// the previous one. But two cars entering the pit lane together is normal,
// and the marshal taps them one right after the other: the second car was
// silently not recorded.
//
// Now: only a second tap on the SAME car is ignored, and the tiles keep
// their places for a moment after each tap.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    TapGuard, TileLayoutHold, DOUBLE_TAP_MS, LAYOUT_HOLD_MS,
} from '../../default/js/contagem/box-toques.js';
import { SENTIDO, orderCarsForMode } from '../../default/js/contagem/pit-session.js';

test('two different cars tapped a moment apart are both recorded', () => {
    // SITUATION: cars 12 and 41 enter the pit lane nose to tail. The
    // marshal taps 12 and, 200 ms later, 41.
    const guard = new TapGuard();

    // EXPECTED: both taps record.
    assert.equal(guard.accepts(12, 10_000), true);
    assert.equal(guard.accepts(41, 10_200), true);
});

test('a double tap on the same car records once', () => {
    // SITUATION: a nervous finger taps car 12 twice, 150 ms apart. With the
    // tiles held in place (see below) the second tap lands on car 12 again.
    const guard = new TapGuard();
    assert.equal(guard.accepts(12, 10_000), true);

    assert.equal(guard.accepts(12, 10_150), false);

    // Later the same car can be tapped again (the marshal changed mode, or
    // the car came round again).
    assert.equal(guard.accepts(12, 10_150 + DOUBLE_TAP_MS), true);
});

test('going back and forth between two cars quickly records every tap', () => {
    const guard = new TapGuard();

    const recorded = [[12, 0], [41, 100], [12, 200], [41, 300]].map(([car, atMs]) => guard.accepts(car, atMs));

    // Each tap is on a different car than the one before it.
    assert.deepEqual(recorded, [true, true, true, true]);
});

test('after a tap the tiles keep their places, so the next tap lands on the car it was aimed at', () => {
    // SITUATION: "Entrada" mode, cars 3, 5, 12 and 41 outside. The marshal
    // taps 3 and, 250 ms later, the tile right next to it: 5.
    //
    // WHAT WOULD GO WRONG if the tiles were re-ordered at once: 3 leaves
    // the first group, 5 slides into its place and 12 into the place of 5.
    // The second tap, aimed at where 5 was, would record car 12.
    const hold = new TileLayoutHold();
    const mode = 'box_entrada';
    const groupsWith = (carsInside) => orderCarsForMode({ listedCars: [3, 5, 12, 41], carsInside, sentido: SENTIDO.ENTRADA });
    const before = hold.layoutFor(mode, groupsWith([]), 1000).groups;
    assert.deepEqual(before.primeiros, [3, 5, 12, 41]);

    hold.noteTap(3, 1000);
    const rightAfter = hold.layoutFor(mode, groupsWith([3]), 1050);

    // EXPECTED: the same tiles in the same places; car 3 is only MARKED as
    // tapped, so the marshal sees the tap worked.
    assert.deepEqual(rightAfter.groups.primeiros, [3, 5, 12, 41]);
    assert.deepEqual(rightAfter.tappedCars, [3]);

    // The second tap extends the hold: nothing moves until the marshal has
    // stopped tapping for LAYOUT_HOLD_MS.
    hold.noteTap(5, 1250);
    const stillHeld = hold.layoutFor(mode, groupsWith([3, 5]), 1000 + LAYOUT_HOLD_MS + 100);
    assert.deepEqual(stillHeld.groups.primeiros, [3, 5, 12, 41]);
    assert.deepEqual(stillHeld.tappedCars, [3, 5]);

    // Then the tiles are put in order: the two cars are inside now.
    const settled = hold.layoutFor(mode, groupsWith([3, 5]), 1250 + LAYOUT_HOLD_MS);
    assert.deepEqual(settled.groups, { primeiros: [12, 41], outros: [3, 5] });
    assert.deepEqual(settled.tappedCars, []);
});

test('changing the mode redraws the tiles at once, hold or no hold', () => {
    // The marshal's own tap on "Saída" is not a surprise to the marshal.
    const hold = new TileLayoutHold();
    hold.layoutFor('box_entrada', { primeiros: [3, 5], outros: [] }, 1000);
    hold.noteTap(3, 1000);

    const afterSwitch = hold.layoutFor('box_saida', { primeiros: [3], outros: [5] }, 1100);

    assert.deepEqual(afterSwitch.groups, { primeiros: [3], outros: [5] });
    assert.deepEqual(afterSwitch.tappedCars, []);
});

test('with no tap, a change coming from the server is drawn at once', () => {
    // The person at the other end recorded an exit; the next sync answer
    // moves that car. Nobody's finger is on the way.
    const hold = new TileLayoutHold();
    hold.layoutFor('box_saida', { primeiros: [3, 5], outros: [12] }, 1000);

    const afterSync = hold.layoutFor('box_saida', { primeiros: [5], outros: [3, 12] }, 6000);

    assert.deepEqual(afterSync.groups, { primeiros: [5], outros: [3, 12] });
});
