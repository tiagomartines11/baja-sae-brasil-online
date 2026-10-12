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
  acoesDaEntrada, alvoDaAnulacao, comMotivo, decisaoAceitar, decisaoAdicionar, decisaoAnular, decisaoDesfazer,
  decisaoExcluirFonte, decisaoReatribuir, descreverDecisao, descreverSugestao, exclusoesAbertas,
  mensagemDeConflito, pedidoParaAlerta, pedidoParaEntrada, pedidoSemAlvo, podeDecidirSobreAlerta, problemaAoAdicionar,
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
const ESTADO = { versao: '9f3a01c2', ultima_decisao_id: 9 };

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

// What the K button does is tested in revisao-confirmar.test.js.

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
  const pedido = pedidoParaAlerta(decisaoAceitar(TROCA_PROVAVEL), TROCA_PROVAVEL, ESTADO, 'abc123');

  assert.deepEqual(pedido, {
    decisao: { tipo: 'reatribuir', passagem_id: 103, equipe_numero: 41 },
    passagem_ids: [101, 102, 103],
    assinatura: 'abc123',
    ultima_decisao_id: 9,
  });
});

// SITUATION: a reviewer opens a flag. The screen loads the flag's timeline
// once; at that moment the flag's fingerprint ("assinatura") is "abc123".
// The flag LIST, however, is refreshed every two seconds. A late upload
// then adds an entry to the flag: in the list its fingerprint becomes
// "zzz999", while the timeline on screen still shows the old picture.
// The reviewer, looking at that old picture, presses "A".
//
// WHAT WENT WRONG: the request used to carry the fingerprint from the
// refreshed list ("zzz999"). That is by definition what the server has, so
// the check "is this still what the reviewer saw?" always passed, and the
// decision was applied to entries the reviewer had never seen.
//
// EXPECTED: the request carries the fingerprint the VISIBLE timeline was
// loaded with ("abc123"). The server then sees that it is out of date and
// refuses. And while the timeline is behind, the buttons are off anyway.
test('a decision on a flag carries the fingerprint of the timeline on screen, not of the refreshed list', () => {
  const alertaNaListaAtualizada = { ...TROCA_PROVAVEL, assinatura: 'zzz999' };
  const assinaturaDaJanelaNaTela = 'abc123';

  const pedido = pedidoParaAlerta(decisaoAceitar(alertaNaListaAtualizada), alertaNaListaAtualizada, ESTADO, assinaturaDaJanelaNaTela);

  assert.equal(pedido.assinatura, 'abc123');
});

test('A, K and V are off while the timeline is loading or behind the flag list', () => {
  assert.equal(podeDecidirSobreAlerta({ janelaCarregada: false, desatualizada: false }), false, 'still loading');
  assert.equal(podeDecidirSobreAlerta({ janelaCarregada: true, desatualizada: true }), false, 'new data arrived');
  assert.equal(podeDecidirSobreAlerta({ janelaCarregada: true, desatualizada: false }), true);
});

// SITUATION: a flag is about entries 101, 102 and 103. On its timeline the
// reviewer also sees entry 555, of another car, and clicks it; then
// presses "V" (void).
//
// WHAT WENT WRONG: the request said "I was looking at 101, 102, 103" with
// their fingerprint, and "void 555". The server only checks the entries
// listed, so it voided 555 without knowing whether 555 was still as the
// reviewer saw it.
//
// EXPECTED: such a request is not built as a decision on the flag at all
// (null). The screen then sends it as a decision on that one chip, which
// carries 555's own fingerprint. The server also refuses the old shape.
test('a decision about an entry that is not one of the flag\'s is not sent as a decision on the flag', () => {
  assert.equal(pedidoParaAlerta(decisaoAnular(555), TROCA_PROVAVEL, ESTADO, 'abc123'), null);
  // An entry of the flag is fine.
  assert.notEqual(pedidoParaAlerta(decisaoAnular(102), TROCA_PROVAVEL, ESTADO, 'abc123'), null);
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
  assert.equal(pedido.ultima_decisao_id, 9);
});

// SITUATION: a reviewer adds a pass that nobody recorded. The form asks
// for the "tempo de prova": time since the start, such as 1:02:05.
//
// WHAT WENT WRONG: (1) the form could be used before the race had a start,
// when there is no time of race at all; (2) a reviewer typed the time of
// day, "14:03:00", into it. That was read as 14 hours of race, and the car
// got a lap dated hours in the future.
//
// EXPECTED: both are refused on the screen with a sentence that says what
// to type. (The server refuses them too; see ReviewDecisionRulesTest.)
test('a pass cannot be added before the start or at a time that has not come yet', () => {
  const largada = 1_760_000_000_000;
  const agora = largada + 30 * 60 * 1000; // half an hour into the race
  const corrida = { largada_ms: largada };

  assert.match(problemaAoAdicionar(largada + 60_000, { largada_ms: null }, agora), /ainda não tem largada/);
  assert.match(problemaAoAdicionar(largada + 14 * 3600 * 1000, corrida, agora), /não a hora do relógio/);
  assert.match(problemaAoAdicionar(null, corrida, agora), /Tempo inválido/);
  assert.equal(problemaAoAdicionar(largada + 10 * 60 * 1000, corrida, agora), null, 'ten minutes in is fine');
});

// SITUATION: the reviewer types why they are deciding ("carro quebrou na
// curva 3") in the optional field next to the buttons.
//
// EXPECTED: the reason travels with the decision (the server stores it and
// the history shows it). An empty field adds nothing.
test('the optional reason travels with the decision', () => {
  assert.deepEqual(comMotivo(decisaoAnular(5), '  carro quebrou na curva 3 '), { tipo: 'anular', passagem_id: 5, motivo: 'carro quebrou na curva 3' });
  assert.deepEqual(comMotivo(decisaoAnular(5), '   '), { tipo: 'anular', passagem_id: 5 });
  assert.deepEqual(comMotivo(decisaoAnular(5), null), { tipo: 'anular', passagem_id: 5 });
});

// SITUATION: Contagem 2's phone fell in the mud at 0:40:00 and will not
// come back. The reviewer excludes that source from then on, with no end.
//
// EXPECTED: the decision is sent with no end time, the history words it as
// "até segunda ordem", and it is listed among the exclusions the screen
// must keep in sight (a period that already ended is not).
test('a source can be excluded with no end, and that exclusion stays in sight', () => {
  assert.deepEqual(decisaoExcluirFonte(2, 5000), { tipo: 'excluir_fonte', fonte_id: 2, ts_ms: 5000, ts_fim_ms: null });
  assert.equal(
    descreverDecisao({ tipo: 'excluir_fonte', fonte_id: 2, ts_ms: 5000, ts_fim_ms: null }, () => 'Contagem 2'),
    'Excluiu a fonte Contagem 2 até segunda ordem',
  );

  const estado = {
    historico_em_vigor: [
      { id: 4, tipo: 'excluir_fonte', fonte_id: 2, ts_ms: 5000, ts_fim_ms: null },
      { id: 3, tipo: 'excluir_fonte', fonte_id: 1, ts_ms: 1000, ts_fim_ms: 2000 },
      { id: 2, tipo: 'adicionar', equipe_numero: 41, ts_ms: 3000 },
    ],
  };
  assert.deepEqual(exclusoesAbertas(estado).map((decisao) => decisao.id), [4]);
});

test('each kind of decision carries the fields the server expects', () => {
  assert.deepEqual(decisaoReatribuir(5, 41), { tipo: 'reatribuir', passagem_id: 5, equipe_numero: 41 });
  assert.deepEqual(decisaoAdicionar(41, 1000), { tipo: 'adicionar', equipe_numero: 41, ts_ms: 1000 });
  assert.deepEqual(decisaoExcluirFonte(3, 1000, 2000), { tipo: 'excluir_fonte', fonte_id: 3, ts_ms: 1000, ts_fim_ms: 2000 });
  assert.deepEqual(decisaoDesfazer(8), { tipo: 'desfazer', desfaz_decisao_id: 8 });
});

// ---- what can be done with one chip ----------------------------------------

test('an entry that is part of a pass can be voided, reassigned or confirmed', () => {
  const acoes = acoesDaEntrada({ id: 10, decisoes: [], grupo: { contado: false, passagem_ids: [10] } });
  assert.deepEqual(acoes.map((opcao) => opcao.acao), ['anular', 'reatribuir', 'confirmar']);
});

// An entry in no pass at all (its source was excluded at that moment, or it
// is from before the start) and not voided as a repeat: confirming it would
// change nothing, so the button is not there to be misread.
test('an entry that is in no pass has nothing to confirm', () => {
  const acoes = acoesDaEntrada({ id: 10, decisoes: [], grupo: null, fora_de_uso: true });
  assert.deepEqual(acoes.map((opcao) => opcao.acao), ['anular', 'reatribuir']);
});

test('an entry whose pass has other entries can also have the whole pass discarded', () => {
  // Car 41 crossed once; all three counters typed it (entries 10, 11, 12).
  const acoes = acoesDaEntrada({ id: 10, decisoes: [], grupo: { contado: true, passagem_ids: [10, 11, 12] } });
  assert.deepEqual(acoes[acoes.length - 1], { acao: 'descartar_passagem', registros: 3 });
  // Alone in its pass there is nothing more to discard than "anular" does.
  const sozinha = acoesDaEntrada({ id: 10, decisoes: [], grupo: { contado: false, passagem_ids: [10] } });
  assert.equal(sozinha.some((opcao) => opcao.acao === 'descartar_passagem'), false);
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
    descreverDecisao({ tipo: 'excluir_fonte', fonte_id: 2, ts_ms: 1000, ts_fim_ms: 2000 }, (id) => (id === 2 ? 'Contagem 2' : null)),
    'Excluiu a fonte Contagem 2 por um período',
  );
});
