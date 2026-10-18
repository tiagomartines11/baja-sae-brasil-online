// Tests for how the review screen keeps in touch with the server.
//
// The screen "polls": every 2 seconds it asks the server "anything new?".
// The server answers either with the whole state of the race, or with
// {"inalterado": true} when its last calculation is still the one the
// screen has. Each calculation is named by a "versao": a random token, not
// a number. Two tokens are either the same or not; neither is "newer".
//
// Each test says: SITUATION (what is going on), WHAT WENT WRONG (when the
// test guards against a mistake that really happened) and EXPECTED.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  aplicarResposta, avisoDeConexao, chaveAoVivo, chaveDoDetalhe, criarOrdemDePedidos, proximoAtrasoMs,
  versaoParaPedir,
} from '../../default/js/contagem/revisao-conexao.js';

test('while everything works the screen asks every 2 seconds', () => {
  assert.equal(proximoAtrasoMs(0), 2000);
});

test('each failure in a row doubles the wait', () => {
  // The server is down. Asking again every 2 seconds from every open
  // screen would only make it harder for it to come back.
  assert.equal(proximoAtrasoMs(1), 4000);
  assert.equal(proximoAtrasoMs(2), 8000);
  assert.equal(proximoAtrasoMs(3), 16000);
});

test('the wait never grows beyond 30 seconds', () => {
  // A reviewer must not sit minutes in front of a stale screen after the
  // network is back.
  assert.equal(proximoAtrasoMs(4), 30000);
  assert.equal(proximoAtrasoMs(50), 30000);
  assert.equal(proximoAtrasoMs(100000), 30000);
});

test('one successful answer puts the wait back to 2 seconds', () => {
  // The screen resets its failure count to 0 on success; this is the wait
  // that follows.
  assert.equal(proximoAtrasoMs(0), 2000);
});

test('there is no banner while the connection works', () => {
  assert.equal(avisoDeConexao(0), null);
});

test('the first failed poll already shows "sem conexão"', () => {
  // The reviewer has to know at once: what is on screen may be old, and
  // decisions cannot be sent.
  const aviso = avisoDeConexao(1);
  assert.match(aviso, /Sem conexão/);
  assert.match(aviso, /Nova tentativa em 4 s/);
});

test('an expired login is told apart from a network problem', () => {
  // Retrying will never fix an expired session; the reviewer has to log in.
  assert.match(avisoDeConexao(3, true), /sessão expirou/);
});

test('the first poll asks for everything', () => {
  assert.equal(versaoParaPedir(null), null);
});

test('later polls send the version the screen has, so the server can answer "inalterado"', () => {
  assert.equal(versaoParaPedir({ versao: '9f3a01c2' }), '9f3a01c2');
});

// SITUATION: a reviewer's screen shows the race as of version "b7". The
// server's memory of versions is wiped (its cache restarted) and the next
// calculation is named "03".
//
// WHAT WENT WRONG: versions used to be numbers that only went up, and the
// screen threw away any state with a lower number as "older". After the
// restart the numbers began again from 1, every new state looked older than
// what was on screen, and every open review screen froze without any
// message, in the middle of a race.
//
// EXPECTED: answers are put in order by a counter the SCREEN keeps (one
// number per request, in the order they were sent). The state that answers
// the newest request is applied, whatever its version looks like.
test('a state is applied when it answers a newer request, whatever its version looks like', () => {
  const ordem = criarOrdemDePedidos();

  const primeiroPedido = ordem.novo();
  assert.equal(ordem.aceitar(primeiroPedido), true, 'the state "b7" is shown');

  const pedidoDepoisDoReinicio = ordem.novo();
  assert.equal(ordem.aceitar(pedidoDepoisDoReinicio), true, 'the state "03" replaces it, although "03" < "b7"');
});

// SITUATION: the screen sends a poll, which gets the next number. Before
// its answer is back, the reviewer decides something. The decision's
// answer arrives first and brings the state with the decision in it; an
// answer to a decision takes its number when it ARRIVES, so it has the
// higher one. Then the slow poll's answer arrives, with the state from
// BEFORE the decision.
//
// EXPECTED: the poll's answer is dropped. Applying it would put the flag
// the reviewer just resolved back on the screen.
test('an answer that arrives late does not put the screen back in the past', () => {
  const ordem = criarOrdemDePedidos();
  const poll = ordem.novo();
  const decisao = ordem.novo();

  assert.equal(ordem.aceitar(decisao), true, 'the answer to the decision is applied');
  assert.equal(ordem.aceitar(poll), false, 'the older poll is dropped');
  assert.equal(ordem.aceitar(decisao), false, 'and no answer is ever applied twice');
});

test('a full answer replaces the state', () => {
  const novo = { versao: 38, alertas_abertos: [] };
  assert.equal(aplicarResposta({ versao: 37 }, novo), novo);
});

test('an "inalterado" answer keeps the state but updates who is looking at each flag', () => {
  // Nothing was stored, but "maria" has just opened flag "solitaria:12".
  const estado = {
    versao: 37,
    servidor_agora_ms: 1000,
    alertas_abertos: [{ id: 'solitaria:12', olhando: [] }, { id: 'repetida:30', olhando: ['pedro'] }],
    alertas_resolvidos: [],
  };
  const resposta = { inalterado: true, versao: 37, servidor_agora_ms: 3000, olhando: { 'solitaria:12': ['maria'] } };

  const depois = aplicarResposta(estado, resposta, 'joao');

  assert.equal(depois.versao, 37);
  assert.equal(depois.servidor_agora_ms, 3000);
  assert.deepEqual(depois.alertas_abertos[0].olhando, ['maria']);
  // "pedro" closed the other flag: he is no longer listed there.
  assert.deepEqual(depois.alertas_abertos[1].olhando, []);
});

test('an "inalterado" answer brings whether changes are waiting for a calculation', () => {
  const estado = { versao: 1, mudancas_pendentes: false, alertas_abertos: [], alertas_resolvidos: [] };
  assert.equal(aplicarResposta(estado, { inalterado: true, versao: 1, mudancas_pendentes: true, olhando: {} }, 'joao').mudancas_pendentes, true);
});

test('a reviewer is not told that they themselves are looking at a flag', () => {
  const estado = { versao: 1, alertas_abertos: [{ id: 'a', olhando: [] }], alertas_resolvidos: [] };
  const resposta = { inalterado: true, versao: 1, olhando: { a: ['joao', 'maria'] } };

  const depois = aplicarResposta(estado, resposta, 'joao');

  assert.deepEqual(depois.alertas_abertos[0].olhando, ['maria']);
});

test('an "inalterado" answer does not modify the previous state object', () => {
  const estado = { versao: 1, alertas_abertos: [{ id: 'a', olhando: [] }], alertas_resolvidos: [] };
  aplicarResposta(estado, { inalterado: true, versao: 1, olhando: { a: ['maria'] } }, 'joao');
  assert.deepEqual(estado.alertas_abertos[0].olhando, []);
});

// SITUATION: nothing new was typed for a while, so the server keeps
// answering "inalterado". Meanwhile car 12 has gone a long time without
// passing, Contagem 2's phone has lost its connection, and somebody
// switched publishing off.
//
// EXPECTED: an "inalterado" answer still carries those things, and they
// reach the screen: the car becomes "atrasado", the source shows as offline
// with its last contact, the phone is listed as "sem contato", and the
// "publicação desligada" warning appears. Everything that only a new
// calculation can change (the laps, the flags) stays as it was.
test('an "inalterado" answer still updates what changes with time', () => {
  const estado = {
    versao: 'b7',
    corrida: { estado: 'correndo', largada_ms: 1000 },
    carros: [{ carro: 12, voltas: 9, status: 'correndo', status_desde_ms: null }, { carro: 41, voltas: 8, status: 'correndo', status_desde_ms: null }],
    fontes: [{ id: 2, nome: 'Contagem 2', stats: { entradas: 50, offline: false, visto_em_ms: 5000 } }],
    dispositivos: [{ id: 7, fonte_id: 2, situacao: 'ativo' }],
    alertas_abertos: [],
    alertas_resolvidos: [],
    publicacao_desligada: false,
  };
  const resposta = {
    inalterado: true,
    versao: 'b7',
    servidor_agora_ms: 400000,
    corrida: { estado: 'correndo', largada_ms: 1000, decorrido_ms: 399000 },
    status_dos_carros: { 12: { status: 'atrasado', status_desde_ms: 300000 }, 41: { status: 'correndo', status_desde_ms: null } },
    contato_das_fontes: { 2: { visto_em_ms: 5000, offline: true } },
    dispositivos: [{ id: 7, fonte_id: 2, situacao: 'sem_contato' }],
    olhando: {},
    publicacao_desligada: true,
  };

  const depois = aplicarResposta(estado, resposta, 'joao');

  assert.equal(depois.carros[0].status, 'atrasado');
  assert.equal(depois.carros[0].status_desde_ms, 300000);
  assert.equal(depois.carros[0].voltas, 9, 'the lap count is not touched');
  assert.equal(depois.carros[1].status, 'correndo');
  assert.equal(depois.fontes[0].stats.offline, true);
  assert.equal(depois.fontes[0].stats.entradas, 50, 'the statistics of the last calculation are kept');
  assert.equal(depois.dispositivos[0].situacao, 'sem_contato');
  assert.equal(depois.publicacao_desligada, true);
  assert.equal(depois.corrida.decorrido_ms, 399000);
});

// SITUATION: the lists on screen (cars, sources) are redrawn only when
// something they show has changed, so that a scroll position or a click is
// not lost every two seconds.
//
// EXPECTED: a car changing status, or a phone going quiet, counts as a
// change even though the version is the same; an identical state does not.
test('the screen notices a change that came without a new version', () => {
  const antes = { versao: 'b7', carros: [{ carro: 12, status: 'correndo' }], dispositivos: [{ id: 7, situacao: 'ativo' }] };
  const igual = { versao: 'b7', carros: [{ carro: 12, status: 'correndo' }], dispositivos: [{ id: 7, situacao: 'ativo' }] };
  const carroAtrasado = { ...antes, carros: [{ carro: 12, status: 'atrasado' }] };
  const telefoneMudo = { ...antes, dispositivos: [{ id: 7, situacao: 'silencioso' }] };

  assert.equal(chaveAoVivo(antes), chaveAoVivo(igual));
  assert.notEqual(chaveAoVivo(antes), chaveAoVivo(carroAtrasado));
  assert.notEqual(chaveAoVivo(antes), chaveAoVivo(telefoneMudo));
});

// SITUATION: the reviewer has a flag about car 41 open and is reading its
// timeline, scrolled to the side. Elsewhere on the track car 7 completes a
// lap; the server recalculates and sends a state with a new version.
//
// WHAT WENT WRONG: the detail panel was rebuilt for every new version. The
// timeline jumped back, a chip being clicked was replaced under the mouse,
// and this happened every few seconds for as long as the race ran.
//
// EXPECTED: what decides whether the panel is rebuilt does not contain the
// version. It changes when the flag's signature changes (its entries, their
// groups, the decisions about them), when somebody else starts looking at
// it, or when the reviewer does something in the panel.
test('the detail panel is not rebuilt for a new calculation that left the selected flag as it was', () => {
  const alerta = { id: 'solitaria:9', assinatura: 'abc', olhando: [], motivo: 'x', sugestao: null };
  const visao = {
    selecao: { tipo: 'alerta', id: 'solitaria:9' }, alerta, carro: null, janelaCarregada: true, janelaAssinatura: 'abc',
    desatualizada: false, erroDaJanela: false, entradaSelecionadaId: null, modoManual: false, ocupado: false, semConexao: false,
    largadaMs: 1000,
  };

  // A new calculation: another object for the same flag, nothing else.
  assert.equal(chaveDoDetalhe({ ...visao, alerta: { ...alerta } }), chaveDoDetalhe(visao));

  assert.notEqual(chaveDoDetalhe({ ...visao, alerta: { ...alerta, assinatura: 'zzz' } }), chaveDoDetalhe(visao), 'a late entry joined');
  assert.notEqual(chaveDoDetalhe({ ...visao, alerta: { ...alerta, olhando: ['maria'] } }), chaveDoDetalhe(visao), 'somebody is looking');
  assert.notEqual(chaveDoDetalhe({ ...visao, entradaSelecionadaId: 5 }), chaveDoDetalhe(visao), 'the reviewer clicked a chip');
});

test('a car\'s panel is rebuilt when that car\'s own numbers change, not when another car\'s do', () => {
  const carro = { carro: 41, voltas: 12, passagens: 13, status: 'correndo', ultima_volta_ms: 200000, melhor_volta_ms: 190000 };
  const visao = { selecao: { tipo: 'carro', numero: 41 }, carro, janelaCarregada: true, desatualizada: false };

  assert.equal(chaveDoDetalhe({ ...visao, carro: { ...carro } }), chaveDoDetalhe(visao));
  assert.notEqual(chaveDoDetalhe({ ...visao, carro: { ...carro, voltas: 13 } }), chaveDoDetalhe(visao));
  assert.notEqual(chaveDoDetalhe({ ...visao, carro: { ...carro, status: 'abastecendo' } }), chaveDoDetalhe(visao));
});

test('switching the simulation setting on reaches a screen that is already open', () => {
  // The setting travels with every answer, also the short "inalterado" one,
  // so the "SIMULAÇÃO" banner appears without a reload.
  const estado = { versao: 'aaa', simulacao: false, alertas_abertos: [], alertas_resolvidos: [] };
  const depois = aplicarResposta(estado, { inalterado: true, versao: 'aaa', simulacao: true });
  assert.equal(depois.simulacao, true);
  assert.notEqual(chaveAoVivo(depois), chaveAoVivo(estado));
});
