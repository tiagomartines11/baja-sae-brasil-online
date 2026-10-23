// The invariant of the safe mode, checked over many simulated races, with
// no database and no web server:
//
//   in "confirmadas" mode, with no reviewer action, a car's official lap
//   count never exceeds the number of laps it has really completed.
//
// For each seed this script builds a real-scale race with the simulation's
// own modules (the same race and the same counters' mistakes a browser or
// the Node runner would play for that seed) and hands it to the PHP engine
// (scripts/contagem-invariante.php, run inside the app container), which
// counts it at several moments of the race and compares with the truth.
//
// THE KNOWN WAY TO BREAK IT is TWO COUNTERS MAKING THE SAME MISTAKE at the
// same moment: both typing the same wrong number for one crossing, or both
// typing a car that is standing in the pit lane. Two witnesses then agree
// on a pass that never happened; when it lands a believable lap after that
// car's last pass, nothing in the car's own entries tells it from a real
// one. This script knows every mistake that was injected, so it sorts each
// violation into one of those two kinds or "anything else". "Anything
// else" is a bug, and the run fails on it. The two kinds are counted and
// reported (and fail the run only with --sem-erros-compartilhados).
//
//     node baja-php/scripts/contagem-invariante.mjs --ate 300
//
// Options: --de N (first seed, default 1), --ate N (last seed, default 300),
//          --carros N (default 64), --volta-media-s N (default 230),
//          --contadores N (default 3), --container baja-app,
//          --taxas '{"numero_errado": 20}' (error rates per thousand),
//          --sem-erros-compartilhados 1 (fail on shared typos too)
//
// Exit code 0 when no race broke the invariant in an unexplained way.

import { spawn } from 'node:child_process';
import { generateRaceScript, AREA_BOX } from '../default/js/contagem/simulacao-corrida.js';
import { planCounter, planStopLog } from '../default/js/contagem/simulacao-contador.js';

const options = Object.fromEntries(process.argv.slice(2).reduce((pairs, arg, index, all) => {
    if (arg.startsWith('--')) pairs.push([arg.slice(2), all[index + 1]]);
    return pairs;
}, []));
const FIRST_SEED = Number(options.de ?? 1);
const LAST_SEED = Number(options.ate ?? 300);
const CARS = Number(options.carros ?? 64);
const LAP_MS = Number(options['volta-media-s'] ?? 230) * 1000;
const COUNTERS = Number(options.contadores ?? 3);
const CONTAINER = options.container ?? 'baja-app';
const RATES = options.taxas ? JSON.parse(options.taxas) : {};
const STRICT = options['sem-erros-compartilhados'] !== undefined;
/** Any fixed moment will do: the engine only looks at differences. */
const START_MS = 1_760_000_000_000;
/** The default matching window: two entries this close are one pass. */
const WINDOW_MS = 20000;

/** Car numbers the way an event has them: not consecutive, one and two digits, a few of three. */
function carNumbers(count) {
    const numbers = [];
    for (let number = 1; numbers.length < count; number++) {
        if (number % 10 !== 0 || number > 90) numbers.push(number);
    }
    return numbers;
}

/** The race of a seed, with every counter's plan. */
function buildRace(seed) {
    const script = generateRaceScript({ semente: seed, carros: carNumbers(CARS), voltaMediaMs: LAP_MS, largadaMs: START_MS });
    const plans = [];
    for (let fonteId = 1; fonteId <= COUNTERS; fonteId++) {
        plans.push(planCounter({ roteiro: script, semente: seed, fonteId, taxas: RATES }));
    }
    return { script, plans, stops: planStopLog({ roteiro: script, semente: seed, area: AREA_BOX }) };
}

function raceLine(seed, { script, plans, stops }) {
    return JSON.stringify({
        semente: seed,
        largada_ms: script.largadaMs,
        fim_ms: script.fimMs,
        carros: script.carros,
        travessias: script.travessias.map((crossing) => [crossing.carro, crossing.tsMs]),
        entradas: plans.flatMap((plan, index) => plan.acoes.map((action) => [index + 1, action.carro, action.tsMs])),
        paradas: stops.acoes.map((action) => [action.tipo, action.carro, action.tsMs]),
    }) + '\n';
}

const SHARED_TYPO = 'two counters typed the same wrong number';
const SHARED_PIT_STRAY = 'two counters typed a car that was standing in the pit lane';

/**
 * Was this car given a pass, up to that moment, by TWO counters each
 * typing its number when it did not cross? Only entries the plans recorded
 * as injected mistakes are looked at. Returns which kind, or null.
 *
 * - SHARED_TYPO: both mistyped another car's number as this one.
 * - SHARED_PIT_STRAY: at least one of the two typed this car while it was
 *   in the pit lane. The engine keeps such a pass counted on purpose when
 *   two counters agree (a missed pit exit is the usual cause) and flags it
 *   `no_box`; here the counters were wrong and the pit log was right.
 */
function sharedMistakeFor(car, upToMs, plans) {
    const mistakes = plans.flatMap((plan, index) => plan.erros
        .filter((error) => (error.tipo === 'numero_errado' || error.tipo === 'no_box') && error.digitado === car && error.tsMs <= upToMs)
        .map((error) => ({ counter: index, tsMs: error.tsMs, tipo: error.tipo })));
    let found = null;
    for (const first of mistakes) {
        for (const second of mistakes) {
            if (second.counter === first.counter || Math.abs(second.tsMs - first.tsMs) > WINDOW_MS) continue;
            if (first.tipo === 'no_box' || second.tipo === 'no_box') return SHARED_PIT_STRAY;
            found = SHARED_TYPO;
        }
    }
    return found;
}

const engine = spawn('docker', ['exec', '-i', CONTAINER, 'php', 'scripts/contagem-invariante.php'], { stdio: ['pipe', 'pipe', 'inherit'] });
const racesBySeed = new Map();
const tally = { races: 0, sharedTypoRaces: [], pitStrayRaces: [], unexplainedRaces: [], shortAtTheEnd: 0 };
let pending = '';
engine.stdout.on('data', (chunk) => {
    pending += chunk;
    const lines = pending.split('\n');
    pending = lines.pop();
    for (const line of lines.filter((candidate) => candidate !== '')) {
        let result = null;
        try {
            result = JSON.parse(line);
        } catch {
            // Not a result: PHP printed an error. Show it; the count of races below will not add up.
            console.error(line);
            continue;
        }
        tally.races++;
        tally.shortAtTheEnd += result.a_menos;
        const { plans } = racesBySeed.get(result.semente);
        racesBySeed.delete(result.semente);
        const reasons = result.violacoes.map((violation) => sharedMistakeFor(violation.carro, violation.ate_ms, plans));
        const unexplainedIndex = reasons.indexOf(null);
        if (unexplainedIndex !== -1) {
            tally.unexplainedRaces.push(result.semente);
            const first = result.violacoes[unexplainedIndex];
            console.log(`seed ${result.semente}: UNEXPLAINED, car ${first.carro} had ${first.voltas} official laps and ${first.verdade} true at ${Math.round((first.ate_ms - START_MS) / 1000)} s`);
        } else {
            if (reasons.includes(SHARED_TYPO)) tally.sharedTypoRaces.push(result.semente);
            if (reasons.includes(SHARED_PIT_STRAY)) tally.pitStrayRaces.push(result.semente);
        }
    }
});

for (let seed = FIRST_SEED; seed <= LAST_SEED; seed++) {
    const race = buildRace(seed);
    racesBySeed.set(seed, race);
    // Wait when the pipe is full: a race is a few hundred kilobytes.
    if (!engine.stdin.write(raceLine(seed, race))) {
        await new Promise((resolve) => engine.stdin.once('drain', resolve));
    }
}
engine.stdin.end();
const exitCode = await new Promise((resolve) => engine.on('close', resolve));
const expectedRaces = LAST_SEED - FIRST_SEED + 1;
if (exitCode !== 0 || tally.races !== expectedRaces) {
    console.error(`The engine side ended with code ${exitCode} after ${tally.races} of ${expectedRaces} races.`);
    process.exit(1);
}

console.log(
    `${tally.races} races of ${CARS} cars, ${COUNTERS} counters, average lap ${LAP_MS / 1000} s, checked at 6 moments each.\n`
    + `Races with a car above the truth for a reason OTHER than two counters making the same mistake: ${tally.unexplainedRaces.length}.\n`
    + `Races with a car above the truth because ${SHARED_TYPO}: ${tally.sharedTypoRaces.length}`
    + (tally.sharedTypoRaces.length > 0 ? ` (seeds ${tally.sharedTypoRaces.join(', ')})` : '') + '.\n'
    + `Races with a car above the truth because ${SHARED_PIT_STRAY}: ${tally.pitStrayRaces.length}`
    + (tally.pitStrayRaces.length > 0 ? ` (seeds ${tally.pitStrayRaces.join(', ')})` : '') + '.\n'
    + `Crossings not counted at the end (allowed; they wait for a person): ${tally.shortAtTheEnd}, ${(tally.shortAtTheEnd / tally.races).toFixed(1)} per race.`,
);
const failed = tally.unexplainedRaces.length > 0 || (STRICT && tally.sharedTypoRaces.length + tally.pitStrayRaces.length > 0);
process.exit(failed ? 1 : 0);
