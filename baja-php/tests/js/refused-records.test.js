// RECORDS THE SERVER WILL NOT STORE ("recusado pelo servidor", brief 2.1).
//
// Background: the phone sends every record again and again until the
// server's answer names its uuid. That is what makes a bad network harmless.
// But it had a blind spot: a record the server would NEVER name.
//
// WHAT WENT WRONG: one such record (an entry whose time the server found
// absurd) stayed in the queue for ever. The count of waiting records never
// reached zero, so the phone never told the server "you have everything"
// (`queue_empty`), so the server kept waiting for this counter and held back
// alerts, and "Encerrar meu turno" always answered "ainda há 1 registro na
// fila". One bad record blocked a whole phone for the rest of the race.
//
// Now a record leaves the QUEUE (never the phone) in two cases:
//   1. the server lists it in `refused`, with a reason;
//   2. safety net: several successful answers in a row do not mention it.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    MISSES_BEFORE_GIVING_UP, MOTIVO_SEM_CONFIRMACAO, UnacknowledgedTracker, refusedRecords,
} from '../../default/js/contagem/refused-records.js';
import { endShift, TURNO } from '../../default/js/contagem/shift-control.js';
import { buildEntriesCsv } from '../../default/js/contagem/csv-export.js';
import { describeBanner } from '../../default/js/contagem/status-messages.js';
import { ARMAZENAMENTO } from '../../default/js/contagem/storage-open.js';
import { createDevice } from './helpers/fakes.js';

const ON_DISK = { tokenProblem: null, armazenamento: ARMAZENAMENTO.DISCO };

/** As many heartbeats as the safety net needs to give up on a record. */
async function heartbeatsUntilGivenUp(device) {
    for (let round = 0; round < MISSES_BEFORE_GIVING_UP; round++) {
        device.clock.advance(5000);
        await device.queue.syncOnce();
    }
}

// ---------------------------------------------------------------------------
// 1. The server says so
// ---------------------------------------------------------------------------

test('an entry the server refuses with a reason leaves the queue and stays on the phone', async () => {
    // SITUATION: a simulated entry reaches a prova that does not allow
    // simulation (somebody left a simulation running in another tab). The
    // server answers 200, does not store it, and lists it in `refused`.
    const device = await createDevice();
    device.server.simulacao = false;
    await device.type(41, 'simulacao');
    await device.type(7);

    await device.queue.flush();

    // EXPECTED: the ordinary entry is uploaded as always...
    assert.deepEqual(device.server.storedCars(), [7]);
    // ...the refused one is marked, with the server's reason, and no longer
    // counts as waiting...
    const refused = device.store.findEntry('uuid-1');
    assert.equal(refused.refused, true);
    assert.equal(refused.refused_reason, 'simulacao_nao_permitida');
    assert.equal(refused.sent, false);
    assert.equal(device.queue.getState().pendentes, 0);
    // ...so the phone can tell the server it has everything...
    assert.equal(device.server.receivedRequests.at(-1).queue_empty, true);
    // ...and is not sent again.
    await device.queue.syncOnce();
    assert.deepEqual(device.server.receivedRequests.at(-1).entries, []);
});

test('a refused entry is still in the local log and in "Exportar meus dados"', async () => {
    const device = await createDevice();
    await device.type(41, 'simulacao');
    await device.queue.flush();

    // EXPECTED: nothing is ever deleted from the phone. The export has the
    // entry, with the reason in its last column.
    assert.deepEqual(device.store.allEntries().map((entry) => entry.car), [41]);
    const lines = buildEntriesCsv(device.store.allEntries()).trim().split('\r\n');
    assert.match(lines[1], /^uuid-1;41;.*;simulacao;nao;nao;nao;simulacao_nao_permitida;;$/);
});

test('the counter is told, in a banner that stays', async () => {
    const device = await createDevice();
    await device.type(41, 'simulacao');
    await device.type(12, 'simulacao');
    await device.queue.flush();

    const banner = describeBanner(device.queue.getState(), ON_DISK);

    // EXPECTED: one warning saying how many, why, and that nothing was
    // deleted. "Tentar de novo" is not offered: the server gave its reason,
    // and asking again would get the same answer.
    assert.equal(banner.avisos.length, 1);
    assert.match(banner.avisos[0], /^2 registros foram recusados pelo servidor/);
    assert.match(banner.avisos[0], /a simulação não está permitida nesta prova/);
    assert.match(banner.avisos[0], /Exportar meus dados/);
    assert.equal(banner.oferecerNovaTentativa, false);
});

test('a refused pit event is handled the same way', async () => {
    const device = await createDevice();
    await device.pit.recordEvent({ car: 12, tipo: 'box_entrada', mode: 'simulacao' });

    await device.queue.flush();

    assert.equal(device.store.allPitEvents()[0].refused, true);
    assert.equal(device.queue.getState().pendentes, 0);
    assert.deepEqual(refusedRecords(device.store).map((item) => item.collection), ['pit_events']);
});

// ---------------------------------------------------------------------------
// 2. The safety net: the server says nothing at all about a record
// ---------------------------------------------------------------------------

test('an entry that several good answers in a row fail to mention stops blocking the phone', async () => {
    // SITUATION: the bug this section is about, whatever its cause on the
    // server: every answer is a proper 200, and none of them ever names the
    // entry for car 41, neither as stored nor as refused. Car 7, typed
    // after it, is stored normally.
    const device = await createDevice();
    await device.type(41);
    device.server.ignoredUuids.add('uuid-1');
    await device.type(7);

    await device.queue.syncOnce();
    assert.equal(device.queue.getState().pendentes, 1, 'at first it simply waits, as any unconfirmed entry');
    assert.equal(device.store.findEntry('uuid-1').refused, false);

    await heartbeatsUntilGivenUp(device);

    // EXPECTED: after MISSES_BEFORE_GIVING_UP answers the phone gives up on
    // it: marked "recusado" (reason: no confirmation), out of the queue,
    // still on the phone.
    const givenUp = device.store.findEntry('uuid-1');
    assert.equal(givenUp.refused, true);
    assert.equal(givenUp.refused_reason, MOTIVO_SEM_CONFIRMACAO);
    assert.equal(device.queue.getState().pendentes, 0);
    assert.deepEqual(device.server.storedCars(), [7]);

    // The phone is whole again: it reports an empty queue...
    await device.queue.syncOnce();
    assert.equal(device.server.receivedRequests.at(-1).queue_empty, true);
    assert.deepEqual(device.server.receivedRequests.at(-1).entries, []);
    // ...and the counter can end the shift.
    assert.equal(await endShift(device.queue), TURNO.ENCERRADO);
});

test('for an entry given up on, "Tentar de novo" is offered and puts it back in the queue', async () => {
    // SITUATION: as above; meanwhile the server's problem was fixed.
    const device = await createDevice();
    await device.type(41);
    device.server.ignoredUuids.add('uuid-1');
    await device.queue.syncOnce();
    await heartbeatsUntilGivenUp(device);

    const banner = describeBanner(device.queue.getState(), ON_DISK);
    assert.match(banner.avisos[0], /^1 registro foi recusado pelo servidor \(o servidor não confirmou o recebimento\)/);
    assert.equal(banner.oferecerNovaTentativa, true);

    device.server.ignoredUuids.clear();
    await device.queue.retryRefused();
    await device.queue.flush();

    // EXPECTED: it goes up, once, and the banner has nothing left to say.
    assert.deepEqual(device.server.storedCars(), [41]);
    assert.equal(device.store.findEntry('uuid-1').refused, false);
    assert.deepEqual(describeBanner(device.queue.getState(), ON_DISK).avisos, []);
});

test('failed requests do not count: only answers that could have named the entry do', async () => {
    // SITUATION: twenty heartbeats with no network at all, then a server
    // that answers 500, then garbage.
    const device = await createDevice();
    await device.type(41);
    for (const behaviour of ['offline', 'server_error', 'garbage', 'answer_lost']) {
        device.server.behaviour = behaviour;
        for (let round = 0; round < 20; round++) {
            await device.queue.syncOnce();
        }
    }

    // EXPECTED: the entry is still waiting. A bad network must never make
    // the phone give up on anything.
    assert.equal(device.store.findEntry('uuid-1').refused, false);
    assert.equal(device.queue.getState().pendentes, 1);
    device.server.behaviour = 'ok';
    await device.queue.flush();
    assert.deepEqual(device.server.storedCars(), [41]);
});

test('entries a busy server has simply not reached yet are not counted as skipped', () => {
    // SITUATION: the server stores only the first 2 records of each big
    // request and leaves the rest for the next one. The records after the
    // last stored one were not passed over, they were not reached.
    const tracker = new UnacknowledgedTracker(2);

    for (let round = 0; round < 10; round++) {
        const givenUp = tracker.noteAnswer('entries', ['a', 'b', 'c', 'd'], new Set(['a', 'b']));
        assert.deepEqual(givenUp, []);
    }

    // But a record the server went PAST (it stored a later one) was skipped.
    assert.deepEqual(tracker.noteAnswer('entries', ['x', 'y'], new Set(['y'])), []);
    assert.deepEqual(tracker.noteAnswer('entries', ['x', 'z'], new Set(['z'])), ['x']);
});

test('an undo the server never confirms does not block the phone either', async () => {
    // SITUATION: the entry is on the server; its "desfazer" goes up and the
    // server never lists it in `voided_uuids` (for example because a
    // reviewer had already voided that entry).
    const device = await createDevice();
    await device.type(41);
    await device.queue.flush();
    await device.session.undo('uuid-1');
    device.server.ignoredUuids.add('void:uuid-1');

    await heartbeatsUntilGivenUp(device);

    // EXPECTED: the undo is given up on like an entry would be. On the
    // phone the entry still shows as undone.
    assert.equal(device.store.findEntry('uuid-1').void_refused, true);
    assert.equal(device.store.findEntry('uuid-1').voided, true);
    assert.equal(device.queue.getState().pendentes, 0);
});

test('undoing an entry the server refused leaves nothing waiting', async () => {
    // SITUATION: the counter taps "desfazer" on an entry that is marked
    // "recusado pelo servidor". The server never had it; there is nothing
    // to undo there.
    const device = await createDevice();
    await device.type(41, 'simulacao');
    await device.queue.flush();

    await device.session.undo('uuid-1');

    assert.equal(device.queue.getState().pendentes, 0);
});
