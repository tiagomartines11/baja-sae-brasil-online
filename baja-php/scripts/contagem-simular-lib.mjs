// The pieces of the simulation runner (contagem-simular.mjs): simulated
// devices built from the count pages' own modules, a reviewer who talks to
// the real review endpoints, and the comparisons with the answer key.

import { execFile } from 'node:child_process';
import { LocalStore } from '../default/js/contagem/local-store.js';
import { createMemoryStorage } from '../default/js/contagem/storage-memory.js';
import { ServerClock } from '../default/js/contagem/server-clock.js';
import { SyncQueue } from '../default/js/contagem/sync-queue.js';
import { CountSession } from '../default/js/contagem/count-session.js';
import { PitSession } from '../default/js/contagem/pit-session.js';
import { postJson } from '../default/js/contagem/http.js';

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function fail(message) {
    console.error(message);
    process.exit(1);
}

/** Runs the PHP helper inside the app container and returns what it printed, parsed. */
export function runHelper(container, evento, prova, ...args) {
    return new Promise((resolve) => {
        const command = ['exec', container, 'php', 'scripts/contagem-simular-apoio.php', evento, prova, ...args.map(String)];
        execFile('docker', command, { timeout: 120000 }, (error, stdout, stderr) => {
            if (error) fail(`The set-up helper failed: ${stderr.trim() || error.message}`);
            resolve(JSON.parse(stdout));
        });
    });
}

// ------------------------------------------------------------ devices

const MODE_SIMULATION = 'simulacao';

/**
 * A simulated phone, made of the SAME modules a count page runs: the local
 * store (kept in memory here), the server-anchored clock, the upload queue
 * and the session that records a pass. Only the screen is missing. So a
 * run of this script also exercises the client: stamping, queueing,
 * batching, acknowledgement.
 */
export class ClientDevice {
    /**
     * @param {object} options
     * @param {string} options.name     for messages
     * @param {string} options.syncUrl
     * @param {string} options.token
     * @param {Array<{numero: number}>} options.cars the classified cars, as the server lists them
     * @param {number} options.janelaMs
     */
    static async open({ name, syncUrl, token, cars, janelaMs }) {
        const device = new ClientDevice();
        device.name = name;
        device.store = await LocalStore.open(createMemoryStorage());
        const monotonicNow = () => performance.now();
        device.clock = new ServerClock({ now: Date.now, monotonicNow, loadId: crypto.randomUUID() });
        device.queue = new SyncQueue({
            store: device.store,
            clock: device.clock,
            send: (body) => postJson(fetch, syncUrl, { token, body, now: monotonicNow }),
            onChange: () => {},
            vouchesForHistory: true,
        });
        await device.queue.prepare();
        // A replay types passes of hours ago; see simulacao.js.
        device.queue.suprimirMarcaDeFila(true);
        const generateUuid = () => crypto.randomUUID();
        device.counting = new CountSession({
            store: device.store, clock: device.clock, generateUuid,
            getValidCars: () => cars, getJanelaMs: () => janelaMs,
        });
        device.stops = new PitSession({ store: device.store, clock: device.clock, generateUuid });
        // The first contact gives the clock its anchor, as on a page.
        await device.queue.flush();
        return device;
    }

    recordPass(action) {
        if (action.corrige) {
            // "Informar número" on an earlier "??" of this counter.
            return this.counting.fixUnread(action.corrige.uuid, action.carro);
        }
        return this.counting.recordPass({ car: action.carro, typingMs: null, mode: MODE_SIMULATION, tsMs: action.tsMs, uuid: action.uuid });
    }

    recordStop(action) {
        return this.stops.recordEvent({ car: action.carro, tipo: action.tipo, tsMs: action.tsMs, mode: MODE_SIMULATION, uuid: action.uuid });
    }

    /** Uploads until nothing is waiting. "Busy" answers are simply tried again, as a phone does. */
    async flushEverything() {
        for (let attempt = 1; attempt <= 200; attempt++) {
            await this.queue.flush();
            if (this.store.pendingCount() === 0) return;
            await sleep(50);
        }
        fail(`${this.name}: ${this.store.pendingCount()} records were still waiting after 200 upload attempts.`);
    }

    /** "My queue is empty, and it is now": lets the engine conclude that what this device did not send, it did not see. */
    async sayEverythingWasSent() {
        this.queue.suprimirMarcaDeFila(false);
        await this.flushEverything();
        await this.queue.flush();
    }

    /** Records the server refused or never acknowledged. There must be none. */
    refusedCount() {
        return [...this.store.allEntries(), ...this.store.allPitEvents()].filter((record) => record.refused || record.recusado).length;
    }
}

/**
 * A simulated timing sensor: an automatic system with an API token, which
 * posts its marks (never a car) the way such a system does, in batches.
 * It has no clock of its own to anchor: its times are the plan's, which
 * are the server's.
 */
export class SensorDevice {
    constructor({ syncUrl, token }) {
        this.name = 'sensor';
        this.syncUrl = syncUrl;
        this.token = token;
        this.waiting = [];
        this.refused = 0;
        this.queue = { flush: () => this.flushEverything() };
    }

    recordMark(action) {
        this.waiting.push({ uuid: action.uuid, car: null, ts_ms: action.tsMs, time_quality: 'exato', mode: 'simulacao' });
    }

    async flushEverything(queueEmpty = false) {
        for (let attempt = 1; attempt <= 200; attempt++) {
            const batch = this.waiting.slice(0, 200);
            if (batch.length === 0 && !queueEmpty) return;
            const answer = await postJson(fetch, this.syncUrl, {
                token: this.token,
                body: { entries: batch, now_ms: Date.now(), queue_empty: batch.length === this.waiting.length && queueEmpty },
            });
            if (answer.status !== 200) {
                await sleep(50);
                continue;
            }
            const stored = new Set(answer.body.stored_uuids ?? []);
            this.refused += (answer.body.refused ?? []).length;
            this.waiting = this.waiting.filter((mark) => !stored.has(mark.uuid));
            if (this.waiting.length === 0) return;
        }
        fail(`sensor: ${this.waiting.length} marks were still waiting after 200 upload attempts.`);
    }

    async sayEverythingWasSent() {
        await this.flushEverything(true);
    }

    refusedCount() {
        return this.refused;
    }
}

// ------------------------------------------------------------ the reviewer

/** A logged-in reviewer: what the review screen does, without the screen. */
export class Reviewer {
    static async login({ juizUrl, forumUrl, prova, username }) {
        const response = await fetch(`${forumUrl}app.php/baja/login`, {
            method: 'POST', redirect: 'manual',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ username, password: '123456', redirect: `${juizUrl}index.php` }),
        });
        // The forum may set the same cookie twice in one answer; a browser keeps the last.
        const cookieByName = new Map();
        for (const line of response.headers.getSetCookie()) {
            const pair = line.split(';')[0];
            cookieByName.set(pair.slice(0, pair.indexOf('=')), pair);
        }
        if (cookieByName.size === 0) fail(`Could not log in as ${username}.`);

        const reviewer = new Reviewer();
        reviewer.juizUrl = juizUrl;
        reviewer.prova = prova;
        reviewer.cookie = [...cookieByName.values()].join('; ');
        const page = await reviewer.getText('contagem_revisao.php', {});
        reviewer.csrf = /data-csrf="([^"]+)"/.exec(page)?.[1];
        if (!reviewer.csrf) fail(`The review page did not carry a CSRF token. Is ${username} a controller of this prova?`);
        return reviewer;
    }

    async getText(page, params) {
        const query = new URLSearchParams({ p: this.prova, ...params });
        const response = await fetch(`${this.juizUrl}${page}?${query}`, { headers: { Cookie: this.cookie } });
        if (response.status !== 200) fail(`${page} answered HTTP ${response.status}.`);
        return response.text();
    }

    async getJson(page, params = {}) {
        return JSON.parse(await this.getText(page, params));
    }

    /** The review state, as the screen polls it. */
    state() {
        return this.getJson('contagem_estado.php');
    }

    /** Every chip of one car's timeline. */
    async chipsOf(car) {
        const timeline = await this.getJson('contagem_janela.php', { carro: car });
        return (timeline.raias ?? []).flatMap((lane) => lane.entradas ?? []);
    }

    /** One of the CSV exports of race control, as rows of {column: value}. */
    async exportRows(tipo) {
        const text = (await this.getText('contagem_exportar.php', { tipo })).replace(/^﻿/, '');
        const [header, ...lines] = text.split(/\r?\n/).filter((line) => line !== '');
        const columns = header.split(';').map((name) => name.replaceAll('"', ''));
        if (columns.length === 1 && columns[0] === 'sem dados') return [];
        return lines.map((line) => Object.fromEntries(line.split(';').map((cell, index) => [columns[index], cell.replaceAll('"', '')])));
    }

    /**
     * One decision through the real decision address.
     *
     * @returns {Promise<{stored: boolean, state: ?object}>} `state` is the fresh review state the answer carried, if any
     */
    async decide(request) {
        for (let attempt = 1; attempt <= 20; attempt++) {
            const response = await fetch(`${this.juizUrl}contagem_decidir.php?p=${this.prova}`, {
                method: 'POST',
                headers: { Cookie: this.cookie, 'Content-Type': 'application/json', 'X-Contagem-Csrf': this.csrf },
                body: JSON.stringify(request),
            });
            // 503: the count was busy and nothing was stored. Try again.
            if (response.status === 503) {
                await sleep(300);
                continue;
            }
            const body = await response.json();
            return { stored: response.status === 200, state: body.estado ?? null, message: body.mensagem ?? null };
        }
        return fail('The decision endpoint stayed busy for twenty attempts.');
    }
}

// ------------------------------------------------------------ comparing with the truth

/** How far a pass time may be from the true crossing: counters type from 0.5 s before to 1.5 s after. */
export const CROSSING_TOLERANCE_MS = 2500;

/**
 * Pairs one car's true crossings with its counted passes, in time order.
 *
 * @param {number[]} trueTimes    when the car really crossed, sorted
 * @param {number[]} countedTimes the pass times the server counted (pass zero included), sorted
 * @returns {{missing: number[], phantom: number[]}} crossings with no counted pass, and counted passes with no crossing
 */
export function matchCrossings(trueTimes, countedTimes) {
    const missing = [];
    const phantom = [];
    let countedIndex = 0;
    for (const trueMs of trueTimes) {
        while (countedIndex < countedTimes.length && countedTimes[countedIndex] < trueMs - CROSSING_TOLERANCE_MS) {
            phantom.push(countedTimes[countedIndex++]);
        }
        if (countedIndex < countedTimes.length && Math.abs(countedTimes[countedIndex] - trueMs) <= CROSSING_TOLERANCE_MS) {
            countedIndex++;
        } else {
            missing.push(trueMs);
        }
    }
    phantom.push(...countedTimes.slice(countedIndex));
    return { missing, phantom };
}

/**
 * The counted passes of every car, from the laps export.
 *
 * @param {Array<object>} exportRows rows of the "voltas" export
 * @returns {Map<number, number[]>} car => sorted pass times of its counted rows (pass zero and laps)
 */
export function countedTimesByCar(exportRows) {
    const byCar = new Map();
    for (const row of exportRows) {
        if (row.estado !== 'contada' && row.estado !== 'inicial') continue;
        const car = Number(row.equipe_numero);
        if (!byCar.has(car)) byCar.set(car, []);
        byCar.get(car).push(Number(row.ts_ms));
    }
    for (const times of byCar.values()) times.sort((a, b) => a - b);
    return byCar;
}

/** "1:02:05" since the start. */
export function raceClock(tsMs, startMs) {
    const seconds = Math.max(0, Math.round((tsMs - startMs) / 1000));
    return `${Math.trunc(seconds / 3600)}:${String(Math.trunc((seconds % 3600) / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}
