// Tests for how the review screen writes times.
//
// How to read this file if you have never seen a test before:
//   - `test('some sentence', () => { ... })` describes one behaviour.
//   - Inside it, `assert.equal(what_we_got, what_we_expect)` fails the test
//     when the two differ.
//   - Run them all with:  node --test baja-php/tests/js/
//
// All times in the system are stored in milliseconds (1 second = 1000 ms).
// The review screen always shows whole seconds.

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  duracao, idade, interpretarTempoDeProva, relogio, relogioDaCorrida, tempoDeProva,
} from '../../default/js/contagem/revisao-formato.js';

const SEGUNDO = 1000;
const MINUTO = 60 * SEGUNDO;
const HORA = 60 * MINUTO;

test('the race clock is written as hours:minutes:seconds', () => {
  assert.equal(relogio(0), '0:00:00');
  assert.equal(relogio(1 * HORA + 2 * MINUTO + 5 * SEGUNDO), '1:02:05');
});

test('the race clock drops the milliseconds instead of rounding up', () => {
  // 59.9 seconds into the race the clock still says 59: a clock that
  // showed 1:00 early would disagree with every other clock at the track.
  assert.equal(relogio(59900), '0:00:59');
});

test('a time that is not known yet is shown as a dash', () => {
  assert.equal(relogio(null), '—');
  assert.equal(duracao(null), '—');
  assert.equal(idade(undefined), '—');
});

test('a lap time is written as minutes:seconds', () => {
  assert.equal(duracao(2 * MINUTO + 5 * SEGUNDO), '2:05');
  // A very slow lap (over an hour, after a long repair) stays in minutes.
  assert.equal(duracao(61 * MINUTO), '61:00');
});

test('"how long ago" uses the largest unit that fits', () => {
  assert.equal(idade(500), 'agora');
  assert.equal(idade(12 * SEGUNDO), '12 s');
  assert.equal(idade(3 * MINUTO + 20 * SEGUNDO), '3 min');
  assert.equal(idade(2 * HORA + 10 * MINUTO), '2 h');
});

test('"how long ago" never shows a negative age', () => {
  // The server's clock and this computer's can differ by a moment, which
  // would make something look like it happened in the future.
  assert.equal(idade(-800), 'agora');
});

test('a moment of the race is shown as time since the start', () => {
  const largada = 1_760_000_000_000;
  assert.equal(tempoDeProva(largada + 90 * SEGUNDO, largada), '0:01:30');
});

test('a moment cannot be shown as race time before the race has a start', () => {
  assert.equal(tempoDeProva(1_760_000_000_000, null), '—');
});

test('a reviewer can type a race time as seconds, m:ss or h:mm:ss', () => {
  assert.equal(interpretarTempoDeProva('75'), 75 * SEGUNDO);
  assert.equal(interpretarTempoDeProva('1:15'), 75 * SEGUNDO);
  assert.equal(interpretarTempoDeProva('1:02:05'), 1 * HORA + 2 * MINUTO + 5 * SEGUNDO);
  assert.equal(interpretarTempoDeProva('  0:30 '), 30 * SEGUNDO);
});

test('text that is not a time is refused instead of guessed', () => {
  // Returning null makes the screen say "tempo inválido". Guessing would
  // add a pass at the wrong moment of the race.
  assert.equal(interpretarTempoDeProva(''), null);
  assert.equal(interpretarTempoDeProva('abc'), null);
  assert.equal(interpretarTempoDeProva('1:75'), null);
  assert.equal(interpretarTempoDeProva('1:02:03:04'), null);
  assert.equal(interpretarTempoDeProva('-5'), null);
});

test('what relogio() writes can be typed back', () => {
  const momento = 2 * HORA + 41 * MINUTO + 7 * SEGUNDO;
  assert.equal(interpretarTempoDeProva(relogio(momento)), momento);
});

test('the race clock runs from the start time', () => {
  const corrida = { largada_ms: 1000, encerramento_ms: null };
  assert.equal(relogioDaCorrida(corrida, 61000), 60000);
});

test('the race clock stops at the end of the race', () => {
  const corrida = { largada_ms: 1000, encerramento_ms: 5000 };
  // Ten minutes after the finish the clock still shows the race's length.
  assert.equal(relogioDaCorrida(corrida, 600000), 4000);
});

test('there is no race clock before the start', () => {
  assert.equal(relogioDaCorrida({ largada_ms: null, encerramento_ms: null }, 5000), null);
});
