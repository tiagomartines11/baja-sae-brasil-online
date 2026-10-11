// OWN-DATA WARNINGS: the two hints a counter gets right after typing.
//
// Background: the whole method rests on three people counting INDEPENDENTLY.
// If counter A could see what counter B typed, A would start copying or
// "correcting" towards B, and three counts would be worth one. So the count
// page may only warn about things it can tell from the counter's own typing:
//
//   1. "that number is not on the car list" (probably a typo);
//   2. "you already typed that car a few seconds ago" (probably a double tap).
//
// A warning never blocks or changes anything. The entry is recorded as typed;
// the counter decides whether to tap "desfazer".

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    AVISO, DEFAULT_JANELA_MS, carListIsKnown, computeWarnings, teamNameOf,
} from '../../default/js/contagem/warnings.js';
import { createDevice, FakeClock, FakeServer } from './helpers/fakes.js';

const CAR_LIST = [{ numero: 7, nome: 'Equipe Sete' }, { numero: 41, nome: 'Equipe Quarenta e Um' }];

/** An earlier entry of this same counter, as the local store keeps it. */
function ownEntry(car, tsDeviceMs, voided = false) {
    return { uuid: 'x-' + car + '-' + tsDeviceMs, car, ts_device_ms: tsDeviceMs, voided };
}

function warningTypes(input) {
    return computeWarnings(input).map((warning) => warning.tipo);
}

// ---------------------------------------------------------------------------
// Unknown car number
// ---------------------------------------------------------------------------

test('a number that is not on the car list produces a warning', () => {
    // Situation: the counter meant 41 and typed 14. There is no car 14.
    const warnings = computeWarnings({
        car: 14, tsDeviceMs: 100_000, ownEntries: [], validCars: CAR_LIST, janelaMs: 20_000,
    });

    // Expected: one warning, in Portuguese, that says the entry WAS recorded.
    assert.equal(warnings.length, 1);
    assert.equal(warnings[0].tipo, AVISO.NUMERO_DESCONHECIDO);
    assert.match(warnings[0].mensagem, /14/);
    assert.match(warnings[0].mensagem, /Registrado mesmo assim/);
});

test('an unknown number is still recorded and still uploaded', async () => {
    // Situation: same typo, seen end to end. Maybe it is not a typo at all: a
    // car that was added late and is not on this phone's list yet. Refusing the
    // entry would lose a real pass, so the system never refuses.
    const device = await createDevice();
    await device.queue.syncOnce();              // the phone learns the car list (7 and 41)

    const result = await device.type(14);
    await device.queue.syncOnce();

    assert.deepEqual(result.avisos.map((warning) => warning.tipo), [AVISO.NUMERO_DESCONHECIDO]);
    assert.equal(result.nomeEquipe, null, 'no team name to show for an unknown number');
    assert.deepEqual(device.store.allEntries().map((entry) => entry.car), [14]);
    assert.deepEqual(device.server.storedCars(), [14]);
});

test('a number on the car list produces no warning', () => {
    assert.deepEqual(warningTypes({
        car: 41, tsDeviceMs: 100_000, ownEntries: [], validCars: CAR_LIST, janelaMs: 20_000,
    }), []);
});

test('while the car list is not known yet, no number is called unknown', () => {
    // Situation: the page was opened for the first time with no network, so
    // it has no car list. Warning on every single entry would teach the
    // counter to ignore warnings.
    assert.deepEqual(warningTypes({
        car: 14, tsDeviceMs: 100_000, ownEntries: [], validCars: null, janelaMs: 20_000,
    }), []);
});

test('an EMPTY car list also means "not known", not "every car is wrong"', () => {
    // Situation: the organisers have not marked the teams as present yet, so
    // the list the server sends has no car in it. The counter types car 14,
    // which is a perfectly real car.
    //
    // What used to go wrong: an empty list was taken literally ("no number
    // is valid"), so EVERY entry flashed "número não está na lista" with the
    // warning sound, for the whole race.
    assert.deepEqual(warningTypes({
        car: 14, tsDeviceMs: 100_000, ownEntries: [], validCars: [], janelaMs: 20_000,
    }), []);

    // The page uses the same rule to decide whether to write "Número
    // desconhecido" under the number: only when it really has a list.
    assert.equal(carListIsKnown([]), false);
    assert.equal(carListIsKnown(null), false);
    assert.equal(carListIsKnown(CAR_LIST), true);
    assert.equal(teamNameOf(14, []), null);
});

// ---------------------------------------------------------------------------
// Same car again too soon
// ---------------------------------------------------------------------------

test('the same car typed again inside the window produces a warning', () => {
    // Situation: the counter typed 41, was not sure it registered, and typed
    // 41 again 4 seconds later. No car laps the track in 4 seconds.
    const warnings = computeWarnings({
        car: 41,
        tsDeviceMs: 104_000,
        ownEntries: [ownEntry(41, 100_000)],
        validCars: CAR_LIST,
        janelaMs: 20_000,
    });

    // Expected: a warning that says how long ago and what to do about it.
    assert.equal(warnings.length, 1);
    assert.equal(warnings[0].tipo, AVISO.REPETIDO);
    assert.match(warnings[0].mensagem, /4 s/);
    assert.match(warnings[0].mensagem, /desfazer/);
});

test('the same car typed again after the window produces no warning', () => {
    // Situation: car 41 comes round again 95 seconds later. A normal lap.
    assert.deepEqual(warningTypes({
        car: 41, tsDeviceMs: 195_000, ownEntries: [ownEntry(41, 100_000)], validCars: CAR_LIST, janelaMs: 20_000,
    }), []);
});

test('the window is "less than": exactly at its edge there is no warning', () => {
    // The rule in the specification is "same car LESS THAN janela_ms ago".
    const earlier = [ownEntry(41, 100_000)];

    assert.deepEqual(warningTypes({
        car: 41, tsDeviceMs: 119_999, ownEntries: earlier, validCars: CAR_LIST, janelaMs: 20_000,
    }), [AVISO.REPETIDO]);
    assert.deepEqual(warningTypes({
        car: 41, tsDeviceMs: 120_000, ownEntries: earlier, validCars: CAR_LIST, janelaMs: 20_000,
    }), []);
});

test('a different car inside the window produces no warning', () => {
    // Situation: cars 7 and 41 cross the line nose to tail.
    assert.deepEqual(warningTypes({
        car: 41, tsDeviceMs: 101_000, ownEntries: [ownEntry(7, 100_000)], validCars: CAR_LIST, janelaMs: 20_000,
    }), []);
});

test('only the most recent entry of that car matters', () => {
    // Situation: 41 was typed long ago AND 3 seconds ago.
    assert.deepEqual(warningTypes({
        car: 41,
        tsDeviceMs: 500_000,
        ownEntries: [ownEntry(41, 100_000), ownEntry(41, 497_000)],
        validCars: CAR_LIST,
        janelaMs: 20_000,
    }), [AVISO.REPETIDO]);
});

test('an entry the counter already undid does not count as "typed before"', () => {
    // Situation: the counter typed 41 by mistake, tapped "desfazer", and two
    // seconds later the real car 41 passes and is typed. That is a correct
    // entry and must not be greeted with "you already typed this".
    assert.deepEqual(warningTypes({
        car: 41,
        tsDeviceMs: 102_000,
        ownEntries: [ownEntry(41, 100_000, true)],
        validCars: CAR_LIST,
        janelaMs: 20_000,
    }), []);
});

test('the window comes from the prova settings, with 20 s as the fallback', () => {
    // Situation: the organisers shortened the window to 5 s for a short track.
    const earlier = [ownEntry(41, 100_000)];
    assert.deepEqual(warningTypes({
        car: 41, tsDeviceMs: 108_000, ownEntries: earlier, validCars: CAR_LIST, janelaMs: 5_000,
    }), []);

    // And a phone that never received the setting uses the default.
    assert.equal(DEFAULT_JANELA_MS, 20_000);
    assert.deepEqual(warningTypes({
        car: 41, tsDeviceMs: 108_000, ownEntries: earlier, validCars: CAR_LIST, janelaMs: null,
    }), [AVISO.REPETIDO]);
});

test('both warnings can appear together', () => {
    // Situation: the counter double-taps a number that does not exist.
    assert.deepEqual(warningTypes({
        car: 14, tsDeviceMs: 101_000, ownEntries: [ownEntry(14, 100_000)], validCars: CAR_LIST, janelaMs: 20_000,
    }), [AVISO.NUMERO_DESCONHECIDO, AVISO.REPETIDO]);
});

// ---------------------------------------------------------------------------
// Independence between counters
// ---------------------------------------------------------------------------

test('what another counter typed never causes a warning on this counter', async () => {
    // Situation: two counters, two phones, one server. Counter B types car 41
    // and it is uploaded. Two seconds later counter A, who has not typed 41
    // at all, types 41 for the same crossing.
    const clock = new FakeClock();
    const server = new FakeServer(clock);
    const phoneOfA = await createDevice({ server, clock, uuidPrefix: 'phone-a' });
    const phoneOfB = await createDevice({ server, clock, uuidPrefix: 'phone-b' });
    await phoneOfA.queue.syncOnce();
    await phoneOfB.type(41);
    await phoneOfB.queue.syncOnce();
    clock.advance(2000);
    await phoneOfA.queue.syncOnce();            // A talks to the server AFTER B's entry is there

    const result = await phoneOfA.type(41);

    // Expected: no warning for A. From A's own data this is the first 41.
    // If this test ever fails, counters have stopped being independent.
    assert.deepEqual(result.avisos, []);

    // B typing 41 again right away DOES warn B: that is B's own data.
    const secondOfB = await phoneOfB.type(41);
    assert.deepEqual(secondOfB.avisos.map((warning) => warning.tipo), [AVISO.REPETIDO]);
});

test('the warning function has no input through which other counters could leak', () => {
    // A guard for future changes: computeWarnings is given this device's
    // entries, the car list and the window. Extra data passed by mistake is
    // simply not looked at.
    const warnings = computeWarnings({
        car: 41,
        tsDeviceMs: 101_000,
        ownEntries: [],
        validCars: CAR_LIST,
        janelaMs: 20_000,
        otherCountersEntries: [ownEntry(41, 100_000)],
    });

    assert.deepEqual(warnings, []);
});

// ---------------------------------------------------------------------------
// Team name for the big flash
// ---------------------------------------------------------------------------

test('the team name shown after an entry comes from the car list', () => {
    assert.equal(teamNameOf(41, CAR_LIST), 'Equipe Quarenta e Um');
    assert.equal(teamNameOf(14, CAR_LIST), null);
    assert.equal(teamNameOf(41, null), null);
});

// ---------------------------------------------------------------------------
// A car that is at the event but not classified for this prova (brief 7)
// ---------------------------------------------------------------------------

const UNCLASSIFIED = [{ numero: 9, nome: 'Equipe Nove' }];

test('a pass for an unclassified car gets a loud warning of its own', () => {
    // SITUATION: car 9 is at the event but was not classified for the
    // endurance race (it failed the brake test). It goes out on the track
    // anyway, and the counter types it.
    const warnings = computeWarnings({
        car: 9, tsDeviceMs: 50_000, ownEntries: [], validCars: CAR_LIST, unclassifiedCars: UNCLASSIFIED, janelaMs: 20000,
    });

    // EXPECTED: "carro não classificado", marked `grave`: the page shows it
    // in the error colour with the error sound. It is NOT called an unknown
    // number: the car exists, it just may not race.
    assert.deepEqual(warnings.map((warning) => warning.tipo), [AVISO.NAO_CLASSIFICADO]);
    assert.equal(warnings[0].grave, true);
    assert.match(warnings[0].mensagem, /^CARRO NÃO CLASSIFICADO/);
    assert.match(warnings[0].mensagem, /Registrado mesmo assim/);
});

test('the other warnings are not "grave"', () => {
    const warnings = computeWarnings({
        car: 99, tsDeviceMs: 50_000, ownEntries: [ownEntry(99, 45_000)], validCars: CAR_LIST,
        unclassifiedCars: UNCLASSIFIED, janelaMs: 20000,
    });

    assert.deepEqual(warnings.map((warning) => [warning.tipo, warning.grave]),
        [[AVISO.NUMERO_DESCONHECIDO, false], [AVISO.REPETIDO, false]]);
});

test('a pass for an unclassified car is stored and uploaded like any other', async () => {
    // WHY: the phone's list can be out of date (the car was classified five
    // minutes ago), and a stored pass is what protects a protest. The
    // server stores it too, does not count it, and shows a reviewer a flag.
    const device = await createDevice();
    device.server.unclassifiedCars = UNCLASSIFIED;
    await device.queue.syncOnce();

    const result = await device.type(9);
    await device.queue.flush();

    assert.deepEqual(result.avisos.map((aviso) => aviso.tipo), [AVISO.NAO_CLASSIFICADO]);
    // The flash still shows whose car it is.
    assert.equal(result.nomeEquipe, 'Equipe Nove');
    assert.deepEqual(device.server.storedCars(), [9]);
});

test('a number in neither list is "unknown", also when every car is unclassified so far', () => {
    // Situation: the classification field was just set up and nobody is
    // marked yet: the classified list is empty, the other has everybody.
    const types = warningTypes({
        car: 500, tsDeviceMs: 50_000, ownEntries: [], validCars: [], unclassifiedCars: UNCLASSIFIED, janelaMs: 20000,
    });

    assert.deepEqual(types, [AVISO.NUMERO_DESCONHECIDO]);
});

// ---------------------------------------------------------------------------
// "Typed again too soon" must not depend on the phone's clock
// ---------------------------------------------------------------------------

test('the repeat warning survives the phone clock being changed between the two entries', async () => {
    // SITUATION: car 41 is typed; the phone's clock then jumps an hour
    // ahead; 6 seconds later the counter types 41 again by mistake.
    //
    // WHAT WOULD GO WRONG if the warning compared the phone-clock readings:
    // "an hour apart", no warning, and the double entry goes unnoticed.
    const device = await createDevice();
    await device.type(41);
    device.clock.stepWallClock(3_600_000);
    device.clock.advance(6000);

    const result = await device.type(41);

    // EXPECTED: the two entries are compared on a clock the step did not
    // touch: 6 seconds apart, warning shown.
    assert.deepEqual(result.avisos.map((aviso) => aviso.tipo), [AVISO.REPETIDO]);
    assert.match(result.avisos[0].mensagem, /há 6 s/);
});
