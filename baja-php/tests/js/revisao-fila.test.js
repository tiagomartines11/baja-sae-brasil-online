// Tests for the flag queue of the review screen: in which order the flags
// appear, what each source's chip says, and which source is switched on
// but counting nothing. The car table is in revisao-carros.test.js.
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
  compararNuloPorUltimo, dispositivosDaFonte, encontrarAlerta, estadoDaFonte, fonteSilenciosa, janelaDoAlerta, ordenarFila,
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
  const chip = estadoDaFonte(fonte, AGORA);
  assert.equal(chip.classe, 'ao-vivo');
  assert.equal(chip.texto, 'ao vivo');
  assert.equal(chip.idade, null);
});

// SITUATION (field note N8): the bar is redrawn every second. The phone of
// a counter says "I have sent everything" with each heartbeat, every few
// seconds, so between two heartbeats the chip read "ao vivo", then "ao
// vivo, sincronizado há 3 s", "há 4 s"… then "ao vivo" again: it grew and
// shrank all the time, and the whole bar jumped.
//
// EXPECTED: the normal rhythm (under 15 s) shows no age at all; the exact
// age is only in the title.
test('the normal lag between two heartbeats changes nothing on the chip', () => {
  const textos = [];
  for (const atrasoS of [0, 1, 3, 5, 9, 14]) {
    const fonte = { ativa: true, tipo: 'manual', stats: { offline: false, visto_em_ms: AGORA - 500, sincronizado_ate_ms: AGORA - atrasoS * 1000 } };
    const chip = estadoDaFonte(fonte, AGORA);
    textos.push([chip.classe, chip.texto, chip.idade]);
  }
  assert.deepEqual(new Set(textos.map((texto) => JSON.stringify(texto))).size, 1);
  const fonte = { ativa: true, tipo: 'manual', stats: { offline: false, visto_em_ms: AGORA - 500, sincronizado_ate_ms: AGORA - 9000 } };
  assert.equal(estadoDaFonte(fonte, AGORA).titulo, 'ao vivo, sincronizado há 9 s');
});

test('a source in contact but with entries still to upload turns amber and says how late, in steps', () => {
  // The counter's phone is talking to the server, but its last "I have
  // sent everything" was 40 seconds ago: recent passes may be missing.
  const fonte = { ativa: true, tipo: 'manual', stats: { offline: false, visto_em_ms: AGORA - 2000, sincronizado_ate_ms: AGORA - 40000 } };
  const chip = estadoDaFonte(fonte, AGORA);
  assert.equal(chip.classe, 'ao-vivo atrasada');
  assert.equal(chip.texto, 'ao vivo', 'the text keeps its shape');
  assert.equal(chip.idade, '30 s', 'the age moves in steps of 15 s');
  assert.match(chip.titulo, /há 40 s/, 'the exact age is in the title');
});

test('a source the server has not heard from is shown as offline, with for how long', () => {
  // The counter walked into an area without signal 3 minutes ago. They keep
  // counting on the phone; the reviewer must know their passes are missing.
  const fonte = { ativa: true, tipo: 'manual', stats: { offline: true, visto_em_ms: AGORA - 180000, sincronizado_ate_ms: AGORA - 180000 } };
  const chip = estadoDaFonte(fonte, AGORA);
  assert.equal(chip.classe, 'offline');
  assert.equal(chip.texto, 'offline');
  assert.equal(chip.idade, '3 min');
});

// ---- a source that is switched on but counts nothing ----------------------

// SITUATION (26SE): "Contagem 3" was active the whole race. Its only phone
// had been revoked the night before; nobody opened it again. It recorded
// nothing, and its chip said "ao vivo" in green from start to finish.
//
// EXPECTED: after the start, an active counting source with no device open
// and no entry at all is a problem, said in red; before the start it is
// not (nobody has had to count yet).
const LARGADA = AGORA - 3_600_000;
const CONTAGEM_3 = {
  id: 3, nome: 'Contagem 3', ativa: true, tipo: 'manual', papel: 'votante',
  stats: { entradas: 0, offline: false, visto_em_ms: LARGADA - 12 * 3_600_000, nada_a_esperar: true, sincronizado_ate_ms: null },
};

test('an active source with no device open and nothing recorded since the start is not "ao vivo"', () => {
  const chip = estadoDaFonte(CONTAGEM_3, AGORA, LARGADA);
  assert.equal(chip.classe, 'silenciosa');
  assert.equal(chip.texto, 'sem registros desde a largada');
  assert.equal(fonteSilenciosa(CONTAGEM_3, LARGADA, AGORA), true);
});

test('the same source while the race is still being prepared is no problem yet', () => {
  assert.equal(fonteSilenciosa(CONTAGEM_3, null, AGORA), false, 'no start yet');
  assert.equal(fonteSilenciosa(CONTAGEM_3, AGORA + 60000, AGORA), false, 'start in a minute');
  assert.notEqual(estadoDaFonte(CONTAGEM_3, AGORA, null).classe, 'silenciosa');
});

test('a source that counted and then closed its shift is not silent, and not green either', () => {
  // Handover done: the phone pressed "Encerrar meu turno" after 300 entries.
  const encerrada = { ...CONTAGEM_3, stats: { ...CONTAGEM_3.stats, entradas: 300, visto_em_ms: AGORA - 60000 } };
  assert.equal(fonteSilenciosa(encerrada, LARGADA, AGORA), false);
  assert.equal(estadoDaFonte(encerrada, AGORA, LARGADA).classe, 'sem-dispositivo');
});

test('a source nobody ever opened is silent once the race has started', () => {
  const nunca = { ...CONTAGEM_3, stats: { entradas: 0, offline: true, visto_em_ms: null, nada_a_esperar: true, sincronizado_ate_ms: null } };
  assert.equal(estadoDaFonte(nunca, AGORA, null).classe, 'nunca');
  assert.equal(estadoDaFonte(nunca, AGORA, LARGADA).classe, 'silenciosa');
});

test('a source with a phone open but no entry yet is not silent: the counter may just be waiting', () => {
  const aberta = { ...CONTAGEM_3, stats: { entradas: 0, offline: false, visto_em_ms: AGORA - 1000, nada_a_esperar: false, sincronizado_ate_ms: AGORA - 1000 } };
  assert.equal(fonteSilenciosa(aberta, LARGADA, AGORA), false);
  assert.equal(estadoDaFonte(aberta, AGORA, LARGADA).classe, 'ao-vivo');
});

test('an observer that records nothing is never a missing counter', () => {
  // A camera on trial (papel 'observadora') counts nothing anyway.
  const camera = { ...CONTAGEM_3, tipo: 'automatica', papel: 'observadora' };
  assert.equal(fonteSilenciosa(camera, LARGADA, AGORA), false);
  assert.notEqual(estadoDaFonte(camera, AGORA, LARGADA).classe, 'silenciosa');
});

test('a switched-off source and a paper total are never silent', () => {
  assert.equal(fonteSilenciosa({ ...CONTAGEM_3, ativa: false }, LARGADA, AGORA), false);
  assert.equal(fonteSilenciosa({ ...CONTAGEM_3, tipo: 'totais' }, LARGADA, AGORA), false);
});

test('a source nobody has opened yet says so', () => {
  const fonte = { ativa: true, tipo: 'manual', stats: { offline: true, visto_em_ms: null, sincronizado_ate_ms: null } };
  assert.equal(estadoDaFonte(fonte, AGORA).classe, 'nunca');
});

test('a deactivated source and a paper-totals source are never "offline"', () => {
  assert.equal(estadoDaFonte({ ativa: false, tipo: 'manual', stats: null }, AGORA).classe, 'inativa');
  assert.equal(estadoDaFonte({ ativa: true, tipo: 'totais', stats: null }, AGORA).classe, 'totais');
});

test('the open-flag counter is written in proper Portuguese', () => {
  assert.equal(resumoDeAlertas(0), 'nenhum alerta');
  assert.equal(resumoDeAlertas(1), '1 alerta');
  assert.equal(resumoDeAlertas(7), '7 alertas');
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
  const chip = estadoDaFonte(fonte, 10000);
  assert.equal(chip.texto, 'sem dispositivo aberto');
  // Nothing more is coming from it: that is no "ao vivo" in green.
  assert.equal(chip.classe, 'sem-dispositivo');
});

test('null comes last when comparing, whatever is compared', () => {
  assert.deepEqual([3, null, 1, undefined, 2].sort(compararNuloPorUltimo), [1, 2, 3, null, undefined]);
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
