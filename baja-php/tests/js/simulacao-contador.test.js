// THE RACE SIMULATION, PART 2: what each simulated person types, and when.
//
// Background: all tabs share one race script (see simulacao-corrida.test.js).
// On top of it each simulated COUNTER adds what a human adds: a little delay
// around each crossing, and mistakes at small rates. The mistakes must be
//
//   - different for each counter (or all three would agree on every typo
//     and no flag would ever be raised),
//   - the same every time for the same counter and seed (so a rehearsal can
//     be repeated and compared),
//   - written down (the "injected errors"), so that the review screen can
//     be checked against them afterwards.
//
// The PLAYER then hands each planned action to the page at the right
// moment. It is tested here with a fake clock: time only moves when the
// test says so.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { Prng } from '../../default/js/contagem/simulacao-prng.js';
import { generateRaceScript, AREA_BOX, AREA_ABASTECIMENTO } from '../../default/js/contagem/simulacao-corrida.js';
import {
    planCounter, planStopLog, wrongNumberFor, timingFor, withStableIds, ERRO, TAXAS_PADRAO,
} from '../../default/js/contagem/simulacao-contador.js';
import {
    playPlan, windowFitsLap, replayOverrunMs, mayStartSimulation,
} from '../../default/js/contagem/simulacao-execucao.js';
import { injectedErrors } from '../../default/js/contagem/simulacao-gabarito.js';

const START_MS = 1_760_000_000_000;
const CARS = [3, 7, 12, 25, 41, 58, 64, 77, 80, 99];
const NO_MISTAKES = Object.fromEntries(Object.keys(TAXAS_PADRAO).map((name) => [name, 0]));

const race = generateRaceScript({
    semente: 42, carros: CARS, voltaMediaMs: 240_000, largadaMs: START_MS, abastecimento: true,
});

function counter(fonteId, overrides = {}) {
    return planCounter({ roteiro: race, semente: 42, fonteId, ...overrides });
}

// ---------------------------------------------------------------------------
// A counter's plan
// ---------------------------------------------------------------------------

test('a counter who makes no mistakes types every crossing once, close to when it happened', () => {
    // Situation: all error rates set to zero.
    // Expected: one entry per crossing, for the right car, from half a
    // second before to a second and a half after the car crossed.
    const plan = counter(1, { taxas: NO_MISTAKES });

    assert.equal(plan.acoes.length, race.travessias.length);
    assert.deepEqual(plan.erros, []);
    const crossingsLeft = race.travessias.map((crossing) => ({ ...crossing }));
    for (const action of plan.acoes) {
        const match = crossingsLeft.findIndex(
            (crossing) => crossing.carro === action.carro && action.tsMs >= crossing.tsMs - 500 && action.tsMs <= crossing.tsMs + 1500,
        );
        assert.notEqual(match, -1, 'every entry belongs to a crossing');
        crossingsLeft.splice(match, 1);
    }
});

test('the same counter makes the same mistakes every time; another counter makes different ones', () => {
    // Situation: the simulation is run twice with seed 42 on source 1, and
    // once on source 2.
    assert.deepEqual(counter(1), counter(1));
    assert.notDeepEqual(counter(1).erros, counter(2).erros);
    assert.notDeepEqual(counter(1).acoes, counter(2).acoes);
});

test('every kind of mistake is injected, and each one is written down with its time', () => {
    // Situation: error rates turned up to 15 % each, so that a single race
    // certainly contains every kind. One car (9) is present but not
    // classified, to give that mistake something to type.
    const loud = Object.fromEntries(Object.keys(TAXAS_PADRAO).map((name) => [name, 150]));
    const plan = counter(1, { taxas: loud, naoClassificados: [9] });
    const kinds = new Set(plan.erros.map((error) => error.tipo));

    for (const kind of [ERRO.PERDIDO, ERRO.NUMERO_ERRADO, ERRO.TOQUE_DUPLO, ERRO.REPETIDA, ERRO.DESCONHECIDO, ERRO.NAO_CLASSIFICADO, ERRO.NO_BOX]) {
        assert.ok(kinds.has(kind), 'the plan contains a mistake of kind ' + kind);
    }
    assert.ok(plan.erros.every((error) => Number.isInteger(error.tsMs) && error.fonteId === 1));
});

test('a missed car is not typed; a wrong number is typed instead of the right one', () => {
    // Situation: this counter misses every car (rate 1000 per thousand).
    const blind = counter(1, { taxas: { ...NO_MISTAKES, perdido: 1000 } });
    assert.equal(blind.acoes.length, 0);
    assert.equal(blind.erros.length, race.travessias.length);

    // Situation: this one mistypes every car.
    const clumsy = counter(1, { taxas: { ...NO_MISTAKES, numero_errado: 1000 } });
    assert.equal(clumsy.acoes.length, race.travessias.length, 'still one entry per crossing');
    for (const error of clumsy.erros) {
        assert.equal(error.tipo, ERRO.NUMERO_ERRADO);
        assert.notEqual(error.digitado, error.carro);
    }
});

test('entries for a car in the pit lane are only made up for cars that really are there', () => {
    const plan = counter(1, { taxas: { ...NO_MISTAKES, no_box: 1000 } });
    const strays = plan.erros.filter((error) => error.tipo === ERRO.NO_BOX);

    assert.ok(strays.length > 0);
    for (const stray of strays) {
        const standingThere = race.paradas.some(
            (stop) => stop.area === AREA_BOX && stop.carro === stray.digitado && stop.entradaMs <= stray.tsMs && stray.tsMs < stop.saidaMs,
        );
        assert.equal(standingThere, true);
    }
});

/** Every wrong number drawn for a car over many attempts, with how often each came up. */
function typosOf(car, carsInTheRace = new Set(), attempts = 4000) {
    const prng = new Prng(1);
    const timesByNumber = new Map();
    for (let attempt = 0; attempt < attempts; attempt++) {
        const typed = wrongNumberFor(car, prng, carsInTheRace);
        timesByNumber.set(typed, (timesByNumber.get(typed) ?? 0) + 1);
    }
    return timesByNumber;
}

test('wrong numbers are the ones fingers make on a keypad', () => {
    // Situation: a counter means 41 and slips.
    // Expected: only these come out, and never 41 itself:
    //   a key next to the 4 (1, 5, 7) or next to the 1 (2, 4): 11, 51, 71, 42, 44
    //   the two digits swapped: 14
    //   the first digit typed twice: 44 (the same as one of the above)
    //   the second digit lost: 4
    const typos = typosOf(41);

    assert.deepEqual([...typos.keys()].sort((a, b) => a - b), [4, 11, 14, 42, 44, 51, 71]);
});

test('a key next to the right one is the commonest slip, a lost digit the rarest', () => {
    // Why it matters: the simulation is used to measure how often two
    // counters make the SAME mistake. That figure is only worth anything
    // if the mistakes are spread the way real ones are.
    const typos = typosOf(41);
    const neighbourKey = typos.get(11) + typos.get(51) + typos.get(71) + typos.get(42);

    assert.ok(neighbourKey > typos.get(14), 'neighbouring keys beat swapped digits');
    assert.ok(typos.get(14) > typos.get(4), 'swapped digits beat a lost digit');
    assert.ok(typos.get(4) < 4000 / 20, 'a lost digit is under one slip in twenty');
});

test('a lost digit never lands on a car of the race', () => {
    // Situation: cars 4 and 40 to 49 are all in the race.
    // What went wrong before: a lost digit turned every one of 40..49 into
    // "4". Ten cars kept sending their typos to the same car, so "two
    // counters typed the same wrong number" came up on car 4 several times
    // more often than it would in a real race.
    // Expected: with car 4 in the race, none of them is ever mistyped as
    // 4. A lost digit that lands on no car is still simulated.
    const race = new Set([4, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49]);
    for (const car of [40, 41, 42, 43, 44, 45, 46, 47, 48, 49]) {
        assert.equal(typosOf(car, race, 1000).has(4), false, 'car ' + car + ' is never typed as 4');
    }
    // Car 4 is not in this race: 41 may lose its digit, and "4" is then an unknown number.
    assert.equal(typosOf(41, new Set([41, 42]), 1000).has(4), true);
});

test('a single-digit car is only ever mistyped as a key next to it', () => {
    assert.deepEqual([...typosOf(7).keys()].sort((a, b) => a - b), [4, 8]);
    // Nobody types "0" for a car: the 8 next to it has 0 as a neighbour, and that is left out.
    assert.deepEqual([...typosOf(8).keys()].sort((a, b) => a - b), [5, 7, 9]);
});

test('in a compressed rehearsal the typing times shrink with the matching window', () => {
    // Situation: a real race has a matching window of 20 s; a rehearsal
    // with 10-second laps has one of about a second.
    // What went wrong in the first draft: counters typed up to 1.5 s late
    // whatever the window, so with a 1-second window the three counters'
    // entries for one crossing were no longer matched to each other.
    // Expected: with the real window the times are the human ones; with a
    // short window everything fits inside it.
    assert.deepEqual(
        [timingFor(20_000).earlyMs, timingFor(20_000).lateMs, timingFor(20_000).doubleTapMaxMs],
        [500, 1500, 900],
    );
    const short = timingFor(1000);
    assert.ok(short.earlyMs + short.lateMs < 1000);
    assert.ok(short.doubleTapMaxMs <= 200);

    const compressedRace = generateRaceScript({ semente: 42, carros: CARS, voltaMediaMs: 10_000, largadaMs: START_MS });
    const plan = planCounter({ roteiro: compressedRace, semente: 42, fonteId: 1, janelaMs: 1000, taxas: NO_MISTAKES });
    plan.acoes.forEach((action, index) => {
        const crossing = compressedRace.travessias.find(
            (candidate) => candidate.carro === action.carro && Math.abs(candidate.tsMs - action.tsMs) <= 500,
        );
        assert.ok(crossing, 'entry ' + index + ' is within half a second of its crossing');
    });
});

// ---------------------------------------------------------------------------
// The people at the pit lane and the fueling station
// ---------------------------------------------------------------------------

test('a careful pit-log person logs an entry and an exit for every stop of their area', () => {
    const careful = { perdido: 0, atrasado: 0 };
    const pitPlan = planStopLog({ roteiro: race, semente: 42, area: AREA_BOX, taxas: careful });
    const fuelPlan = planStopLog({ roteiro: race, semente: 42, area: AREA_ABASTECIMENTO, taxas: careful });
    const pitStops = race.paradas.filter((stop) => stop.area === AREA_BOX);

    assert.equal(pitPlan.acoes.length, 2 * pitStops.length);
    assert.ok(pitPlan.acoes.every((action) => action.tipo === 'box_entrada' || action.tipo === 'box_saida'));
    assert.ok(fuelPlan.acoes.every((action) => action.tipo.startsWith('abastecimento_')));
    assert.deepEqual(pitPlan.erros, []);
});

test('missed and late pit events are injected and written down', () => {
    // Situation: the person at the pit lane misses one event in five and
    // is late with one in five.
    const plan = planStopLog({ roteiro: race, semente: 42, area: AREA_BOX, taxas: { perdido: 200, atrasado: 200 } });
    const missed = plan.erros.filter((error) => error.tipo === ERRO.PARADA_PERDIDA);
    const late = plan.erros.filter((error) => error.tipo === ERRO.PARADA_ATRASADA);
    const events = 2 * race.paradas.filter((stop) => stop.area === AREA_BOX).length;

    assert.ok(missed.length > 0 && late.length > 0);
    assert.equal(plan.acoes.length, events - missed.length, 'a missed event is simply not logged');
});

test('the list of injected mistakes of several people is one list, oldest first', () => {
    const all = injectedErrors([counter(1), counter(2), planStopLog({ roteiro: race, semente: 42, area: AREA_BOX })]);
    const times = all.map((error) => error.tsMs);

    assert.equal(all.length, counter(1).erros.length + counter(2).erros.length + planStopLog({ roteiro: race, semente: 42, area: AREA_BOX }).erros.length);
    assert.deepEqual(times, [...times].sort((a, b) => a - b));
});

// ---------------------------------------------------------------------------
// "??": a counter who could not read the number
// ---------------------------------------------------------------------------

test('"??" is off unless asked for: the default plan is the plan a seed always made', () => {
    // The "??" draws come from a stream of their own, so leaving the rate
    // out (or at 0) changes nothing in any other mistake.
    const withoutIt = Object.fromEntries(Object.entries(TAXAS_PADRAO).filter(([name]) => !name.startsWith('ilegivel')));
    assert.equal(TAXAS_PADRAO.ilegivel, 0);
    assert.deepEqual(counter(1, { taxas: withoutIt }), counter(1));
    assert.ok(counter(1).acoes.every((action) => Number.isInteger(action.carro)));
});

test('with `ilegivel`, some crossings are typed as "??" (car null), written down with the real car', () => {
    const plan = counter(1, { taxas: { ...NO_MISTAKES, ilegivel: 200 } });
    const unread = plan.acoes.filter((action) => action.carro === null);
    const noted = plan.erros.filter((error) => error.tipo === ERRO.ILEGIVEL);

    assert.ok(unread.length > 0);
    assert.equal(plan.acoes.length, race.travessias.length, 'still one entry per crossing');
    assert.equal(noted.length, unread.length);
    for (const error of noted) {
        assert.ok(CARS.includes(error.carro), 'the answer key says which car it was');
        assert.equal(error.digitado, null);
    }
    // Every entry is still typed when it would have been without "??".
    const times = (taxas) => counter(1, { taxas }).acoes.map((action) => action.tsMs);
    assert.deepEqual(times({ ...TAXAS_PADRAO, ilegivel: 200 }), times(TAXAS_PADRAO));
});

test('with `ilegivel_corrigido`, the counter later gives some "??" their number', () => {
    const plan = counter(1, { taxas: { ...NO_MISTAKES, ilegivel: 300, ilegivel_corrigido: 500 } });
    const fixes = plan.acoes.filter((action) => action.corrige);

    assert.ok(fixes.length > 0);
    for (const fix of fixes) {
        assert.equal(fix.corrige.carro, null, 'it points at a "??" action of the same plan');
        assert.ok(plan.acoes.includes(fix.corrige));
        const later = fix.tsMs - fix.corrige.tsMs;
        assert.ok(later >= 5000 && later <= 15000, 'some seconds after: ' + later);
        assert.ok(CARS.includes(fix.carro));
    }
    assert.equal(plan.erros.filter((error) => error.tipo === ERRO.ILEGIVEL_CORRIGIDO).length, fixes.length);
    const ided = withStableIds(plan, { semente: 42, largadaMs: START_MS, voltaMediaMs: 240_000 }, 1);
    assert.ok(ided.acoes.filter((action) => action.corrige).every((fix) => typeof fix.corrige.uuid === 'string'),
        'the page is told which entry to correct');
});

// ---------------------------------------------------------------------------
// The player
// ---------------------------------------------------------------------------

/** A clock that only moves when "waited on": each wait advances it by exactly what was asked. */
function fakeTime(startMs = 0) {
    let nowMs = startMs;
    return {
        agora: () => nowMs,
        esperar: async (ms) => { nowMs += ms; },
        jump(ms) { nowMs += ms; },
    };
}

const THREE_ACTIONS = [
    { tsMs: START_MS + 1000, carro: 7 },
    { tsMs: START_MS + 2000, carro: 41 },
    { tsMs: START_MS + 61_000, carro: 7 },
];

test('each entry is recorded with the time in the plan, not the time the timer fired', async () => {
    // Situation: the tab is in the background and the browser lets its
    // timers run only once in a long while: here every "wait" is followed
    // by the clock jumping seven seconds further than asked.
    // What goes wrong otherwise: entries stamped with "now" land seconds
    // away from the crossing, differently in each tab, and the three
    // counters stop agreeing.
    // Expected: all three actions are recorded, each with exactly its
    // planned time.
    const time = fakeTime();
    const recorded = [];
    const player = playPlan({
        acoes: THREE_ACTIONS,
        registrar: async (action) => { recorded.push(action.tsMs); },
        agora: time.agora,
        esperar: async (ms) => { await time.esperar(ms); time.jump(7000); },
        inicioCorridaMs: START_MS,
    });

    const result = await player.terminou;

    assert.deepEqual(recorded, THREE_ACTIONS.map((action) => action.tsMs));
    assert.deepEqual(result, { registradas: 3, falhas: 0 });
});

test('live mode waits for each action\'s moment', async () => {
    // Situation: real time (speed 1). The third action is a minute after
    // the second.
    // Expected: when the third one is recorded, about 61 seconds have passed.
    const time = fakeTime();
    const recordedAfterMs = [];
    const player = playPlan({
        acoes: THREE_ACTIONS,
        registrar: async () => { recordedAfterMs.push(time.agora()); },
        agora: time.agora,
        esperar: time.esperar,
        inicioCorridaMs: START_MS,
    });
    await player.terminou;

    assert.ok(recordedAfterMs[0] >= 1000 && recordedAfterMs[0] < 1300);
    assert.ok(recordedAfterMs[2] >= 61_000 && recordedAfterMs[2] < 61_300);
});

test('replay mode plays the same plan thirty times faster, with the same times on the entries', async () => {
    // Situation: `velocidade: 30`. The race lies in the past; a four-hour
    // race is played back in eight minutes.
    // Expected: the action planned 61 s into the race is recorded about 2 s
    // into the replay, still carrying its own planned time.
    const time = fakeTime();
    const recorded = [];
    const player = playPlan({
        acoes: THREE_ACTIONS,
        registrar: async (action) => { recorded.push({ plannedMs: action.tsMs, afterMs: time.agora() }); },
        agora: time.agora,
        esperar: time.esperar,
        velocidade: 30,
        inicioCorridaMs: START_MS,
    });
    await player.terminou;

    assert.equal(recorded[2].plannedMs, START_MS + 61_000);
    assert.ok(recorded[2].afterMs >= 2033 && recorded[2].afterMs < 2400);
});

test('a tab that joins a live race late first catches up with what it missed', async () => {
    // Situation: the simulation is started in this tab when the race is
    // already at 0:00:30. Two actions lie in the past.
    // Expected: they are recorded at once (with their planned, past times),
    // so this counter's log is complete, and the rest follows in real time.
    const time = fakeTime();
    const recordedAfterMs = [];
    const player = playPlan({
        acoes: THREE_ACTIONS,
        registrar: async () => { recordedAfterMs.push(time.agora()); },
        agora: time.agora,
        esperar: time.esperar,
        inicioCorridaMs: START_MS + 30_000,
    });
    await player.terminou;

    assert.deepEqual(recordedAfterMs.slice(0, 2), [0, 0]);
    assert.ok(recordedAfterMs[2] >= 31_000);
});

test('"parar" stops the simulation, and a failed entry does not', async () => {
    // Situation: the page refuses the second action (the shift was ended,
    // say). Later the person stops the simulation before the third.
    const time = fakeTime();
    const recorded = [];
    let player = null;
    player = playPlan({
        acoes: THREE_ACTIONS,
        registrar: async (action) => {
            if (action.carro === 41) {
                player.parar();
                throw new Error('turno encerrado');
            }
            recorded.push(action.carro);
        },
        agora: time.agora,
        esperar: time.esperar,
        inicioCorridaMs: START_MS,
    });

    const result = await player.terminou;

    assert.deepEqual(recorded, [7]);
    assert.deepEqual(result, { registradas: 1, falhas: 1 });
});

test('a clock that has no answer for a moment makes the race wait, not hang or skip', async () => {
    // Situation: in live mode the race follows the page's official clock.
    // For a few seconds the page has no official time (it is checking its
    // clock against the server) and answers null.
    // What went wrong in the first version: arithmetic on "no time" gave
    // "not a number", and the loop spun without ever waiting.
    // Expected: while there is no time nothing is recorded; when the clock
    // is back the race carries on from where the clock says it is.
    let officialMs = START_MS;
    let waits = 0;
    const recorded = [];
    const player = playPlan({
        acoes: THREE_ACTIONS,
        registrar: async (action) => { recorded.push(action.carro); },
        agora: () => (waits >= 1 && waits <= 3 ? null : officialMs),
        esperar: async (ms) => { waits++; officialMs += ms; },
        inicioCorridaMs: START_MS,
    });

    const result = await player.terminou;

    assert.deepEqual(recorded, [7, 41, 7]);
    assert.equal(result.falhas, 0);
});

// ---------------------------------------------------------------------------
// Running it twice, and the three refusals
// ---------------------------------------------------------------------------

test('the same action gets the same id every time, so running the simulation twice injects nothing twice', () => {
    // Situation: a tab is reloaded in the middle of a rehearsal and the
    // command is typed again. The plan is built again from scratch.
    // What went wrong before: every record got a fresh random id, so the
    // server stored the whole race a second time.
    // Expected: each action has the same id as the first time. The server
    // keeps one record per id, so the second copy is dropped.
    const raceKey = { semente: 42, largadaMs: START_MS, voltaMediaMs: 240_000 };
    const first = withStableIds(counter(1), raceKey, 1);
    const again = withStableIds(counter(1), raceKey, 1);

    assert.deepEqual(first.acoes.map((action) => action.uuid), again.acoes.map((action) => action.uuid));
    assert.equal(new Set(first.acoes.map((action) => action.uuid)).size, first.acoes.length, 'no two actions share an id');
    // The server accepts ids of 8 to 36 letters, digits and hyphens.
    assert.ok(first.acoes.every((action) => /^[A-Za-z0-9-]{8,36}$/.test(action.uuid)));
});

test('another counter, another start or another seed gives other ids', () => {
    // Otherwise counter 2's entries would be taken for copies of counter
    // 1's, or this afternoon's rehearsal for a copy of this morning's.
    const raceKey = { semente: 42, largadaMs: START_MS, voltaMediaMs: 240_000 };
    const idOfFirstAction = (key, who) => withStableIds(counter(1), key, who).acoes[0].uuid;
    const reference = idOfFirstAction(raceKey, 1);

    assert.notEqual(idOfFirstAction(raceKey, 2), reference);
    assert.notEqual(idOfFirstAction({ ...raceKey, largadaMs: START_MS + 1000 }, 1), reference);
    assert.notEqual(idOfFirstAction({ ...raceKey, semente: 43 }, 1), reference);
    assert.notEqual(idOfFirstAction({ ...raceKey, voltaMediaMs: 60_000 }, 1), reference);
    assert.notEqual(withStableIds(planStopLog({ roteiro: race, semente: 42, area: AREA_BOX }), raceKey, AREA_BOX).acoes[0].uuid, reference);
});

test('a simulation does not start on a prova that publishes, unless the prova is named on purpose', () => {
    // Situation: the prova's counts go to the official results.
    // Expected: refused. Made-up passes must not reach the results page by
    // accident. Typing the prova's own id as confirmation is the way to
    // say "yes, on purpose"; anything else is not.
    assert.equal(mayStartSimulation({ publica: true, provaId: 'END', confirmacao: undefined }), false);
    assert.equal(mayStartSimulation({ publica: true, provaId: 'END', confirmacao: true }), false);
    assert.equal(mayStartSimulation({ publica: true, provaId: 'END', confirmacao: 'XYZ' }), false);
    assert.equal(mayStartSimulation({ publica: true, provaId: 'END', confirmacao: 'END' }), true);
    // Publishing off ("desligada"): nothing to confirm.
    assert.equal(mayStartSimulation({ publica: false, provaId: 'END', confirmacao: undefined }), true);
    // A page that does not know yet (no answer from the server) is not told "go ahead".
    assert.equal(mayStartSimulation({ publica: undefined, provaId: 'END', confirmacao: undefined }), false);
});

test('a replay must lie wholly in the past', () => {
    // Situation: a replay of a four-hour race. The start on record is only
    // three hours ago.
    // Expected: an hour of the race would lie in the future; the command
    // refuses and can say by how much the start is too late.
    const fourHoursMs = 4 * 3_600_000;
    const now = START_MS + 3 * 3_600_000;

    assert.equal(replayOverrunMs(START_MS, fourHoursMs, now), 3_600_000);
    // Started four hours and one minute ago: fine.
    assert.ok(replayOverrunMs(START_MS, fourHoursMs, START_MS + fourHoursMs + 60_000) <= 0);
});

test('the simulation only starts when the matching window fits the lap', () => {
    // The real settings (window of 20 s) with real laps: fine.
    assert.equal(windowFitsLap(20_000, 230_000), true);
    // The real settings with one-minute laps: a third of a lap would be
    // "the same crossing". Refused; the person must apply the test preset.
    assert.equal(windowFitsLap(20_000, 60_000), false);
    // After the test preset for one-minute laps (window of 5 s): fine.
    assert.equal(windowFitsLap(5000, 60_000), true);
});
