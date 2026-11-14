// Tests for the repeated groups on the judge's entry page (BAJAAPP-103):
// the script that keeps each attempt's block state and lock up to date
// while the judge types (default/js/repeticoes.js).
//
// For someone new to this: a prova like Tração has up to six attempts. The
// page shows each attempt as a block with its own fields (T_3 and D_3 for
// attempt 3: a time OR a distance). Attempt n+1 is locked until attempt n
// is complete; earlier attempts stay editable. Each block says whether it
// is "preenchida" (complete), "incompleta" (partly filled), "vazia"
// (empty, may be filled) or "bloqueada" (empty and locked).
//
// The server draws the page with the same rules (Baja\Juiz\EntryBlocks,
// tested by tests/Unit/Juiz/RepeatedGroupsEntryTest.php); these tests check
// that the script agrees, and that it changes the page as it should.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import { aplicar, estadosDosBlocos, xorAtivo } from '../../default/js/repeticoes.js';

/** Six attempts of Tração: T_n and D_n, a time-or-distance pair (xor "A_n"). */
function tracao(tentativas = 6) {
  const blocos = [];
  for (let n = 1; n <= tentativas; n++) {
    blocos.push({
      ancora: `repeticao-1-${n}`,
      grupo: 1,
      numero: n,
      campos: [{ codigo: `T_${n}`, xor: `A_${n}` }, { codigo: `D_${n}`, xor: `A_${n}` }],
    });
  }
  return blocos;
}

const estadosDe = (resultado) => Object.values(resultado).map((r) => r.estado);

test('a new team: attempt 1 is open, the rest are locked', () => {
  assert.deepEqual(estadosDe(estadosDosBlocos(tracao(), {})),
    ['vazia', 'bloqueada', 'bloqueada', 'bloqueada', 'bloqueada', 'bloqueada']);
});

test('one side of a time-or-distance pair completes the attempt and opens the next one', () => {
  const estados = estadosDosBlocos(tracao(), { T_1: '4.512', D_2: '12.25' });
  assert.deepEqual(estadosDe(estados), ['preenchida', 'preenchida', 'vazia', 'bloqueada', 'bloqueada', 'bloqueada']);
  assert.equal(estados['repeticao-1-3'].bloqueado, false);
  assert.equal(estados['repeticao-1-4'].bloqueado, true);
});

test('an empty text is empty, and 0 is a value', () => {
  assert.deepEqual(estadosDe(estadosDosBlocos(tracao(2), { T_1: '' })), ['vazia', 'bloqueada']);
  assert.deepEqual(estadosDe(estadosDosBlocos(tracao(2), { T_1: '0' })), ['preenchida', 'vazia']);
});

test('a block with two independent fields and only one filled is "incompleta", and the next one waits', () => {
  const blocos = [1, 2].map((n) => ({
    ancora: `repeticao-1-${n}`, grupo: 1, numero: n, campos: [{ codigo: `A_${n}` }, { codigo: `B_${n}` }],
  }));
  assert.deepEqual(estadosDe(estadosDosBlocos(blocos, { A_1: '3' })), ['incompleta', 'bloqueada']);
});

test('a block that already has values is never locked, so saving cannot erase them', () => {
  // Attempt 2 was emptied after attempt 3 was saved. Locking attempt 3 would
  // disable its fields, the browser would not send them, and the save
  // would erase T_3. The server refuses that save instead, with a message.
  const estados = estadosDosBlocos(tracao(), { T_1: '4.1', T_3: '4.3' });
  assert.deepEqual(estadosDe(estados), ['preenchida', 'vazia', 'preenchida', 'vazia', 'bloqueada', 'bloqueada']);
});

test('two groups lock separately', () => {
  const blocos = [
    { ancora: 'repeticao-1-1', grupo: 1, numero: 1, campos: [{ codigo: 'I_1' }] },
    { ancora: 'repeticao-1-2', grupo: 1, numero: 2, campos: [{ codigo: 'I_2' }] },
    { ancora: 'repeticao-2-1', grupo: 2, numero: 1, campos: [{ codigo: 'V_1' }] },
    { ancora: 'repeticao-2-2', grupo: 2, numero: 2, campos: [{ codigo: 'V_2' }] },
  ];
  assert.deepEqual(estadosDe(estadosDosBlocos(blocos, { I_1: '1' })), ['preenchida', 'vazia', 'vazia', 'bloqueada']);
});

test('the xor letters the page handles: A, B, and those scoped to an attempt', () => {
  for (const letra of ['A', 'B', 'A_1', 'B_20']) assert.equal(xorAtivo(letra), true, letra);
  for (const letra of ['X', 'C', 'A_0', 'A_', 'a', '', undefined, null, 'A_1x']) assert.equal(xorAtivo(letra), false, String(letra));
});

test('an xor letter the page does not handle does not complete a pair', () => {
  const blocos = [{ ancora: 'repeticao-1-1', grupo: 1, numero: 1, campos: [{ codigo: 'T_1', xor: 'X' }, { codigo: 'D_1', xor: 'X' }] }];
  assert.deepEqual(estadosDe(estadosDosBlocos(blocos, { T_1: '4' })), ['incompleta']);
});

// --- On the page ------------------------------------------------------
//
// A tiny stand-in for the page: just what aplicar() touches. Each block has
// its fields, its state text and a numbered jump button.

function paginaFalsa(blocos, valores) {
  const elementos = {};
  const saltos = {};
  const pagina = {
    getElementById: (id) => elementos[id],
    querySelectorAll: (seletor) => {
      const salto = /^\.repeticao-salto\[data-alvo="(.+)"\]$/.exec(seletor);
      if (salto) return [saltos[salto[1]]];
      return [];
    },
  };
  for (const bloco of blocos) {
    const texto = { textContent: '' };
    elementos[bloco.ancora] = { dataset: {}, querySelector: () => texto, texto };
    saltos[bloco.ancora] = { dataset: {} };
    for (const campo of bloco.campos) {
      campo.elemento = { value: valores[campo.codigo] ?? '', disabled: false };
    }
  }
  return { pagina, elementos, saltos };
}

test('on the page: the state shows on the block and its jump button, and a locked block\'s fields are disabled', () => {
  const blocos = tracao(3);
  const { pagina, elementos, saltos } = paginaFalsa(blocos, { T_1: '4.5' });

  aplicar(pagina, blocos);

  assert.equal(elementos['repeticao-1-1'].dataset.estado, 'preenchida');
  assert.equal(elementos['repeticao-1-1'].texto.textContent, '✔ Preenchida');
  assert.equal(elementos['repeticao-1-3'].texto.textContent, '🔒 Bloqueada');
  assert.equal(saltos['repeticao-1-3'].dataset.estado, 'bloqueada');
  assert.equal(blocos[1].campos[0].elemento.disabled, false, 'attempt 2 is open');
  assert.equal(blocos[2].campos[0].elemento.disabled, true, 'attempt 3 is locked');
  assert.equal(blocos[2].campos[1].elemento.disabled, true);
});

test('on the page: typing into attempt 2 opens attempt 3', () => {
  const blocos = tracao(3);
  const { pagina } = paginaFalsa(blocos, { T_1: '4.5' });
  aplicar(pagina, blocos);
  assert.equal(blocos[2].campos[0].elemento.disabled, true);

  blocos[1].campos[1].elemento.value = '11.5';  // D_2
  aplicar(pagina, blocos);

  assert.equal(blocos[2].campos[0].elemento.disabled, false);
});

test('on the page: an attempt unlocked here stays disabled when another lock holds it', () => {
  // The page's other script locks with its own flags (xor, pass 2...).
  // The field is enabled only when no flag holds it.
  const blocos = tracao(2);
  const { pagina } = paginaFalsa(blocos, { T_1: '4.5' });
  blocos[1].campos[1].elemento['xor-disabled'] = true;  // D_2, as if T_2 were filled

  aplicar(pagina, blocos);

  assert.equal(blocos[1].campos[0].elemento.disabled, false);
  assert.equal(blocos[1].campos[1].elemento.disabled, true);
});
