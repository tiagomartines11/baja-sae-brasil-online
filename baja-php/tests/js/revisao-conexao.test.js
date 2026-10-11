// Tests for how the review screen keeps in touch with the server.
//
// The screen "polls": every 2 seconds it asks the server "anything new?".
// The server answers either with the whole state of the race, or with
// {"inalterado": true} when nothing changed since the version the screen
// already has.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  POLLS_ENTRE_ESTADOS_COMPLETOS, aplicarResposta, avisoDeConexao, desvioDoServidorMs, proximoAtrasoMs, versaoParaPedir,
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
  assert.equal(versaoParaPedir(null, 0), null);
});

test('later polls send the version the screen has, so the server can answer "inalterado"', () => {
  assert.equal(versaoParaPedir({ versao: 37 }, 1), 37);
});

test('every few polls the screen asks for everything even if nothing was stored', () => {
  // Some things change with time alone and do not make a new version: a
  // source going offline, a car becoming "atrasado".
  assert.equal(versaoParaPedir({ versao: 37 }, POLLS_ENTRE_ESTADOS_COMPLETOS), null);
});

test('the difference between the two clocks is server minus this computer', () => {
  // This computer's clock is 3 seconds behind the server's.
  assert.equal(desvioDoServidorMs(10_000, 7_000), 3_000);
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
