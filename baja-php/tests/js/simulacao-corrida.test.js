// THE RACE SIMULATION, PART 1: the shared race script and its answer key.
//
// Background: for rehearsals, the count pages can play a made-up race by
// themselves. Three browser tabs (three "counters") and the pit page must
// all play THE SAME race, or their entries would never agree and the
// rehearsal would show nothing. They do not talk to each other; each builds
// the race from the same few inputs:
//
//   - a "seed": any whole number; the same seed gives the same race;
//   - the list of cars;
//   - the average lap time;
//   - the start time (the real one, from the server).
//
// The result is the "script" (roteiro): every crossing of the line by every
// car, every pit and fueling stop, every breakdown and retirement. The
// "answer key" (gabarito) is each car's true lap count, taken from the
// script alone.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { Prng, deriveSeed, scale } from '../../default/js/contagem/simulacao-prng.js';
import {
    generateRaceScript, raceDurationFor, carsInsideAt, AREA_BOX, AREA_ABASTECIMENTO,
} from '../../default/js/contagem/simulacao-corrida.js';
import {
    trueLapCounts, lapCountsCsv, errorsCsv, raceTime, INICIAL_LARGADA,
} from '../../default/js/contagem/simulacao-gabarito.js';

const START_MS = 1_760_000_000_000;
const CARS = [3, 7, 12, 25, 41, 58, 64, 77, 80, 99];

function script(overrides = {}) {
    return generateRaceScript({
        semente: 42, carros: CARS, voltaMediaMs: 60_000, largadaMs: START_MS, abastecimento: true, ...overrides,
    });
}

// ---------------------------------------------------------------------------
// The random numbers
// ---------------------------------------------------------------------------

test('the same seed always gives the same sequence of numbers', () => {
    // Situation: two browsers each create a generator with seed 42.
    // Expected: they draw exactly the same numbers. These particular values
    // are written down here on purpose: if anybody changes the generator,
    // every recorded simulation stops being reproducible, and this test says so.
    const first = new Prng(42);
    const second = new Prng(42);
    const drawn = [first.next(), first.next(), first.next()];

    assert.deepEqual(drawn, [second.next(), second.next(), second.next()]);
    assert.deepEqual(drawn, [11355432, 2836018348, 476557059]);
});

test('every number drawn is a whole number inside the range asked for', () => {
    const prng = new Prng(7);
    for (let draw = 0; draw < 2000; draw++) {
        const value = prng.between(-500, 1500);
        assert.ok(Number.isInteger(value) && value >= -500 && value <= 1500);
    }
});

test('each counter gets a different stream of numbers from the same shared seed', () => {
    // Situation: counters on sources 1, 2 and 3 all run the simulation with
    // seed 42. Their mistakes must not be the same mistakes.
    const seeds = [deriveSeed(42, 1), deriveSeed(42, 2), deriveSeed(42, 3)];

    assert.equal(new Set(seeds).size, 3);
    // And the same counter gets the same stream every time.
    assert.equal(deriveSeed(42, 2), seeds[1]);
});

test('durations are scaled in whole numbers', () => {
    // 60 000 ms slowed down by 7 % (1070 per thousand).
    assert.equal(scale(60_000, 1070), 64_200);
    // Rounded DOWN, never to a fraction of a millisecond.
    assert.equal(scale(1001, 333), 333);
});

// ---------------------------------------------------------------------------
// The script
// ---------------------------------------------------------------------------

test('the same seed, cars, lap and start give the identical race', () => {
    // Situation: the count page of counter 1 and the Node runner both build
    // the race for seed 42.
    // Expected: not one millisecond of difference.
    assert.deepEqual(script(), script());
});

test('the order in which a page happened to receive the car list does not matter', () => {
    // Situation: one tab got the cars as 3, 7, 12...; another, after a
    // reload, as 99, 80, 77... Both must still build the same race.
    const reversed = [...CARS].reverse();

    assert.deepEqual(script({ carros: reversed }), script());
});

test('another seed gives another race', () => {
    assert.notDeepEqual(script({ semente: 43 }).travessias, script().travessias);
});

test('every time in the script is a whole number of milliseconds', () => {
    // Why it matters: fractions are where two machines can round differently.
    const race = script();
    const times = [
        ...race.travessias.map((crossing) => crossing.tsMs),
        ...race.paradas.flatMap((stop) => [stop.entradaMs, stop.saidaMs]),
    ];

    assert.ok(times.length > 100);
    assert.ok(times.every(Number.isInteger));
});

test('the whole field crosses the line shortly after the start, and the race has the length of a real one', () => {
    // Situation: an average lap of 60 s.
    // Expected: the simulated race has as many laps as a real four-hour
    // race has at a real four-minute lap (60 of them), so it lasts an hour.
    // Every car's first crossing (its "pass zero") comes within the first
    // lap: the counting line is just after the start line.
    const race = script();
    const firstCrossingByCar = new Map();
    for (const crossing of race.travessias) {
        if (!firstCrossingByCar.has(crossing.carro)) {
            firstCrossingByCar.set(crossing.carro, crossing.tsMs);
        }
    }

    assert.equal(raceDurationFor(60_000), 3_600_000);
    assert.equal(race.fimMs, START_MS + 3_600_000);
    assert.equal(firstCrossingByCar.size, CARS.length);
    for (const firstMs of firstCrossingByCar.values()) {
        assert.ok(firstMs > START_MS && firstMs < START_MS + 60_000);
    }
    assert.ok(race.travessias.every((crossing) => crossing.tsMs <= race.fimMs));
});

test('crossings are listed in the order they happen', () => {
    const times = script().travessias.map((crossing) => crossing.tsMs);

    assert.deepEqual(times, [...times].sort((a, b) => a - b));
});

test('a car never crosses the line while the script has it standing in an area', () => {
    // Situation: the script sends cars to the pit lane and to the fueling
    // station. A stop happens DURING a lap: the car leaves before its next
    // crossing.
    const race = script({ semente: 5 });
    assert.ok(race.paradas.length > 0, 'this seed has stops');

    for (const stop of race.paradas) {
        assert.ok(stop.saidaMs > stop.entradaMs);
        const crossedWhileInside = race.travessias.some(
            (crossing) => crossing.carro === stop.carro && crossing.tsMs >= stop.entradaMs && crossing.tsMs <= stop.saidaMs,
        );
        assert.equal(crossedWhileInside, false);
    }
});

test('fueling stops exist only when the prova logs the fueling station', () => {
    const without = script({ abastecimento: false });
    const withFueling = script({ abastecimento: true });

    assert.equal(without.paradas.filter((stop) => stop.area === AREA_ABASTECIMENTO).length, 0);
    assert.ok(withFueling.paradas.some((stop) => stop.area === AREA_ABASTECIMENTO));
    assert.ok(withFueling.paradas.some((stop) => stop.area === AREA_BOX));
});

test('a retired car makes no more crossings', () => {
    // Find a seed with a retirement (they are rare) and check that car.
    let race = null;
    for (let seed = 1; seed <= 50 && (race === null || race.abandonos.length === 0); seed++) {
        race = script({ semente: seed });
    }
    assert.ok(race.abandonos.length > 0, 'one of fifty seeds has a retirement');

    for (const retirement of race.abandonos) {
        const later = race.travessias.filter((crossing) => crossing.carro === retirement.carro && crossing.tsMs > retirement.tsMs);
        assert.equal(later.length, 0);
    }
});

test('"which cars are inside the pit lane now" follows the script', () => {
    const race = script({ semente: 5 });
    const stop = race.paradas.find((candidate) => candidate.area === AREA_BOX);

    assert.ok(carsInsideAt(race, AREA_BOX, stop.entradaMs + 1).includes(stop.carro));
    assert.ok(!carsInsideAt(race, AREA_BOX, stop.entradaMs - 1).includes(stop.carro));
    assert.ok(!carsInsideAt(race, AREA_BOX, stop.saidaMs).includes(stop.carro));
});

// ---------------------------------------------------------------------------
// The answer key
// ---------------------------------------------------------------------------

/** A tiny hand-made script: car 7 crosses three times, car 41 once, car 99 never. */
const TINY = {
    largadaMs: START_MS,
    carros: [7, 41, 99],
    travessias: [
        { carro: 7, tsMs: START_MS + 10_000 },
        { carro: 41, tsMs: START_MS + 12_000 },
        { carro: 7, tsMs: START_MS + 70_000 },
        { carro: 7, tsMs: START_MS + 130_000 },
    ],
};

test('under the default rule the first crossing is "pass zero": laps are crossings minus one', () => {
    // Situation: the counting line is after the start line, so the first
    // time a car crosses it has not completed a lap yet.
    // Expected: car 7 (three crossings) has 2 laps; car 41 (one) has 0; car
    // 99, which never crossed, has 0 and is still listed.
    const laps = trueLapCounts(TINY);

    assert.deepEqual([...laps], [[7, 2], [41, 0], [99, 0]]);
});

test('under the "largada" rule the start is pass zero: every crossing is a lap', () => {
    const laps = trueLapCounts(TINY, { passagemInicial: INICIAL_LARGADA });

    assert.deepEqual([...laps], [[7, 3], [41, 1], [99, 0]]);
});

test('in a live race the answer key can be asked "as of now"', () => {
    // Situation: one minute and a half into the race, car 7 has crossed twice.
    const laps = trueLapCounts(TINY, { ateMs: START_MS + 90_000 });

    assert.equal(laps.get(7), 1);
});

test('the answer key downloads as two small spreadsheets', () => {
    const lapsCsv = lapCountsCsv(trueLapCounts(TINY));
    const mistakesCsv = errorsCsv(
        [{ tipo: 'numero_errado', fonteId: 2, tsMs: START_MS + 70_400, carro: 7, digitado: 77 }],
        START_MS,
    );

    assert.equal(lapsCsv, 'carro;voltas\r\n7;2\r\n41;0\r\n99;0\r\n');
    assert.equal(
        mistakesCsv,
        'tipo;tempo_de_prova;ts_ms;fonte_id;area;carro_real;digitado\r\n'
        + 'numero_errado;0:01:10;' + (START_MS + 70_400) + ';2;;7;77\r\n',
    );
});

test('times are shown as time of race', () => {
    assert.equal(raceTime(START_MS + 3_725_000, START_MS), '1:02:05');
    assert.equal(raceTime(START_MS - 5_000, START_MS), '-0:00:05');
});
