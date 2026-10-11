// DEVICE TOKEN: the key this browser uses to upload.
//
// Background: opening the count page needs a normal login. At that moment the
// server gives the browser a "device token", a long random password that
// belongs to this browser and this counting source. From then on uploads use
// only the token. That is what keeps a counter working when the login session
// expires in the third hour of the race, or when the page is reloaded with no
// network at all.
//
// `requestToken` below stands for the call to contagem_dispositivo.php. Each
// test decides what that call answers and counts how often it is made.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createMemoryStorage } from '../../default/js/contagem/storage-memory.js';
import { LocalStore } from '../../default/js/contagem/local-store.js';
import { obtainDeviceToken, TokenUnavailableError } from '../../default/js/contagem/device-token.js';

/** A pretend contagem_dispositivo.php that hands out "token-1", "token-2", ... */
function createTokenEndpoint() {
    const endpoint = {
        calls: 0,
        behaviour: 'ok',
        requestToken: async () => {
            endpoint.calls++;
            if (endpoint.behaviour === 'offline') {
                throw new Error('simulated: no network');
            }
            if (endpoint.behaviour === 'forbidden') {
                return { status: 403, body: { erro: 'sem_permissao' } };
            }
            if (endpoint.behaviour === 'login_page') {
                // An expired session: the server redirects to the login page,
                // so what arrives is HTML, which is not JSON (body: null).
                return { status: 200, body: null };
            }
            return { status: 200, body: { token: 'token-' + endpoint.calls } };
        },
    };
    return endpoint;
}

test('the first time the page opens it asks the server for a token and stores it', async () => {
    const disk = createMemoryStorage();
    const store = await LocalStore.open(disk);
    const endpoint = createTokenEndpoint();

    const result = await obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' });

    assert.deepEqual(result, { token: 'token-1', issuedNow: true });
    assert.equal(endpoint.calls, 1);
    assert.deepEqual((await LocalStore.open(disk)).getMeta('dispositivo'), { token: 'token-1', usuario: 'equipe1' });
});

test('later page loads reuse the stored token and do not contact the server', async () => {
    // Situation: the page is reloaded in a place with no network and with an
    // expired login. Neither matters, because the token is on the disk.
    const disk = createMemoryStorage();
    const endpoint = createTokenEndpoint();
    await obtainDeviceToken({ store: await LocalStore.open(disk), requestToken: endpoint.requestToken, usuario: 'equipe1' });
    endpoint.behaviour = 'offline';

    const afterReload = await LocalStore.open(disk);
    const result = await obtainDeviceToken({ store: afterReload, requestToken: endpoint.requestToken, usuario: 'equipe1' });

    // Expected: same token, and the token endpoint was not called again.
    assert.deepEqual(result, { token: 'token-1', issuedNow: false });
    assert.equal(endpoint.calls, 1);
});

test('with no token yet and no network, the page reports "sem_rede" and can try again later', async () => {
    // Situation: very first visit, and the network drops right after the page
    // itself loaded. The counter can already type (entries go to the disk);
    // uploading has to wait for a token.
    const store = await LocalStore.open(createMemoryStorage());
    const endpoint = createTokenEndpoint();
    endpoint.behaviour = 'offline';

    await assert.rejects(
        () => obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' }),
        (error) => error instanceof TokenUnavailableError && error.motivo === 'sem_rede',
    );

    // The next attempt, with network, succeeds.
    endpoint.behaviour = 'ok';
    const result = await obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' });
    assert.equal(result.token, 'token-2');
});

test('a user who is not allowed on this source gets "sem_permissao"', async () => {
    // Situation: the server answers 403 (the person is not linked to this
    // counter).
    const store = await LocalStore.open(createMemoryStorage());
    const endpoint = createTokenEndpoint();
    endpoint.behaviour = 'forbidden';

    await assert.rejects(
        () => obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' }),
        (error) => error.motivo === 'sem_permissao',
    );
    assert.equal(store.getMeta('dispositivo'), null);
});

test('the login page arriving instead of a token is reported as "sem_sessao"', async () => {
    // Situation: no token stored, and the login session has expired.
    const store = await LocalStore.open(createMemoryStorage());
    const endpoint = createTokenEndpoint();
    endpoint.behaviour = 'login_page';

    await assert.rejects(
        () => obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' }),
        (error) => error.motivo === 'sem_sessao',
    );
});

test('a revoked token is never reused: the page asks for a new one', async () => {
    // Situation: the upload endpoint answered 401 earlier, so the stored token
    // is marked as revoked. The page is opened again by a user who has been
    // linked to the source again.
    const store = await LocalStore.open(createMemoryStorage());
    const endpoint = createTokenEndpoint();
    await obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' });
    await store.setMeta('token_revogado', true);

    const result = await obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' });

    assert.deepEqual(result, { token: 'token-2', issuedNow: true });
});

test('a revoked token with no way to get a new one leaves the page without a token', async () => {
    // Situation: revoked, and the server refuses to issue another (the person
    // is really no longer allowed). The old token must not be used "anyway".
    const store = await LocalStore.open(createMemoryStorage());
    const endpoint = createTokenEndpoint();
    await obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' });
    await store.setMeta('token_revogado', true);

    for (const behaviour of ['forbidden', 'offline', 'login_page']) {
        endpoint.behaviour = behaviour;
        await assert.rejects(
            () => obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' }),
            TokenUnavailableError,
        );
    }
});

test('when a different person takes over the phone, a token in their own name is requested', async () => {
    // Situation: a handover. equipe1 counted on this phone; now capitao2 logs
    // in on the same phone and opens the same counter.
    const store = await LocalStore.open(createMemoryStorage());
    const endpoint = createTokenEndpoint();
    await obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' });

    const result = await obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'capitao2' });

    // Expected: a new token, so the server knows who is counting now.
    assert.deepEqual(result, { token: 'token-2', issuedNow: true });
    assert.deepEqual(store.getMeta('dispositivo'), { token: 'token-2', usuario: 'capitao2' });
});

test('a handover with no network keeps uploading with the previous token', async () => {
    // Situation: the same handover, but the request for the new token fails
    // (no signal at that moment). The entries must still be able to reach the
    // server, so the previous, still valid token stays in use until a new one
    // can be issued.
    const store = await LocalStore.open(createMemoryStorage());
    const endpoint = createTokenEndpoint();
    await obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' });
    endpoint.behaviour = 'offline';

    const result = await obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'capitao2' });

    assert.deepEqual(result, { token: 'token-1', issuedNow: false });
});

test('a handover refused by the server does not fall back to the other person\'s token', async () => {
    // Situation: capitao2 opens a counter they are not linked to, on a phone
    // where equipe1's token is stored. (Reaching this point at all means a
    // cached copy of the page was shown; the server itself says no.)
    const store = await LocalStore.open(createMemoryStorage());
    const endpoint = createTokenEndpoint();
    await obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'equipe1' });
    endpoint.behaviour = 'forbidden';

    // Expected: no token. A clear "no" from the server is respected.
    await assert.rejects(
        () => obtainDeviceToken({ store, requestToken: endpoint.requestToken, usuario: 'capitao2' }),
        (error) => error.motivo === 'sem_permissao',
    );
});
