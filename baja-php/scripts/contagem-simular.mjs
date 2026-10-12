// A whole simulated race against the DEV stack, with no browser, checked
// against its answer key.
//
// It uses the modules the pages load: the simulation's own
// (default/js/contagem/simulacao-*.js), so the race and each counter's
// mistakes are exactly what a browser plays for the same seed; and the
// count page's local store, upload queue and server clock, so the client
// is exercised too. Only the screens are missing.
//
// WHAT IT DOES
//
//   1. Sets the race up (inside the app container, through
//      scripts/contagem-simular-apoio.php): WIPES the prova's lap counting
//      data, starts a race far enough in the past to hold the whole race,
//      and creates a device for each counter and one for the pit log.
//   2. Replays the race, `velocidade` times faster than real time: N
//      counters, the pit lane and (when the prova logs it) the fueling
//      station, each with their own injected mistakes.
//   3. THE INVARIANT. Several times during the replay and at its end,
//      before any reviewer has done anything: no car may have more
//      published laps than it has really completed. The one known way to
//      break it is two counters making the SAME mistake at the same
//      moment (the same wrong number, or the same car standing in the pit
//      lane); the runner knows every injected mistake, reports such a
//      case as the known limit, and fails on a car that is over for any
//      other reason.
//   4. Stage 1, the suggestions: accepts every suggestion the review
//      screen offers, then compares EVERY car (unclassified ones too)
//      crossing by crossing with the laps export. Tolerated: a crossing
//      that fewer than two counters typed for the right car; and what a
//      shared mistake left wrong, PROVIDED a flag is open on a car it
//      touched. Anything else fails. Also checked: LAST, and the stops in
//      the public feed against the script.
//   5. Stage 2, the person: for what stage 1 tolerated, does what a
//      reviewer does (counts the lone entry, adds the pass, moves a
//      shared wrong number to the right car, voids a shared pit-lane
//      entry). Then every car must equal the answer key exactly.
//   6. "Reconstruir" must change nothing: rebuild equals published.
//
// Exit code 0 when all of that holds, 1 otherwise.
//
// BEFORE RUNNING IT: on the settings page switch "Permitir simulação" on;
// the publish mode must be "confirmadas" and the time settings the real
// ones (this is a replay at real scale). The runner refuses otherwise and
// never changes a setting. Since the prova publishes, the prova's id must
// be given as confirmation:
//
//     node baja-php/scripts/contagem-simular.mjs --semente 42 --confirmar-publicacao END
//
// Options: --semente N (default 1), --volta-media-s N (default 230),
//          --velocidade N (default 240; a four-hour race in a minute),
//          --contadores N (default 3), --evento 26BR, --prova END,
//          --domain baja.local, --container baja-app,
//          --revisor juiz1 (a seeded dev user, password 123456),
//          --taxas '{"perdido": 10}' (error rates per thousand, see
//          simulacao-contador.js)
//
// Never point it at production.

import { generateRaceScript, raceDurationFor, AREA_BOX, AREA_ABASTECIMENTO } from '../default/js/contagem/simulacao-corrida.js';
import { planCounter, planStopLog, withStableIds } from '../default/js/contagem/simulacao-contador.js';
import { playPlan, mayStartSimulation } from '../default/js/contagem/simulacao-execucao.js';
import { trueLapCounts, injectedErrors } from '../default/js/contagem/simulacao-gabarito.js';
import {
    pedidosDaSugestao, pedidoParaAlerta, pedidoParaEntrada, pedidoSemAlvo,
} from '../default/js/contagem/revisao-decisoes.js';
import {
    ClientDevice, Reviewer, runHelper, fail, matchCrossings, countedTimesByCar, raceClock, CROSSING_TOLERANCE_MS,
} from './contagem-simular-lib.mjs';

const options = Object.fromEntries(process.argv.slice(2).reduce((pairs, arg, index, all) => {
    if (arg.startsWith('--')) pairs.push([arg.slice(2), all[index + 1]]);
    return pairs;
}, []));
const SEED = Number(options.semente ?? 1);
const LAP_MS = Number(options['volta-media-s'] ?? 230) * 1000;
const SPEED = Number(options.velocidade ?? 240);
const COUNTERS = Number(options.contadores ?? 3);
const EVENTO = options.evento ?? '26BR';
const PROVA = options.prova ?? 'END';
const DOMAIN = options.domain ?? 'baja.local';
const CONTAINER = options.container ?? 'baja-app';
const RATES = options.taxas ? JSON.parse(options.taxas) : {};

const JUIZ = `http://juiz.${DOMAIN}/${EVENTO}/`;
const RESULTADOS = `http://resultados.${DOMAIN}/`;
const helper = (...args) => runHelper(CONTAINER, EVENTO, PROVA, ...args);
/** How many times during the replay the invariant is checked, besides the end. */
const CHECKPOINTS = 5;

// This runner needs the prova to publish (it compares published counts),
// which is exactly what a simulation must not do by accident.
if (!mayStartSimulation({ publica: true, provaId: PROVA, confirmacao: options['confirmar-publicacao'] })) {
    fail(`This run publishes a simulated race to the results of prova ${PROVA}. To confirm, add: --confirmar-publicacao ${PROVA}`);
}

// ------------------------------------------------------------ the race

const durationMs = raceDurationFor(LAP_MS);
// The whole race must lie in the past, pit stops included: a car that
// starts its last lap just before the end may break down and stop on it,
// and leaves the pit lane several laps' worth of time after the end.
const AFTER_THE_END_MS = 6 * LAP_MS + 60000;
const setup = await helper('preparar', Date.now() - durationMs - AFTER_THE_END_MS, COUNTERS);
const script = generateRaceScript({
    semente: SEED, carros: setup.carros, voltaMediaMs: LAP_MS, largadaMs: setup.largada_ms, abastecimento: setup.abastecimento,
});
const raceKey = { semente: SEED, largadaMs: script.largadaMs, voltaMediaMs: LAP_MS };
console.log(`Simulation: seed ${SEED}, average lap ${LAP_MS / 1000} s, ${COUNTERS} counters, replay ${SPEED}x against ${JUIZ} (prova ${PROVA})`);
console.log(`${script.carros.length} cars, ${script.travessias.length} crossings, ${script.paradas.length} stops, ${script.abandonos.length} retirements`);

const counterPlans = setup.fontes.map((source) => withStableIds(planCounter({
    roteiro: script, semente: SEED, fonteId: source.id,
    naoClassificados: setup.nao_classificados, janelaMs: setup.janela_ms, taxas: RATES,
}), raceKey, source.id));
const stopAreas = setup.abastecimento ? [AREA_BOX, AREA_ABASTECIMENTO] : [AREA_BOX];
const stopPlans = stopAreas.map((area) => withStableIds(planStopLog({ roteiro: script, semente: SEED, area }), raceKey, area));

const carList = setup.carros.map((numero) => ({ numero, nome: `Carro ${numero}` }));
const openDevice = (name, token) => ClientDevice.open({ name, syncUrl: `${JUIZ}contagem_sync.php`, token, cars: carList, janelaMs: setup.janela_ms });
const counterDevices = [];
for (const source of setup.fontes) counterDevices.push(await openDevice(source.nome, source.token));
const pitDevice = await openDevice('pit log', setup.box_token);
const allDevices = [...counterDevices, pitDevice];

const truthAt = (ateMs) => trueLapCounts(script, { passagemInicial: setup.passagem_inicial, ateMs });

/**
 * THE INVARIANT: with no reviewer action, no car has more published laps
 * than it has really completed by that moment.
 *
 * @param {number} upToMs every action planned up to this race time has been uploaded
 * @returns {Promise<Array<{car: number, upToMs: number, voltas: number, truth: number}>>} the cars that are over
 */
async function carsAboveTheTruth(upToMs) {
    for (const device of allDevices) await device.flushEverything();
    const published = await helper('publicado');
    // A crossing typed up to this moment happened at most half a second later.
    const truth = truthAt(upToMs + 1000);
    return Object.entries(published)
        .filter(([car, { voltas }]) => voltas > (truth.get(Number(car)) ?? 0))
        .map(([car, { voltas }]) => ({ car: Number(car), upToMs, voltas, truth: truth.get(Number(car)) ?? 0 }));
}

// One merged plan, so that "everything up to now" is the same moment for
// every device when the invariant is checked.
const merged = [
    ...counterPlans.flatMap((plan, index) => plan.acoes.map((action) => ({ ...action, record: () => counterDevices[index].recordPass(action) }))),
    ...stopPlans.flatMap((plan) => plan.acoes.map((action) => ({ ...action, record: () => pitDevice.recordStop(action) }))),
].sort((a, b) => a.tsMs - b.tsMs);
const checkpointTimes = Array.from({ length: CHECKPOINTS }, (unused, index) => script.largadaMs + Math.trunc((durationMs * (index + 1)) / (CHECKPOINTS + 1)));
const violations = [];
let recorded = 0;

const replayStartedAt = Date.now();
await playPlan({
    acoes: merged,
    registrar: async (action) => {
        if (checkpointTimes.length > 0 && action.tsMs > checkpointTimes[0]) {
            violations.push(...await carsAboveTheTruth(checkpointTimes.shift()));
        }
        await action.record();
        // Uploading as it goes, in the batches a phone would send.
        if (++recorded % 300 === 0) await Promise.all(allDevices.map((device) => device.queue.flush()));
    },
    agora: () => performance.now(),
    esperar: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
    velocidade: SPEED,
    inicioCorridaMs: script.largadaMs,
}).terminou;
for (const device of allDevices) await device.sayEverythingWasSent();
violations.push(...await carsAboveTheTruth(script.fimMs));
const refused = allDevices.reduce((sum, device) => sum + device.refusedCount(), 0);
console.log(`Replay done in ${Math.round((Date.now() - replayStartedAt) / 1000)} s: ${merged.length} records through the client modules, ${refused} refused`);

const errors = injectedErrors([...counterPlans, ...stopPlans]);
const errorCounts = {};
for (const error of errors) errorCounts[error.tipo] = (errorCounts[error.tipo] ?? 0) + 1;
console.log('Injected mistakes:', JSON.stringify(errorCounts));

// ------------------------------------------------------------ what the plans say

/** How many counters typed this crossing for the right car. */
function witnessesOf(crossing) {
    return counterPlans.filter((plan) => plan.acoes.some(
        (action) => action.carro === crossing.carro && Math.abs(action.tsMs - crossing.tsMs) <= 1500,
    )).length;
}

/**
 * THE KNOWN LIMIT of "a pass counts when two counters agree": two counters
 * making the SAME mistake at the same moment. Either both type the same
 * wrong number for one crossing, or both type a car that is standing in the
 * pit lane. Two witnesses then agree on a pass of `typedCar` that never
 * happened. Each group found here is such a moment, from the injected
 * mistakes: who typed what, and for a wrong number, which car really crossed.
 *
 * @returns {Array<{typedCar: number, tsMs: number, entries: Array<{fonteId: number, tsMs: number, realCar: ?number, crossingMs: ?number}>}>}
 */
function sharedMistakes() {
    const byTypedCar = new Map();
    counterPlans.forEach((plan, index) => {
        for (const error of plan.erros.filter((candidate) => candidate.tipo === 'numero_errado' || candidate.tipo === 'no_box')) {
            const crossing = error.tipo === 'numero_errado'
                ? script.travessias.find((candidate) => candidate.carro === error.carro && Math.abs(candidate.tsMs - error.tsMs) <= 1500)
                : null;
            const entry = { counter: index, fonteId: setup.fontes[index].id, tsMs: error.tsMs, realCar: crossing ? error.carro : null, crossingMs: crossing ? crossing.tsMs : null };
            if (!byTypedCar.has(error.digitado)) byTypedCar.set(error.digitado, []);
            byTypedCar.get(error.digitado).push(entry);
        }
    });
    const groups = [];
    for (const [typedCar, entries] of byTypedCar) {
        entries.sort((a, b) => a.tsMs - b.tsMs);
        let current = [];
        for (const entry of [...entries, null]) {
            // Entries within one matching window of the first belong together.
            if (entry !== null && (current.length === 0 || entry.tsMs - current[0].tsMs <= setup.janela_ms)) {
                current.push(entry);
                continue;
            }
            if (new Set(current.map((member) => member.counter)).size >= 2) {
                groups.push({ typedCar, tsMs: current[0].tsMs, entries: current });
            }
            current = entry === null ? [] : [entry];
        }
    }
    return groups;
}
const mistakes = sharedMistakes();
const describeMistake = (group) => (group.entries.some((entry) => entry.realCar !== null)
    ? `${group.entries.length} counters typed ${group.typedCar} for car ${group.entries.find((entry) => entry.realCar !== null).realCar}`
    : `${group.entries.length} counters typed car ${group.typedCar} while it stood in the pit lane`);
/** The shared mistake that put a phantom pass on this car around this moment, if any. */
const phantomSourceFor = (car, aroundMs, withinMs) => mistakes.find((group) => group.typedCar === car && Math.abs(group.tsMs - aroundMs) <= withinMs);

const problems = [];
if (refused > 0) problems.push(`${refused} records were refused by the server`);
if (violations.length === 0) {
    console.log(`Invariant held at ${CHECKPOINTS} points during the race and at the end: no car above the truth.`);
} else {
    console.log(`\nWith no reviewer action, ${violations.length} time(s) a car had more laps than it had run:`);
    for (const violation of violations) {
        // Any shared mistake on that car so far explains one lap too many.
        const cause = mistakes.find((group) => group.typedCar === violation.car && group.tsMs <= violation.upToMs);
        console.log(`  car ${violation.car}: published ${violation.voltas}, true ${violation.truth} at ${raceClock(violation.upToMs, script.largadaMs)}; `
            + (cause ? `the known limit: ${describeMistake(cause)}` : 'UNEXPLAINED'));
        if (!cause) problems.push(`the invariant was broken for car ${violation.car} with no shared mistake to explain it`);
    }
}

/**
 * Every car, crossing by crossing, against the laps export.
 *
 * @returns {Promise<Array<{car: number, missing: number[], phantom: number[]}>>} only the cars with a difference
 */
async function differencesPerCrossing(reviewer) {
    const counted = countedTimesByCar(await reviewer.exportRows('voltas'));
    const everyCar = new Set([...script.carros, ...setup.nao_classificados, ...counted.keys()]);
    const differences = [];
    for (const car of [...everyCar].sort((a, b) => a - b)) {
        const trueTimes = script.travessias.filter((crossing) => crossing.carro === car).map((crossing) => crossing.tsMs);
        const { missing, phantom } = matchCrossings(trueTimes, counted.get(car) ?? []);
        if (missing.length > 0 || phantom.length > 0) differences.push({ car, missing, phantom });
    }
    return differences;
}

// ------------------------------------------------------------ stage 1: the suggestions

const reviewer = await Reviewer.login({ juizUrl: JUIZ, forumUrl: `http://forum.${DOMAIN}/`, prova: PROVA, username: options.revisor ?? 'juiz1' });
const accepted = {};
const notAccepted = new Set();
let state = await reviewer.state();
// Far more rounds than any race needs; only so that a bug cannot loop for ever.
for (let round = 0; round < 5000; round++) {
    const flag = (state.alertas_abertos ?? []).find((candidate) => candidate.sugestao && !notAccepted.has(candidate.id));
    if (!flag) break;
    const requests = pedidosDaSugestao(flag, state);
    // No request: the screen would need the loaded timeline to act on this
    // one (the suggestion names an entry outside the flag). Left for a person.
    let everyRequestStored = requests.length > 0;
    for (const request of requests) {
        const answer = await reviewer.decide(request);
        everyRequestStored &&= answer.stored;
        state = answer.state ?? await reviewer.state();
    }
    // Refused (409): what the flag was about changed under us, because an
    // earlier decision resolved it too. Do not try that flag again.
    if (everyRequestStored) accepted[flag.tipo] = (accepted[flag.tipo] ?? 0) + 1;
    else notAccepted.add(flag.id);
}
console.log('Suggestions accepted, by flag type:', JSON.stringify(accepted));
const openFlags = state.alertas_abertos ?? [];
const openCounts = {};
for (const flag of openFlags) openCounts[flag.tipo] = (openCounts[flag.tipo] ?? 0) + 1;
console.log('Flags left open:', JSON.stringify(openCounts));

/**
 * "Either right, or with an open flag": is a flag open on a car this
 * shared mistake touched (the car typed, or the car that really crossed),
 * around that moment? That flag is what leads a reviewer to it. A flag on
 * EVERY car touched cannot be asked for: when the third counter typed
 * nothing, the car that really crossed has no entry to hang a flag on.
 */
function hasAnOpenFlagNear(group) {
    const touched = new Set([group.typedCar, ...group.entries.map((entry) => entry.realCar).filter((car) => car !== null)]);
    return openFlags.some((flag) => (flag.carros ?? []).some((car) => touched.has(car)) && Math.abs(flag.ts_ms - group.tsMs) <= 2 * LAP_MS);
}

const afterSuggestions = await differencesPerCrossing(reviewer);
const forAPerson = { loneCrossings: [], mistakes: new Set() };
/** A difference caused by a shared mistake: tolerated with an open flag near it, a failure without. */
function judgeSharedMistake(group, what) {
    forAPerson.mistakes.add(group);
    if (hasAnOpenFlagNear(group)) return `the known limit (${describeMistake(group)}), with a flag open on a car it touched`;
    problems.push(`${what}: ${describeMistake(group)}, and no flag is open on any car it touched`);
    return `NOT TOLERATED: ${describeMistake(group)}, with no open flag on any car it touched`;
}
console.log(`\nStage 1 (suggestions only): ${afterSuggestions.length} car(s) differ from the script.`);
for (const { car, missing, phantom } of afterSuggestions) {
    for (const crossingMs of missing) {
        const crossing = { carro: car, tsMs: crossingMs };
        const witnesses = witnessesOf(crossing);
        // This crossing itself was mistyped by two counters, or a phantom on
        // this car got the car's own real pass held beside it.
        const mistypedAs = mistakes.find((group) => group.entries.some((entry) => entry.realCar === car && entry.crossingMs === crossingMs));
        const heldBeside = phantomSourceFor(car, crossingMs, 2 * LAP_MS);
        let verdict = `tolerated: only ${witnesses} counter(s) typed it right`;
        if (mistypedAs) {
            verdict = judgeSharedMistake(mistypedAs, `car ${car} is short`);
        } else if (witnesses >= 2 && heldBeside) {
            verdict = 'held beside a phantom pass; ' + judgeSharedMistake(heldBeside, `car ${car} has a real pass held`);
        } else if (witnesses >= 2) {
            verdict = 'NOT TOLERATED: two or more counters typed it right';
            problems.push(`car ${car} misses a crossing that ${witnesses} counters typed right`);
        } else {
            forAPerson.loneCrossings.push(crossing);
        }
        console.log(`  car ${car}: crossing at ${raceClock(crossingMs, script.largadaMs)} not counted; ${verdict}`);
    }
    for (const passMs of phantom) {
        const cause = phantomSourceFor(car, passMs, setup.janela_ms + CROSSING_TOLERANCE_MS);
        let verdict = 'NOT TOLERATED: no car crossed then and no two counters shared a mistake';
        if (cause) verdict = judgeSharedMistake(cause, `car ${car} has a pass that never happened`);
        else problems.push(`car ${car} has a counted pass at ${raceClock(passMs, script.largadaMs)} that never happened`);
        console.log(`  car ${car}: counted pass at ${raceClock(passMs, script.largadaMs)} with no crossing; ${verdict}`);
    }
}

// LAST: for every car that is right, the published time of its last pass.
const published = await helper('publicado');
const wrongCars = new Set(afterSuggestions.map((difference) => difference.car));
for (const car of script.carros.filter((candidate) => !wrongCars.has(candidate))) {
    const lastCrossing = script.travessias.filter((crossing) => crossing.carro === car).at(-1);
    if (!lastCrossing) continue;
    const trueSeconds = (lastCrossing.tsMs - script.largadaMs) / 1000;
    if (Math.abs((published[car]?.last ?? 0) - trueSeconds) > CROSSING_TOLERANCE_MS / 1000 + 1) {
        problems.push(`car ${car}: LAST is ${published[car]?.last}, its last crossing was at ${Math.round(trueSeconds)} s`);
    }
}
for (const car of setup.nao_classificados) {
    if ((published[car]?.voltas ?? 0) !== 0) problems.push(`unclassified car ${car} has ${published[car].voltas} published laps`);
}

// Stops: completed stays per car in the public feed, against the script.
// A stay is complete when its entry was logged and its exit was logged OR
// marked by the reviewer (the "Marcar saída" suggestions accepted above
// close stays whose exit the pit log missed). So the feed must show at
// least the stays with both ends logged, and at most those with an entry.
const feed = await (await fetch(`${RESULTADOS}contagem_feed.php?evt=${EVENTO}&p=${PROVA}`)).json();
stopAreas.forEach((area, index) => {
    const logged = (carro, tipo, aroundMs) => stopPlans[index].acoes.some((action) => action.carro === carro && action.tipo === tipo && action.tsMs >= aroundMs && action.tsMs <= aroundMs + 21000);
    for (const row of feed.carros ?? []) {
        const stops = script.paradas.filter((stop) => stop.area === area && stop.carro === row.numero);
        const withEntry = stops.filter((stop) => logged(stop.carro, `${area}_entrada`, stop.entradaMs));
        const withBothEnds = withEntry.filter((stop) => logged(stop.carro, `${area}_saida`, stop.saidaMs));
        const shown = row[area]?.paradas ?? 0;
        if (shown < withBothEnds.length || shown > withEntry.length) {
            problems.push(`car ${row.numero}: the feed shows ${shown} completed ${area} stay(s); the log has both ends of ${withBothEnds.length} and the entry of ${withEntry.length}`);
        }
    }
});

if (problems.length > 0) {
    console.log('\nFAIL:');
    for (const problem of problems) console.log('  ' + problem);
    await helper('encerrar');
    process.exit(1);
}

// ------------------------------------------------------------ stage 2: the person

const byHand = { reassigned: 0, voided: 0, confirmed: 0, added: 0 };
for (const group of forAPerson.mistakes) {
    for (const wanted of group.entries) {
        // Read afresh for every entry: each decision changes what the next chip looks like.
        const chip = (await reviewer.chipsOf(group.typedCar)).find((candidate) => candidate.fonte_id === wanted.fonteId && candidate.ts_ms === wanted.tsMs);
        if (!chip || chip.carro !== group.typedCar || chip.anulada) continue;
        // A wrong number goes to the car that really crossed ("era outro
        // carro"); an entry for a car that stood in the pit lane is voided.
        const decision = wanted.realCar !== null
            ? { tipo: 'reatribuir', passagem_id: chip.id, equipe_numero: wanted.realCar }
            : { tipo: 'anular', passagem_id: chip.id };
        const answer = await reviewer.decide(pedidoParaEntrada(decision, chip, await reviewer.state()));
        if (answer.stored) byHand[wanted.realCar !== null ? 'reassigned' : 'voided']++;
    }
}
// What a shared mistake left wrong may be settled now; only what still differs needs more.
const stillDiffering = await differencesPerCrossing(reviewer);
state = await reviewer.state();
for (const { car, missing } of stillDiffering) {
    for (const crossingMs of missing) {
        // "Contar esta passagem" on a flag about a lone or held pass of that
        // car at that moment; "adicionar passagem" when nobody typed it.
        const flag = (state.alertas_abertos ?? []).find((candidate) => (candidate.carros ?? []).includes(car)
            && candidate.efeito_de_confirmar === 'mais_uma_passagem'
            && Math.abs(candidate.ts_ms - crossingMs) <= CROSSING_TOLERANCE_MS && (candidate.passagem_ids ?? []).length > 0);
        const request = flag
            ? pedidoParaAlerta({ tipo: 'confirmar', passagem_id: flag.passagem_ids[0] }, flag, state, flag.assinatura ?? null)
            : pedidoSemAlvo({ tipo: 'adicionar', equipe_numero: car, ts_ms: crossingMs }, state);
        const answer = await reviewer.decide(request);
        if (answer.stored) byHand[flag ? 'confirmed' : 'added']++;
        state = answer.state ?? await reviewer.state();
    }
}
const resolvedByHand = forAPerson.mistakes.size + forAPerson.loneCrossings.length;
console.log(
    `\nStage 2 (a person): for ${forAPerson.mistakes.size} shared mistake(s), moved ${byHand.reassigned} entries to the right car and voided ${byHand.voided}; `
    + `counted ${byHand.confirmed} lone or held passes, added ${byHand.added} passes nobody typed right.`,
);

const afterAPerson = await differencesPerCrossing(reviewer);
const finalPublished = await helper('publicado');
const answerKey = truthAt(null);
const wrongTotals = [...answerKey].filter(([car, laps]) => (finalPublished[car]?.voltas ?? 0) !== laps);
if (afterAPerson.length > 0 || wrongTotals.length > 0) {
    console.log('\nFAIL: after a person resolved what stage 1 left, the race still differs from the answer key:');
    for (const { car, missing, phantom } of afterAPerson) console.log(`  car ${car}: ${missing.length} crossing(s) not counted, ${phantom.length} counted pass(es) that never happened`);
    for (const [car, laps] of wrongTotals) console.log(`  car ${car}: true ${laps}, published ${finalPublished[car]?.voltas ?? 0}`);
    await helper('encerrar');
    process.exit(1);
}

// ------------------------------------------------------------ rebuild equals published

const lapsBefore = JSON.stringify(await reviewer.exportRows('voltas'));
await helper('reconstruir');
const rebuiltPublished = await helper('publicado');
const lapsAfter = JSON.stringify(await reviewer.exportRows('voltas'));
await helper('encerrar');
if (JSON.stringify(rebuiltPublished) !== JSON.stringify(finalPublished) || lapsBefore !== lapsAfter) {
    console.log('\nFAIL: "Reconstruir" changed the result: the incremental count and a rebuild from the raw entries disagree.');
    process.exit(1);
}

const stage = resolvedByHand === 0
    ? 'after the suggestions alone'
    : `after a person resolved ${forAPerson.loneCrossings.length} lone crossing(s) and ${forAPerson.mistakes.size} shared mistake(s)`;
console.log(`\nPASS ${stage}: all ${answerKey.size} cars equal the answer key, crossing by crossing; rebuild equals published.`);
process.exit(0);
