// Tests for what the status bar of the review screen says about the points.
//
// BACKGROUND: at 26SE every car of the Enduro scored 0. The lap counting
// had published the right lap counts and the points were recalculated, but
// the classification field was DESCLAS, which the scoring formula reads as
// "SIM = disqualified", and every car had been marked SIM. Nobody on the
// review screen could see it. The state now carries `pontuacao`:
//
//   campo_classificacao_na_formula  the classification field, when the formula reads it
//   todos_os_pontos_zero            a car has a lap and every published points value is 0
//   recalculados_em_ms              when points were last recalculated
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import { avisosDaPontuacao, recalculoDosPontos } from '../../default/js/contagem/revisao-frases.js';
import { aplicarResposta } from '../../default/js/contagem/revisao-conexao.js';

// SITUATION: the 26SE state: DESCLAS is the classification field, the
// formula reads it, and every car has 0 points although cars have laps.
//
// EXPECTED: two warnings, one naming the field, one saying all points are 0.
test('a classification field the formula reads, and a race where nobody scores, are both said', () => {
  const avisos = avisosDaPontuacao({ campo_classificacao_na_formula: 'DESCLAS', todos_os_pontos_zero: true, recalculados_em_ms: null });

  assert.equal(avisos.length, 2);
  assert.match(avisos[0], /campo de classificação DESCLAS é lido pela fórmula de pontuação/);
  assert.equal(avisos[1], 'todos os pontos publicados são 0 — confira o campo de classificação e a fórmula');
});

// SITUATION: a healthy prova (APTO, points that are not all zero), and an
// answer from a server that does not send `pontuacao` at all.
//
// EXPECTED: no warning in either case.
test('a healthy prova, or an older server, gives no warning', () => {
  assert.deepEqual(avisosDaPontuacao({ campo_classificacao_na_formula: null, todos_os_pontos_zero: false, recalculados_em_ms: 1 }), []);
  assert.deepEqual(avisosDaPontuacao(undefined), []);
});

// SITUATION: points were last recalculated at 14:03:27 local time.
//
// EXPECTED: "pontos recalculados às 14:03:27", next to "publicado há N s".
// Nothing when the time is unknown.
test('the time points were last recalculated is shown as a time of day', () => {
  const quando = new Date(2026, 8, 26, 14, 3, 27).getTime();

  assert.equal(recalculoDosPontos({ recalculados_em_ms: quando }), 'pontos recalculados às 14:03:27');
  assert.equal(recalculoDosPontos({ recalculados_em_ms: null }), null);
  assert.equal(recalculoDosPontos(undefined), null);
});

// SITUATION: nothing new was typed, so the server answers with the small
// "inalterado" reply. Meanwhile somebody fixed the classification field.
//
// EXPECTED: the reply's `pontuacao` replaces the old one: the warning goes
// away without waiting for a new version of the race.
test('the points check follows the server also in the small "inalterado" reply', () => {
  const antes = { versao: 'v1', alertas_abertos: [], alertas_resolvidos: [],
    pontuacao: { campo_classificacao_na_formula: 'DESCLAS', todos_os_pontos_zero: true, recalculados_em_ms: 1 } };
  const agora = { campo_classificacao_na_formula: null, todos_os_pontos_zero: false, recalculados_em_ms: 2 };

  const depois = aplicarResposta(antes, { inalterado: true, versao: 'v1', pontuacao: agora });

  assert.deepEqual(depois.pontuacao, agora);
});
