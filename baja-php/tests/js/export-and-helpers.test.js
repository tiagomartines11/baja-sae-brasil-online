// SMALL PIECES: the CSV export, the race clock text and the uuid generator.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { buildEntriesCsv, exportFileName } from '../../default/js/contagem/csv-export.js';
import {
    describeOfficialTime, describeRaceClock, describeRecordTime, formatElapsed, formatTimeOfDay,
} from '../../default/js/contagem/race-clock.js';
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

    // Expected: a header and one line per entry, nothing left out. After the
    // mode come: uploaded? undone? undo uploaded? and, last, the reason when
    // the server refused the entry (empty for an ordinary one).
    assert.equal(lines[0],
        'uuid;carro;horario_oficial_ms;horario_oficial_utc;qualidade_horario;'
        + 'horario_dispositivo_ms;horario_dispositivo_utc;digitacao_ms;modo;enviado;anulado;anulacao_enviada;'
        + 'recusado_pelo_servidor');
    assert.equal(lines.length, 4);
    assert.match(lines[1], /^uuid-1;7;.*;teclado;sim;nao;nao;$/);
    assert.match(lines[2], /^uuid-2;41;.*;teclado;sim;sim;sim;$/);
    assert.match(lines[3], /^uuid-3;12;.*;blocos;nao;nao;nao;$/);
});

test('the export shows the official time, how sure it is, and what the phone clock said', () => {
    // Situation: an entry whose official (server) time is 14:03:22.500 UTC,
    // typed on a phone whose own clock was two minutes ahead.
    const officialMs = Date.UTC(2026, 9, 11, 14, 3, 22, 500);
    const csv = buildEntriesCsv([{
        uuid: 'a', car: 41, ts_ms: officialMs, time_quality: 'exato', ts_device_ms: officialMs + 120_000,
        typing_ms: 850, mode: 'teclado', sent: true, voided: false, void_sent: false,
    }]);

    // Expected: both times, each as a number and as readable UTC text.
    assert.match(csv, /a;41;1791727402500;2026-10-11T14:03:22\.500Z;exato;1791727522500;2026-10-11T14:05:22\.500Z;850;teclado;sim;nao;nao;/);
});

test('an entry still waiting for its official time says so; empty cells are empty, not "null"', () => {
    // Situation: a one-tap tile entry (no typing time) made before the page
    // had heard from the server: it has no official time yet.
    const csv = buildEntriesCsv([{
        uuid: 'a', car: 41, ts_ms: null, time_quality: null, ts_device_ms: 1000,
        typing_ms: null, mode: 'blocos', sent: false, voided: false, void_sent: false,
    }]);

    assert.match(csv, /a;41;;;aguardando;1000;1970-01-01T00:00:01\.000Z;;blocos;nao;nao;nao;/);
});

test('an entry the server refused is in the export, with the reason', () => {
    // The export is the evidence: what the server would not take must be
    // in it more than anything else.
    const csv = buildEntriesCsv([{
        uuid: 'a', car: 41, ts_ms: 5000, time_quality: 'exato', ts_device_ms: 5000, typing_ms: 300,
        mode: 'simulacao', sent: false, voided: false, void_sent: false,
        refused: true, refused_reason: 'simulacao_nao_permitida',
    }]);

    assert.match(csv, /;simulacao;nao;nao;nao;simulacao_nao_permitida\r\n$/);
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

    assert.deepEqual(describeRaceClock(notStarted, 1_000_000),
        { texto: '--:--:--', rotulo: 'Aguardando largada', estado: 'aguardando' });
    assert.equal(describeRaceClock(null, null).texto, '--:--:--');
});

test('during the race the clock runs on SERVER time', () => {
    // Situation: the race started at server time 10_000_000, and the page's
    // server-anchored clock says it is 10_060_000 now: one minute in.
    const race = { largada_ms: 10_000_000, encerramento_ms: null, estado: 'correndo' };

    const clock = describeRaceClock(race, 10_060_000);

    // Expected: 1:00, the same on every counter's screen whatever their own
    // clocks say.
    assert.equal(clock.texto, '0:01:00');
    assert.equal(clock.rotulo, 'Correndo');
});

test('until this page load has heard from the server, the race clock shows dashes', () => {
    // SITUATION: the page was reloaded with no signal. The race start is
    // remembered from the last sync, but in THIS page load the server has
    // not answered yet, so the page does not know the server's time.
    //
    // WHAT WENT WRONG: the page used the phone's own clock "as it is". A
    // phone set an hour wrong showed a race clock an hour wrong, with
    // nothing on the screen to say so.
    //
    // EXPECTED: no clock rather than a wrong clock. The state ("Correndo")
    // is still shown; the official time beside it says "sincronizando…".
    const race = { largada_ms: 10_000_000, encerramento_ms: null, estado: 'correndo' };

    assert.deepEqual(describeRaceClock(race, null), { texto: '--:--:--', rotulo: 'Correndo', estado: 'correndo' });
});

test('after the finish the clock stops at the race duration, with or without server time', () => {
    const race = { largada_ms: 10_000_000, encerramento_ms: 10_000_000 + 4 * 3_600_000, estado: 'encerrada' };

    const clock = describeRaceClock(race, 99_000_000);

    assert.equal(clock.texto, '4:00:00');
    assert.equal(clock.rotulo, 'Encerrada');
    // The duration of a finished race needs no clock at all.
    assert.equal(describeRaceClock(race, null).texto, '4:00:00');
});

test('"Horário oficial" is the server time in the event\'s time zone, or "sincronizando…"', () => {
    // SITUATION: the server's clock reads 2026-10-11 17:03:22 UTC, which is
    // 14:03:22 at the track (São Paulo). The phone could be set to any zone.
    const serverNowMs = Date.UTC(2026, 9, 11, 17, 3, 22);

    // EXPECTED: the hour everybody at the track reads on the official clock.
    assert.equal(describeOfficialTime(serverNowMs, 'America/Sao_Paulo'), '14:03:22');
    assert.equal(describeOfficialTime(serverNowMs, 'UTC'), '17:03:22');
    // Before the first answer of this page load there is no server time.
    // The page must not fall back on the phone's clock.
    assert.equal(describeOfficialTime(null, 'America/Sao_Paulo'), 'sincronizando…');
    // A time zone name the browser does not know must not break the page.
    assert.match(describeOfficialTime(serverNowMs, 'Nao/Existe'), /^\d\d:\d\d:\d\d$/);
});

test('a record shows its official time, or says that it is waiting for one', () => {
    const typedAt = Date.UTC(2026, 9, 11, 17, 3, 22);

    assert.equal(describeRecordTime({ ts_ms: typedAt }, 'America/Sao_Paulo'), '14:03:22');
    assert.equal(describeRecordTime({ ts_ms: null, ts_device_ms: typedAt }, 'America/Sao_Paulo'),
        'aguardando horário oficial');
});

test('a red flag is named in the status bar', () => {
    const race = { largada_ms: 10_000_000, encerramento_ms: null, estado: 'bandeira_vermelha' };

    assert.equal(describeRaceClock(race, 10_060_000).rotulo, 'Bandeira vermelha');
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
