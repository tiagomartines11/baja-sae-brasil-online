// SMALL PIECES: the CSV export, the race clock text and the uuid generator.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { buildEntriesCsv, exportFileName } from '../../default/js/contagem/csv-export.js';
import { describeRaceClock, formatElapsed, formatTimeOfDay } from '../../default/js/contagem/race-clock.js';
import { generateUuid } from '../../default/js/contagem/uuid.js';
import { createDevice } from './helpers/fakes.js';

// ---------------------------------------------------------------------------
// "Exportar meus dados"
// ---------------------------------------------------------------------------

test('the export contains every entry: uploaded, waiting and undone', async () => {
    // Situation: the counter typed three cars. The first was uploaded, the
    // second was uploaded and then undone, the third is still waiting because
    // the network went away. The organiser asks for the counter's file.
    const device = await createDevice();
    await device.type(7);
    await device.type(41);
    await device.queue.flush();
    await device.session.undo('uuid-2');
    await device.queue.flush();
    device.server.behaviour = 'offline';
    await device.type(12, 'blocos');

    const csv = buildEntriesCsv(device.store.allEntries());
    const lines = csv.replace('﻿', '').trim().split('\r\n');

    // Expected: a header and one line per entry, nothing left out. The last
    // three columns say: uploaded? undone? undo uploaded?
    assert.equal(lines[0],
        'uuid;carro;horario_dispositivo_ms;horario_dispositivo_utc;desvio_relogio_ms;digitacao_ms;modo;enviado;anulado;anulacao_enviada');
    assert.equal(lines.length, 4);
    assert.match(lines[1], /^uuid-1;7;.*;teclado;sim;nao;nao$/);
    assert.match(lines[2], /^uuid-2;41;.*;teclado;sim;sim;sim$/);
    assert.match(lines[3], /^uuid-3;12;.*;blocos;nao;nao;nao$/);
});

test('the export shows both the raw device time and a readable UTC time', () => {
    const csv = buildEntriesCsv([{
        uuid: 'a', car: 41, ts_device_ms: Date.UTC(2026, 9, 11, 14, 3, 22, 500), clock_offset_ms: -120,
        typing_ms: 850, mode: 'teclado', sent: true, voided: false, void_sent: false,
    }]);

    assert.match(csv, /a;41;1791727402500;2026-10-11T14:03:22\.500Z;-120;850;teclado;sim;nao;nao/);
});

test('an unknown clock offset and a tile tap leave their columns empty, not "null"', () => {
    const csv = buildEntriesCsv([{
        uuid: 'a', car: 41, ts_device_ms: 1000, clock_offset_ms: null,
        typing_ms: null, mode: 'blocos', sent: false, voided: false, void_sent: false,
    }]);

    assert.match(csv, /a;41;1000;1970-01-01T00:00:01\.000Z;;;blocos;nao;nao;nao/);
});

test('the export opens correctly in Excel: byte-order mark, semicolons, quoted specials', () => {
    // Excel in Portuguese expects ";" between columns, and needs the invisible
    // byte-order mark at the start to read accents correctly.
    const csv = buildEntriesCsv([{
        uuid: 'a;b"c', car: 41, ts_device_ms: 1000, clock_offset_ms: null,
        typing_ms: null, mode: 'teclado', sent: false, voided: false, void_sent: false,
    }]);

    assert.equal(csv.charCodeAt(0), 0xFEFF);
    assert.ok(csv.includes('"a;b""c";41;'), 'a value containing ; or " is wrapped in quotes');
    assert.ok(csv.endsWith('\r\n'));
});

test('the export of an empty log is just the header', () => {
    const lines = buildEntriesCsv([]).trim().split('\r\n');

    assert.equal(lines.length, 1);
});

test('the file name says which event, prova and source it came from, and when', () => {
    const fileName = exportFileName(['26BR', 'END', 'Contagem nº 1'], Date.UTC(2026, 9, 11, 14, 3, 22));

    assert.equal(fileName, 'contagem-26br-end-contagem-n-1-2026-10-11T14-03-22.csv');
});

// ---------------------------------------------------------------------------
// Race clock
// ---------------------------------------------------------------------------

test('elapsed time is written as hours:minutes:seconds', () => {
    assert.equal(formatElapsed(0), '0:00:00');
    assert.equal(formatElapsed(59_999), '0:00:59');
    assert.equal(formatElapsed(3_725_000), '1:02:05');
    assert.equal(formatElapsed(-500), '0:00:00', 'never a negative clock');
});

test('before the start the clock shows dashes and "Aguardando largada"', () => {
    // Situation: the page is open but race control has not started the race,
    // or the phone has never heard from the server.
    const notStarted = { largada_ms: null, encerramento_ms: null, estado: 'aguardando' };

    assert.deepEqual(describeRaceClock(notStarted, 1_000_000, 0),
        { texto: '--:--:--', rotulo: 'Aguardando largada', estado: 'aguardando' });
    assert.equal(describeRaceClock(null, 1_000_000, null).texto, '--:--:--');
});

test('during the race the clock runs on SERVER time, using the clock offset', () => {
    // Situation: the race started at server time 10_000_000. The phone's
    // clock is 5 seconds behind the server (offset +5000). The phone reads
    // 10_055_000, which is really 10_060_000 on the server: one minute in.
    const race = { largada_ms: 10_000_000, encerramento_ms: null, estado: 'correndo' };

    const clock = describeRaceClock(race, 10_055_000, 5000);

    // Expected: 1:00, the same on every counter's screen whatever their own
    // clocks say.
    assert.equal(clock.texto, '0:01:00');
    assert.equal(clock.rotulo, 'Correndo');
});

test('with no offset known the phone clock is used as it is', () => {
    // Situation: offline reload. The race start is remembered from the last
    // sync, the offset is not. A slightly wrong clock beats no clock.
    const race = { largada_ms: 10_000_000, encerramento_ms: null, estado: 'correndo' };

    assert.equal(describeRaceClock(race, 10_055_000, null).texto, '0:00:55');
});

test('after the finish the clock stops at the race duration', () => {
    const race = { largada_ms: 10_000_000, encerramento_ms: 10_000_000 + 4 * 3_600_000, estado: 'encerrada' };

    const clock = describeRaceClock(race, 99_000_000, 0);

    assert.equal(clock.texto, '4:00:00');
    assert.equal(clock.rotulo, 'Encerrada');
});

test('a red flag is named in the status bar', () => {
    const race = { largada_ms: 10_000_000, encerramento_ms: null, estado: 'bandeira_vermelha' };

    assert.equal(describeRaceClock(race, 10_060_000, 0).rotulo, 'Bandeira vermelha');
});

test('"último envio" is shown as a time of day, or a dash when there was none', () => {
    const quarterPastTwo = new Date(2026, 9, 11, 14, 15, 9).getTime();

    assert.equal(formatTimeOfDay(quarterPastTwo), '14:15:09');
    assert.equal(formatTimeOfDay(null), '—');
});

// ---------------------------------------------------------------------------
// Uuids
// ---------------------------------------------------------------------------

const UUID_V4_SHAPE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

test('every entry id has the standard uuid shape, 36 characters', () => {
    // The server column is CHAR(36); anything else would be rejected.
    assert.match(generateUuid(), UUID_V4_SHAPE);
});

test('ids are still valid on a page without crypto.randomUUID (plain http)', () => {
    // Situation: browsers only offer crypto.randomUUID on secure (https)
    // pages. The development setup is plain http, and so might a field setup be.
    const onlyRandomValues = { getRandomValues: (bytes) => globalThis.crypto.getRandomValues(bytes) };

    assert.match(generateUuid(onlyRandomValues), UUID_V4_SHAPE);
    assert.match(generateUuid(null), UUID_V4_SHAPE, 'and even with no crypto object at all');
});

test('a thousand ids are all different', () => {
    // Two entries sharing an id would be treated by the server as one entry.
    for (const cryptoSource of [globalThis.crypto, null]) {
        const ids = new Set();
        for (let count = 0; count < 1000; count++) {
            ids.add(generateUuid(cryptoSource));
        }
        assert.equal(ids.size, 1000);
    }
});
