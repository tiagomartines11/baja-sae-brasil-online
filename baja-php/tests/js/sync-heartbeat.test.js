// HEARTBEAT: the 5-second timer behind the uploads, and how a device that was
// shut out finds its way back in.
//
// Background: the page calls the server every 5 seconds even when no car has
// passed. That call is the retry after a failed upload, and it is how the
// server tells "no car passed" from "this phone is dead".
//
// A device can also be SHUT OUT: a controller removes the counter from the
// source, and the server then answers that device's uploads with HTTP 401 and
// {"erro": "token_invalido"}. The page shows "Envio bloqueado" and keeps
// everything it has. If the controller links the counter again, the page must
// start uploading again BY ITSELF. Telling a volunteer in the middle of a race
// to reload a page, log in again and hope is how entries get lost.
//
// In these tests `device.heartbeat.tick()` is the timer firing once, and
// `renewToken` stands for the request for a new device token: it answers true
// only when the server issued one (see device-token.test.js for that part).

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { CONEXAO } from '../../default/js/contagem/sync-queue.js';
import { HEARTBEAT_INTERVAL_MS, REVOKED_RETRY_INTERVAL_MS } from '../../default/js/contagem/sync-heartbeat.js';
import { describeBanner } from '../../default/js/contagem/status-messages.js';
import { ARMAZENAMENTO } from '../../default/js/contagem/storage-open.js';
import { FakeClock, createDevice } from './helpers/fakes.js';

/** Lets everything that was started by a timer tick run to its end. */
async function settle(device) {
    await new Promise((resolve) => setImmediate(resolve));
    await device.queue.flush();
    await new Promise((resolve) => setImmediate(resolve));
}

/** A pretend "ask the server for a new token" that the test can switch on. */
function createTokenDesk() {
    const desk = {
        counterIsLinkedAgain: false,
        requests: 0,
        renewToken: async () => {
            desk.requests++;
            return desk.counterIsLinkedAgain;
        },
    };
    return desk;
}

test('the heartbeat talks to the server every 5 seconds even with nothing to send', async () => {
    // Situation: a quiet minute, no car passes.
    const device = await createDevice();
    const timers = [];
    const fakeSetInterval = (callback, intervalMs) => {
        timers.push({ callback, intervalMs });
        return timers.length;
    };

    device.heartbeat.start(fakeSetInterval, () => {});
    await settle(device);

    // Expected: a 5000 ms timer, and a first request sent right away, with
    // empty lists.
    assert.equal(HEARTBEAT_INTERVAL_MS, 5000);
    assert.equal(timers[0].intervalMs, 5000);
    assert.ok(device.server.receivedRequests.length >= 1);
    assert.deepEqual(device.server.receivedRequests[0].entries, []);

    // Each tick of the timer produces another request.
    const requestsBeforeTick = device.server.receivedRequests.length;
    timers[0].callback();
    await settle(device);
    assert.ok(device.server.receivedRequests.length > requestsBeforeTick);
});

test('a counter who is linked again starts uploading again without reloading the page', async () => {
    // Situation: by mistake the controller removes equipe1 from "Contagem 1"
    // while equipe1 is counting. The phone gets the 401 and shows "Envio
    // bloqueado". equipe1 keeps typing: cars 41 and 12 pile up on the phone.
    // A minute later the controller notices and links equipe1 again.
    const desk = createTokenDesk();
    const device = await createDevice({ renewToken: desk.renewToken });
    device.server.behaviour = 'revoked';
    await device.type(41);
    await device.queue.syncOnce();
    await settle(device);
    assert.equal(device.queue.getState().conexao, CONEXAO.REVOGADO);
    // The page asked for a new token the moment it was refused.
    assert.equal(desk.requests, 1);
    await device.type(12);

    // Still shut out: half a minute later the page asks again, the server
    // says no again, nothing changes.
    device.clock.advance(REVOKED_RETRY_INTERVAL_MS);
    device.heartbeat.tick();
    await settle(device);
    assert.equal(desk.requests, 2);
    assert.equal(device.queue.getState().conexao, CONEXAO.REVOGADO);

    // The controller links the counter again.
    desk.counterIsLinkedAgain = true;
    device.server.behaviour = 'ok';
    device.clock.advance(REVOKED_RETRY_INTERVAL_MS);
    device.heartbeat.tick();
    await settle(device);

    // Expected: the page healed by itself. Both cars that were waiting are
    // on the server, once each, and the mark on the disk is gone.
    assert.equal(device.queue.getState().conexao, CONEXAO.ONLINE);
    assert.deepEqual(device.server.storedCars(), [41, 12]);
    assert.equal(device.server.worstInsertCount(), 1);
    assert.equal(device.store.getMeta('token_revogado'), false);
});

test('a shut-out device asks to be let back in slowly, not every 5 seconds', async () => {
    // Situation: the counter really was removed, and the phone stays on the
    // table with the page open for five minutes. The 5-second timer fires 60
    // times in that period.
    const desk = createTokenDesk();
    const device = await createDevice({ renewToken: desk.renewToken });
    await device.type(41);
    device.server.behaviour = 'revoked';
    await device.queue.syncOnce();

    for (let tick = 0; tick < 60; tick++) {
        device.clock.advance(HEARTBEAT_INTERVAL_MS);
        device.heartbeat.tick();
        await settle(device);
    }

    // Expected: one request at the moment of the refusal, then one per 30
    // seconds: 11 in five minutes. "Removed" is a decision by a person;
    // knocking on the door every 5 seconds would be noise in the server's
    // log, not persistence.
    assert.equal(REVOKED_RETRY_INTERVAL_MS, 30000);
    assert.equal(desk.requests, 11);
    // And not one upload attempt: the old token is never tried again.
    assert.equal(device.server.receivedRequests.length, 0);
});

/**
 * A pretend contagem_dispositivo.php, for tests that use the page's REAL
 * token source. Each request takes `answerDelayMs`; the answer is "blocked by
 * the controller" until the test sets `released`.
 */
function createTokenEndpoint(clock, answerDelayMs = 200) {
    const endpoint = {
        released: false,
        requests: 0,
        requestToken: async () => {
            endpoint.requests++;
            clock.advance(answerDelayMs);
            return endpoint.released
                ? { status: 200, body: { token: 'new-token' } }
                : { status: 403, body: { erro: 'dispositivo_revogado' } };
        },
    };
    return endpoint;
}

test('a device blocked by the controller says so by itself, at once', async () => {
    // SITUATION: the controller presses "revogar" on equipe1's phone. The
    // phone's next upload gets the 401.
    //
    // WHAT WENT WRONG: the page showed the general "desconectado" text. The
    // sentence that tells the counter what to do ("bloqueado pelo
    // responsável... peça para liberar") comes from the token request, and
    // (1) that request was first made 30 s later, (2) nothing redrew the
    // warning after it. In practice it appeared only when the counter
    // pressed "Tentar de novo".
    const clock = new FakeClock();
    const endpoint = createTokenEndpoint(clock);
    const device = await createDevice({ clock, requestToken: endpoint.requestToken });
    await device.type(41);
    device.server.behaviour = 'revoked';
    assert.equal(device.tokenProblem, null);

    // The upload that gets the 401. No timer tick and no button after it.
    await device.queue.syncOnce();
    const redrawsAfterTheRefusal = device.stateChanges.length;
    await settle(device);

    // EXPECTED: the 401 itself does not say why, so the page asks for a
    // token right away, learns the reason from that answer, and tells the
    // page to redraw. The warning now is the "bloqueado" one.
    assert.equal(endpoint.requests, 1);
    assert.equal(device.tokenProblem, 'dispositivo_revogado');
    assert.ok(device.stateChanges.length > redrawsAfterTheRefusal, 'the page was told to redraw after the answer');
    const banner = describeBanner(device.queue.getState(), { tokenProblem: device.tokenProblem, armazenamento: ARMAZENAMENTO.DISCO });
    assert.match(banner.avisos.join(' '), /bloqueado nesta contagem pelo responsável/);
});

test('a blocked device really asks every 30 seconds, and heals when released', async () => {
    // SITUATION: blocked, phone left on the table for five minutes with the
    // page open.
    //
    // WHAT WENT WRONG: two pauses of 30 s were stacked. The heartbeat waited
    // 30 s between attempts, and the token source refused to ask again
    // within 30 s of the last ANSWER (a moment after the attempt). So every
    // other attempt of the heartbeat died without reaching the server: one
    // real request per minute, and a released counter waited up to a minute.
    //
    // And a browser timer is not exact: the tick "at 30 s" comes a few
    // milliseconds early as often as late. Early by 2 ms meant "too soon",
    // and the real pause became 35 s.
    const clock = new FakeClock();
    const endpoint = createTokenEndpoint(clock, 0);
    const askedAtMs = [];
    const device = await createDevice({
        clock,
        requestToken: () => {
            askedAtMs.push(clock.serverNow());
            return endpoint.requestToken();
        },
    });
    await device.type(41);
    device.server.behaviour = 'revoked';
    await device.queue.syncOnce();
    await settle(device);

    // The 5-second timer; its first tick comes 3 ms early, so every sixth
    // tick is 29.997 s after the refusal instead of 30 s.
    for (let tick = 0; tick < 60; tick++) {
        device.clock.advance(tick === 0 ? HEARTBEAT_INTERVAL_MS - 3 : HEARTBEAT_INTERVAL_MS);
        device.heartbeat.tick();
        await settle(device);
    }

    // EXPECTED: one request at the refusal, then one every 30 s (give or
    // take the timer's few milliseconds).
    const gapsMs = askedAtMs.slice(1).map((atMs, index) => atMs - askedAtMs[index]);
    assert.ok(askedAtMs.length >= 10, askedAtMs.length + ' requests in five minutes');
    assert.ok(gapsMs.every((gapMs) => gapMs >= 29_500 && gapMs <= 30_500), 'gaps: ' + gapsMs.join(', '));

    // The controller presses "liberar". The next attempt gets a token and
    // the waiting entry goes up, with no reload and no button.
    endpoint.released = true;
    device.server.behaviour = 'ok';
    for (let tick = 0; tick < 6; tick++) {
        device.clock.advance(HEARTBEAT_INTERVAL_MS);
        device.heartbeat.tick();
        await settle(device);
    }
    assert.equal(device.queue.getState().conexao, CONEXAO.ONLINE);
    assert.deepEqual(device.server.storedCars(), [41]);
    assert.equal(device.tokenProblem, null);
});

test('a page opened while shut out asks once right away', async () => {
    // Situation: the counter was shut out yesterday. Today the controller
    // says "I linked you again, open the page". The counter opens it.
    const desk = createTokenDesk();
    const device = await createDevice({ renewToken: desk.renewToken });
    await device.type(41);
    device.server.behaviour = 'revoked';
    await device.queue.syncOnce();

    await settle(device);
    const requestsYesterday = desk.requests;

    desk.counterIsLinkedAgain = true;
    device.server.behaviour = 'ok';
    await device.reload();                      // the page is opened again
    assert.equal(device.queue.getState().conexao, CONEXAO.REVOGADO, 'the mark is on the disk');
    device.heartbeat.start(() => 1, () => {});
    await settle(device);

    // Expected: no 30-second wait on a freshly opened page.
    assert.equal(desk.requests, requestsYesterday + 1);
    assert.deepEqual(device.server.storedCars(), [41]);
});

test('the "Tentar de novo" button asks at once, without waiting for the slow timer', async () => {
    // Situation: the controller stands next to the counter and says "try
    // now". The counter taps the button in the warning banner.
    const desk = createTokenDesk();
    const device = await createDevice({ renewToken: desk.renewToken });
    await device.type(41);
    device.server.behaviour = 'revoked';
    await device.queue.syncOnce();
    desk.counterIsLinkedAgain = true;
    device.server.behaviour = 'ok';

    const healed = await device.heartbeat.tryToRecoverFromRevocation({ force: true });
    await settle(device);

    assert.equal(healed, true);
    assert.deepEqual(device.server.storedCars(), [41]);
});

test('a request for a new token that fails leaves the device shut out, with its data', async () => {
    // Situation: shut out, and the request for a new token cannot even be
    // made (no network).
    const device = await createDevice({
        renewToken: async () => {
            throw new Error('simulated: no network');
        },
    });
    await device.type(41);
    device.server.behaviour = 'revoked';
    await device.queue.syncOnce();

    device.clock.advance(REVOKED_RETRY_INTERVAL_MS);
    device.heartbeat.tick();
    await settle(device);

    // Expected: nothing breaks, nothing is lost, and the next slow attempt
    // is free to try again.
    assert.equal(device.queue.getState().conexao, CONEXAO.REVOGADO);
    assert.equal(device.queue.getState().pendentes, 1);
});
