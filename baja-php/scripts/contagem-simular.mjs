// A whole simulated race against the DEV stack, with no browser, checked
// against its answer key.
//
// It uses the same simulation modules the count pages load
// (default/js/contagem/simulacao-*.js), so the race and each counter's
// mistakes are exactly the ones a browser would play for the same seed.
//
// WHAT IT DOES
//
//   1. Sets the race up (inside the app container, through
//      scripts/contagem-simular-apoio.php): WIPES the prova's lap counting
//      data, starts a race far enough in the past to hold the whole race,
//      and creates a device for each counter and one for the pit log.
//   2. Replays the race: N counters, the pit lane and (when the prova logs
//      it) the fueling station, each with their own injected mistakes.
//      Entries are uploaded to the real upload address with their
//      intended times, `velocidade` times faster than real time.
//   3. Plays the reviewer: logs in, reads the review state, and accepts
//      every suggestion through the real decision address, until no flag
//      offers one.
//   4. Compares the lap counts in the scoring system with the answer key.
//      A car may still be short, by exactly the crossings that fewer than
//      two counters typed right (no suggestion can make those official),
//      or over, by exactly the false passes on which two counters typed
//      the same wrong number. Any other difference fails the run.
//   5. For those crossings only, plays the person a reviewer is: counts
//      the lone entry, adds the pass, or reassigns the shared typo. Then
//      every car must match.
//      Exit code 0 when they do, 1 otherwise. The output says which of the
//      two stages the race needed.
//
// BEFORE RUNNING IT
//
// Switch "Permitir simulação" on for the prova (settings page). The runner
// refuses otherwise and never changes a setting itself. Use the prova's
// real time settings: this is a replay at real scale.
//
//     node baja-php/scripts/contagem-simular.mjs --semente 42
//
// Options: --semente N (default 1), --volta-media-s N (default 230),
//          --velocidade N (default 120; a four-hour race in two minutes),
//          --contadores N (default 3), --evento 26BR, --prova END,
//          --domain baja.local, --container baja-app,
//          --revisor juiz1 (a seeded dev user, password 123456),
//          --taxas '{"perdido": 10}' (error rates per thousand, see
//          simulacao-contador.js)
//
// Never point it at production.

import { execFile } from 'node:child_process';
import { generateRaceScript, raceDurationFor, AREA_BOX, AREA_ABASTECIMENTO } from '../default/js/contagem/simulacao-corrida.js';
import { planCounter, planStopLog } from '../default/js/contagem/simulacao-contador.js';
import { playPlan } from '../default/js/contagem/simulacao-execucao.js';
import { trueLapCounts, injectedErrors } from '../default/js/contagem/simulacao-gabarito.js';
import { pedidoParaAlerta, pedidoParaEntrada, pedidoSemAlvo } from '../default/js/contagem/revisao-decisoes.js';

const options = Object.fromEntries(process.argv.slice(2).reduce((pairs, arg, index, all) => {
    if (arg.startsWith('--')) pairs.push([arg.slice(2), all[index + 1]]);
    return pairs;
}, []));
const SEED = Number(options.semente ?? 1);
const LAP_MS = Number(options['volta-media-s'] ?? 230) * 1000;
const SPEED = Number(options.velocidade ?? 120);
const COUNTERS = Number(options.contadores ?? 3);
const EVENTO = options.evento ?? '26BR';
const PROVA = options.prova ?? 'END';
const DOMAIN = options.domain ?? 'baja.local';
const CONTAINER = options.container ?? 'baja-app';
const REVIEWER = options.revisor ?? 'juiz1';
const RATES = options.taxas ? JSON.parse(options.taxas) : {};

const JUIZ = `http://juiz.${DOMAIN}/${EVENTO}/`;
const FORUM = `http://forum.${DOMAIN}/`;
const SYNC_URL = `${JUIZ}contagem_sync.php`;
/** Entries per upload; a phone coming back from an hour offline sends batches like this. */
const BATCH_SIZE = 50;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function fail(message) {
    console.error(message);
    process.exit(1);
}

/** Runs the PHP helper inside the app container and returns what it printed, parsed. */
function helper(...args) {
    return new Promise((resolve) => {
        const command = ['exec', CONTAINER, 'php', 'scripts/contagem-simular-apoio.php', EVENTO, PROVA, ...args.map(String)];
        execFile('docker', command, { timeout: 120000 }, (error, stdout, stderr) => {
            if (error) fail(`The set-up helper failed: ${stderr.trim() || error.message}`);
            resolve(JSON.parse(stdout));
        });
    });
}

// ------------------------------------------------------------ uploading

/** A simulated device: what it has to send, and the sending. */
class Device {
    constructor(name, token) {
        this.name = name;
        this.token = token;
        this.waiting = [];
        this.sent = 0;
    }

    async post(body) {
        // "ocupado" (503) means the count was busy and nothing was stored:
        // the same batch is simply sent again, as a phone does.
        for (let attempt = 1; attempt <= 20; attempt++) {
            const response = await fetch(SYNC_URL, {
                method: 'POST',
                headers: { Authorization: `Bearer ${this.token}`, 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (response.status === 200) return response.json();
            if (response.status !== 503) fail(`${this.name}: upload refused with HTTP ${response.status}: ${await response.text()}`);
            await sleep(200 * attempt);
        }
        return fail(`${this.name}: the server stayed busy for twenty attempts.`);
    }

    /** Sends what is waiting, in batches, under the given key of the request body. */
    async flush(listName) {
        while (this.waiting.length > 0) {
            const batch = this.waiting.slice(0, BATCH_SIZE);
            const answer = await this.post({ entries: [], voids: [], pit_events: [], [listName]: batch, now_ms: Date.now(), queue_empty: false });
            if (answer.refused?.length > 0) fail(`${this.name}: the server refused entries: ${JSON.stringify(answer.refused[0])}`);
            const stored = new Set(answer.stored_uuids);
            if (!batch.every((record) => stored.has(record.uuid))) fail(`${this.name}: the server did not acknowledge a whole batch.`);
            this.waiting.splice(0, batch.length);
            this.sent += batch.length;
        }
    }

    /** "My queue is empty, and it is now": lets the engine conclude that what this device did not send, it did not see. */
    sayEverythingWasSent() {
        return this.post({ entries: [], voids: [], pit_events: [], now_ms: Date.now(), queue_empty: true });
    }
}

/** What a count page uploads for one key press, with the time the simulation intends. */
function entryRecord(action) {
    return {
        uuid: crypto.randomUUID(), car: action.carro, mode: 'simulacao', typing_ms: null,
        ts_ms: action.tsMs, time_quality: 'exato', ts_device_ms: action.tsMs, anchor: null,
    };
}

function stopRecord(action) {
    return {
        uuid: crypto.randomUUID(), car: action.carro, tipo: action.tipo, mode: 'simulacao',
        ts_ms: action.tsMs, time_quality: 'exato', ts_device_ms: action.tsMs,
    };
}

/** Plays one plan into a device, uploading as it goes. */
async function playInto(device, plan, listName, toRecord, raceStartMs) {
    const player = playPlan({
        acoes: plan.acoes,
        registrar: async (action) => { device.waiting.push(toRecord(action)); },
        agora: () => performance.now(),
        esperar: async (ms) => { await device.flush(listName); await sleep(ms); },
        velocidade: SPEED,
        inicioCorridaMs: raceStartMs,
    });
    await player.terminou;
    await device.flush(listName);
}

// ------------------------------------------------------------ reviewing

async function login(username) {
    const response = await fetch(`${FORUM}app.php/baja/login`, {
        method: 'POST', redirect: 'manual',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ username, password: '123456', redirect: `${JUIZ}index.php` }),
    });
    // The forum may set the same cookie twice in one answer; a browser keeps the last.
    const cookieByName = new Map();
    for (const line of response.headers.getSetCookie()) {
        const pair = line.split(';')[0];
        cookieByName.set(pair.slice(0, pair.indexOf('=')), pair);
    }
    if (cookieByName.size === 0) fail(`Could not log in as ${username}.`);
    return [...cookieByName.values()].join('; ');
}

/** The review state as the review screen polls it. */
async function reviewState(cookie) {
    const response = await fetch(`${JUIZ}contagem_estado.php?p=${PROVA}`, { headers: { Cookie: cookie } });
    if (response.status !== 200) fail(`The review state answered HTTP ${response.status}. Is ${REVIEWER} a controller of this prova?`);
    return response.json();
}

/**
 * Accepts every suggestion the review screen offers, one at a time, the
 * way a reviewer pressing "aceitar sugestão" does. Each decision changes
 * the race, so the state is read again after each one.
 *
 * @returns {Promise<{accepted: object, leftOver: Array}>} how many of each flag type were accepted, and the flags that remain
 */
async function acceptEverySuggestion(reviewer) {
    const accepted = {};
    const refusedFlagIds = new Set();
    let state = await reviewState(reviewer.cookie);
    // Far more than any race needs; only here so that a bug cannot loop for ever.
    for (let round = 0; round < 5000; round++) {
        const flag = (state.alertas_abertos ?? []).find((candidate) => candidate.sugestao && !refusedFlagIds.has(candidate.id));
        if (!flag) break;
        const answer = await sendDecision(reviewer, pedidoParaAlerta({ ...flag.sugestao }, flag, state, flag.assinatura ?? null));
        if (answer.stored) {
            accepted[flag.tipo] = (accepted[flag.tipo] ?? 0) + 1;
        } else {
            // 409: what the flag was about changed under us (an earlier
            // decision resolved it too). Not accepted; do not try it again.
            refusedFlagIds.add(flag.id);
        }
        state = answer.state ?? await reviewState(reviewer.cookie);
    }
    return { accepted, state };
}

/** Logs the reviewer in and fetches what the review screen needs to send decisions. */
async function openReviewScreen() {
    const cookie = await login(REVIEWER);
    const page = await (await fetch(`${JUIZ}contagem_revisao.php?p=${PROVA}`, { headers: { Cookie: cookie } })).text();
    const csrf = /data-csrf="([^"]+)"/.exec(page)?.[1];
    if (!csrf) fail('The review page did not carry a CSRF token: the reviewer login did not work.');
    return { cookie, csrf };
}

/**
 * One decision through the real decision address.
 *
 * @returns {Promise<{stored: boolean, state: ?object}>} `state` is the fresh review state the answer carried, if any
 */
async function sendDecision(reviewer, request) {
    for (let attempt = 1; attempt <= 20; attempt++) {
        const response = await fetch(`${JUIZ}contagem_decidir.php?p=${PROVA}`, {
            method: 'POST',
            headers: { Cookie: reviewer.cookie, 'Content-Type': 'application/json', 'X-Contagem-Csrf': reviewer.csrf },
            body: JSON.stringify(request),
        });
        // 503: the count was busy and nothing was stored. Try again.
        if (response.status === 503) {
            await sleep(300);
            continue;
        }
        const body = await response.json();
        return { stored: response.status === 200, state: body.estado ?? null };
    }
    return fail('The decision endpoint stayed busy for twenty attempts.');
}

/**
 * The crossings of a car that fewer than two counters typed for the right
 * car. With the rule "a pass counts when two counters agree" no suggestion
 * can make these official: only a person can, looking at the timeline.
 *
 * @param {Set<string>} alreadyFixed crossings ("car:time") put right by reassigning agreed typos
 */
function underWitnessedCrossings(car, alreadyFixed = new Set()) {
    return script.travessias.filter((crossing) => crossing.carro === car).filter((crossing) => {
        if (alreadyFixed.has(`${crossing.carro}:${crossing.tsMs}`)) return false;
        const sawIt = counterPlans.filter((plan) => plan.acoes.some(
            (action) => action.carro === car && Math.abs(action.tsMs - crossing.tsMs) <= 1500,
        )).length;
        return sawIt < 2;
    });
}

/**
 * The rare double mistake: two (or three) counters typed THE SAME wrong
 * number for one crossing. Two witnesses then agree on a pass that never
 * happened: the wrong car gets a lap and the right car loses one. The
 * engine cannot know; it shows the result as a lap that is too short
 * (`volta_curta`), for a person to sort out.
 *
 * @returns {Array<{realCar: number, typedCar: number, crossingMs: number, entries: Array<{fonteId: number, tsMs: number}>}>}
 */
function agreedTypos() {
    const byCrossing = new Map();
    for (const plan of counterPlans) {
        for (const error of plan.erros.filter((candidate) => candidate.tipo === 'numero_errado')) {
            const crossing = script.travessias.find(
                (candidate) => candidate.carro === error.carro && Math.abs(candidate.tsMs - error.tsMs) <= 1500,
            );
            const key = `${error.carro}:${crossing.tsMs}:${error.digitado}`;
            const group = byCrossing.get(key) ?? { realCar: error.carro, typedCar: error.digitado, crossingMs: crossing.tsMs, entries: [] };
            group.entries.push({ fonteId: error.fonteId, tsMs: error.tsMs });
            byCrossing.set(key, group);
        }
    }
    return [...byCrossing.values()].filter((group) => group.entries.length >= 2);
}

/**
 * Plays the person again: reassigns each entry of an agreed typo to the car
 * that really crossed ("era outro carro" on the chip).
 *
 * @returns {Promise<number>} how many entries were reassigned
 */
async function reassignAgreedTypos(reviewer, typos) {
    let reassigned = 0;
    for (const typo of typos) {
        for (const wanted of typo.entries) {
            // Read afresh for every entry: each decision changes what the
            // next chip looks like, and the server refuses a decision made
            // on a picture that is no longer current.
            const timeline = await (await fetch(`${JUIZ}contagem_janela.php?p=${PROVA}&carro=${typo.typedCar}`, { headers: { Cookie: reviewer.cookie } })).json();
            const chips = (timeline.raias ?? []).flatMap((lane) => lane.entradas ?? []);
            const chip = chips.find((candidate) => candidate.fonte_id === wanted.fonteId && candidate.ts_ms === wanted.tsMs);
            if (!chip) {
                console.log(`  (typo of source ${wanted.fonteId} for car ${typo.realCar} not found on car ${typo.typedCar}'s timeline)`);
                continue;
            }
            const state = await reviewState(reviewer.cookie);
            const request = pedidoParaEntrada({ tipo: 'reatribuir', passagem_id: chip.id, equipe_numero: typo.realCar }, chip, state);
            const answer = await sendDecision(reviewer, request);
            if (answer.stored) reassigned++;
            else console.log(`  (reassigning entry ${chip.id} to car ${typo.realCar} was refused)`);        }
    }
    return reassigned;
}

/**
 * Plays the person: for a crossing that only one counter recorded, press
 * "Contar esta passagem (+1 volta)" on its lone-entry flag; for one nobody
 * recorded for the right car, "adicionar passagem" at the true time. The
 * answer key stands in for the person's own knowledge of the race.
 *
 * @returns {Promise<{confirmed: number, added: number}>}
 */
async function resolveAsAPerson(reviewer, state, crossings) {
    let confirmed = 0;
    let added = 0;
    for (const crossing of crossings) {
        const loneFlag = (state.alertas_abertos ?? []).find((flag) => flag.tipo === 'solitaria'
            && (flag.carros ?? []).includes(crossing.carro)
            && Math.abs(flag.ts_ms - crossing.tsMs) <= 1500
            && (flag.passagem_ids ?? []).length > 0);
        const request = loneFlag
            ? pedidoParaAlerta({ tipo: 'confirmar', passagem_id: loneFlag.passagem_ids[0] }, loneFlag, state, loneFlag.assinatura ?? null)
            : pedidoSemAlvo({ tipo: 'adicionar', equipe_numero: crossing.carro, ts_ms: crossing.tsMs }, state);
        const answer = await sendDecision(reviewer, request);
        if (answer.stored && loneFlag) confirmed++;
        if (answer.stored && !loneFlag) added++;
        state = answer.state ?? await reviewState(reviewer.cookie);
    }
    return { confirmed, added };
}

/** The cars whose published lap count differs from the answer key. */
async function differencesFromTheAnswerKey(answerKey) {
    const published = await helper('publicado');
    const differences = [];
    for (const [car, trueLaps] of answerKey) {
        const publishedLaps = published[car] ?? 0;
        if (publishedLaps !== trueLaps) differences.push({ car, trueLaps, publishedLaps });
    }
    return differences;
}

// ------------------------------------------------------------ the run

const durationMs = raceDurationFor(LAP_MS);
// The whole race lies in the past, with a minute to spare.
const startMs = Date.now() - durationMs - 60000;
console.log(`Simulation: seed ${SEED}, average lap ${LAP_MS / 1000} s, ${COUNTERS} counters, replay ${SPEED}x against ${JUIZ} (prova ${PROVA})`);

const setup = await helper('preparar', startMs, COUNTERS);
const script = generateRaceScript({
    semente: SEED, carros: setup.carros, voltaMediaMs: LAP_MS, largadaMs: setup.largada_ms, abastecimento: setup.abastecimento,
});
console.log(`${script.carros.length} cars, ${script.travessias.length} crossings, ${script.paradas.length} stops, ${script.abandonos.length} retirements`);

const counterPlans = setup.fontes.map((source) => planCounter({
    roteiro: script, semente: SEED, fonteId: source.id,
    naoClassificados: setup.nao_classificados, janelaMs: setup.janela_ms, taxas: RATES,
}));
const stopAreas = setup.abastecimento ? [AREA_BOX, AREA_ABASTECIMENTO] : [AREA_BOX];
const stopPlans = stopAreas.map((area) => planStopLog({ roteiro: script, semente: SEED, area }));

const counterDevices = setup.fontes.map((source) => new Device(source.nome, source.token));
// The two areas are logged from the same kind of device; one token serves both here.
const stopDevices = stopAreas.map((area) => new Device(`pit log (${area})`, setup.box_token));

const replayStartedAt = Date.now();
await Promise.all([
    ...counterPlans.map((plan, index) => playInto(counterDevices[index], plan, 'entries', entryRecord, script.largadaMs)),
    ...stopPlans.map((plan, index) => playInto(stopDevices[index], plan, 'pit_events', stopRecord, script.largadaMs)),
]);
for (const device of counterDevices) await device.sayEverythingWasSent();
const uploaded = [...counterDevices, ...stopDevices].reduce((sum, device) => sum + device.sent, 0);
console.log(`Replay done in ${Math.round((Date.now() - replayStartedAt) / 1000)} s: ${uploaded} records uploaded`);

const errors = injectedErrors([...counterPlans, ...stopPlans]);
const errorCounts = {};
for (const error of errors) errorCounts[error.tipo] = (errorCounts[error.tipo] ?? 0) + 1;
console.log('Injected mistakes:', JSON.stringify(errorCounts));

const reviewer = await openReviewScreen();
const { accepted, state: stateAfterSuggestions } = await acceptEverySuggestion(reviewer);
console.log('Suggestions accepted, by flag type:', JSON.stringify(accepted));
const leftOverCounts = {};
for (const flag of stateAfterSuggestions.alertas_abertos ?? []) leftOverCounts[flag.tipo] = (leftOverCounts[flag.tipo] ?? 0) + 1;
console.log('Flags left for a person (no suggestion):', JSON.stringify(leftOverCounts));

// ------------------------------------------------------------ the verdict
//
// Stage 1: after the suggestions alone. Any car that still differs must be
// short by EXACTLY the number of its crossings that fewer than two counters
// typed right; anything else is a bug and fails the run at once.
//
// Stage 2: a person resolves those crossings (see resolveAsAPerson). After
// that every car must equal the answer key.

const answerKey = trueLapCounts(script, { passagemInicial: setup.passagem_inicial });
const afterSuggestions = await differencesFromTheAnswerKey(answerKey);
if (afterSuggestions.length === 0) {
    console.log(`\nPASS after the suggestions alone: the published lap count of all ${answerKey.size} cars equals the answer key.`);
    process.exit(0);
}

console.log(`\nAfter the suggestions alone, ${afterSuggestions.length} of ${answerKey.size} cars differ from the answer key:`);
const typos = agreedTypos();
let unexplained = 0;
for (const { car, trueLaps, publishedLaps } of afterSuggestions) {
    const weak = underWitnessedCrossings(car).length;
    const falsePasses = typos.filter((typo) => typo.typedCar === car).length;
    // Short by the crossings too few counters typed right, over by the
    // false passes two counters agreed on. Nothing else is acceptable.
    const explained = publishedLaps - trueLaps === falsePasses - weak;
    if (!explained) unexplained++;
    console.log(
        `  car ${car}: true ${trueLaps}, published ${publishedLaps}; crossings fewer than two counters typed right: ${weak}`
        + `; false passes two counters agreed on: ${falsePasses}` + (explained ? '' : '   <-- NOT explained by these'),
    );
}
if (unexplained > 0) {
    console.log(`\nFAIL: ${unexplained} car(s) differ for a reason other than the two above.`);
    process.exit(1);
}

const reassigned = await reassignAgreedTypos(reviewer, typos);
const fixedByReassigning = new Set(typos.map((typo) => `${typo.realCar}:${typo.crossingMs}`));
const stillDiffering = await differencesFromTheAnswerKey(answerKey);
const crossingsForAPerson = stillDiffering.flatMap(({ car }) => underWitnessedCrossings(car, fixedByReassigning));
const byHand = await resolveAsAPerson(reviewer, await reviewState(reviewer.cookie), crossingsForAPerson);
console.log(
    `A person then reassigned ${reassigned} entries of ${typos.length} typo(s) two counters shared, `
    + `counted ${byHand.confirmed} lone entries and added ${byHand.added} passes nobody recorded for the right car.`,
);
const afterAPerson = await differencesFromTheAnswerKey(answerKey);
if (afterAPerson.length === 0) {
    console.log(`\nPASS after a person resolved ${typos.length + crossingsForAPerson.length} crossing(s): all ${answerKey.size} cars equal the answer key.`);
    process.exit(0);
}
console.log(`\nFAIL: ${afterAPerson.length} car(s) still differ after a person resolved the lone crossings:`);
for (const { car, trueLaps, publishedLaps } of afterAPerson) console.log(`  car ${car}: true ${trueLaps}, published ${publishedLaps}`);
process.exit(1);
