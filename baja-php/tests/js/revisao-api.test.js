// Tests for how the review screen reads what the server answers.
//
// Background: the screen talks to three small addresses on the server, and
// each of them always answers in JSON, a format programs read. When an
// answer is NOT JSON, something else answered in the server's place, and
// the screen has to work out what, because the two possibilities need
// opposite reactions from the reviewer:
//
//   - the login ran out: the server sends the browser to the login page.
//     Nothing the reviewer did was stored. They must log in again.
//   - the server broke half way: it prints an error page. The login is
//     fine, and a decision that was being sent MAY HAVE BEEN STORED.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import { tipoDeResposta } from '../../default/js/contagem/revisao-api.js';

const JSON_ = 'application/json; charset=utf-8';
const HTML = 'text/html; charset=UTF-8';

// SITUATION: the reviewer's forum login ended while the screen was open.
// The next request is redirected to the login page (which arrives as an
// ordinary page with status 200), or is refused with 401 or 403.
//
// EXPECTED: all three are recognised as "sessão expirada".
test('a redirect to the login page, a 401 and a 403 mean the session ended', () => {
  assert.equal(tipoDeResposta({ status: 200, redirecionada: true, tipoDeConteudo: HTML }), 'sessao_expirada');
  assert.equal(tipoDeResposta({ status: 401, redirecionada: false, tipoDeConteudo: HTML }), 'sessao_expirada');
  assert.equal(tipoDeResposta({ status: 403, redirecionada: false, tipoDeConteudo: JSON_ }), 'sessao_expirada');
});

// SITUATION: a reviewer presses "Anular". On the server the decision is
// stored, and then something fails while the answer is being prepared: the
// browser receives an error page (HTML, status 500) instead of JSON. The
// same happens when a proxy in between gives up waiting (502, 504), or
// when the server prints a warning in front of an otherwise good answer.
//
// WHAT WENT WRONG: every answer that was not JSON was reported as "sua
// sessão expirou; a decisão NÃO foi registrada". The reviewer logged in
// again and repeated the decision, which had in fact been stored the first
// time, so the entry ended up voided twice.
//
// EXPECTED: these are a server error, not an expired session. (The screen
// then says the outcome is unknown and reloads the state, so the reviewer
// can see whether the decision is there.)
test('an error page is a server error, not an expired session', () => {
  assert.equal(tipoDeResposta({ status: 500, redirecionada: false, tipoDeConteudo: HTML }), 'erro_do_servidor');
  assert.equal(tipoDeResposta({ status: 502, redirecionada: false, tipoDeConteudo: 'text/plain' }), 'erro_do_servidor');
  assert.equal(tipoDeResposta({ status: 200, redirecionada: false, tipoDeConteudo: HTML }), 'erro_do_servidor');
  assert.equal(tipoDeResposta({ status: 200, redirecionada: false, tipoDeConteudo: null }), 'erro_do_servidor');
});

// SITUATION: the server answers in JSON, whatever the news: stored (200),
// refused because of a bad request (400), refused because somebody decided
// first (409), busy (503), or an error it caught itself (500).
//
// EXPECTED: all of these are answers of ours, to be read and shown. Each
// carries its own sentence for the reviewer.
test('a JSON answer is read whatever its status says', () => {
  for (const status of [200, 400, 409, 500, 503]) {
    assert.equal(tipoDeResposta({ status, redirecionada: false, tipoDeConteudo: JSON_ }), 'json');
  }
});
