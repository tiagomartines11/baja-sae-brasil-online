// Tests for how a reviewer's click becomes a request to the server.
//
// Background: a "decisão" is a correction made by a person. The server
// stores it as a new record; it never edits or deletes what the counters
// typed. Every request also says what the reviewer was looking at
// ("passagem_ids") and a fingerprint of it ("assinatura"). If any of that
// changed on the server before the click arrived, the server refuses with
// HTTP 409 so that nobody decides about something they did not see.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  acoesDaEntrada, alvoDaAnulacao, decisaoAceitar, decisaoAdicionar, decisaoAnular, decisaoDesfazer,
  decisaoExcluirFonte, decisaoManterAmbas, decisaoReatribuir, descreverDecisao, descreverSugestao,
  mensagemDeConflito, pedidoParaAlerta, pedidoParaEntrada, pedidoSemAlvo,
} from '../../default/js/contagem/revisao-decisoes.js';

// The situation most tests use: counters 1 and 2 typed car 41, counter 3
// typed 14 at the same moment. The engine suspects counter 3 swapped the
// digits and suggests reading entry 103 as car 41.
const TROCA_PROVAVEL = {
  id: 'troca_provavel:101',
  tipo: 'troca_provavel',
  carros: [14, 41],
  passagem_ids: [101, 102, 103],
  sugestao: { tipo: 'reatribuir', passagem_id: 103, equipe_numero: 41 },
  assinatura: 'abc123',
};

// What the screen knew when the reviewer clicked.
const ESTADO = { versao: 37, ultima_decisao_id: 9 };

test('"aceitar sugestão" (A) stores exactly what the engine suggested', () => {
  assert.deepEqual(decisaoAceitar(TROCA_PROVAVEL), { tipo: 'reatribuir', passagem_id: 103, equipe_numero: 41 });
});

test('"aceitar sugestão" does nothing on a flag that has no suggestion', () => {
  // Null is what keeps the button disabled and the A key without effect.
  assert.equal(decisaoAceitar({ ...TROCA_PROVAVEL, sugestao: null }), null);
});

test('accepting a suggestion does not hand out the flag\'s own object', () => {
  // If the request shared the object with the screen's state, changing one
  // would silently change the other.
  const decisao = decisaoAceitar(TROCA_PROVAVEL);
  decisao.equipe_numero = 99;
  assert.equal(TROCA_PROVAVEL.sugestao.equipe_numero, 41);
});

test('"manter ambas" (K) confirms the first entry of the flag', () => {
  // The reviewer watched the video: both cars really did cross together.
  // By convention that is a "confirmar" on the flag's first entry.
  assert.deepEqual(decisaoManterAmbas(TROCA_PROVAVEL), { tipo: 'confirmar', passagem_id: 101 });
});

test('"manter ambas" is not available on a flag that is about no entry', () => {
  assert.equal(decisaoManterAmbas({ passagem_ids: [] }), null);
});

test('"anular" (V) acts on the chip the reviewer clicked', () => {
  assert.equal(alvoDaAnulacao(TROCA_PROVAVEL, 102), 102);
});

test('"anular" with nothing clicked voids what the engine suggests voiding', () => {
  // Counter 1 tapped car 30 twice within a second; the engine suggests
  // voiding the second tap (entry 201).
  const repetida = { passagem_ids: [200, 201], sugestao: { tipo: 'anular', passagem_id: 201 } };
  assert.equal(alvoDaAnulacao(repetida, null), 201);
});

test('"anular" on a flag with a single entry needs no click', () => {
  // Only counter 2 typed car 77 at that moment: there is one candidate.
  const solitaria = { passagem_ids: [300], sugestao: null };
  assert.equal(alvoDaAnulacao(solitaria, null), 300);
});

test('"anular" refuses to guess among several entries', () => {
  // Three entries, a suggestion that is not a void, nothing clicked.
  // Null makes the screen ask "click the entry first".
  assert.equal(alvoDaAnulacao(TROCA_PROVAVEL, null), null);
});

test('a decision on a flag says the reviewer was looking at all of its entries', () => {
  const pedido = pedidoParaAlerta(decisaoAceitar(TROCA_PROVAVEL), TROCA_PROVAVEL, ESTADO);

  assert.deepEqual(pedido, {
    decisao: { tipo: 'reatribuir', passagem_id: 103, equipe_numero: 41 },
    versao: 37,
    passagem_ids: [101, 102, 103],
    assinatura: 'abc123',
    ultima_decisao_id: 9,
  });
});

test('a decision on one chip of the timeline says only that entry was being looked at', () => {
  const entrada = { id: 555, carro: 12, assinatura: 'def456' };
  const pedido = pedidoParaEntrada(decisaoAnular(555), entrada, ESTADO);

  assert.deepEqual(pedido.passagem_ids, [555]);
  assert.equal(pedido.assinatura, 'def456');
  assert.deepEqual(pedido.decisao, { tipo: 'anular', passagem_id: 555 });
});

test('adding a pass, excluding a source and undoing look at no entry', () => {
  const pedido = pedidoSemAlvo(decisaoAdicionar(41, 1_760_000_000_000), ESTADO);

  assert.deepEqual(pedido.passagem_ids, []);
  assert.equal(pedido.assinatura, null);
  assert.equal(pedido.versao, 37);
});

test('each kind of decision carries the fields the server expects', () => {
  assert.deepEqual(decisaoReatribuir(5, 41), { tipo: 'reatribuir', passagem_id: 5, equipe_numero: 41 });
  assert.deepEqual(decisaoAdicionar(41, 1000), { tipo: 'adicionar', equipe_numero: 41, ts_ms: 1000 });
  assert.deepEqual(decisaoExcluirFonte(3, 1000, 2000), { tipo: 'excluir_fonte', fonte_id: 3, ts_ms: 1000, ts_fim_ms: 2000 });
  assert.deepEqual(decisaoDesfazer(8), { tipo: 'desfazer', desfaz_decisao_id: 8 });
});

// ---- what can be done with one chip ----------------------------------------

test('a normal entry can be voided, reassigned or confirmed', () => {
  const acoes = acoesDaEntrada({ id: 10, decisoes: [] });
  assert.deepEqual(acoes.map((opcao) => opcao.acao), ['anular', 'reatribuir', 'confirmar']);
});

test('a voided entry can only be restored, by undoing the void', () => {
  // Decision 4 voided entry 10. "Restaurar" must undo decision 4.
  const acoes = acoesDaEntrada({ id: 10, decisoes: [{ id: 4, tipo: 'anular' }] });
  assert.deepEqual(acoes, [{ acao: 'restaurar', decisaoId: 4 }]);
});

test('a reassigned entry can be put back to the car that was typed', () => {
  const acoes = acoesDaEntrada({ id: 10, decisoes: [{ id: 6, tipo: 'reatribuir' }] });
  assert.ok(acoes.some((opcao) => opcao.acao === 'desfazer_troca' && opcao.decisaoId === 6));
});

test('an entry already confirmed is not offered "confirmar" again', () => {
  const acoes = acoesDaEntrada({ id: 10, decisoes: [{ id: 7, tipo: 'confirmar' }] });
  assert.equal(acoes.some((opcao) => opcao.acao === 'confirmar'), false);
});

test('a pass added by a reviewer is removed by undoing the decision that added it', () => {
  // Added passes are not real entries. The server gives them a negative id:
  // minus the id of the "adicionar" decision (here decision 12).
  const acoes = acoesDaEntrada({ id: -12, adicionada_por_revisor: true, decisoes: [] });
  assert.deepEqual(acoes, [{ acao: 'remover', decisaoId: 12 }]);
});

// ---- sentences ---------------------------------------------------------------

test('when another reviewer decided first, the message names them', () => {
  // Two reviewers had the same flag open. "maria" clicked first; this is
  // what the second reviewer sees.
  const mensagem = mensagemDeConflito({ erro: 'conflito', motivo: 'ja_decidido', por: 'maria' });
  assert.match(mensagem, /Já decidido por maria/);
});

test('when the entries changed without a decision, the message says the data changed', () => {
  // Example: counter 3 was offline and its entries arrived just now,
  // joining the group the reviewer was looking at.
  const mensagem = mensagemDeConflito({ erro: 'conflito', motivo: 'dados_mudaram', por: null });
  assert.match(mensagem, /Os dados mudaram/);
});

test('a conflict with no name still makes a sentence', () => {
  assert.match(mensagemDeConflito({ motivo: 'ja_decidido', por: null }), /Já decidido por outra pessoa/);
});

test('the suggestion is written as one sentence', () => {
  assert.equal(descreverSugestao(TROCA_PROVAVEL), 'Sugestão: trocar a passagem para o carro 41.');
  assert.equal(descreverSugestao({ sugestao: { tipo: 'anular', passagem_id: 1 } }), 'Sugestão: anular a passagem.');
  assert.match(descreverSugestao({ sugestao: null }), /Sem sugestão automática/);
});

test('the history describes each decision in words', () => {
  assert.equal(descreverDecisao({ tipo: 'anular', passagem_id: 5 }), 'Anulou a passagem #5');
  assert.equal(descreverDecisao({ tipo: 'reatribuir', passagem_id: 5, equipe_numero: 41 }), 'Trocou a passagem #5 para o carro 41');
  assert.equal(descreverDecisao({ tipo: 'desfazer', desfaz_decisao_id: 3 }), 'Desfez a decisão #3');
  assert.equal(
    descreverDecisao({ tipo: 'excluir_fonte', fonte_id: 2 }, (id) => (id === 2 ? 'Contagem 2' : null)),
    'Excluiu a fonte Contagem 2 por um período',
  );
});
