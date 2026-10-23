// TILES MODE ("Blocos") and WHAT THE PAGE KNOWS ABOUT THE PROVA.
//
// Tiles (brief, section 9): counters are trained to do two things for each
// car: pick the car as it approaches, then mark the moment it crosses the
// line. The tiles used to record at the first tap. Now the default is two
// taps (tile, then a large "Passou" button whose tap is the pass time), and a
// switch brings the one-tap behaviour back for those who want it.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { TilesControl } from '../../default/js/contagem/tiles-control.js';
import { createPageInfo } from '../../default/js/contagem/page-info.js';
import { createPreferences } from '../../default/js/contagem/entry-modes.js';
import { AREA } from '../../default/js/contagem/pit-session.js';
import { createDevice } from './helpers/fakes.js';

/** `window.localStorage` for one pretend phone. */
function createPhoneStorage() {
    const values = new Map();
    return {
        getItem: (key) => (values.has(key) ? values.get(key) : null),
        setItem: (key, value) => values.set(key, value),
    };
}

function createTiles(phoneStorage = createPhoneStorage()) {
    const time = { monotonicMs: 1000 };
    const control = new TilesControl({
        preferences: createPreferences(phoneStorage), monotonicNow: () => time.monotonicMs,
    });
    return { control, time, phoneStorage };
}

// ---------------------------------------------------------------------------
// Two taps (the default)
// ---------------------------------------------------------------------------

test('by default a tap on a tile only selects the car; "Passou" records it', () => {
    // SITUATION: car 41 comes into view. The counter taps its tile, follows
    // the car for 3.2 seconds, and taps "Passou" as it crosses the line.
    const { control, time } = createTiles();

    const afterTile = control.tapTile(41);

    // EXPECTED: nothing is recorded by the first tap; the car is shown as
    // selected.
    assert.equal(afterTile, null);
    assert.equal(control.selected(), 41);

    time.monotonicMs += 3200;
    const pass = control.tapPassou();

    // The pass is recorded by the SECOND tap (the page takes the timestamp
    // at that moment). How long the car stayed selected goes along, like
    // the typing time of the keyboard modes.
    assert.deepEqual(pass, { car: 41, typingMs: 3200 });
    assert.equal(control.selected(), null);
});

test('tapping another tile changes the selection; nothing is recorded for the first', () => {
    // SITUATION: the counter taps 41, then sees it is in fact car 14.
    const { control } = createTiles();

    control.tapTile(41);
    control.tapTile(14);
    const pass = control.tapPassou();

    assert.equal(pass.car, 14);
});

test('"Passou" with no car selected records nothing', () => {
    // A stray touch on the big button must not invent a pass.
    const { control } = createTiles();

    assert.equal(control.tapPassou(), null);
});

test('one selection is one pass: a second tap on "Passou" does not count the car again', () => {
    const { control } = createTiles();
    control.tapTile(41);

    assert.notEqual(control.tapPassou(), null);
    assert.equal(control.tapPassou(), null);
});

// ---------------------------------------------------------------------------
// One tap (the switch "Um toque marca a passagem")
// ---------------------------------------------------------------------------

test('the one-tap switch is off until somebody turns it on', () => {
    const { control } = createTiles();

    assert.equal(control.oneTap(), false);
});

test('with the switch on, a tap on a tile records the pass at once', () => {
    const { control } = createTiles();
    control.setOneTap(true);

    const pass = control.tapTile(41);

    // EXPECTED: as tiles worked before: recorded immediately, nothing typed
    // (typingMs null), no selection left behind.
    assert.deepEqual(pass, { car: 41, typingMs: null });
    assert.equal(control.selected(), null);
});

test('the switch is remembered on the device', () => {
    // Situation: the counter turned it on yesterday; today the page is
    // opened again on the same phone.
    const phoneStorage = createPhoneStorage();
    createTiles(phoneStorage).control.setOneTap(true);

    const today = createTiles(phoneStorage).control;

    assert.equal(today.oneTap(), true);
});

test('switching modes drops a car that was waiting for "Passou"', () => {
    // Situation: 41 is selected; the counter turns one-tap on (or off).
    // A selection made under the other rule must not be recorded later.
    const { control } = createTiles();
    control.tapTile(41);

    control.setOneTap(true);
    control.setOneTap(false);

    assert.equal(control.tapPassou(), null);
});

// ---------------------------------------------------------------------------
// What the page knows about the prova: the page's own copy, then the server's
// ---------------------------------------------------------------------------

const SHELL_CONFIG = {
    carros: [{ numero: 1, nome: 'Um' }, { numero: 2, nome: 'Dois' }],
    carros_nao_classificados: [{ numero: 3, nome: 'Três' }],
    janela_ms: 15000,
    abastecimento: false,
    simulacao: false,
};

test('before the first sync the page uses what was printed into it', async () => {
    // Situation: first load, and the server has not answered yet.
    const device = await createDevice({ anchored: false });
    const info = createPageInfo(device.store, SHELL_CONFIG);

    assert.deepEqual(info.validCars().map((car) => car.numero), [1, 2]);
    assert.deepEqual(info.unclassifiedCars().map((car) => car.numero), [3]);
    assert.equal(info.janelaMs(), 15000);
    assert.equal(info.fuelingEnabled(), false);
    assert.equal(info.simulationAllowed(), false);
    assert.deepEqual(info.serverCarsInside(AREA.BOX), []);
});

test('after a sync the server\'s lists win: tiles show classified cars only', async () => {
    // SITUATION: since the page was printed, car 3 was classified and car 2
    // lost its classification. The sync answer has the new lists.
    const device = await createDevice();
    device.server.validCars = [{ numero: 1, nome: 'Um' }, { numero: 3, nome: 'Três' }];
    device.server.unclassifiedCars = [{ numero: 2, nome: 'Dois' }];
    device.server.abastecimento = true;
    device.server.simulacao = true;
    device.server.carsInPit = [1];
    device.server.carsInFuel = [3];
    const info = createPageInfo(device.store, SHELL_CONFIG);

    await device.queue.syncOnce();

    // EXPECTED: `validCars` (what tiles and lists are drawn from) has the
    // classified cars only; car 2 is in the other list, where typing it
    // gives the loud warning.
    assert.deepEqual(info.validCars().map((car) => car.numero), [1, 3]);
    assert.deepEqual(info.unclassifiedCars().map((car) => car.numero), [2]);
    // The settings the pit page and the banner depend on follow as well.
    assert.equal(info.fuelingEnabled(), true);
    assert.equal(info.simulationAllowed(), true);
    assert.deepEqual(info.serverCarsInside(AREA.BOX), [1]);
    assert.deepEqual(info.serverCarsInside(AREA.ABASTECIMENTO), [3]);

    // And they are still known after a reload with no network.
    device.server.behaviour = 'offline';
    await device.reload();
    const afterReload = createPageInfo(device.store, SHELL_CONFIG);
    assert.deepEqual(afterReload.validCars().map((car) => car.numero), [1, 3]);
    assert.equal(afterReload.simulationAllowed(), true);
});

test('the two lists are taken together: no classified car yet is not "use the old list"', async () => {
    // SITUATION: the classification field was just configured and nobody is
    // marked yet. The server says: classified [], unclassified [1, 2, 3].
    const device = await createDevice();
    device.server.validCars = [];
    device.server.unclassifiedCars = [{ numero: 1, nome: 'Um' }, { numero: 2, nome: 'Dois' }, { numero: 3, nome: 'Três' }];
    const info = createPageInfo(device.store, SHELL_CONFIG);

    await device.queue.syncOnce();

    // EXPECTED: no tiles (nobody may race yet), and every car gives the
    // "não classificado" warning. Mixing the server's unclassified list
    // with the page's stale classified list would show cars 1 and 2 in both.
    assert.deepEqual(info.validCars(), []);
    assert.deepEqual(info.unclassifiedCars().map((car) => car.numero), [1, 2, 3]);
});

test('an answer with no car at all keeps the lists printed into the page', async () => {
    // An event with no car does not exist: two empty lists mean "teams not
    // marked as present yet", and the page's own lists are better than none.
    const device = await createDevice();
    device.server.validCars = [];
    device.server.unclassifiedCars = [];
    const info = createPageInfo(device.store, SHELL_CONFIG);

    await device.queue.syncOnce();

    assert.deepEqual(info.validCars().map((car) => car.numero), [1, 2]);
});

test('the simulation flag follows the server: off again after the setting is switched off', async () => {
    // The "SIMULAÇÃO" banner and the simulation hook appear and disappear
    // with the prova setting, without a reload.
    const device = await createDevice();
    const info = createPageInfo(device.store, { ...SHELL_CONFIG, simulacao: true });
    assert.equal(info.simulationAllowed(), true, 'from the page, before any answer');

    device.server.simulacao = false;
    await device.queue.syncOnce();

    assert.equal(info.simulationAllowed(), false);
});
