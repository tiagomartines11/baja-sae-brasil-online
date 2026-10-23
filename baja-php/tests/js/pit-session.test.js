// PIT LANE AND FUELING STATION LOG.
//
// Background: at the pit lane, and at the fueling station when the prova has
// one, volunteers record every car that enters and every car that leaves.
// Usually two people do it, one at each end, each on a phone: one records
// entries, the other exits. A phone that has never chosen is asked (which
// area, then entry or exit); one that has opens in the mode it used last.
// After that ONE TAP ON A CAR IS ONE EVENT (which taps count: box-toques.test.js).
//
// The page shows which cars are inside right now. That list comes from the
// server (`cars_in_pit`, `cars_in_fuel`), but the server only knows the
// events it has received. The page therefore adds the events still waiting
// on this phone, so the list is right the instant a car is tapped, with or
// without network.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    AREA, SENTIDO, PASSO, TIPO_PARADA, PitFlow, mergeCarsInside, orderCarsForMode, partsOfTipo, tipoFor,
} from '../../default/js/contagem/pit-session.js';
import {
    describeGroups, describeRecordedEvent, describeReopened, describeTipo, TEXTO_ABASTECIMENTO_DESLIGADO, TEXTO_BLOQUEADO, TEXTO_NAO_GUARDOU,
} from '../../default/js/contagem/box-listas.js';
import { createPreferences } from '../../default/js/contagem/entry-modes.js';
import { buildPitEventsCsv } from '../../default/js/contagem/csv-export.js';
import { createDevice } from './helpers/fakes.js';

/** A pretend pit-lane phone: the same fake device as the count tests; `device.pit` is its PitSession. */
function createPitDevice(options) {
    return createDevice({ uuidPrefix: 'pit', ...options });
}

function localEvent(seq, car, tipo) {
    return { uuid: 'e' + seq, seq, car, tipo };
}

/** `window.localStorage` for one pretend phone. */
function createPhoneStorage() {
    const values = new Map();
    return {
        getItem: (key) => (values.has(key) ? values.get(key) : null),
        setItem: (key, value) => values.set(key, value),
    };
}

// ---------------------------------------------------------------------------
// The four event types
// ---------------------------------------------------------------------------

test('area and direction together give the event type of the upload contract', () => {
    assert.equal(tipoFor(AREA.BOX, SENTIDO.ENTRADA), 'box_entrada');
    assert.equal(tipoFor(AREA.BOX, SENTIDO.SAIDA), 'box_saida');
    assert.equal(tipoFor(AREA.ABASTECIMENTO, SENTIDO.ENTRADA), 'abastecimento_entrada');
    assert.equal(tipoFor(AREA.ABASTECIMENTO, SENTIDO.SAIDA), 'abastecimento_saida');
    assert.deepEqual(partsOfTipo('abastecimento_saida'), { area: 'abastecimento', sentido: 'saida' });
    assert.equal(partsOfTipo('largada'), null);
});

// ---------------------------------------------------------------------------
// The choices in front of the car tiles
// ---------------------------------------------------------------------------

test('without a fueling station the page asks one thing: entry or exit', () => {
    // SITUATION: a prova with the pit lane only. A volunteer opens the page.
    const flow = new PitFlow({ preferences: createPreferences(createPhoneStorage()), fuelingEnabled: false });

    // EXPECTED: no question about the area (there is only one). Nothing can
    // be recorded yet: a tap on a car before the choice has no meaning.
    assert.equal(flow.passo(), PASSO.SENTIDO);
    assert.equal(flow.tipo(), null);

    flow.chooseSentido(SENTIDO.ENTRADA);

    // From here on every tap on a car records an entry into the pit lane.
    assert.equal(flow.passo(), PASSO.CARROS);
    assert.equal(flow.tipo(), TIPO_PARADA.BOX_ENTRADA);
});

test('with a fueling station the page asks for the area first, then entry or exit', () => {
    // SITUATION: the prova logs the fueling station too
    // (`contagem.abastecimento`). The volunteer stands at its exit.
    const flow = new PitFlow({ preferences: createPreferences(createPhoneStorage()), fuelingEnabled: true });
    assert.equal(flow.passo(), PASSO.AREA);

    flow.chooseArea(AREA.ABASTECIMENTO);
    assert.equal(flow.passo(), PASSO.SENTIDO);
    assert.equal(flow.tipo(), null, 'still nothing can be recorded');

    flow.chooseSentido(SENTIDO.SAIDA);
    assert.equal(flow.tipo(), TIPO_PARADA.ABASTECIMENTO_SAIDA);
});

test('the mode stays a switch: one person doing both ends changes it with one tap', () => {
    const flow = new PitFlow({ preferences: createPreferences(createPhoneStorage()), fuelingEnabled: true });
    flow.chooseArea(AREA.BOX);
    flow.chooseSentido(SENTIDO.ENTRADA);

    flow.chooseSentido(SENTIDO.SAIDA);
    assert.equal(flow.tipo(), TIPO_PARADA.BOX_SAIDA);
    flow.chooseArea(AREA.ABASTECIMENTO);
    assert.equal(flow.tipo(), TIPO_PARADA.ABASTECIMENTO_SAIDA);
});

test('a reopened page goes straight to the mode this phone used last', () => {
    // SITUATION: the marshal at the pit exit is recording exits. The phone
    // restarts (or the page is reloaded) while a car is on its way out.
    //
    // WHAT WENT WRONG: the page asked "entry or exit?" again every time it
    // was opened, and only highlighted the last answer. The car left while
    // the marshal was answering.
    const phoneStorage = createPhoneStorage();
    const before = new PitFlow({ preferences: createPreferences(phoneStorage), fuelingEnabled: false });
    assert.equal(before.abriuNoModoLembrado, false, 'a phone that never chose is asked');
    before.chooseSentido(SENTIDO.SAIDA);

    const reopened = new PitFlow({ preferences: createPreferences(phoneStorage), fuelingEnabled: false });

    // EXPECTED: no question; the first tap records an exit. The flow says
    // that it assumed the mode, so the page can tell the marshal.
    assert.equal(reopened.passo(), PASSO.CARROS);
    assert.equal(reopened.tipo(), TIPO_PARADA.BOX_SAIDA);
    assert.equal(reopened.abriuNoModoLembrado, true);
});

test('with a fueling station, the remembered area is reopened too', () => {
    const phoneStorage = createPhoneStorage();
    const before = new PitFlow({ preferences: createPreferences(phoneStorage), fuelingEnabled: true });
    before.chooseArea(AREA.ABASTECIMENTO);
    before.chooseSentido(SENTIDO.ENTRADA);

    const reopened = new PitFlow({ preferences: createPreferences(phoneStorage), fuelingEnabled: true });
    assert.equal(reopened.tipo(), TIPO_PARADA.ABASTECIMENTO_ENTRADA);

    // The same phone on a prova WITHOUT a fueling station: the remembered
    // area means nothing there; the pit lane, in the remembered direction.
    const otherProva = new PitFlow({ preferences: createPreferences(phoneStorage), fuelingEnabled: false });
    assert.equal(otherProva.tipo(), TIPO_PARADA.BOX_ENTRADA);
});

test('what the phone does not remember is still asked', () => {
    // SITUATION: this phone only ever worked on provas without a fueling
    // station, so it remembers "Saída" but no area. This prova has one.
    const phoneStorage = createPhoneStorage();
    new PitFlow({ preferences: createPreferences(phoneStorage), fuelingEnabled: false }).chooseSentido(SENTIDO.SAIDA);

    const flow = new PitFlow({ preferences: createPreferences(phoneStorage), fuelingEnabled: true });

    // EXPECTED: the area is asked (guessing it would log the wrong place);
    // the direction is not asked again.
    assert.equal(flow.passo(), PASSO.AREA);
    assert.equal(flow.abriuNoModoLembrado, false);
    flow.chooseArea(AREA.BOX);
    assert.equal(flow.tipo(), TIPO_PARADA.BOX_SAIDA);
});

test('the fueling area cannot be chosen when the prova does not log it', () => {
    const flow = new PitFlow({ preferences: createPreferences(createPhoneStorage()), fuelingEnabled: false });

    flow.chooseArea(AREA.ABASTECIMENTO);
    flow.chooseSentido(SENTIDO.ENTRADA);

    assert.equal(flow.tipo(), TIPO_PARADA.BOX_ENTRADA);
});

test('when the fueling station is switched off mid-race, the page moves to the pit lane and says so', () => {
    // SITUATION: the volunteer is recording fueling exits. The controller
    // turns `contagem.abastecimento` off; the next sync answer says so.
    const flow = new PitFlow({ preferences: createPreferences(createPhoneStorage()), fuelingEnabled: true });
    flow.chooseArea(AREA.ABASTECIMENTO);
    flow.chooseSentido(SENTIDO.SAIDA);

    const movedToPitLane = flow.setFuelingEnabled(false);

    // EXPECTED: true, so the page can show a message; taps now record the
    // pit lane, since the fueling types would no longer mean anything.
    assert.equal(movedToPitLane, true);
    assert.equal(flow.tipo(), TIPO_PARADA.BOX_SAIDA);
    // Hearing the same thing again changes nothing and reports nothing.
    assert.equal(flow.setFuelingEnabled(false), false);
});

test('a fueling station switched on while the page is still asking adds the area question', () => {
    // SITUATION: the page was printed (or kept offline) before the
    // controller switched the fueling station on. The first sync answer
    // says it is on; the volunteer has not chosen anything yet.
    const flow = new PitFlow({ preferences: createPreferences(createPhoneStorage()), fuelingEnabled: false });
    assert.equal(flow.passo(), PASSO.SENTIDO);

    flow.setFuelingEnabled(true);

    // EXPECTED: the area is asked first, as on a freshly printed page.
    assert.equal(flow.passo(), PASSO.AREA);

    // But somebody already recording is not interrupted: the area switch
    // appears, and the taps go on meaning what they meant.
    const recording = new PitFlow({ preferences: createPreferences(createPhoneStorage()), fuelingEnabled: false });
    recording.chooseSentido(SENTIDO.ENTRADA);
    recording.setFuelingEnabled(true);
    assert.equal(recording.tipo(), TIPO_PARADA.BOX_ENTRADA);
});

// ---------------------------------------------------------------------------
// Which cars are inside
// ---------------------------------------------------------------------------

test('with nothing waiting on the phone, the list is the server list, sorted', () => {
    assert.deepEqual(mergeCarsInside([40, 12], []), [12, 40]);
});

test('a car that just entered shows up before the event is uploaded', () => {
    // Situation: the server says 12 and 40 are in. The marshal taps car 7 in
    // "Entrada" mode; the upload has not happened yet.
    const carsInPit = mergeCarsInside([12, 40], [localEvent(1, 7, TIPO_PARADA.BOX_ENTRADA)]);

    assert.deepEqual(carsInPit, [7, 12, 40]);
});

test('a car that just left disappears before the event is uploaded', () => {
    const carsInPit = mergeCarsInside([12, 40], [localEvent(1, 12, TIPO_PARADA.BOX_SAIDA)]);

    assert.deepEqual(carsInPit, [40]);
});

test('waiting events are applied in the order they happened', () => {
    // Situation, offline: car 7 enters and then leaves; car 9 leaves (the
    // marshal missed its entry) and then enters again.
    const waiting = [
        localEvent(1, 7, TIPO_PARADA.BOX_ENTRADA),
        localEvent(2, 7, TIPO_PARADA.BOX_SAIDA),
        localEvent(3, 9, TIPO_PARADA.BOX_SAIDA),
        localEvent(4, 9, TIPO_PARADA.BOX_ENTRADA),
    ];

    // Expected: 7 is out, 9 is in. The array order given to the function must
    // not matter, only the order in which the events were recorded.
    assert.deepEqual(mergeCarsInside([], waiting), [9]);
    assert.deepEqual(mergeCarsInside([], waiting.slice().reverse()), [9]);
});

test('an exit nobody saw enter, or an entry recorded twice, does not break the list', () => {
    const waiting = [
        localEvent(1, 33, TIPO_PARADA.BOX_SAIDA),
        localEvent(2, 12, TIPO_PARADA.BOX_ENTRADA),
        localEvent(3, 12, TIPO_PARADA.BOX_ENTRADA),
    ];

    // Expected: 12 appears once; 33 simply is not in. All three events are
    // still recorded and uploaded as tapped; the review screen shows the
    // unpaired ones. Nothing is ever blocked here.
    assert.deepEqual(mergeCarsInside([12], waiting), [12]);
});

test('before the first answer from the server the list is built from local events alone', () => {
    assert.deepEqual(mergeCarsInside(undefined, [localEvent(1, 7, TIPO_PARADA.BOX_ENTRADA)]), [7]);
});

test('the pit lane and the fueling station are two separate lists', () => {
    // SITUATION: one phone, used at both areas during an offline spell: car 7
    // went into the pit lane, car 9 into the fueling station, and car 12,
    // which the server has in the fueling station, left it.
    const waiting = [
        localEvent(1, 7, TIPO_PARADA.BOX_ENTRADA),
        localEvent(2, 9, TIPO_PARADA.ABASTECIMENTO_ENTRADA),
        localEvent(3, 12, TIPO_PARADA.ABASTECIMENTO_SAIDA),
    ];

    // EXPECTED: each list only moves with the events of its own area. A car
    // can be in both at once; the areas know nothing of each other.
    assert.deepEqual(mergeCarsInside([40], waiting, AREA.BOX), [7, 40]);
    assert.deepEqual(mergeCarsInside([12], waiting, AREA.ABASTECIMENTO), [9]);
});

// ---------------------------------------------------------------------------
// The order of the car tiles (brief 8.2)
// ---------------------------------------------------------------------------

test('in "Saída" mode the cars inside come first and every other car is below', () => {
    // SITUATION: cars 1 to 5 are racing; 2 and 4 are in the pit lane. The
    // volunteer at the exit is about to tap one of those two.
    const groups = orderCarsForMode({ listedCars: [1, 2, 3, 4, 5], carsInside: [4, 2], sentido: SENTIDO.SAIDA });

    assert.deepEqual(groups.primeiros, [2, 4]);
    // EXPECTED: the others are NOT hidden. If the volunteer at the entry
    // missed car 5 going in, the one at the exit must still be able to tap
    // it when it leaves.
    assert.deepEqual(groups.outros, [1, 3, 5]);
});

test('in "Entrada" mode it is the reverse: cars outside first, cars inside below', () => {
    const groups = orderCarsForMode({ listedCars: [1, 2, 3, 4, 5], carsInside: [4, 2], sentido: SENTIDO.ENTRADA });

    assert.deepEqual(groups.primeiros, [1, 3, 5]);
    // A second entry with no exit in between is possible (the exit was
    // missed), so the cars already inside can still be tapped.
    assert.deepEqual(groups.outros, [2, 4]);
});

test('a car that is inside is shown even when it is not in the car list', () => {
    // SITUATION: car 9 is not classified, so it is not in the list the page
    // draws tiles from. But somebody typed it in at the pit entry, and the
    // server says it is inside.
    const leaving = orderCarsForMode({ listedCars: [1, 2], carsInside: [9], sentido: SENTIDO.SAIDA });
    const entering = orderCarsForMode({ listedCars: [1, 2], carsInside: [9], sentido: SENTIDO.ENTRADA });

    // EXPECTED: it has a tile in both modes; otherwise it could never be
    // logged out again. No car appears twice.
    assert.deepEqual(leaving, { primeiros: [9], outros: [1, 2] });
    assert.deepEqual(entering, { primeiros: [1, 2], outros: [9] });
});

test('the headings say in words which area and which direction the next tap records', () => {
    const exitFromFueling = describeGroups(AREA.ABASTECIMENTO, SENTIDO.SAIDA);
    const entryToPitLane = describeGroups(AREA.BOX, SENTIDO.ENTRADA);

    assert.equal(exitFromFueling.instrucao, 'SAÍDA do abastecimento: toque no carro que SAIU');
    // The short form, for "Como da última vez: …".
    assert.equal(exitFromFueling.modo, 'SAÍDA do abastecimento');
    assert.equal(entryToPitLane.modo, 'ENTRADA no box');
    assert.equal(exitFromFueling.primeiros, 'No abastecimento agora');
    assert.equal(entryToPitLane.instrucao, 'ENTRADA no box: toque no carro que ENTROU');
    assert.equal(entryToPitLane.outros, 'Outros (já estão no box)');
    assert.equal(describeTipo('abastecimento_saida'), 'saiu do abastecimento');
    assert.equal(describeTipo('box_entrada'), 'entrou no box');
});

test('the line above the tiles says what the last tap did in at most two lines', () => {
    // Background (field note N5, 26SE): this line is right above the car
    // tiles. It grew from one line to three with a longer message, and every
    // tile moved down under the finger. It is now always two lines high; on
    // a 360 px phone two lines of its 16 px bold text hold about 70
    // characters (measured in headless Chromium: the longest message below,
    // 69 characters, fits), so every message keeps to that.
    const messages = [
        describeRecordedEvent(1234, 'abastecimento_saida', true),
        describeRecordedEvent(15, 'box_entrada', false),
        describeReopened(AREA.ABASTECIMENTO, SENTIDO.SAIDA),
        describeReopened(AREA.ABASTECIMENTO, SENTIDO.ENTRADA),
        TEXTO_NAO_GUARDOU(1234),
        TEXTO_BLOQUEADO(1234),
        TEXTO_ABASTECIMENTO_DESLIGADO,
    ];

    assert.equal(messages[0], 'Carro 1234 saiu do abastecimento. Número fora da lista (registrado).');
    assert.equal(messages[1], 'Carro 15 entrou no box.');
    assert.equal(messages[2], 'Como da última vez: SAÍDA do abastecimento (mude acima).');
    for (const message of messages) {
        assert.ok(message.length <= 70, '"' + message + '" is ' + message.length + ' characters');
    }
});

// ---------------------------------------------------------------------------
// Recording and uploading: same mechanics as the count page
// ---------------------------------------------------------------------------

test('a pit event is uploaded in the shape the server expects', async () => {
    // Situation: car 12 enters the pit lane; good network.
    const device = await createPitDevice();
    device.clock.advance(2000);
    const tappedAt = device.clock.serverNow();
    const recorded = await device.pit.recordEvent({ car: 12, tipo: TIPO_PARADA.BOX_ENTRADA });

    await device.queue.syncOnce();

    // Expected: it travels in `pit_events` (not in `entries`) with the fields
    // of the contract, its time already final and on the server's clock, and
    // is then marked as sent.
    const request = device.server.receivedRequests[0];
    assert.deepEqual(request.entries, []);
    assert.deepEqual(request.pit_events, [{
        uuid: 'pit-1', car: 12, tipo: 'box_entrada', ts_ms: tappedAt, time_quality: 'exato',
        ts_device_ms: recorded.ts_device_ms,
    }]);
    assert.equal(device.store.allPitEvents()[0].sent, true);
    assert.equal(device.queue.getState().pendentes, 0);
});

test('a fueling event goes up with its own type', async () => {
    const device = await createPitDevice();
    await device.pit.recordEvent({ car: 7, tipo: TIPO_PARADA.ABASTECIMENTO_SAIDA });

    await device.queue.syncOnce();

    assert.equal(device.server.receivedRequests[0].pit_events[0].tipo, 'abastecimento_saida');
});

test('pit events typed offline survive a reload and arrive once', async () => {
    // Situation: no signal in the pit lane. Car 12 enters, car 12 leaves, the
    // phone restarts, the signal returns.
    const device = await createPitDevice();
    device.server.behaviour = 'offline';
    await device.pit.recordEvent({ car: 12, tipo: TIPO_PARADA.BOX_ENTRADA });
    await device.pit.recordEvent({ car: 12, tipo: TIPO_PARADA.BOX_SAIDA });
    await device.queue.syncOnce();

    await device.reload();
    device.server.behaviour = 'answer_lost';
    await device.queue.syncOnce();
    device.server.behaviour = 'ok';
    await device.queue.flush();

    // Expected: both events on the server, in order, each stored once even
    // though they were sent twice.
    assert.deepEqual(Array.from(device.server.storedPitEvents.values(), (event) => event.tipo), ['box_entrada', 'box_saida']);
    assert.equal(device.server.worstInsertCount(), 1);
    assert.equal(device.queue.getState().pendentes, 0);
});

test('a pit event tapped before the page has heard from the server gets its exact time afterwards', async () => {
    // SITUATION: the pit page was opened from its offline copy, in a spot
    // with no signal. The phone's clock is 3 minutes fast. Car 12 enters.
    // Ten seconds later the signal is there.
    const device = await createPitDevice({ anchored: false });
    device.clock.setWallClockError(180_000);
    device.server.behaviour = 'offline';
    const enteredAt = device.clock.serverNow();
    await device.pit.recordEvent({ car: 12, tipo: TIPO_PARADA.BOX_ENTRADA });
    device.clock.advance(10_000);

    device.server.behaviour = 'ok';
    await device.queue.flush();

    // EXPECTED: the event is stored with the true time of the tap, not the
    // time of the upload and not the phone's fast clock.
    const stored = device.server.storedPitEvents.get('pit-1');
    assert.equal(stored.ts_ms, enteredAt);
    assert.equal(stored.time_quality, 'exato');
});

test('the list on screen combines the server answer with events still waiting', async () => {
    // Situation: the server knows car 40 is in the pit and car 9 is fueling.
    // The phone is offline; the marshal records 7 entering the pit and 40
    // leaving it.
    const device = await createPitDevice();
    device.server.carsInPit = [40];
    device.server.carsInFuel = [9];
    await device.queue.syncOnce();
    const serverInfo = () => device.store.getMeta('servidor');
    assert.deepEqual(device.pit.carsInside(AREA.BOX, serverInfo().cars_in_pit), [40]);

    device.server.behaviour = 'offline';
    await device.pit.recordEvent({ car: 7, tipo: TIPO_PARADA.BOX_ENTRADA });
    await device.pit.recordEvent({ car: 40, tipo: TIPO_PARADA.BOX_SAIDA });

    // Expected: 7 in, 40 out, immediately; the fueling list is untouched.
    assert.deepEqual(device.pit.carsInside(AREA.BOX, serverInfo().cars_in_pit), [7]);
    assert.deepEqual(device.pit.carsInside(AREA.ABASTECIMENTO, serverInfo().cars_in_fuel), [9]);

    // When the upload succeeds the server's new list takes over, and the
    // already-uploaded events are no longer applied a second time.
    device.server.behaviour = 'ok';
    device.server.carsInPit = [7];
    await device.queue.syncOnce();
    assert.deepEqual(device.pit.carsInside(AREA.BOX, serverInfo().cars_in_pit), [7]);
});

test('an event type other than the four known ones is refused', async () => {
    // A guard against a programming mistake in the page: nothing but the
    // four known types may ever be stored or uploaded.
    const device = await createPitDevice();

    await assert.rejects(() => device.pit.recordEvent({ car: 12, tipo: 'largada' }));
    assert.equal(device.store.allPitEvents().length, 0);
});

// ---------------------------------------------------------------------------
// The race simulation records through the same function, with a given time
// ---------------------------------------------------------------------------

test('a simulated pit event carries its intended time and says it is simulated', async () => {
    // SITUATION: a rehearsal. The simulation plays a race script; a browser
    // timer fires late (tabs in the background are slowed down), so "now"
    // is 4 seconds after the moment the car was meant to enter the pit.
    const device = await createPitDevice();
    device.server.simulacao = true;
    const intendedMs = device.clock.serverNow() - 4000;

    await device.pit.recordEvent({ car: 12, tipo: TIPO_PARADA.BOX_ENTRADA, tsMs: intendedMs, mode: 'simulacao' });
    await device.queue.flush();

    // EXPECTED: the same path as a tap (local store, queue, upload), with
    // the intended time and `mode: "simulacao"` so the server can tell.
    const stored = device.server.storedPitEvents.get('pit-1');
    assert.equal(stored.ts_ms, intendedMs);
    assert.equal(stored.time_quality, 'exato');
    assert.equal(stored.mode, 'simulacao');
});

test('the pit export lists every event recorded on this phone', async () => {
    // Situation: one event uploaded, one still waiting; the marshal exports.
    const device = await createPitDevice();
    await device.pit.recordEvent({ car: 12, tipo: TIPO_PARADA.BOX_ENTRADA });
    await device.queue.syncOnce();
    await device.pit.recordEvent({ car: 12, tipo: TIPO_PARADA.ABASTECIMENTO_SAIDA });

    const lines = buildPitEventsCsv(device.store.allPitEvents()).trim().split('\r\n');

    assert.equal(lines.length, 3, 'header plus two events');
    assert.match(lines[1], /;12;box_entrada;.*;exato;.*;sim;$/);
    assert.match(lines[2], /;12;abastecimento_saida;.*;nao;$/);
});
