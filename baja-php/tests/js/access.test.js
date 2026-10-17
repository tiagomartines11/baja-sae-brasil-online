// ACCESS: nobody records without access, and nothing already recorded is lost.
//
// Background: the count and pit pages keep an offline copy (the service
// worker, see service-worker.test.js) so that counting goes on with no
// signal. That copy is the page as the LAST person who opened it on this
// browser got it, and the phone's disk holds that person's device token.
//
// What João saw on the VPS: logged in as a user with no permission at all,
// he could record pit entries and exits. The server refused him the page;
// the worker served the offline copy instead (so that unsent entries are
// never out of reach), and that copy worked as if he were the previous user.
//
// Now there are two ways a page learns it must record nothing new:
//   - the worker marks the copy it serves in place of a refused page;
//   - the server answers an upload with 403 {"erro": "sem_permissao"}.
// Either way the page uploads what it holds, keeps the export, and says why.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    BLOQUEIO, RecordingBlockedError, shellSaysAccessRefused, recordingBlockReason,
} from '../../default/js/contagem/access.js';
import { TIPO_PARADA } from '../../default/js/contagem/pit-session.js';
import { CONEXAO, RESULTADO } from '../../default/js/contagem/sync-queue.js';
import { describeBanner, describeConnection } from '../../default/js/contagem/status-messages.js';
import { ARMAZENAMENTO } from '../../default/js/contagem/storage-open.js';
import { buildEntriesCsv } from '../../default/js/contagem/csv-export.js';
import { createDevice } from './helpers/fakes.js';

/** Just enough of `document` for shellSaysAccessRefused: the <meta> tags of the page. */
function pageWithMetaTags(tags) {
    return {
        querySelector(selector) {
            const name = /meta\[name="([^"]+)"\]/.exec(selector)[1];
            const tag = tags.find((candidate) => candidate.name === name);
            return tag ? { getAttribute: (attribute) => tag[attribute] } : null;
        },
    };
}

function bannerOf(device, bloqueio) {
    return describeBanner(device.queue.getState(), { tokenProblem: null, armazenamento: ARMAZENAMENTO.DISCO, bloqueio });
}

// ---------------------------------------------------------------------------
// The offline copy served in place of a refused page
// ---------------------------------------------------------------------------

test('the page recognises the mark the worker puts on a copy served for a refused page', () => {
    const marked = pageWithMetaTags([{ name: 'viewport', content: 'width=device-width' }, { name: 'contagem-acesso', content: 'recusado' }]);
    const plain = pageWithMetaTags([{ name: 'viewport', content: 'width=device-width' }]);

    assert.equal(shellSaysAccessRefused(marked), true);
    assert.equal(shellSaysAccessRefused(plain), false);
});

test('on a refused page, no pass and no pit or fueling event can be recorded, by any path', async () => {
    // SITUATION: equipe1 counted on this phone; their entries 41 and 7 are
    // still waiting (no signal for a while). Now somebody without access
    // opens the page: the worker serves the offline copy, marked. They type
    // car 12, tap pit and fueling events, and try the simulation hook.
    //
    // WHAT WENT WRONG: all of it was recorded, and uploaded with equipe1's
    // token.
    const device = await createDevice();
    device.server.behaviour = 'offline';
    await device.type(41);
    await device.type(7);
    device.shellRefused = true;

    // EXPECTED: every way of recording is refused, before any time is
    // taken, with the reason; nothing new is on the phone.
    await assert.rejects(() => device.type(12), (error) => error instanceof RecordingBlockedError && error.motivo === BLOQUEIO.ACESSO_RECUSADO);
    await assert.rejects(() => device.session.recordPass({ car: 12, typingMs: null, mode: 'simulacao', tsMs: 1, uuid: 'sim-1' }));
    for (const tipo of Object.values(TIPO_PARADA)) {
        await assert.rejects(() => device.pit.recordEvent({ car: 12, tipo }), RecordingBlockedError, tipo);
    }
    assert.deepEqual(device.store.allEntries().map((entry) => entry.car), [41, 7]);
    assert.equal(device.store.allPitEvents().length, 0);

    // ...while what equipe1 recorded still goes up when the signal returns,
    // and the export still has it.
    device.server.behaviour = 'ok';
    await device.queue.flush();
    assert.deepEqual(device.server.storedCars(), [41, 7]);
    assert.equal(buildEntriesCsv(device.store.allEntries()).trim().split('\r\n').length, 3);

    // And the page says why, in words, first thing in the banner.
    assert.match(bannerOf(device, device.recordingBlocked()).avisos[0], /não está liberada para o usuário que está conectado agora/);
});

test('the offline copy served for want of a network records normally', async () => {
    // SITUATION: the counter at the far corner of the track reloads with no
    // signal. The worker serves the copy WITHOUT the mark.
    const device = await createDevice({ shellRefused: false });
    device.server.behaviour = 'offline';

    const { entry } = await device.type(41);
    await device.pit.recordEvent({ car: 12, tipo: TIPO_PARADA.ABASTECIMENTO_ENTRADA });

    assert.equal(entry.car, 41);
    assert.equal(device.store.allPitEvents().length, 1);
    assert.equal(device.recordingBlocked(), null);
});

test('an expired forum login: the page records, and uploads with the counter\'s own token', async () => {
    // SITUATION: four hours into the race equipe1's forum session expires
    // and the page is reloaded. The worker gets the login redirect and
    // serves the stored copy UNMARKED (service-worker.test.js). A new token
    // cannot be had (the token endpoint now answers with the login page).
    //
    // WHY IT MATTERS: counting survives an expired forum login. The token
    // equipe1 got earlier is the credential.
    let tokenAnswers = [{ status: 200, body: { token: 'token-equipe1' } }];
    const device = await createDevice({
        anchored: true,
        requestToken: async () => tokenAnswers.shift() || { status: 200, body: null },
    });
    await device.tokenSource.get();             // the token, from when the session was alive
    tokenAnswers = [];
    device.shellRefused = false;

    // EXPECTED: recording works, and the token is still equipe1's own.
    const { entry } = await device.type(41);
    assert.equal(entry.car, 41);
    assert.equal(device.recordingBlocked(), null);
    assert.deepEqual(await device.tokenSource.get(), { token: 'token-equipe1', issuedNow: false });
});

// ---------------------------------------------------------------------------
// The server refuses uploads: 403 {"erro": "sem_permissao"}
// ---------------------------------------------------------------------------

test('a server that refuses this user stops new records and keeps the queue', async () => {
    // SITUATION: the controller removes equipe1's access while equipe1 is
    // logging the pit lane. Car 12's entry is waiting to go up. The server
    // now answers every upload of this device with 403 sem_permissao.
    //
    // WHAT WENT WRONG: the page took the 403 for a network problem ("Sem
    // conexão") and went on recording as if nothing had happened.
    const device = await createDevice({ uuidPrefix: 'pit' });
    await device.pit.recordEvent({ car: 12, tipo: TIPO_PARADA.BOX_ENTRADA });
    device.server.behaviour = 'no_permission';

    const outcome = await device.queue.syncOnce();

    // EXPECTED: said in so many words, the queue untouched, and from now on
    // nothing new can be recorded.
    assert.equal(outcome.resultado, RESULTADO.SEM_PERMISSAO);
    assert.equal(device.queue.getState().conexao, CONEXAO.SEM_PERMISSAO);
    assert.equal(describeConnection(device.queue.getState(), { armazenamento: ARMAZENAMENTO.DISCO }), 'Sem permissão');
    assert.equal(device.queue.getState().pendentes, 1);
    assert.equal(device.recordingBlocked(), BLOQUEIO.SEM_PERMISSAO);
    await assert.rejects(() => device.pit.recordEvent({ car: 7, tipo: TIPO_PARADA.ABASTECIMENTO_SAIDA }), RecordingBlockedError);
    await assert.rejects(() => device.type(7), RecordingBlockedError);
    assert.match(bannerOf(device, device.recordingBlocked()).avisos[0], /não tem mais permissão para registrar aqui/);
});

test('the refusal survives a reload, and ends by itself when access is given back', async () => {
    // SITUATION: as above; the marshal reloads the page ("it stopped
    // working"), still refused. Later the controller gives access back.
    const device = await createDevice({ uuidPrefix: 'pit' });
    await device.pit.recordEvent({ car: 12, tipo: TIPO_PARADA.BOX_ENTRADA });
    device.server.behaviour = 'no_permission';
    await device.queue.syncOnce();

    await device.reload();
    // EXPECTED: no window, between the reload and the first answer, in
    // which new events could be tapped.
    assert.equal(device.recordingBlocked(), BLOQUEIO.SEM_PERMISSAO);

    device.server.behaviour = 'ok';
    await device.queue.flush();

    // The waiting event goes up by itself, and recording is possible again.
    assert.equal(device.server.storedPitEvents.size, 1);
    assert.equal(device.recordingBlocked(), null);
    const record = await device.pit.recordEvent({ car: 7, tipo: TIPO_PARADA.BOX_SAIDA });
    assert.equal(record.car, 7);
});

test('the decision itself: either reason blocks, neither allows', () => {
    assert.equal(recordingBlockReason({ shellRefused: false, queueState: { semPermissao: false } }), null);
    assert.equal(recordingBlockReason({ shellRefused: true, queueState: { semPermissao: false } }), BLOQUEIO.ACESSO_RECUSADO);
    assert.equal(recordingBlockReason({ shellRefused: false, queueState: { semPermissao: true } }), BLOQUEIO.SEM_PERMISSAO);
});
