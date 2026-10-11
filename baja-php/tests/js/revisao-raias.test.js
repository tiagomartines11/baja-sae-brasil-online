// Tests for the geometry of the lane timeline of the review screen.
//
// The timeline draws one horizontal lane per source. Each entry ("source 2
// typed car 41 at this moment") is a small box, a "chip", placed along the
// lane according to its time. These tests check where the chips go; the
// drawing itself needs a browser and is not tested here.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  classesDoChip, descricaoDoChip, distribuirEmLinhas, encontrarEntrada, limitesDaJanela, marcasDeTempo, posicaoPx,
  entradasDeHorarioDesconhecido, raiasDeOcorrencias,
} from '../../default/js/contagem/revisao-raias-logica.js';

test('a moment is placed in proportion to the time since the left edge', () => {
  // The window starts at 100 000 ms; at 10 pixels per second an entry
  // 3 seconds later is drawn 30 pixels from the left.
  assert.equal(posicaoPx(103000, 100000, 10), 30);
});

test('a flag\'s window uses the limits that came with it', () => {
  const janela = { de_ms: 1000, ate_ms: 61000, raias: [] };
  assert.deepEqual(limitesDaJanela(janela), { de: 1000, ate: 61000 });
});

test('a car\'s whole race takes its limits from what there is to draw', () => {
  // The car view comes without limits. First entry at 50 s, last at 500 s;
  // a pit stop was logged at 600 s. Ten seconds of margin on each side.
  const janela = {
    de_ms: null,
    ate_ms: null,
    raias: [{ entradas: [{ id: 1, ts_ms: 50000 }, { id: 2, ts_ms: 500000 }] }],
    ocorrencias: [{ ts_ms: 600000 }],
  };
  assert.deepEqual(limitesDaJanela(janela), { de: 40000, ate: 610000 });
});

test('a car nobody ever typed has nothing to draw', () => {
  assert.equal(limitesDaJanela({ de_ms: null, ate_ms: null, raias: [{ entradas: [] }], ocorrencias: [] }), null);
});

test('entries far apart all sit on the first row of their lane', () => {
  // Two entries 10 seconds apart at 10 px/s: 100 pixels between chips that
  // are 40 pixels wide. No overlap.
  const resultado = distribuirEmLinhas([{ id: 1, ts_ms: 0 }, { id: 2, ts_ms: 10000 }], 0, 10, 40);

  assert.equal(resultado.linhas, 1);
  assert.deepEqual(resultado.entradas.map((entrada) => entrada.linha), [0, 0]);
  assert.deepEqual(resultado.entradas.map((entrada) => entrada.x), [0, 100]);
});

test('a double tap is drawn on a second row instead of on top of the first chip', () => {
  // The counter tapped car 30 twice, one second apart. At 10 px/s the two
  // chips would be 10 pixels apart and 40 wide: the second would hide the
  // first, and the reviewer would not see there were two.
  const resultado = distribuirEmLinhas([{ id: 1, ts_ms: 0 }, { id: 2, ts_ms: 1000 }], 0, 10, 40);

  assert.equal(resultado.linhas, 2);
  assert.deepEqual(resultado.entradas.map((entrada) => entrada.linha), [0, 1]);
});

test('once there is room again, chips go back to the first row', () => {
  const entradas = [{ id: 1, ts_ms: 0 }, { id: 2, ts_ms: 1000 }, { id: 3, ts_ms: 9000 }];
  const resultado = distribuirEmLinhas(entradas, 0, 10, 40);

  assert.deepEqual(resultado.entradas.map((entrada) => entrada.linha), [0, 1, 0]);
});

test('chips are placed in time order whatever order the entries came in', () => {
  const resultado = distribuirEmLinhas([{ id: 2, ts_ms: 5000 }, { id: 1, ts_ms: 0 }], 0, 10, 40);
  assert.deepEqual(resultado.entradas.map((entrada) => entrada.id), [1, 2]);
});

test('an empty lane still has one row, so it keeps its height', () => {
  assert.equal(distribuirEmLinhas([], 0, 10, 40).linhas, 1);
});

test('a one-minute window gets a ruler mark every 5 seconds', () => {
  // At 14 px/s, 5 seconds are 70 pixels: enough room for a label.
  const marcas = marcasDeTempo(0, 60000, 14);
  assert.equal(marcas.length, 13);
  assert.equal(marcas[1].ts_ms, 5000);
  assert.equal(marcas[1].x, 70);
});

test('a whole race gets ruler marks minutes apart, not thousands of them', () => {
  // Four hours at 0.5 px/s. Marks every 5 minutes are 150 pixels apart.
  const quatroHoras = 4 * 3600 * 1000;
  const marcas = marcasDeTempo(0, quatroHoras, 0.5);
  assert.equal(marcas[1].ts_ms - marcas[0].ts_ms, 300000);
  assert.equal(marcas.length, 49);
});

test('ruler marks fall on round times even when the window does not start on one', () => {
  const marcas = marcasDeTempo(1234, 20000, 14);
  assert.deepEqual(marcas.map((marca) => marca.ts_ms), [5000, 10000, 15000, 20000]);
});

test('a chip shows whether its entry was voided, reassigned, counted or is part of the flag', () => {
  // Entry 7 belongs to the selected flag and was counted as a lap.
  const contada = { id: 7, grupo: { contado: true, numero_volta: 12 } };
  assert.deepEqual(classesDoChip(contada, [7, 8], null), ['chip', 'contada', 'em-destaque']);

  // Entry 9 was voided by a person; the reviewer has just clicked it.
  const anulada = { id: 9, anulada: true, grupo: null };
  assert.deepEqual(classesDoChip(anulada, [], 9), ['chip', 'anulada', 'selecionada']);

  // Entry 3 was typed as 14 and reassigned to 41 by a reviewer.
  const reatribuida = { id: 3, reatribuida: true, grupo: { contado: false } };
  assert.deepEqual(classesDoChip(reatribuida, [], null), ['chip', 'reatribuida']);
});

test('a double tap the engine voided on its own looks different from a void made by a person', () => {
  assert.ok(classesDoChip({ id: 1, anulada_auto: true }, [], null).includes('anulada-auto'));
  assert.ok(!classesDoChip({ id: 1, anulada_auto: true }, [], null).includes('anulada'));
});

test('the tooltip of a chip tells its story', () => {
  const entrada = { id: 3, carro: 41, carro_digitado: 14, reatribuida: true, grupo: { contado: true, numero_volta: 12 } };
  assert.equal(descricaoDoChip(entrada), 'passagem #3 · digitado 14, trocado para 41 · volta 12');
});

test('an entry is found by id across all lanes', () => {
  const janela = { raias: [{ entradas: [{ id: 1 }] }, { entradas: [{ id: 2 }, { id: 3 }] }] };
  assert.equal(encontrarEntrada(janela, 3).id, 3);
  assert.equal(encontrarEntrada(janela, 99), null);
  assert.equal(encontrarEntrada(null, 1), null);
});

// SITUATION: on a car's timeline there are four kinds of crossing that
// two counters agreed on:
//   - the car's first crossing, its "passagem zero" (official, not a lap);
//   - an ordinary lap;
//   - a crossing typed inside the "janela de largada" (the organisers chose
//     not to count the start pack, but a counter typed it anyway);
//   - a second crossing after the finish (only the first one after the
//     finish counts).
//
// EXPECTED: each looks different and its tooltip says what it is. The last
// two stay VISIBLE on the timeline, marked as not counting: a team that
// protests must be able to see that the pass was recorded and why it is
// not a lap.
test('the pass zero and the passes left out by the rules are told apart from laps', () => {
  const zero = { id: 1, grupo: { papel: 'inicial', contado: true, numero_volta: 0 } };
  const volta = { id: 2, grupo: { papel: 'volta', contado: true, numero_volta: 1 } };
  const naJanela = { id: 3, grupo: { papel: 'janela_largada', contado: false, numero_volta: null } };
  const depoisDoFim = { id: 4, grupo: { papel: 'apos_encerramento', contado: false, numero_volta: null } };

  assert.deepEqual(classesDoChip(zero, [], null), ['chip', 'contada', 'passagem-zero']);
  assert.deepEqual(classesDoChip(volta, [], null), ['chip', 'contada']);
  assert.deepEqual(classesDoChip(naJanela, [], null), ['chip', 'janela-largada']);
  assert.deepEqual(classesDoChip(depoisDoFim, [], null), ['chip', 'apos-encerramento']);

  assert.match(descricaoDoChip(zero), /passagem zero/);
  assert.match(descricaoDoChip(volta), /volta 1$/);
  assert.match(descricaoDoChip(naJanela), /janela de largada/);
  assert.match(descricaoDoChip(depoisDoFim), /depois do encerramento/);
});

test('an entry that is not in use says why', () => {
  const antes = { id: 5, fora_de_uso: true, motivo_fora_de_uso: 'antes_da_largada', grupo: null };
  const excluida = { id: 6, fora_de_uso: true, motivo_fora_de_uso: 'fonte_excluida', grupo: null };
  assert.match(descricaoDoChip(antes), /antes da largada/);
  assert.match(descricaoDoChip(excluida), /fonte foi excluída/);
});

// SITUATION: a counter's phone was offline, was restarted, and had no saved
// idea of the official time. The entry it typed for car 41 is uploaded with
// whatever the phone's own clock said: here, three days before the race.
// The engine never counts such an entry by itself.
//
// WHAT WOULD GO WRONG: placed on the time axis like the others, that one
// chip would stretch the car's timeline over three days (the whole race a
// few pixels wide at the far end) and sit where nothing happened.
//
// EXPECTED: the timeline covers only the entries whose time is known. The
// one of unknown time is listed apart, still clickable, with its lane's
// name, and its chip and tooltip say what it is.
test('an entry of unknown time does not stretch the timeline and is listed apart', () => {
  const tresDias = 3 * 24 * 3600 * 1000;
  const janela = {
    de_ms: null,
    ate_ms: null,
    raias: [
      { nome: 'Contagem 1', entradas: [{ id: 1, carro: 41, ts_ms: 1_000_000 }, { id: 2, carro: 41, ts_ms: 1_200_000 }] },
      { nome: 'Contagem 2', entradas: [{ id: 3, carro: 41, ts_ms: 1_000_000 - tresDias, qualidade_tempo: 'desconhecido', fora_de_uso: true, motivo_fora_de_uso: 'tempo_desconhecido' }] },
    ],
    ocorrencias: [],
  };

  assert.deepEqual(limitesDaJanela(janela), { de: 990_000, ate: 1_210_000 });

  const soltas = entradasDeHorarioDesconhecido(janela);
  assert.deepEqual(soltas.map((entrada) => [entrada.id, entrada.raia]), [[3, 'Contagem 2']]);
  assert.ok(classesDoChip(soltas[0], [], null).includes('tempo-desconhecido'));
  assert.match(descricaoDoChip(soltas[0]), /horário desconhecido/);
});

test('an entry whose time was estimated stays on the timeline and is marked', () => {
  // Typed offline and stamped after the page was reloaded: close to right,
  // matched like any other, and shown with a "~".
  const entrada = { id: 7, carro: 41, ts_ms: 1000, qualidade_tempo: 'estimado', grupo: { papel: 'volta', numero_volta: 3, contado: true } };
  assert.ok(classesDoChip(entrada, [], null).includes('tempo-estimado'));
  assert.match(descricaoDoChip(entrada), /horário estimado/);
});

// SITUATION: the start was recorded a few seconds late (the button was
// pressed after the cars had gone). Car 12's first crossing, typed by all
// three counters, is now two seconds BEFORE the start.
//
// WHAT WENT WRONG: entries before the start simply vanished from every
// screen. Car 12 lost a lap and nothing anywhere said why.
//
// EXPECTED: the pass stays on the timeline, drawn as not counting and
// described as "antes da largada", with what to do about it. Once a
// reviewer confirms it, it is the car's pass zero and is drawn as counted.
test('a pass typed just before the start stays visible and says what it is', () => {
  const antes = { id: 1, carro: 12, ts_ms: 998, grupo: { papel: 'antes_da_largada', contado: false, numero_volta: null } };
  assert.ok(classesDoChip(antes, [], null).includes('antes-da-largada'));
  assert.ok(!classesDoChip(antes, [], null).includes('contada'));
  assert.match(descricaoDoChip(antes), /antes da largada \(não conta/);

  const confirmada = { ...antes, grupo: { papel: 'antes_da_largada', contado: true, numero_volta: 0 } };
  assert.ok(classesDoChip(confirmada, [], null).includes('contada'));
  assert.match(descricaoDoChip(confirmada), /confirmada pelo revisor como passagem zero/);
});

// SITUATION: the pit lane and the fueling station are each logged by two
// people, one at the way in and one at the way out. Car 12 went through the
// pit lane normally. Car 40 left the fueling station, but whoever stood at
// its entrance never logged the car going in.
//
// EXPECTED: below the counters' lanes there is one lane for race control,
// one for the pit lane and one for the fueling station. A stay with both
// ends is a bar; the exit with no entry is kept, shown, and marked as
// unpaired. It blocks nothing.
test('race control, the pit lane and the fueling station each get a lane, and an unpaired event is shown as unpaired', () => {
  const janela = {
    abastecimento: true,
    ocorrencias: [
      { id: 1, tipo: 'largada', carro: null, ts_ms: 0, area: null, sem_par: false },
      { id: 2, tipo: 'box_entrada', carro: 12, ts_ms: 1000, area: 'box', sem_par: false },
      { id: 3, tipo: 'box_saida', carro: 12, ts_ms: 4000, area: 'box', sem_par: false },
      { id: 4, tipo: 'abastecimento_saida', carro: 40, ts_ms: 5000, area: 'abastecimento', sem_par: true },
    ],
    estadias: [{ area: 'box', carro: 12, de_ms: 1000, ate_ms: 4000 }],
  };

  const [direcao, box, abastecimento] = raiasDeOcorrencias(janela);

  assert.deepEqual(direcao.marcas.map((marca) => marca.tipo), ['largada']);
  assert.deepEqual(box.marcas.map((marca) => marca.id), [2, 3]);
  assert.deepEqual(box.estadias, [{ area: 'box', carro: 12, de_ms: 1000, ate_ms: 4000 }]);
  assert.equal(abastecimento.nome, 'Abastecimento');
  assert.deepEqual(abastecimento.marcas.map((marca) => [marca.carro, marca.sem_par]), [[40, true]]);
  assert.deepEqual(abastecimento.estadias, [], 'an exit with no entry is not a stay');
});

test('with the fueling area switched off and nothing logged there, its lane is not drawn', () => {
  const raias = raiasDeOcorrencias({ abastecimento: false, ocorrencias: [], estadias: [] });
  assert.deepEqual(raias.map((raia) => raia.nome), ['Direção de prova', 'Box']);
});
