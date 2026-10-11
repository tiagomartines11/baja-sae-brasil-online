// Tests for the K button of the review screen, for "descartar esta
// passagem" and for closing an open-ended exclusion.
//
// Background for someone new to this. Three counters type the number of
// every car that crosses the line. A pass counts when two of them agree.
// Where they do not, the reviewer gets a "flag" and decides. One possible
// decision is "confirmar": the reviewer vouches for an entry.
//
// What "confirmar" DOES depends on the situation:
//   - if the pass already counts, it only tells the system to stop flagging;
//   - if the pass does not count yet (only one counter typed it), it makes
//     it count: the car gets one more pass, which is one more lap.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  acaoDeConfirmar, acoesDaEntrada, decisaoConfirmarAlerta, decisaoDescartarPassagem, decisaoEncerrarExclusao,
  pedidoParaAlerta, pedidoSemAlvo, perguntaSobreVoltaCurta, problemaAoEncerrarExclusao, voltaCurtaAoContar,
  voltaImpossivelmenteCurta,
} from '../../default/js/contagem/revisao-decisoes.js';

// The settings the server sends with the state.
const CONFIGURACOES = { janela_ms: 20000, fracao_volta_minima: 0.5 };

// Only counter 2 typed car 77 at that moment (entry 300).
const SOLITARIA = {
  id: 'solitaria:300', tipo: 'solitaria', carros: [77], passagem_ids: [300], sugestao: null,
  efeito_de_confirmar: 'mais_uma_passagem', volta_resultante_ms: 180000,
};

// SITUATION: counter 1 typed car 30 twice, 12 seconds apart (entries 200
// and 201). The engine flags it as "repetida" and suggests voiding the
// second. The reviewer looks, decides nothing is wrong and presses K, which
// was labelled "Manter ambas" and documented as "keep, it is fine as it is".
//
// WHAT WENT WRONG: K sent "confirmar" on an entry of the flag. For a
// repeat that makes the second entry a pass of its own: car 30 got one more
// lap, 12 seconds after the previous one, on one counter's word. The same
// happened on a lone entry and on a probable swap.
//
// EXPECTED: a repeat has no K at all. Its choices are "anular a segunda"
// (A or V) and "era outro carro" (move the entry to another car).
test('a repeat has no K: "keep both" would count the car twice', () => {
  const repetida = {
    tipo: 'repetida', carros: [30], passagem_ids: [201, 200], sugestao: { tipo: 'anular', passagem_id: 201 },
    efeito_de_confirmar: 'mais_uma_passagem',
  };

  assert.equal(acaoDeConfirmar(repetida), null);
  assert.equal(decisaoConfirmarAlerta(repetida), null, 'the K key sends nothing either');
});

// EXPECTED: where K adds a pass, the button says so in as many words.
test('on a lone entry and on a probable swap, K is labelled as what it does: one more lap', () => {
  assert.deepEqual(acaoDeConfirmar(SOLITARIA), { rotulo: 'Contar esta passagem (+1 volta)', maisUmaPassagem: true });

  const troca = { tipo: 'troca_provavel', carros: [14, 41], passagem_ids: [103], efeito_de_confirmar: 'mais_uma_passagem' };
  assert.equal(acaoDeConfirmar(troca).rotulo, 'Contar esta passagem (+1 volta)');
  assert.deepEqual(decisaoConfirmarAlerta(troca), { tipo: 'confirmar', passagem_id: 103 });
});

// EXPECTED: on the flags where the pass already counts, K only silences
// the flag and is labelled so. The count does not move.
test('where the pass already counts, K is "está correto, parar de avisar"', () => {
  for (const tipo of ['no_box', 'no_abastecimento', 'volta_curta', 'primeira_passagem_tardia', 'no_encerramento', 'tempo_estimado']) {
    const alerta = { tipo, carros: [5], passagem_ids: [1, 2], efeito_de_confirmar: 'nenhum' };
    assert.deepEqual(acaoDeConfirmar(alerta), { rotulo: 'Está correto, parar de avisar', maisUmaPassagem: false }, tipo);
  }
});

// SITUATION: car 9 is at the event but was not classified for this race
// (it failed a safety check). A counter types it anyway. The pass is stored
// and flagged, and does not count.
//
// EXPECTED: K on that flag is "contar mesmo assim": the reviewer overrules
// the classification for that one pass.
test('on a pass of an unclassified car, K is "contar mesmo assim"', () => {
  const alerta = { tipo: 'carro_nao_classificado', carros: [9], passagem_ids: [50, 51], efeito_de_confirmar: 'mais_uma_passagem' };
  assert.deepEqual(acaoDeConfirmar(alerta), { rotulo: 'Contar mesmo assim (+1 volta)', maisUmaPassagem: true });
});

test('on a pass typed just before the start, K is "contar como passagem inicial"', () => {
  const alerta = { tipo: 'antes_da_largada', carros: [9], passagem_ids: [50], efeito_de_confirmar: 'mais_uma_passagem' };
  assert.equal(acaoDeConfirmar(alerta).rotulo, 'Contar como passagem inicial');
});

// The server says what confirming does ("efeito_de_confirmar"). A state
// calculated before that existed does not; the type then decides, and it
// must decide on the careful side for the types that add a pass.
test('a flag that does not say what confirming does is judged by its type', () => {
  assert.equal(acaoDeConfirmar({ tipo: 'solitaria', passagem_ids: [1] }).maisUmaPassagem, true);
  assert.equal(acaoDeConfirmar({ tipo: 'troca_provavel', passagem_ids: [1] }).maisUmaPassagem, true);
  assert.equal(acaoDeConfirmar({ tipo: 'no_box', passagem_ids: [1] }).maisUmaPassagem, false);
});

test('K is not offered on a flag that is about no entry', () => {
  assert.equal(acaoDeConfirmar({ tipo: 'divergencia_total', passagem_ids: [] }), null);
});

// SITUATION: car 77 laps in about three minutes. A lone entry for it sits
// 25 seconds after its previous pass. Counting it would create a 25-second
// lap, which no car can do.
//
// EXPECTED: the screen asks before sending. The rule is the engine's own:
// a lap is impossibly short under two matching windows (2 x 20 s), or under
// half the lap expected of that car. A lap of normal length is not asked about.
test('before adding a pass that would make an impossibly short lap, the screen asks', () => {
  const estado = { configuracoes: CONFIGURACOES, carros: [{ carro: 77, volta_esperada_ms: 180000 }] };

  assert.equal(voltaCurtaAoContar({ ...SOLITARIA, volta_resultante_ms: 25000 }, estado), 25000, 'under two matching windows');
  assert.equal(voltaCurtaAoContar({ ...SOLITARIA, volta_resultante_ms: 80000 }, estado), 80000, 'under half the expected lap');
  assert.equal(voltaCurtaAoContar({ ...SOLITARIA, volta_resultante_ms: 170000 }, estado), null, 'a normal lap: no question');
  assert.equal(voltaCurtaAoContar({ ...SOLITARIA, volta_resultante_ms: null }, estado), null, 'the car has no other pass: no lap to judge');

  assert.match(perguntaSobreVoltaCurta(25000), /volta de 0:25, curta demais/);
});

test('K that only silences a flag never asks, however short the lap', () => {
  const voltaCurta = { tipo: 'volta_curta', carros: [77], passagem_ids: [1, 2], efeito_de_confirmar: 'nenhum', volta_resultante_ms: 5000 };
  assert.equal(voltaCurtaAoContar(voltaCurta, { configuracoes: CONFIGURACOES, carros: [] }), null);
});

test('the short-lap rule is the engine\'s: two windows, or the configured fraction of the expected lap', () => {
  assert.equal(voltaImpossivelmenteCurta(39_999, CONFIGURACOES, null), true);
  assert.equal(voltaImpossivelmenteCurta(40_000, CONFIGURACOES, null), false, 'pace unknown: only the two-window rule applies');
  assert.equal(voltaImpossivelmenteCurta(89_999, CONFIGURACOES, 180_000), true);
  assert.equal(voltaImpossivelmenteCurta(90_000, CONFIGURACOES, 180_000), false);
});

// SITUATION: counter 3 double-tapped car 12. The engine voided the second
// tap on its own. The reviewer clicks that struck-out chip; the box used to
// offer "Confirmar (conta como está)".
//
// WHAT WENT WRONG: "as it is" read as "leave it voided". It did the
// opposite: it un-voided the tap, and car 12 got a lap two seconds long.
//
// EXPECTED: the option says it adds a pass (the screen then labels it
// "Contar esta passagem (+1 volta)" and asks first). On an entry whose pass
// already counts, confirming adds nothing and is labelled as such.
test('confirming a chip whose pass does not count is marked as adding a pass', () => {
  const toqueDuplo = { id: 31, decisoes: [], anulada_auto: true, grupo: null };
  assert.deepEqual(acoesDaEntrada(toqueDuplo).find((opcao) => opcao.acao === 'confirmar'), { acao: 'confirmar', maisUmaPassagem: true });

  const pendente = { id: 32, decisoes: [], grupo: { contado: false, passagem_ids: [32] } };
  assert.equal(acoesDaEntrada(pendente).find((opcao) => opcao.acao === 'confirmar').maisUmaPassagem, true);

  const contada = { id: 33, decisoes: [], grupo: { contado: true, passagem_ids: [33, 34] } };
  assert.equal(acoesDaEntrada(contada).find((opcao) => opcao.acao === 'confirmar').maisUmaPassagem, false);
});

// SITUATION: a "volta curta" flag: car 5 has two official passes 30
// seconds apart. The second (entries 60, 61, 62: all three counters typed
// a wrong number together, or a car was pushed back over the line) did not
// happen. Before, the reviewer had to void three entries one by one, and
// between the clicks the count of car 5 was neither one thing nor the other.
//
// EXPECTED: one request, "descartar_passagem", naming an entry of the pass.
// The server voids every entry of that pass in one step.
test('"descartar esta passagem" is one request for the whole pass', () => {
  const voltaCurta = { tipo: 'volta_curta', carros: [5], passagem_ids: [60, 61, 62] };
  const pedido = pedidoParaAlerta(decisaoDescartarPassagem(60), voltaCurta, { ultima_decisao_id: 9 }, 'abc123');

  assert.deepEqual(pedido.decisao, { tipo: 'descartar_passagem', passagem_id: 60 });
  assert.deepEqual(pedido.passagem_ids, [60, 61, 62]);
  assert.equal(pedido.assinatura, 'abc123');
});

// SITUATION: Contagem 2's phone died at 0:40:00 and the reviewer excluded
// the source "até segunda ordem" (decision 4). At 0:55:00 the counter is
// back with another phone.
//
// WHAT WENT WRONG: the only way out was to UNDO the exclusion. That brings
// the source back for the whole race, including the fifteen minutes in
// which it recorded nothing: every pass in that gap became a "miss" of
// Contagem 2 and lone entries of the others were flagged by the dozen.
//
// EXPECTED: "Encerrar exclusão em 0:55:00" sends one request; the server
// replaces the open exclusion with one that ends there. An end before the
// start of the exclusion, or one that has not come yet, is refused on the
// screen with a sentence.
test('an open-ended exclusion can be given an end instead of being undone', () => {
  const largada = 1_760_000_000_000;
  const exclusao = { id: 4, tipo: 'excluir_fonte', fonte_id: 2, ts_ms: largada + 40 * 60_000, ts_fim_ms: null };
  const agora = largada + 60 * 60_000;
  const fim = largada + 55 * 60_000;

  assert.equal(problemaAoEncerrarExclusao(exclusao, fim, agora), null);
  assert.deepEqual(pedidoSemAlvo(decisaoEncerrarExclusao(4, fim), { ultima_decisao_id: 4 }).decisao,
    { tipo: 'encerrar_exclusao', desfaz_decisao_id: 4, ts_fim_ms: fim });

  assert.match(problemaAoEncerrarExclusao(exclusao, largada + 30 * 60_000, agora), /depois do início/);
  assert.match(problemaAoEncerrarExclusao(exclusao, largada + 90 * 60_000, agora), /ainda não chegou/);
  assert.match(problemaAoEncerrarExclusao(exclusao, null, agora), /Tempo inválido/);
});
