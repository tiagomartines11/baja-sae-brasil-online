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
import { createDevice } from './helpers/fakes.js';

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
    assert.equal(device.queue.getState().conexao, CONEXAO.REVOGADO);
    await device.type(12);

    // Still shut out: the page asks, the server says no, nothing changes.
    device.clock.advance(REVOKED_RETRY_INTERVAL_MS);
    device.heartbeat.tick();
    await settle(device);
    assert.equal(desk.requests, 1);
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

    // Expected: one request for a token per 30 seconds, so 10 in five
    // minutes. "Removed" is a decision by a person; knocking on the door
    // every 5 seconds would be noise in the server's log, not persistence.
    assert.equal(REVOKED_RETRY_INTERVAL_MS, 30000);
    assert.equal(desk.requests, 10);
    // And not one upload attempt: the old token is never tried again.
    assert.equal(device.server.receivedRequests.length, 0);
});

test('a page opened while shut out asks once right away', async () => {
    // Situation: the counter was shut out yesterday. Today the controller
    // says "I linked you again, open the page". The counter opens it.
    const desk = createTokenDesk();
    const device = await createDevice({ renewToken: desk.renewToken });
    await device.type(41);
    device.server.behaviour = 'revoked';
    await device.queue.syncOnce();

    desk.counterIsLinkedAgain = true;
    device.server.behaviour = 'ok';
    await device.reload();                      // the page is opened again
    assert.equal(device.queue.getState().conexao, CONEXAO.REVOGADO, 'the mark is on the disk');
    device.heartbeat.start(() => 1, () => {});
    await settle(device);

    // Expected: no 30-second wait on a freshly opened page.
    assert.equal(desk.requests, 1);
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
