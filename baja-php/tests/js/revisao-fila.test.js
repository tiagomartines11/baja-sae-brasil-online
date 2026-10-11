// Tests for the flag queue of the review screen: in which order the flags
// appear, what each source's chip says, and which counts are highlighted
// in the car table.
//
// Vocabulary:
//   - "alerta" (flag): something the system wants a reviewer to look at.
//   - "prioridade": the best standings position among the cars of a flag.
//     1 means the flag involves the race leader.
//   - "fonte" (source): one counter (a person typing car numbers), an
//     automated system, or totals copied from paper.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  dispositivosDaFonte, encontrarAlerta, estadoDaFonte, fontesDivergentes, janelaDoAlerta, ordenarFila,
  resumoDeAlertas, resumoDosDispositivos,
  mostraCorridaInteira, quandoDoAlerta,
} from '../../default/js/contagem/revisao-fila.js';

// A small helper so each test only writes the fields it cares about.
function alerta(id, prioridade, tsMs) {
  return { id, prioridade, ts_ms: tsMs };
}

test('flags about the top of the standings come first', () => {
  // Three flags: one about the car in 7th, one about the leader, one about 3rd.
  const fila = ordenarFila([alerta('c', 7, 100), alerta('a', 1, 300), alerta('b', 3, 200)]);

  // A mistake on the leader changes who wins; it is looked at first.
  assert.deepEqual(fila.map((item) => item.id), ['a', 'b', 'c']);
});

test('among flags about the same position, the oldest comes first', () => {
  // Both flags involve the car in 2nd place. One is from minute 10 of the
  // race, the other from minute 2.
  const fila = ordenarFila([alerta('recente', 2, 600000), alerta('antigo', 2, 120000)]);

  // The old one has been wrong on the public screen for longer.
  assert.deepEqual(fila.map((item) => item.id), ['antigo', 'recente']);
});

test('a flag about a car with no position goes after the ones that have one', () => {
  // "prioridade: null" happens for a car that has no counted lap yet, or
  // for a number that is not a car of the event at all.
  const fila = ordenarFila([alerta('sem-posicao', null, 10), alerta('decimo', 10, 999)]);

  assert.deepEqual(fila.map((item) => item.id), ['decimo', 'sem-posicao']);
});

test('the queue keeps the same order from one poll to the next', () => {
  // Two flags that tie on everything. The screen redraws every 2 seconds;
  // if ties were broken at random the list would jump under the mouse.
  const umaOrdem = ordenarFila([alerta('x', 4, 50), alerta('y', 4, 50)]);
  const outraOrdem = ordenarFila([alerta('y', 4, 50), alerta('x', 4, 50)]);

  assert.deepEqual(umaOrdem.map((item) => item.id), ['x', 'y']);
  assert.deepEqual(outraOrdem.map((item) => item.id), ['x', 'y']);
});

test('sorting does not change the list the server sent', () => {
  const original = [alerta('b', 2, 1), alerta('a', 1, 1)];
  ordenarFila(original);
  assert.deepEqual(original.map((item) => item.id), ['b', 'a']);
});

test('a selected flag shows about one minute of the race around it', () => {
  const janela = janelaDoAlerta({ ts_ms: 500000 });
  assert.deepEqual(janela, { de: 470000, ate: 530000 });
});

test('a flag with no moment has no timeline to load', () => {
  // Example: "the paper total for car 12 differs from the count". It is
  // about the whole race, not about one instant.
  assert.equal(janelaDoAlerta({ ts_ms: null }), null);
});

test('a flag is found whether it is open or already resolved', () => {
  const estado = {
    alertas_abertos: [{ id: 'solitaria:12' }],
    alertas_resolvidos: [{ id: 'repetida:30' }],
  };
  assert.equal(encontrarAlerta(estado, 'repetida:30').id, 'repetida:30');
  assert.equal(encontrarAlerta(estado, 'nao-existe'), null);
});

// ---- the chip of each source in the status bar --------------------------

const AGORA = 1_760_000_100_000;

test('a source in contact and fully uploaded is "ao vivo"', () => {
  const fonte = { ativa: true, tipo: 'manual', stats: { offline: false, visto_em_ms: AGORA - 1000, sincronizado_ate_ms: AGORA - 1000 } };
  assert.deepEqual(estadoDaFonte(fonte, AGORA), { classe: 'ao-vivo', texto: 'ao vivo' });
});

test('a source in contact but with entries still to upload says since when it is complete', () => {
  // The counter's phone is talking to the server, but its last "I have
  // sent everything" was 40 seconds ago: recent passes may be missing.
  const fonte = { ativa: true, tipo: 'manual', stats: { offline: false, visto_em_ms: AGORA - 2000, sincronizado_ate_ms: AGORA - 40000 } };
  assert.equal(estadoDaFonte(fonte, AGORA).texto, 'ao vivo, sincronizado há 40 s');
});

test('a source the server has not heard from is shown as offline, with for how long', () => {
  // The counter walked into an area without signal 3 minutes ago. They keep
  // counting on the phone; the reviewer must know their passes are missing.
  const fonte = { ativa: true, tipo: 'manual', stats: { offline: true, visto_em_ms: AGORA - 180000, sincronizado_ate_ms: AGORA - 180000 } };
  assert.deepEqual(estadoDaFonte(fonte, AGORA), { classe: 'offline', texto: 'offline há 3 min' });
});

test('a source nobody has opened yet says so', () => {
  const fonte = { ativa: true, tipo: 'manual', stats: { offline: true, visto_em_ms: null, sincronizado_ate_ms: null } };
  assert.equal(estadoDaFonte(fonte, AGORA).classe, 'nunca');
});

test('a deactivated source and a paper-totals source are never "offline"', () => {
  assert.equal(estadoDaFonte({ ativa: false, tipo: 'manual', stats: null }, AGORA).classe, 'inativa');
  assert.equal(estadoDaFonte({ ativa: true, tipo: 'totais', stats: null }, AGORA).classe, 'totais');
});

// ---- highlighting disagreements in the car table --------------------------

const TRES_CONTADORES = [
  { id: 1, tipo: 'manual', ativa: true },
  { id: 2, tipo: 'manual', ativa: true },
  { id: 3, tipo: 'manual', ativa: true },
];

test('no cell is highlighted when every counter agrees with the official count', () => {
  const carro = { voltas: 20, contagem_por_fonte: { 1: 20, 2: 20, 3: 20 }, totais_importados: {} };
  assert.deepEqual(fontesDivergentes(carro, TRES_CONTADORES), []);
});

test('the counter that missed a pass is highlighted', () => {
  // Counter 3 looked away once: 19 entries for a car with 20 official laps.
  const carro = { voltas: 20, contagem_por_fonte: { 1: 20, 2: 20, 3: 19 }, totais_importados: {} };
  assert.deepEqual(fontesDivergentes(carro, TRES_CONTADORES), [3]);
});

test('a counter with no entry at all for a car counts as zero', () => {
  // Counter 2 never typed this car. Its cell is missing from the data,
  // which must read as 0, not as "agrees".
  const carro = { voltas: 2, contagem_por_fonte: { 1: 2, 3: 2 }, totais_importados: {} };
  assert.deepEqual(fontesDivergentes(carro, TRES_CONTADORES), [2]);
});

test('an imported paper total that differs from the count is highlighted', () => {
  const fontes = [...TRES_CONTADORES, { id: 9, tipo: 'totais', ativa: true }];
  const carro = { voltas: 20, contagem_por_fonte: { 1: 20, 2: 20, 3: 20 }, totais_importados: { 9: 21 } };
  assert.deepEqual(fontesDivergentes(carro, fontes), [9]);
});

test('a car missing from the paper sheet is not treated as a disagreement', () => {
  const fontes = [{ id: 9, tipo: 'totais', ativa: true }];
  const carro = { voltas: 20, contagem_por_fonte: {}, totais_importados: {} };
  assert.deepEqual(fontesDivergentes(carro, fontes), []);
});

test('a deactivated source is ignored', () => {
  const fontes = [{ id: 1, tipo: 'manual', ativa: false }];
  const carro = { voltas: 20, contagem_por_fonte: { 1: 3 }, totais_importados: {} };
  assert.deepEqual(fontesDivergentes(carro, fontes), []);
});

test('the open-flag counter is written in proper Portuguese', () => {
  assert.equal(resumoDeAlertas(0), 'nenhum alerta');
  assert.equal(resumoDeAlertas(1), '1 alerta');
  assert.equal(resumoDeAlertas(7), '7 alertas');
});

// ---- the pass zero ------------------------------------------------------------

// SITUATION: the counting line is after the start line, so the first time a
// car crosses it the car has not completed a lap: that crossing is its
// "passagem zero". Car 41 has crossed 6 times: the pass zero and 5 laps.
// All three counters typed all 6 crossings.
//
// WHAT WENT WRONG: the table compared each counter's 6 entries with the 5
// official LAPS, so every cell of every car was highlighted as a
// disagreement and the highlighting meant nothing.
//
// EXPECTED: the table shows 5 laps, and compares what the counters typed
// with the car's 6 official PASSES. Nothing is highlighted. A counter who
// really missed one (5 entries) is.
test('counters are compared with official passes, which include the pass zero', () => {
  const todosViram = { voltas: 5, passagens: 6, contagem_por_fonte: { 1: 6, 2: 6, 3: 6 }, totais_importados: {} };
  assert.deepEqual(fontesDivergentes(todosViram, TRES_CONTADORES), []);

  const contador2Perdeu = { voltas: 5, passagens: 6, contagem_por_fonte: { 1: 6, 2: 5, 3: 6 }, totais_importados: {} };
  assert.deepEqual(fontesDivergentes(contador2Perdeu, TRES_CONTADORES), [2]);
});

test('a paper total is a number of laps and is still compared with the laps', () => {
  const fontes = [{ id: 9, tipo: 'totais', ativa: true }];
  const carro = { voltas: 5, passagens: 6, contagem_por_fonte: {}, totais_importados: { 9: 5 } };
  assert.deepEqual(fontesDivergentes(carro, fontes), []);
});

// ---- the devices of a source ------------------------------------------------

// SITUATION: Contagem 1 was counted on three phones during the race. Ana's
// is working. Bruno's lost its signal ten minutes ago and the system has
// stopped waiting for it. Carla pressed "Encerrar meu turno" when Ana took
// over. A fourth phone, on Contagem 2, was revoked by the controller.
//
// EXPECTED: the status bar says, for Contagem 1, what is NOT simply
// working ("1 silencioso, 1 turno encerrado") and nothing about the phone
// that is fine. Contagem 2 says "1 revogado".
test('the status bar names the devices of a source that are silent, closed or revoked', () => {
  const estado = {
    dispositivos: [
      { id: 1, fonte_id: 1, usuario: 'ana', situacao: 'ativo' },
      { id: 2, fonte_id: 1, usuario: 'bruno', situacao: 'silencioso' },
      { id: 3, fonte_id: 1, usuario: 'carla', situacao: 'fechado' },
      { id: 4, fonte_id: 2, usuario: 'davi', situacao: 'revogado' },
    ],
  };

  assert.equal(dispositivosDaFonte(estado, 1).length, 3);
  assert.equal(resumoDosDispositivos(dispositivosDaFonte(estado, 1)), '1 silencioso, 1 turno encerrado');
  assert.equal(resumoDosDispositivos(dispositivosDaFonte(estado, 2)), '1 revogado');
  assert.equal(resumoDosDispositivos([{ id: 1, fonte_id: 1, situacao: 'ativo' }]), '', 'nothing to say when all is well');
});

test('a source whose devices were all closed says nobody is expected, not a date', () => {
  const fonte = { ativa: true, tipo: 'manual', stats: { visto_em_ms: 9000, offline: false, nada_a_esperar: true, sincronizado_ate_ms: null } };
  assert.deepEqual(estadoDaFonte(fonte, 10000), { classe: 'ao-vivo', texto: 'sem dispositivo aberto' });
});

// SITUATION: a flag "tempo_desconhecido": counter 3's phone uploaded an
// entry for car 33 with no official time. The time the entry carries is the
// phone's own guess, here three days before the race.
//
// WHAT WOULD GO WRONG: shown like any other flag, the queue would say it
// happened at "-71:35:00" and its panel would load one empty minute of
// three days ago.
//
// EXPECTED: such a flag says "sem horário", and its panel shows the car's
// whole race, which is what the reviewer needs to decide where (and
// whether) the pass belongs. Every other flag keeps its time and its
// one-minute window.
test('a flag about an entry of unknown time shows no time and loads the car\'s whole race', () => {
  const largada = 1_760_000_000_000;
  const desconhecido = { tipo: 'tempo_desconhecido', carros: [33], passagem_ids: [9], ts_ms: largada - 3 * 24 * 3600 * 1000 };
  const solitaria = { tipo: 'solitaria', carros: [44], passagem_ids: [5], ts_ms: largada + 400_000 };

  assert.equal(mostraCorridaInteira(desconhecido), true);
  assert.equal(quandoDoAlerta(desconhecido, largada), 'sem horário');

  assert.equal(mostraCorridaInteira(solitaria), false);
  assert.equal(quandoDoAlerta(solitaria, largada), '0:06:40');
});
