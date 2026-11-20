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

import {
  aplicar, blocoInicial, blocosPorGrupo, estadosDosBlocos, ligar, mostrarBloco, xorAtivo,
} from '../../default/js/repeticoes.js';

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

// --- One block at a time ------------------------------------------------
//
// With the script, the page shows only ONE attempt of a group; Anterior,
// Próxima and the numbered buttons switch which one. The others are only
// hidden (the HTML "hidden" attribute), never taken out of the form: a
// hidden field is still sent when the judge presses Salvar, so the server
// gets exactly the same fields as before.

/** Turns the states into the shape blocoInicial() reads: anchor => {estado}. */
function estadosComo(lista) {
  const estados = {};
  lista.forEach((estado, i) => { estados[`repeticao-1-${i + 1}`] = { estado }; });
  return estados;
}

test('first block: the one in the address wins (e.g. the block just saved)', () => {
  const estados = estadosComo(['preenchida', 'preenchida', 'vazia', 'bloqueada', 'bloqueada', 'bloqueada']);
  assert.equal(blocoInicial(tracao(), estados, '#repeticao-1-2'), 'repeticao-1-2');
  // Even a locked block can be opened from the address: it is shown, its fields stay disabled.
  assert.equal(blocoInicial(tracao(), estados, '#repeticao-1-5'), 'repeticao-1-5');
});

test('first block: without an address, the first one still to fill (incompleta or vazia)', () => {
  assert.equal(blocoInicial(tracao(), estadosComo(['preenchida', 'preenchida', 'vazia', 'bloqueada', 'bloqueada', 'bloqueada']), ''), 'repeticao-1-3');
  assert.equal(blocoInicial(tracao(), estadosComo(['preenchida', 'incompleta', 'vazia', 'bloqueada', 'bloqueada', 'bloqueada']), ''), 'repeticao-1-2');
  // A new team: attempt 1.
  assert.equal(blocoInicial(tracao(), estadosComo(['vazia', 'bloqueada', 'bloqueada', 'bloqueada', 'bloqueada', 'bloqueada']), ''), 'repeticao-1-1');
});

test('first block: a locked block is never chosen on its own, and when all are filled it is the last one', () => {
  assert.equal(blocoInicial(tracao(), estadosComo(Array(6).fill('preenchida')), ''), 'repeticao-1-6');
  // Only locked blocks left to fill (cannot really happen, but must not pick one): the last.
  assert.equal(blocoInicial(tracao(2), estadosComo(['preenchida', 'bloqueada']), ''), 'repeticao-1-2');
});

test('first block: an address that is not one of this group\'s blocks is ignored', () => {
  const estados = estadosComo(['preenchida', 'vazia', 'bloqueada', 'bloqueada', 'bloqueada', 'bloqueada']);
  for (const hash of ['#repeticao-1-9', '#repeticao-2-1', '#outra-coisa', '#', '', undefined]) {
    assert.equal(blocoInicial(tracao(), estados, hash), 'repeticao-1-2', String(hash));
  }
});

test('the blocks are split by group, each in page order', () => {
  const blocos = [
    { ancora: 'repeticao-1-1', grupo: 1, numero: 1, campos: [] },
    { ancora: 'repeticao-1-2', grupo: 1, numero: 2, campos: [] },
    { ancora: 'repeticao-2-1', grupo: 2, numero: 1, campos: [] },
  ];
  const grupos = blocosPorGrupo(blocos);
  assert.deepEqual([...grupos.keys()], [1, 2]);
  assert.deepEqual(grupos.get(1).map((b) => b.ancora), ['repeticao-1-1', 'repeticao-1-2']);
});

/**
 * A bigger stand-in for the page, enough for ligar(): every block is a
 * <tbody> with a "hidden" flag, its fields, a select (or not), and the
 * address (location.hash). It records the listeners the script adds, so a
 * test can "click" a link or change the select.
 */
function paginaCompleta(blocos, valores, hash = '', blocoRecusado = null) {
  const elementos = {};
  const ouvintes = {};
  const historico = [];
  const janela = {
    history: { replaceState: (_estado, _titulo, endereco) => { historico.push(endereco); pagina.location.hash = endereco; } },
    addEventListener: (tipo, funcao) => { (ouvintes[`janela:${tipo}`] ??= []).push(funcao); },
  };
  const pagina = {
    location: { hash },
    defaultView: janela,
    getElementById: (id) => elementos[id],
    // After a refused save, entry.php names the block on the table: data-repeticao-aberta.
    querySelector: (seletor) => (seletor === '[data-repeticao-aberta]' && blocoRecusado
      ? { dataset: { repeticaoAberta: blocoRecusado } } : null),
    addEventListener: (tipo, funcao) => { (ouvintes[tipo] ??= []).push(funcao); },
    querySelectorAll: (seletor) => {
      if (seletor === 'tbody.repeticao') return blocos.map((b) => elementos[b.ancora]);
      if (seletor === '.repeticao-select') return blocos.map((b) => elementos[b.ancora].seletor);
      return [];
    },
  };
  for (const bloco of blocos) {
    const seletor = {
      value: bloco.ancora,
      addEventListener: (tipo, funcao) => { (ouvintes[`select:${bloco.ancora}:${tipo}`] ??= []).push(funcao); },
    };
    const campos = bloco.campos.map((campo) => ({
      id: campo.codigo, dataset: { xor: campo.xor }, value: valores[campo.codigo] ?? '', disabled: false,
    }));
    elementos[bloco.ancora] = {
      id: bloco.ancora,
      hidden: false,
      dataset: { grupo: String(bloco.grupo), numero: String(bloco.numero) },
      seletor,
      campos,
      querySelector: () => ({ textContent: '' }),
      querySelectorAll: (s) => (s === '[data-group]' ? campos : s === '.repeticao-select' ? [seletor] : []),
      scrollIntoView: () => {},
      getBoundingClientRect: () => ({ top: 10 }),
    };
  }
  /** A click on a navigation link whose href is "#alvo". Returns whether the script stopped the browser's own jump. */
  const clicar = (alvo, classe = 'repeticao-salto') => {
    let impedido = false;
    const link = { getAttribute: () => `#${alvo}`, closest: (s) => (s.includes(classe) ? link : null) };
    for (const funcao of ouvintes.click ?? []) funcao({ target: link, preventDefault: () => { impedido = true; } });
    return impedido;
  };
  const visiveis = () => blocos.filter((b) => !elementos[b.ancora].hidden).map((b) => b.ancora);
  return { pagina, elementos, ouvintes, historico, clicar, visiveis };
}

/** Tração with three attempts, as blocosDaPagina() reads it back from the page. */
const tracaoDaPagina = () => tracao(3);

test('on opening: only one block is shown, the first still to fill', () => {
  const { pagina, visiveis } = paginaCompleta(tracaoDaPagina(), { T_1: '4.5' });
  ligar(pagina);
  assert.deepEqual(visiveis(), ['repeticao-1-2']);
});

test('on opening after a Salvar: the block in the address (the one saved) is shown', () => {
  // save_entry.php sends the judge back to entry.php#repeticao-1-1 after
  // the Salvar of attempt 1, even though attempt 2 is the next to fill.
  const { pagina, visiveis } = paginaCompleta(tracaoDaPagina(), { T_1: '4.5' }, '#repeticao-1-1');
  ligar(pagina);
  assert.deepEqual(visiveis(), ['repeticao-1-1']);
});

test('after a refused save (no anchor in the address): the block whose Salvar was pressed is shown', () => {
  // save_entry.php refused the save and drew the page again, at
  // save_entry.php?..., without "#...". The page names the block instead.
  const { pagina, visiveis } = paginaCompleta(tracaoDaPagina(), { T_1: '4.5' }, '', 'repeticao-1-3');
  ligar(pagina);
  assert.deepEqual(visiveis(), ['repeticao-1-3']);
});

test('navigation: a numbered button or Próxima switches the block, and the browser does not jump', () => {
  const { pagina, visiveis, clicar, historico } = paginaCompleta(tracaoDaPagina(), { T_1: '4.5' });
  ligar(pagina);

  assert.equal(clicar('repeticao-1-1'), true, 'the browser\'s own jump to the anchor is stopped');
  assert.deepEqual(visiveis(), ['repeticao-1-1']);
  assert.equal(clicar('repeticao-1-2', 'repeticao-seta'), true);
  assert.deepEqual(visiveis(), ['repeticao-1-2']);
  // The address follows, so a reload opens the same block.
  assert.deepEqual(historico, ['#repeticao-1-1', '#repeticao-1-2']);
});

test('navigation: a locked block can be looked at, and its fields stay disabled', () => {
  const blocos = tracaoDaPagina();
  const { pagina, visiveis, clicar, elementos } = paginaCompleta(blocos, { T_1: '4.5' });
  ligar(pagina);
  clicar('repeticao-1-3');
  assert.deepEqual(visiveis(), ['repeticao-1-3']);
  // The fields of attempt 3, as the page holds them.
  const [t3, d3] = elementos['repeticao-1-3'].campos;
  assert.equal(t3.disabled, true);
  assert.equal(d3.disabled, true);
});

test('navigation: a click anywhere else, or on a link to something that is not a block, is left alone', () => {
  const { pagina, visiveis, clicar } = paginaCompleta(tracaoDaPagina(), { T_1: '4.5' });
  ligar(pagina);
  assert.equal(clicar('repeticao-1-9'), false);
  assert.equal(clicar('repeticao-1-1', 'outro-link'), false);
  assert.deepEqual(visiveis(), ['repeticao-1-2']);
});

test('navigation: the select (for many attempts) switches the block too', () => {
  const { pagina, visiveis, ouvintes, elementos } = paginaCompleta(tracaoDaPagina(), { T_1: '4.5' });
  ligar(pagina);
  const seletor = elementos['repeticao-1-2'].seletor;
  seletor.value = 'repeticao-1-1';
  for (const funcao of ouvintes['select:repeticao-1-2:change']) funcao();
  assert.deepEqual(visiveis(), ['repeticao-1-1']);
  assert.equal(elementos['repeticao-1-1'].seletor.value, 'repeticao-1-1', 'the shown block\'s select names it');
});

test('navigating never removes a field nor changes what was typed', () => {
  // What the judge typed in attempt 2, then they look at attempt 1 and 3.
  const blocos = tracaoDaPagina();
  const { pagina, clicar, elementos } = paginaCompleta(blocos, { T_1: '4.5' });
  ligar(pagina);
  const t2 = elementos['repeticao-1-2'].campos[0];
  t2.value = '5.25';
  clicar('repeticao-1-1');
  clicar('repeticao-1-3');

  // Every field is still there, with its value. Hidden blocks are only
  // hidden: their fields are sent with the form like any other.
  assert.deepEqual(blocos.flatMap((b) => elementos[b.ancora].campos.map((c) => c.id)), ['T_1', 'D_1', 'T_2', 'D_2', 'T_3', 'D_3']);
  assert.equal(elementos['repeticao-1-1'].campos[0].value, '4.5');
  assert.equal(t2.value, '5.25');
  assert.equal(t2.disabled, false, 'hiding does not disable (a disabled field would not be sent)');
});

test('two groups: each shows one of its own blocks, and navigating one leaves the other alone', () => {
  const blocos = [
    { ancora: 'repeticao-1-1', grupo: 1, numero: 1, campos: [{ codigo: 'I_1' }] },
    { ancora: 'repeticao-1-2', grupo: 1, numero: 2, campos: [{ codigo: 'I_2' }] },
    { ancora: 'repeticao-2-1', grupo: 2, numero: 1, campos: [{ codigo: 'V_1' }] },
    { ancora: 'repeticao-2-2', grupo: 2, numero: 2, campos: [{ codigo: 'V_2' }] },
  ];
  const { pagina, visiveis, clicar } = paginaCompleta(blocos, { I_1: '1' });
  ligar(pagina);
  assert.deepEqual(visiveis(), ['repeticao-1-2', 'repeticao-2-1']);
  clicar('repeticao-2-2');
  assert.deepEqual(visiveis(), ['repeticao-1-2', 'repeticao-2-2']);
});

test('a field the browser refuses in a hidden block brings that block up', () => {
  const blocos = tracaoDaPagina();
  const { pagina, visiveis, ouvintes, elementos } = paginaCompleta(blocos, { T_1: '4.5' });
  ligar(pagina);
  const corpo = elementos['repeticao-1-1'];
  const campo = { closest: () => corpo };
  for (const funcao of ouvintes.invalid) funcao({ target: campo });
  assert.deepEqual(visiveis(), ['repeticao-1-1']);
});

test('mostrarBloco with an anchor that is not a block changes nothing', () => {
  const blocos = tracaoDaPagina();
  const { pagina, visiveis } = paginaCompleta(blocos, {});
  assert.equal(mostrarBloco(pagina, blocos, 'repeticao-7-7'), false);
  assert.deepEqual(visiveis(), ['repeticao-1-1', 'repeticao-1-2', 'repeticao-1-3']);
});
