// "INFORMAR NÚMERO": getting the number of a "??" to the server.
//
// Background: a counter who pressed "??" can give the number afterwards.
// That number is NOT a new pass (the pass, with its time, is already on the
// server): it goes up as a correction, in the request's `fixes` list, as
// {uuid, car}. The server stores it as the counter's decision on that entry
// ("reatribuir", origem "contador"), the same way it stores a "desfazer".
//
// It follows the rules of every other upload: on the disk first, uploaded
// when the network allows, sent again until the server answers, harmless
// when sent twice, kept across a reload. The server's answer names it in
// `fixed_uuids` (stored) or `fixes_recusados` (never: the entry is not this
// device's, or it has a number). A refused one is shown on its row.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createMemoryStorage } from '../../default/js/contagem/storage-memory.js';
import { MOTIVO_CORRECAO_RECUSADA, MOTIVO_SEM_CONFIRMACAO, MISSES_BEFORE_GIVING_UP } from '../../default/js/contagem/refused-records.js';
import { RecordingBlockedError } from '../../default/js/contagem/access.js';
import { buildEntriesCsv } from '../../default/js/contagem/csv-export.js';
import { createDevice } from './helpers/fakes.js';

/** A "??" recorded and already on the server. */
async function deviceWithUploadedUnread(options = {}) {
    const device = await createDevice(options);
    const { entry } = await device.typeUnread();
    await device.queue.flush();
    assert.equal(device.store.findEntry(entry.uuid).sent, true);
    device.server.receivedRequests.length = 0;
    return { device, uuid: entry.uuid };
}

const fixesSent = (device) => device.server.receivedRequests.flatMap((request) => request.fixes || []);

// ---------------------------------------------------------------------------
// The normal case
// ---------------------------------------------------------------------------

test('a correction goes up in `fixes` as {uuid, car}, and is marked sent when the server names it', async () => {
    const { device, uuid } = await deviceWithUploadedUnread();

    const result = await device.session.fixUnread(uuid, 41);
    assert.equal(result.entry.fix_car, 41);
    assert.equal(device.store.pendingCount(), 1, '"na fila" counts it');

    await device.queue.flush();

    assert.deepEqual(fixesSent(device)[0], { uuid, car: 41 });
    assert.deepEqual(device.server.fixes.get(uuid), 41);
    const stored = device.store.findEntry(uuid);
    assert.equal(stored.fix_sent, true);
    assert.equal(stored.car, null, 'the entry itself stays a "??": the number is a correction, not a retyped pass');
    assert.equal(device.store.pendingCount(), 0);
    assert.equal(device.server.storedEntries.size, 1, 'no new pass');
});

test('a correction takes no time and records nothing: the pass keeps the time of the "??"', async () => {
    const { device, uuid } = await deviceWithUploadedUnread();
    const before = { ...device.store.findEntry(uuid) };
    device.clock.advance(15_000);

    await device.session.fixUnread(uuid, 41);

    const after = device.store.findEntry(uuid);
    assert.equal(after.ts_ms, before.ts_ms);
    assert.equal(after.seq, before.seq);
    assert.equal(device.store.allEntries().length, 1);
});

test('an ordinary request has no `fixes` field at all', async () => {
    const device = await createDevice();
    await device.type(41);
    await device.queue.flush();
    for (const request of device.server.receivedRequests) {
        assert.equal('fixes' in request, false);
    }
});

test('a correction typed before its "??" reached the server waits for it, then follows', async () => {
    // Situation: offline; "??" then its number at once.
    const device = await createDevice();
    device.server.behaviour = 'offline';
    const { entry } = await device.typeUnread();
    await device.session.fixUnread(entry.uuid, 7);
    await device.queue.flush();

    device.server.behaviour = 'ok';
    await device.queue.flush();

    // Expected: the entry first, as "??"; its correction in a later request.
    const [first, ...later] = device.server.receivedRequests;
    assert.equal(first.entries[0].car, null);
    assert.equal('fixes' in first, false, 'never a correction for an entry the server has not seen');
    assert.deepEqual(later.flatMap((request) => request.fixes || []), [{ uuid: entry.uuid, car: 7 }]);
    assert.equal(device.server.fixes.get(entry.uuid), 7);
});

// ---------------------------------------------------------------------------
// Trouble on the way
// ---------------------------------------------------------------------------

test('a correction survives no network and a lost answer, and is applied once', async () => {
    const { device, uuid } = await deviceWithUploadedUnread();
    await device.session.fixUnread(uuid, 41);

    device.server.behaviour = 'offline';
    await device.queue.syncOnce();
    device.server.behaviour = 'answer_lost';
    await device.queue.syncOnce();
    assert.equal(device.store.findEntry(uuid).fix_sent, false, 'not confirmed yet');

    device.server.behaviour = 'ok';
    await device.queue.flush();

    assert.equal(device.store.findEntry(uuid).fix_sent, true);
    assert.equal(fixesSent(device).length, 2, 'the lost answer and the good one');
    assert.deepEqual(device.server.fixLog, [{ uuid, car: 41 }], 'sending it again changed nothing');
});

test('a correction survives a reload, and is uploaded by the page that opens next', async () => {
    const { device, uuid } = await deviceWithUploadedUnread();
    device.server.behaviour = 'offline';
    await device.session.fixUnread(uuid, 41);

    await device.reload({ anchored: true });
    const reloaded = device.store.findEntry(uuid);
    assert.equal(reloaded.fix_car, 41);
    assert.equal(device.store.pendingCount(), 1);

    device.server.behaviour = 'ok';
    await device.queue.flush();
    assert.equal(device.server.fixes.get(uuid), 41);
});

test('entries stored by an earlier version of the page (no correction fields) still load and upload', async () => {
    // Situation: the disk holds records written before "??" existed.
    const disk = createMemoryStorage();
    await disk.put('entries', 'old-1', {
        uuid: 'old-1', car: 7, typing_ms: 500, mode: 'teclado', ts_ms: 1_760_000_000_000, time_quality: 'exato',
        ts_device_ms: 1_760_000_000_000, seq: 1, sent: false, sent_at_ms: null, voided: false, voided_at_ms: null,
        void_sent: false, refused: false, refused_reason: null,
    });
    const device = await createDevice({ disk });

    assert.equal(device.store.pendingCount(), 1);
    assert.deepEqual(device.store.fixesReadyToSend(), []);
    await device.queue.flush();
    assert.deepEqual(device.server.storedCars(), [7]);
    assert.equal(device.store.pendingCount(), 0);
    assert.equal(await device.store.setEntryFix('old-1', 41, 0), null, 'a numbered entry cannot be "corrected"');
});

// ---------------------------------------------------------------------------
// Corrected again, refused, never answered
// ---------------------------------------------------------------------------

test('a second correction replaces the first and goes up again: the newest counts', async () => {
    const { device, uuid } = await deviceWithUploadedUnread();
    await device.session.fixUnread(uuid, 41);
    await device.queue.flush();

    await device.session.fixUnread(uuid, 7);
    assert.equal(device.store.pendingCount(), 1);
    await device.queue.flush();

    assert.deepEqual(fixesSent(device), [{ uuid, car: 41 }, { uuid, car: 7 }]);
    assert.equal(device.server.fixes.get(uuid), 7);
    assert.equal(device.store.findEntry(uuid).fix_sent, true);
});

test('the same number twice is one correction', async () => {
    const { device, uuid } = await deviceWithUploadedUnread();
    await device.session.fixUnread(uuid, 41);
    await device.queue.flush();
    await device.session.fixUnread(uuid, 41);
    assert.equal(device.store.pendingCount(), 0, 'nothing new to send');
});

test('corrected again while the first correction was in the air: the newer one is not lost', async () => {
    // Situation: the counter corrects to 41; the request leaves; before
    // the answer arrives they correct to 7. The answer confirms "uuid".
    const { device, uuid } = await deviceWithUploadedUnread();
    await device.session.fixUnread(uuid, 41);
    const send = device.server.send;
    device.queue.send = async (body) => {
        const answer = await send(body);
        await device.session.fixUnread(uuid, 7);
        return answer;
    };
    await device.queue.syncOnce();

    // Expected: the server has 41, the phone knows it still owes 7.
    assert.equal(device.store.findEntry(uuid).fix_sent, false);
    assert.equal(device.store.pendingCount(), 1);
    device.queue.send = send;
    await device.queue.flush();
    assert.equal(device.server.fixes.get(uuid), 7);
});

test('a refused correction says so on its row and cannot be corrected again', async () => {
    // Situation: the server will not apply it (here: it holds the entry
    // with a number, as it would after it was sent by an older page).
    const { device, uuid } = await deviceWithUploadedUnread();
    device.server.storedEntries.get(uuid).car = 12;
    await device.session.fixUnread(uuid, 41);

    await device.queue.flush();

    const stored = device.store.findEntry(uuid);
    assert.equal(stored.fix_refused, true);
    assert.equal(stored.fix_refused_reason, MOTIVO_CORRECAO_RECUSADA);
    assert.equal(device.store.pendingCount(), 0, 'out of the queue: the shift can end');
    assert.deepEqual(device.queue.getState().recusados, [], 'shown on the row, not in the red banner');
    assert.equal(await device.session.fixUnread(uuid, 7), null);
    assert.equal(fixesSent(device).length, 1, 'asked once');
});

test('a server that never answers corrections: the safety net gives up, and "Tentar de novo" puts it back', async () => {
    // Situation: a server from before corrections existed. It stores
    // entries but says nothing about `fixes`.
    const { device, uuid } = await deviceWithUploadedUnread();
    device.server.answersFixes = false;
    await device.session.fixUnread(uuid, 41);

    for (let answer = 0; answer < MISSES_BEFORE_GIVING_UP; answer++) {
        await device.queue.syncOnce();
    }

    const stored = device.store.findEntry(uuid);
    assert.equal(stored.fix_refused, true);
    assert.equal(stored.fix_refused_reason, MOTIVO_SEM_CONFIRMACAO);
    assert.equal(device.store.pendingCount(), 0);
    assert.deepEqual(device.queue.getState().recusados, [MOTIVO_SEM_CONFIRMACAO], 'the banner offers "Tentar de novo"');

    device.server.answersFixes = true;
    await device.queue.retryRefused();
    await device.queue.flush();
    assert.equal(device.server.fixes.get(uuid), 41);
    assert.equal(device.store.findEntry(uuid).fix_sent, true);
});

test('an undone "??" uploads its void, not its correction, and cannot be corrected any more', async () => {
    // Situation: the counter gave the "??" a number, then saw it was a
    // false press and undid it, before the correction went up.
    const { device, uuid } = await deviceWithUploadedUnread();
    device.server.behaviour = 'offline';
    await device.session.fixUnread(uuid, 41);
    await device.session.undo(uuid);
    device.server.behaviour = 'ok';

    await device.queue.flush();

    // Expected: only the void goes up, so no pointless counter decision
    // lands on a voided entry, and nothing is left waiting in the queue.
    assert.deepEqual(fixesSent(device), []);
    assert.deepEqual(device.server.receivedRequests.flatMap((request) => request.voids || []), [{ uuid }]);
    assert.equal(device.store.pendingCount(), 0);
    assert.equal(await device.session.fixUnread(uuid, 42), null);
});

test('a server that names only the corrections it refuses: the others count as stored', async () => {
    // Situation: the shared contract fixes only `fixes_recusados`. A server
    // that answers that list and no `fixed_uuids` knows about corrections:
    // it must not end in "correção não confirmada" and the red banner.
    const { device, uuid } = await deviceWithUploadedUnread();
    const { entry: other } = await device.typeUnread();
    await device.queue.flush();
    device.server.storedEntries.get(other.uuid).car = 12;
    device.server.answersFixedUuids = false;
    await device.session.fixUnread(uuid, 41);
    await device.session.fixUnread(other.uuid, 7);

    await device.queue.flush();

    assert.equal(device.store.findEntry(uuid).fix_sent, true);
    assert.equal(device.store.findEntry(uuid).fix_refused, undefined);
    assert.equal(device.store.findEntry(other.uuid).fix_refused_reason, MOTIVO_CORRECAO_RECUSADA);
    assert.equal(device.store.pendingCount(), 0);
    assert.deepEqual(device.queue.getState().recusados, []);
});

test('a page refused by the server records no correction either', async () => {
    const { device, uuid } = await deviceWithUploadedUnread();
    device.server.behaviour = 'no_permission';
    await device.queue.syncOnce();

    await assert.rejects(device.session.fixUnread(uuid, 41), RecordingBlockedError);
    assert.equal(device.store.findEntry(uuid).fix_car, undefined);
});

test('the shift ends only after the corrections are on the server', async () => {
    const { device, uuid } = await deviceWithUploadedUnread();
    device.server.behaviour = 'offline';
    await device.session.fixUnread(uuid, 41);
    device.server.behaviour = 'ok';

    const outcome = await device.queue.syncOnce({ encerrando: true });

    // Expected: that request carried the correction, so it could not yet
    // say "I am done"; the next one can.
    assert.equal(outcome.encerrado, false);
    assert.equal(device.server.receivedRequests[0].encerrando, undefined);
    assert.equal((await device.queue.syncOnce({ encerrando: true })).encerrado, true);
});

test('"Exportar meus dados" shows the "??" as "??" and the number given afterwards in its own column', async () => {
    const { device, uuid } = await deviceWithUploadedUnread();
    await device.session.fixUnread(uuid, 41);
    let lines = buildEntriesCsv(device.store.allEntries()).trim().split('\r\n');
    assert.match(lines[1], new RegExp('^' + uuid + ';\\?\\?;.*;sim;nao;nao;;41;na_fila$'));

    await device.queue.flush();
    lines = buildEntriesCsv(device.store.allEntries()).trim().split('\r\n');
    assert.match(lines[1], /;41;enviada$/);
});
