// Load test for the lap counting endpoints, against the DEV stack.
//
// It plays one race day's worth of traffic for a while:
//
//   6 devices   two per counter, each sending a heartbeat every 5 s
//   1 lap       every 2 to 3 s: a car crosses, and the three counters each
//               upload an entry for it
//   3 reviewers polling the review state every 2 s
//   20 viewers  polling the public feed every 2 s
//
// and reports, per kind of request, how many were made, how many failed, and
// the p50 / p95 / max latency.
//
// It also reports how busy each PHP-FPM pool REALLY was. Once a second it
// asks the pools themselves (scripts/contagem-fpm-status.php, run inside
// the app container) how many workers are busy and whether anything is
// queueing. Client latency cannot tell that: it includes the network, and
// it misses the work a worker does after it has answered (the whole-race
// recalculation).
//
// Before running it, fill the prova with a whole race of 64 cars:
//     docker compose exec --user "$(id -u):$(id -g)" baja-app php scripts/contagem-load-seed.php 26BR END 64
// Then, from the host (Node 20 or newer, no dependencies):
//     node baja-php/scripts/contagem-load-test.mjs --minutes 10
// And afterwards remove the stand-in teams the seed added:
//     docker compose exec --user "$(id -u):$(id -g)" baja-app php scripts/contagem-load-seed.php 26BR END limpar
//
// Options: --minutes N (default 10), --evento 26BR, --prova END,
//          --domain baja.local, --container baja-app
//
// It uses the seeded dev users (password 123456). Never point it at production.

import { execFile } from 'node:child_process';

const options = Object.fromEntries(process.argv.slice(2).reduce((pairs, arg, index, all) => {
    if (arg.startsWith('--')) pairs.push([arg.slice(2), all[index + 1]]);
    return pairs;
}, []));
const MINUTES = Number(options.minutes ?? 10);
const EVENTO = options.evento ?? '26BR';
const PROVA = options.prova ?? 'END';
const DOMAIN = options.domain ?? 'baja.local';
const CONTAINER = options.container ?? 'baja-app';

const JUIZ = `http://juiz.${DOMAIN}/${EVENTO}/`;
const RESULTADOS = `http://resultados.${DOMAIN}/`;
const FORUM = `http://forum.${DOMAIN}/`;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const randomBetween = (min, max) => min + Math.random() * (max - min);

/** Latencies and failures per kind of request. */
const measurements = {};
function record(kind, pool, milliseconds, ok) {
    const bucket = (measurements[kind] ??= { pool, latencies: [], failures: 0 });
    bucket.latencies.push(milliseconds);
    if (!ok) bucket.failures++;
}

async function timed(kind, pool, request) {
    const startedAt = performance.now();
    let ok = false;
    let body = null;
    try {
        const response = await request();
        body = await response.text();
        ok = response.status === 200;
    } catch { /* counted as a failure */ }
    record(kind, pool, performance.now() - startedAt, ok);
    return ok ? body : null;
}

/** Logs a forum user in and returns the Cookie header to send afterwards. */
async function login(username) {
    const response = await fetch(`${FORUM}app.php/baja/login`, {
        method: 'POST', redirect: 'manual',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ username, password: '123456', redirect: `${JUIZ}index.php` }),
    });
    // The forum may set the same cookie twice in one answer (first to clear
    // it, then with its value). A browser keeps the last; so does this.
    const cookieByName = new Map();
    for (const line of response.headers.getSetCookie()) {
        const pair = line.split(';')[0];
        cookieByName.set(pair.slice(0, pair.indexOf('=')), pair);
    }
    if (cookieByName.size === 0) throw new Error(`Could not log in as ${username}`);
    return [...cookieByName.values()].join('; ');
}

/** What a count page does when it opens: asks for a device token for its source. */
async function openDevice(username, sourceId) {
    const cookie = await login(username);
    const response = await fetch(`${JUIZ}contagem_dispositivo.php?p=${PROVA}&f=${sourceId}`, { method: 'POST', headers: { Cookie: cookie } });
    const answer = await response.json();
    if (!answer.token) throw new Error(`No token for ${username} on source ${sourceId}: ${JSON.stringify(answer)}`);
    return { username, sourceId, token: answer.token };
}

/** One entry as a count page uploads it: its final time, in server time, and how good that time is. */
function entryFor(car) {
    const nowMs = Date.now();
    return {
        uuid: crypto.randomUUID(), car, mode: 'teclado', typing_ms: 700,
        ts_ms: nowMs, time_quality: 'exato', ts_device_ms: nowMs,
        anchor: { server_ms: nowMs - 5000, rtt_ms: 40, age_ms: 5000 },
    };
}

function sync(device, entries) {
    const body = { entries, voids: [], now_ms: Date.now(), queue_empty: true };
    return timed(entries.length ? 'upload with an entry' : 'heartbeat', 'contagem', () => fetch(`${JUIZ}contagem_sync.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${device.token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    }));
}

async function everyInterval(untilMs, intervalMs, action) {
    await sleep(Math.random() * intervalMs); // so that clients are not all in step
    while (Date.now() < untilMs) {
        const startedAt = Date.now();
        await action();
        await sleep(Math.max(0, intervalMs - (Date.now() - startedAt)));
    }
}

// ---------------------------------------------------------------- set up
console.log(`Load test: ${MINUTES} min against ${JUIZ} (prova ${PROVA})`);
const counters = [['equipe1', 1], ['equipe2', 2], ['capitao1', 3]];
const reliefs = [['capitao2', 1], ['equipe2', 2], ['capitao1', 3]];
// One at a time: the forum does not like several logins in the same instant.
const primaryDevices = [];
for (const [user, source] of counters) primaryDevices.push(await openDevice(user, source));
const reliefDevices = [];
for (const [user, source] of reliefs) reliefDevices.push(await openDevice(user, source));
const reviewerCookie = await login('juiz1');

const firstAnswer = JSON.parse(await sync(primaryDevices[0], []));
const cars = firstAnswer.valid_cars.map((car) => car.numero);
if (!firstAnswer.race.largada_ms) throw new Error('The race has no start. Run contagem-load-seed.php first.');
console.log(`${cars.length} cars; race started ${Math.round((Date.now() - firstAnswer.race.largada_ms) / 60000)} min ago`);

// ---------------------------------------------------------------- run
const untilMs = Date.now() + MINUTES * 60000;
const tasks = [];

for (const device of [...primaryDevices, ...reliefDevices]) {
    tasks.push(everyInterval(untilMs, 5000, () => sync(device, [])));
}

tasks.push((async () => {
    let nextCar = 0;
    while (Date.now() < untilMs) {
        const car = cars[nextCar++ % cars.length];
        // The three counters press Enter within about a second of each other.
        await Promise.all(primaryDevices.map(async (device) => {
            await sleep(Math.random() * 1000);
            return sync(device, [entryFor(car)]);
        }));
        await sleep(randomBetween(1000, 2000));
    }
})());

for (let reviewer = 0; reviewer < 3; reviewer++) {
    let version = '';
    tasks.push(everyInterval(untilMs, 2000, async () => {
        const body = await timed('reviewer poll', 'www', () => fetch(`${JUIZ}contagem_estado.php?p=${PROVA}&versao=${encodeURIComponent(version)}`, { headers: { Cookie: reviewerCookie } }));
        try { version = String(JSON.parse(body).versao ?? version); } catch { /* not JSON: already counted if it failed */ }
    }));
}

for (let viewer = 0; viewer < 20; viewer++) {
    tasks.push(everyInterval(untilMs, 2000, () => timed('public feed', 'www', () => fetch(`${RESULTADOS}contagem_feed.php?evt=${EVENTO}&p=${PROVA}`))));
}

// Once a second, ask the pools how busy they are.
const poolSamples = { www: [], contagem: [] };
function readPoolStatus() {
    return new Promise((resolve) => {
        execFile('docker', ['exec', CONTAINER, 'php', 'scripts/contagem-fpm-status.php'], { timeout: 5000 }, (error, stdout) => {
            try { resolve(error ? null : JSON.parse(stdout)); } catch { resolve(null); }
        });
    });
}
const statusAtStart = await readPoolStatus();
tasks.push(everyInterval(untilMs, 1000, async () => {
    const status = await readPoolStatus();
    for (const pool of Object.keys(poolSamples)) {
        if (status?.[pool]) poolSamples[pool].push(status[pool]);
    }
}));

await Promise.all(tasks);
const statusAtEnd = await readPoolStatus();

// ---------------------------------------------------------------- report
function percentile(sorted, fraction) {
    return sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * fraction))];
}
console.log('\nkind of request          requests  failed    p50     p95     max');
for (const [kind, { latencies, failures }] of Object.entries(measurements)) {
    const sorted = [...latencies].sort((a, b) => a - b);
    console.log(
        kind.padEnd(24), String(latencies.length).padStart(8), String(failures).padStart(7),
        `${percentile(sorted, 0.5).toFixed(0)} ms`.padStart(8), `${percentile(sorted, 0.95).toFixed(0)} ms`.padStart(7),
        `${sorted[sorted.length - 1].toFixed(0)} ms`.padStart(7),
    );
}
console.log('\nworker occupancy, as reported by PHP-FPM itself (sampled once a second):');
let everyWorkerWasBusyAtSomePoint = false;
for (const [pool, samples] of Object.entries(poolSamples)) {
    if (samples.length === 0 || !statusAtStart?.[pool] || !statusAtEnd?.[pool]) {
        console.log(`  ${pool.padEnd(9)} no answer from the pool (is pm.status_path set, and is "${CONTAINER}" the app container?)`);
        continue;
    }
    // The request that reads the status is itself served by a worker of
    // the pool it asks, so one "active" worker in every sample is ours.
    const busy = samples.map((sample) => Math.max(0, sample['active processes'] - 1));
    const workers = samples[0]['total processes'] > 0 ? Math.max(...samples.map((sample) => sample['total processes'])) : 0;
    const meanBusy = busy.reduce((sum, count) => sum + count, 0) / busy.length;
    const timesAllBusy = statusAtEnd[pool]['max children reached'] - statusAtStart[pool]['max children reached'];
    const longestQueue = Math.max(...samples.map((sample) => sample['listen queue']));
    // A request or two in the queue for an instant is normal (a dynamic
    // pool is starting a worker); the pool's own count of "limit reached"
    // is what says it truly ran out.
    everyWorkerWasBusyAtSomePoint ||= timesAllBusy > 0;
    console.log(
        `  ${pool.padEnd(9)} mean ${meanBusy.toFixed(2)} busy, peak ${Math.max(...busy)} of ${workers} running workers`
        + `; longest queue ${longestQueue}; every worker busy ${timesAllBusy} time(s)`
        + `; ${statusAtEnd[pool]['accepted conn'] - statusAtStart[pool]['accepted conn']} requests served`,
    );
}
const anyFailure = Object.values(measurements).some((bucket) => bucket.failures > 0);
if (everyWorkerWasBusyAtSomePoint) console.log('\nA pool ran out of free workers at some point: see the figures above.');
// Only failed requests fail the run. A short queue is worth knowing about
// and is printed above, but a request that waited a moment was still served.
process.exit(anyFailure ? 1 : 0);
