// Tests for the decision history sent as a difference.
//
// Background for someone new to this. The review screen's state carries the
// whole decision history, so that the history's filters see every decision.
// The screen downloads the state again whenever it changes (every few
// seconds during a race) and the history changes only when somebody
// decides. So the screen says which decision is the newest it holds, and
// the server sends only the newer ones (StateSnapshot::withHistoryAfter).
// Decisions are never edited, but an undo makes an older row "desfeita",
// and a reassignment changes the car of every row about that entry: those
// travel too, and the screen folds everything in (juntarHistorico). When
// anything does not fit, the screen asks for the whole state again.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  estadoDaResposta, historicoParaPedir, pedidoDeEstado, proximaConsultaEm,
} from '../../default/js/contagem/revisao-conexao.js';
import { juntarHistorico, linhasDoHistorico } from '../../default/js/contagem/revisao-historico.js';

// A history row as DecisionHistory::describe() makes it.
function decisao(campos) {
  return {
    tipo: 'confirmar', passagem_id: null, passagem_alvo_id: null, fonte_id: null, fonte_nome: null, fonte_alvo_id: null,
    fonte_alvo_nome: null, carro_digitado: null, carro: null, passagem_ts_ms: null, equipe_numero: null, ts_ms: null,
    ts_fim_ms: null, desfaz_decisao_id: null, origem: 'revisor', usuario: 'jbresolin', motivo: null, criado_em_ms: 1000,
    ativa: true, pode_desfazer: true,
    ...campos,
  };
}

// Entry #352 of Contagem 1 (car 3) was confirmed (#10); entry #400 of
// Contagem 2 (car 5) was voided (#11); a pass was added for car 7 (#12).
const CONFIRMACAO = decisao({ id: 10, passagem_id: 352, fonte_id: 1, fonte_nome: 'Contagem 1', carro_digitado: 3, carro: 3 });
const ANULACAO = decisao({ id: 11, tipo: 'anular', passagem_id: 400, fonte_id: 2, fonte_nome: 'Contagem 2', carro_digitado: 5, carro: 5 });
const ADICAO = decisao({ id: 12, tipo: 'adicionar', equipe_numero: 7, ts_ms: 5000 });

const ESTADO = {
  versao: 'a1',
  historico_epoca: '0',
  historico: [ADICAO, ANULACAO, CONFIRMACAO],
  historico_em_vigor: [ADICAO],
};

// A full answer of the server whose history is a difference after #12.
function diferenca(campos) {
  return {
    versao: 'b2',
    historico_epoca: '0',
    historico_em_vigor: [ADICAO],
    historico_desde: 12,
    historico_alteradas: [],
    historico_fontes: { 1: 'Contagem 1', 2: 'Contagem 2' },
    historico_quantas: 3,
    historico_mais_antiga_id: 10,
    historico: [],
    ...campos,
  };
}

test('the screen asks for the decisions after the newest it holds, with its epoch', () => {
  assert.deepEqual(historicoParaPedir(ESTADO), { desde: 12, epoca: '0' });
});

test('with no history, or one from a server that sends no epoch, it asks for the whole history', () => {
  assert.equal(historicoParaPedir(null), null);
  assert.equal(historicoParaPedir({ ...ESTADO, historico: [] }), null);
  assert.equal(historicoParaPedir({ historico: ESTADO.historico }), null);
});

test('a whole answer is taken as it is', () => {
  const resposta = { versao: 'b2', historico_epoca: '0', historico: [CONFIRMACAO] };
  assert.equal(juntarHistorico(ESTADO, resposta), resposta);
});

// SITUATION: a reviewer voids entry #500 (decision #13) while the screen
// holds #10 to #12.
// EXPECTED: the new row goes on top, the older ones stay as they were, and
// the merged state is the state the whole answer would have been.
test('the new decisions go on top of the ones the screen holds', () => {
  const nova = decisao({ id: 13, tipo: 'anular', passagem_id: 500, fonte_id: 1, fonte_nome: 'Contagem 1', carro_digitado: 9, carro: 9 });
  const estado = juntarHistorico(ESTADO, diferenca({ historico: [nova], historico_quantas: 4 }));

  assert.deepEqual(estado.historico, [nova, ADICAO, ANULACAO, CONFIRMACAO]);
  assert.equal(estado.versao, 'b2');
  for (const chave of ['historico_desde', 'historico_alteradas', 'historico_fontes', 'historico_quantas', 'historico_mais_antiga_id']) {
    assert.equal(chave in estado, false, `${chave} is not part of the state`);
  }
});

// SITUATION: a reviewer undoes the OLDEST decision, the confirmation #10.
// EXPECTED: #10 now reads "desfeita" and has no "Desfazer" (the 'em vigor'
// badge and the undo guard read these), and the undo's line says what it
// undid.
test('an undo of an old decision makes that row "desfeita"', () => {
  const desfazer = decisao({ id: 13, tipo: 'desfazer', desfaz_decisao_id: 10, fonte_id: 1, fonte_nome: 'Contagem 1', carro_digitado: 3, carro: 3, pode_desfazer: false });
  const estado = juntarHistorico(ESTADO, diferenca({
    historico: [desfazer], historico_quantas: 4, historico_alteradas: [[10, false, false, 3]],
  }));

  const confirmacao = estado.historico.find((linha) => linha.id === 10);
  assert.equal(confirmacao.ativa, false);
  assert.equal(confirmacao.pode_desfazer, false);
  const linhas = linhasDoHistorico(estado);
  assert.equal(linhas.find((linha) => linha.chave === 'decisao:10').desfeita, true);
  assert.equal(linhas.find((linha) => linha.chave === 'decisao:10').emVigor, false);
  assert.match(linhas.find((linha) => linha.chave === 'decisao:13').texto, /#10/);
});

// SITUATION: entry #352 (car 3, confirmed in #10) is moved to car 8 (#13).
// Later that move is undone (#14).
// EXPECTED: after the move, the old confirmation counts for car 8 (the
// "Carro" filter finds it under 8 and 3). After the undo the server lists
// the move, now undone, but not the confirmation, which is as typed again:
// the screen puts it back to car 3 all the same.
test('a reassignment changes the car of older rows, and its undo puts it back', () => {
  const troca = decisao({ id: 13, tipo: 'reatribuir', passagem_id: 352, equipe_numero: 8, fonte_id: 1, fonte_nome: 'Contagem 1', carro_digitado: 3, carro: 8 });
  const depoisDaTroca = juntarHistorico(ESTADO, diferenca({
    historico: [troca], historico_quantas: 4, historico_alteradas: [[10, true, true, 8]],
  }));
  assert.equal(depoisDaTroca.historico.find((linha) => linha.id === 10).carro, 8);
  assert.deepEqual(linhasDoHistorico(depoisDaTroca).find((linha) => linha.chave === 'decisao:10').carros, [3, 8]);

  const desfazer = decisao({ id: 14, tipo: 'desfazer', desfaz_decisao_id: 13, fonte_id: 1, fonte_nome: 'Contagem 1', carro_digitado: 3, carro: 3, pode_desfazer: false });
  const depoisDoDesfazer = juntarHistorico(depoisDaTroca, diferenca({
    historico: [desfazer], historico_desde: 13, historico_quantas: 5, historico_alteradas: [[13, false, false, 3]],
  }));
  const porId = new Map(depoisDoDesfazer.historico.map((linha) => [linha.id, linha]));
  assert.equal(porId.get(10).carro, 3);
  assert.equal(porId.get(10).ativa, true);
  assert.equal(porId.get(13).ativa, false);
  assert.equal(porId.get(13).carro, 3);
  assert.deepEqual([...porId.keys()], [14, 13, 12, 11, 10]);
});

test('an undo row nothing touched keeps "pode_desfazer" false', () => {
  const desfazer = decisao({ id: 12, tipo: 'desfazer', desfaz_decisao_id: 11, pode_desfazer: false });
  const estado = juntarHistorico({ ...ESTADO, historico: [desfazer, { ...ANULACAO, ativa: false, pode_desfazer: false }] }, diferenca({
    historico_quantas: 2, historico_mais_antiga_id: 11, historico_alteradas: [[11, false, false, 5]],
  }));
  assert.equal(estado.historico[0].pode_desfazer, false);
  assert.equal(estado.historico[1].ativa, false);
});

test('a renamed counter reaches the older rows', () => {
  const estado = juntarHistorico(ESTADO, diferenca({ historico_fontes: { 1: 'Contagem 1', 2: 'Portão' } }));
  assert.equal(estado.historico.find((linha) => linha.id === 11).fonte_nome, 'Portão');
});

test('rows that fell off the end of the server\'s list are dropped', () => {
  const estado = juntarHistorico(ESTADO, diferenca({ historico_quantas: 2, historico_mais_antiga_id: 11 }));
  assert.deepEqual(estado.historico.map((linha) => linha.id), [12, 11]);
});

// SITUATION: "Zerar corrida" wiped the rehearsal; the real race's
// decisions may even get the same ids again.
// EXPECTED: an answer of another epoch is never merged: the screen asks
// for everything.
test('after "Zerar corrida" (another epoch) the screen asks for everything', () => {
  assert.equal(juntarHistorico(ESTADO, diferenca({ historico_epoca: '77' })), null);
  assert.equal(juntarHistorico(null, diferenca({})), null, 'no copy to merge into');
});

test('anything that does not add up makes the screen ask for everything', () => {
  // The screen does not hold the decision the difference starts after.
  assert.equal(juntarHistorico(ESTADO, diferenca({ historico_desde: 13 })), null);
  // The server has a decision the screen lacks.
  assert.equal(juntarHistorico(ESTADO, diferenca({ historico_quantas: 4 })), null);
  // A newer row that is not newer.
  assert.equal(juntarHistorico(ESTADO, diferenca({ historico: [decisao({ id: 11 })], historico_quantas: 4 })), null);
});

// What the poll loop (consultar in revisao.js) does with all this.

test('a poll sends the version and the newest decision the screen holds', () => {
  assert.deepEqual(pedidoDeEstado(ESTADO), { versao: 'a1', historico: { desde: 12, epoca: '0' } });
  assert.deepEqual(pedidoDeEstado(null), { versao: null, historico: null }, 'the first poll asks for everything');
});

test('a poll that asks for everything sends neither the version nor the history it holds', () => {
  assert.deepEqual(pedidoDeEstado(ESTADO, true), { versao: null, historico: null });
});

test('a difference that fits is shown, and the polls go on as usual', () => {
  const nova = decisao({ id: 13, tipo: 'adicionar', equipe_numero: 4, ts_ms: 9000 });
  const { estado, pedirTudo } = estadoDaResposta(ESTADO, diferenca({ historico: [nova], historico_quantas: 4 }));
  assert.deepEqual(estado.historico.map((linha) => linha.id), [13, 12, 11, 10]);
  assert.equal(pedirTudo, false);
  assert.deepEqual(proximaConsultaEm(pedirTudo, 0), { atrasoMs: 2000, pedirTudo: false });
});

// SITUATION: "Zerar corrida" happened between two polls; the screen still
// holds the rehearsal's history and gets a difference of another epoch.
// EXPECTED: the answer is not shown at all (the rehearsal's rows would
// stay), and the screen asks again at once, for everything, without
// sending what it holds. That answer is whole and is shown.
test('after "Zerar corrida" the answer is dropped and the screen reloads everything at once', () => {
  const zerada = estadoDaResposta(ESTADO, diferenca({ historico_epoca: '77' }));
  assert.deepEqual(zerada, { estado: null, pedirTudo: true });
  assert.deepEqual(proximaConsultaEm(zerada.pedirTudo, 0), { atrasoMs: 0, pedirTudo: true });
  assert.deepEqual(pedidoDeEstado(ESTADO, true), { versao: null, historico: null });

  const inteira = { versao: 'c3', historico_epoca: '77', historico: [decisao({ id: 1 })] };
  assert.deepEqual(estadoDaResposta(ESTADO, inteira), { estado: inteira, pedirTudo: false });
});

test('a difference that does not add up is dropped the same way', () => {
  assert.deepEqual(estadoDaResposta(ESTADO, diferenca({ historico_quantas: 4 })), { estado: null, pedirTudo: true });
});
