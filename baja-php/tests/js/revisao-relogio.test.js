// Tests for the official clock of the review screen and for the "calculado
// há N s" indicator.
//
// Background: the screen must show the SERVER's time, the same on every
// computer, even on a laptop whose own clock is wrong. It learns the
// server's time from each answer to its poll and carries it forward with
// performance.now(), a counter that only moves forward at a steady rate and
// knows nothing about dates. In these tests both clocks are plain numbers
// handed in by the test, so each situation can be staged exactly.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import { criarRelogioOficial, horarioOficial, idadeDoCalculo } from '../../default/js/contagem/revisao-relogio.js';

// 2026-10-11 14:00:00 in Brasília, as the server would state it.
const SERVIDOR_14H = Date.UTC(2026, 9, 11, 17, 0, 0);

test('before the first answer there is no official time, and the screen says so', () => {
  const relogio = criarRelogioOficial();

  assert.equal(relogio.sincronizado(), false);
  assert.equal(relogio.agora(5000), null);
  assert.equal(horarioOficial(relogio.agora(5000)), 'sincronizando…');
});

// SITUATION: the reviewer's laptop has its date set three days back. The
// server answers a poll: "it is 14:00:00". The request took 80 ms.
//
// WHAT WENT WRONG: the screen used to compute "server time minus this
// computer's time" once per answer and then add the computer's clock back.
// That works until the computer's clock is corrected (Windows syncing its
// time in the middle of the race): the race clock on screen then jumps by
// the size of the correction.
//
// EXPECTED: the official time depends only on what the server said and on
// how far performance.now() has advanced since. The computer's date never
// enters the calculation: nothing in this test even mentions it.
test('the official time is the server\'s, moved forward by the monotonic clock only', () => {
  const relogio = criarRelogioOficial();
  relogio.registrar({ servidorAgoraMs: SERVIDOR_14H, enviadoPerfMs: 1000, recebidoPerfMs: 1080 });

  // The server stamped its time about half way through the round trip.
  assert.equal(relogio.agora(1040), SERVIDOR_14H);
  // Ten seconds later by the monotonic clock.
  assert.equal(relogio.agora(11_040), SERVIDOR_14H + 10_000);
  assert.equal(horarioOficial(relogio.agora(11_040)), '14:00:10');
});

test('the official time is written in the time zone of the event, not of the computer', () => {
  // 17:00 UTC is 14:00 in Brasília whatever zone this test runs in.
  assert.equal(horarioOficial(SERVIDOR_14H), '14:00:00');
});

// SITUATION: the first answer came over a slow connection (900 ms round
// trip), a later one over a fast one (40 ms).
//
// EXPECTED: the anchor from the fast answer replaces the slow one, because
// "the server's time lies somewhere within the round trip" is a much
// smaller doubt in 40 ms than in 900. A later, slower answer does not
// replace it again.
test('the answer with the shortest round trip is the one the clock is anchored to', () => {
  const relogio = criarRelogioOficial();
  // Slow: the server really stamped at monotonic 1850, late in the trip,
  // but the screen can only assume the middle (1450): it is 400 ms off.
  relogio.registrar({ servidorAgoraMs: SERVIDOR_14H, enviadoPerfMs: 1000, recebidoPerfMs: 1900 });
  assert.equal(relogio.agora(1850), SERVIDOR_14H + 400);

  // Fast: stamped at monotonic 5020, which is 3170 ms after the first stamp.
  relogio.registrar({ servidorAgoraMs: SERVIDOR_14H + 3170, enviadoPerfMs: 5000, recebidoPerfMs: 5040 });
  assert.equal(relogio.agora(5020), SERVIDOR_14H + 3170, 'now right to within the short round trip');

  // A later slow answer with a wildly different idea is ignored.
  relogio.registrar({ servidorAgoraMs: SERVIDOR_14H + 99_999, enviadoPerfMs: 6000, recebidoPerfMs: 6800 });
  assert.equal(relogio.agora(5020), SERVIDOR_14H + 3170);
});

// SITUATION: in the middle of the race the laptop's system clock is set
// forward by one hour (somebody fixes the time zone). The monotonic clock
// is not affected by that.
//
// EXPECTED: the screen cannot tell "the system clock jumped" from "the
// monotonic clock stalled", so it plays safe: it drops its anchor, says
// "sincronizando…" and takes a new anchor from the next answer, one poll
// later. The official time after that is right again, and it never showed
// a time one hour off.
test('a system clock stepped by an hour makes the screen resynchronise, never show a wrong time', () => {
  const relogio = criarRelogioOficial();
  relogio.registrar({ servidorAgoraMs: SERVIDOR_14H, enviadoPerfMs: 0, recebidoPerfMs: 20 });

  relogio.vigiar(1000, 500_000);
  relogio.vigiar(2000, 501_000);
  assert.equal(relogio.sincronizado(), true, 'both clocks advanced one second: all is well');

  relogio.vigiar(3000, 502_000 + 3_600_000);
  assert.equal(relogio.sincronizado(), false);
  assert.equal(horarioOficial(relogio.agora(3000)), 'sincronizando…');

  relogio.registrar({ servidorAgoraMs: SERVIDOR_14H + 4000, enviadoPerfMs: 4000, recebidoPerfMs: 4020 });
  assert.equal(relogio.agora(4010), SERVIDOR_14H + 4000);
});

// SITUATION: the laptop's lid is closed for ten minutes. On this machine
// performance.now() stops while it sleeps; the system clock does not.
//
// WHAT WOULD GO WRONG: the official time would be ten minutes behind for
// the rest of the race, and so would the race clock and every "há N s".
//
// EXPECTED: the first check after waking sees ten minutes on the system
// clock against a second on the monotonic one, and drops the anchor.
test('a monotonic clock that stalled while the computer slept is noticed', () => {
  const relogio = criarRelogioOficial();
  relogio.registrar({ servidorAgoraMs: SERVIDOR_14H, enviadoPerfMs: 0, recebidoPerfMs: 20 });
  relogio.vigiar(1000, 500_000);

  relogio.vigiar(2000, 500_000 + 600_000);

  assert.equal(relogio.sincronizado(), false);
});

test('a second that came a little late is not taken for a stalled clock', () => {
  // Timers in a background tab fire late; both clocks still agree.
  const relogio = criarRelogioOficial();
  relogio.registrar({ servidorAgoraMs: SERVIDOR_14H, enviadoPerfMs: 0, recebidoPerfMs: 20 });
  relogio.vigiar(1000, 500_000);
  relogio.vigiar(9000, 508_300);
  assert.equal(relogio.sincronizado(), true);
});

// SITUATION: flags, lap times and best laps are recalculated every few
// seconds ("recalculo_s", here 5). The status bar says how old the numbers
// on screen are.
//
// EXPECTED: "calculado há 4 s" in the normal colour. Beyond three times the
// interval (15 s) while the race is running, something is stuck and the
// text is marked as late. Before the start nobody types, nothing changes
// and nothing is recalculated: an old calculation is then normal.
test('the age of the calculation turns red beyond three recalculation intervals, while the race runs', () => {
  const estado = { calculado_em_ms: 100_000, configuracoes: { recalculo_s: 5 }, corrida: { estado: 'correndo' } };

  assert.deepEqual(idadeDoCalculo(estado, 104_900), { segundos: 4, atrasado: false });
  assert.deepEqual(idadeDoCalculo(estado, 115_000), { segundos: 15, atrasado: false }, 'exactly three intervals is still fine');
  assert.deepEqual(idadeDoCalculo(estado, 116_000), { segundos: 16, atrasado: true });

  const antesDaLargada = { ...estado, corrida: { estado: 'aguardando' } };
  assert.deepEqual(idadeDoCalculo(antesDaLargada, 400_000), { segundos: 300, atrasado: false });

  assert.equal(idadeDoCalculo({ configuracoes: {} }, 1000), null, 'no calculation yet: nothing to show');
});

// At QA "calculado há 4 min" was red during a quiet stretch of a running
// race: the engine recalculates only when something changed. The server
// now says whether changes are waiting ("mudancas_pendentes").
test('an old calculation turns red only while changes are waiting for it', () => {
  const estado = { calculado_em_ms: 100_000, configuracoes: { recalculo_s: 5 }, corrida: { estado: 'correndo' } };
  assert.deepEqual(idadeDoCalculo({ ...estado, mudancas_pendentes: false }, 340_000), { segundos: 240, atrasado: false }, 'a quiet race');
  assert.deepEqual(idadeDoCalculo({ ...estado, mudancas_pendentes: true }, 116_000), { segundos: 16, atrasado: true }, 'stuck');
  assert.deepEqual(idadeDoCalculo({ ...estado, mudancas_pendentes: true }, 110_000), { segundos: 10, atrasado: false }, 'on its way');
  assert.deepEqual(idadeDoCalculo({ ...estado, mudancas_pendentes: null }, 116_000), { segundos: 16, atrasado: true }, 'unknown: as before');
});
