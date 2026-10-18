// Tests for the car table of the review screen: which sources get a column
// and which the count is compared with, what each row signals, what each
// counter's cell says, and how the rows are sorted.
//
// Vocabulary:
//   - "passagens" (official passes): a car's laps plus its pass zero, the
//     first crossing after the start, which completes no lap.
//   - "contagem_por_fonte": how many passes each source recorded for a car.
//   - "comparada": a source the official count is checked against.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  CHAVE_DA_ORDEM, celulaDaFonte, colunasDeFontes, desdeDoStatus, diferenca, guardarOrdem, indicadorDaOrdem, lerOrdem, ordemEmVigor,
  ordenarCarros, proximaOrdem, sinaisDoCarro, textoDeAcima, textoDosAlertas,
} from '../../default/js/contagem/revisao-carros.js';

const LARGADA = 1_792_238_000_000;
const AGORA = LARGADA + 4 * 3_600_000;

function fonte(id, extra = {}) {
  return {
    id,
    nome: `Contagem ${id}`,
    tipo: 'manual',
    ativa: true,
    papel: 'votante',
    stats: { entradas: 400, nada_a_esperar: false, visto_em_ms: AGORA - 1000 },
    ...extra,
  };
}

function estadoCom(fontes, extra = {}) {
  return { corrida: { largada_ms: LARGADA }, fontes, alertas_abertos: [], historico_em_vigor: [], ...extra };
}

// The three counting sources of 26SE. "Contagem 3" was active all race,
// with its only phone revoked the night before: no device, no entry.
const CONTAGEM_3_SILENCIOSA = fonte(3, {
  stats: { entradas: 0, nada_a_esperar: true, visto_em_ms: LARGADA - 12 * 3_600_000 },
});
const ESTADO_26SE = estadoCom([fonte(1), fonte(2), CONTAGEM_3_SILENCIOSA]);

// ---- the columns ----------------------------------------------------------------

test('a source that recorded nothing since the start and has no device open gets no column', () => {
  // A column of zeros made every car look wrong (26SE, "Contagem 3").
  const colunas = colunasDeFontes(ESTADO_26SE);
  assert.deepEqual(colunas.map((coluna) => coluna.fonte.id), [1, 2]);
  assert.ok(colunas.every((coluna) => coluna.comparada));
});

test('before the start every source has its column, even with nothing recorded', () => {
  const antes = { ...ESTADO_26SE, corrida: { largada_ms: null } };
  assert.deepEqual(colunasDeFontes(antes).map((coluna) => coluna.fonte.id), [1, 2, 3]);
});

// SITUATION: a counter's phone died before the start and was never closed
// or revoked, so the source still has a device open (offline) but has
// recorded nothing all race.
//
// EXPECTED: its column stays (the phone may come back), greyed and not
// compared, instead of a "−N" on every row.
test('a source with a device open but nothing recorded since the start is greyed and not compared', () => {
  const morta = fonte(2, { stats: { entradas: 0, nada_a_esperar: false, offline: true, visto_em_ms: LARGADA - 600_000 } });
  const [primeira, segunda] = colunasDeFontes(estadoCom([fonte(1), morta]), AGORA);
  assert.equal(primeira.comparada, true);
  assert.equal(segunda.fonte.id, 2, 'it keeps its column');
  assert.equal(segunda.comparada, false);
  assert.match(segunda.motivo, /não registrou nada desde a largada/);
});

test('before the start a source with nothing recorded is still compared', () => {
  const vazia = fonte(2, { stats: { entradas: 0, nada_a_esperar: false, visto_em_ms: LARGADA - 600_000 } });
  assert.ok(colunasDeFontes(estadoCom([fonte(1), vazia]), LARGADA - 1000).every((coluna) => coluna.comparada));
});

test('a source excluded from the start with no end keeps its column, greyed and not compared', () => {
  const estado = estadoCom([fonte(1), fonte(2)], {
    historico_em_vigor: [{ tipo: 'excluir_fonte', fonte_id: 2, ts_ms: LARGADA - 5000, ts_fim_ms: null }],
  });
  const [primeira, segunda] = colunasDeFontes(estado);
  assert.equal(primeira.comparada, true);
  assert.equal(segunda.comparada, false);
  assert.match(segunda.motivo, /excluída desde a largada/);
});

test('a source excluded only from the middle of the race is still compared', () => {
  // What it recorded before the exclusion counts.
  const estado = estadoCom([fonte(1), fonte(2)], {
    historico_em_vigor: [{ tipo: 'excluir_fonte', fonte_id: 2, ts_ms: LARGADA + 3_600_000, ts_fim_ms: null }],
  });
  assert.ok(colunasDeFontes(estado).every((coluna) => coluna.comparada));
});

test('an observer gets no column and a paper total is never compared', () => {
  const estado = estadoCom([fonte(1), fonte(7, { tipo: 'automatica', papel: 'observadora' }), fonte(9, { tipo: 'totais', stats: null })]);
  const colunas = colunasDeFontes(estado);
  assert.deepEqual(colunas.map((coluna) => [coluna.fonte.id, coluna.comparada]), [[1, true], [9, false]]);
});

// ---- the cells -----------------------------------------------------------------------

// SITUATION: the counting line is after the start line, so the first time a
// car crosses it the car has not completed a lap: that crossing is its
// "passagem zero". Car 41 has crossed 6 times: the pass zero and 5 laps.
//
// WHAT WENT WRONG (once): each counter's 6 entries were compared with the 5
// official LAPS, so every counter looked one ahead on every car.
//
// EXPECTED: a counter is compared with the 6 official PASSES.
test('a counter cell is the difference to the official passes, the pass zero included', () => {
  const colunas = colunasDeFontes(estadoCom([fonte(1), fonte(2)]));
  const carro = { carro: 41, voltas: 5, passagens: 6, contagem_por_fonte: { 1: 6, 2: 5 } };
  const [igual, perdeu] = colunas.map((coluna) => celulaDaFonte(carro, coluna));
  assert.equal(igual.texto, '=');
  assert.equal(igual.valor, 0);
  assert.equal(perdeu.texto, '−1');
  assert.equal(perdeu.valor, -1);
  assert.match(perdeu.titulo, /5 passagens registradas; oficiais: 6/, 'the raw count is in the title');
});

test('a counter with no entry at all for a car counts as zero', () => {
  const [coluna] = colunasDeFontes(estadoCom([fonte(1)]));
  assert.equal(celulaDaFonte({ carro: 5, voltas: 1, passagens: 2, contagem_por_fonte: {} }, coluna).texto, '−2');
});

test('a paper total is a number of laps and is compared with the laps', () => {
  const [coluna] = colunasDeFontes(estadoCom([fonte(9, { tipo: 'totais', stats: null })]));
  assert.equal(celulaDaFonte({ carro: 5, voltas: 5, passagens: 6, totais_importados: { 9: 5 } }, coluna).texto, '=');
  assert.equal(celulaDaFonte({ carro: 5, voltas: 5, passagens: 6, totais_importados: { 9: 7 } }, coluna).texto, '+2');
  assert.equal(celulaDaFonte({ carro: 5, voltas: 5, passagens: 6, totais_importados: {} }, coluna).valor, null,
    'a car missing from the sheet has no value');
});

test('differences use a real minus sign', () => {
  assert.equal(diferenca(-3), '−3');
  assert.equal(diferenca(0), '=');
  assert.equal(diferenca(4), '+4');
});

// ---- what a row signals ------------------------------------------------------------

// SITUATION (26SE car 1): official 38 passes; Contagem 1 and 2 each have 35.
// Three lone entries were each confirmed twice by reviewers. No cell was
// worth highlighting more than any other: every car had one.
//
// EXPECTED: the row itself says "contado acima de todos os contadores (+3)".
// The silent Contagem 3 (0 passes) is no part of it.
test('a count above every counting source is said on the row, with by how much', () => {
  const colunas = colunasDeFontes(ESTADO_26SE);
  const carro1 = { carro: 1, voltas: 37, passagens: 38, contagem_por_fonte: { 1: 35, 2: 35 } };
  const sinais = sinaisDoCarro(carro1, ESTADO_26SE, colunas);
  assert.equal(sinais.acima, 3);
  assert.equal(textoDeAcima(sinais.acima), 'contado acima de todos os contadores (+3)');
});

test('the count is not "above" while one counter has it all', () => {
  // Counter 2 missed three, counter 1 has every pass: normal two-of-three life.
  const colunas = colunasDeFontes(ESTADO_26SE);
  const carro = { carro: 2, voltas: 37, passagens: 38, contagem_por_fonte: { 1: 38, 2: 35 } };
  assert.equal(sinaisDoCarro(carro, ESTADO_26SE, colunas).acima, null);
});

test('a source excluded for the whole race does not hide a count above the others', () => {
  // Excluded because its counter typed nonsense: 60 entries for car 1.
  const estado = estadoCom([fonte(1), fonte(2)], {
    historico_em_vigor: [{ tipo: 'excluir_fonte', fonte_id: 2, ts_ms: LARGADA, ts_fim_ms: null }],
  });
  const carro = { carro: 1, voltas: 37, passagens: 38, contagem_por_fonte: { 1: 36, 2: 60 } };
  assert.equal(sinaisDoCarro(carro, estado, colunasDeFontes(estado)).acima, 2);
});

test('with no source to compare with, there is no "above"', () => {
  const estado = estadoCom([fonte(9, { tipo: 'totais', stats: null })]);
  assert.equal(sinaisDoCarro({ carro: 1, voltas: 3, passagens: 4 }, estado, colunasDeFontes(estado)).acima, null);
});

test('the open flags of a car are counted, and those that put its count in doubt said apart', () => {
  const estado = estadoCom([fonte(1), fonte(2)], {
    alertas_abertos: [
      { id: 'a', tipo: 'solitaria', carros: [12], contagem_em_duvida: 'a_menos' },
      { id: 'b', tipo: 'troca_provavel', carros: [12, 21], contagem_em_duvida: null },
      { id: 'c', tipo: 'repetida', carros: [30], contagem_em_duvida: null },
      { id: 'd', tipo: 'solitaria', carros: [12], auto_resolvido: true },
    ],
  });
  const sinais = sinaisDoCarro({ carro: 12, voltas: 3, passagens: 4, contagem_por_fonte: { 1: 4, 2: 4 } }, estado, colunasDeFontes(estado));
  assert.equal(sinais.alertas, 2);
  assert.equal(sinais.emDuvida, 1);
  const texto = textoDosAlertas(sinais);
  assert.equal(texto.texto, '2 alertas');
  assert.match(texto.titulo, /Passagem solitária, Troca provável/);
  assert.match(texto.titulo, /1 deles pode mudar a contagem/);
  assert.equal(textoDosAlertas(sinaisDoCarro({ carro: 99, voltas: 0 }, estado, [])), null, 'no flag, no text');
});

test('confirmed short laps are a signal of the row', () => {
  const carro = { carro: 1, voltas: 5, passagens: 6, voltas_curtas_confirmadas: [{ numero_volta: 4, ts_ms: 1, volta_ms: 23000 }] };
  assert.equal(sinaisDoCarro(carro, estadoCom([]), []).curtas, 1);
});

// ---- sorting ------------------------------------------------------------------------

test('a header cycles: its natural direction, the other one, then back to the standings', () => {
  let ordem = proximaOrdem(null, 'voltas');
  assert.deepEqual(ordem, { coluna: 'voltas', direcao: 'desc' }, 'most laps first');
  ordem = proximaOrdem(ordem, 'voltas');
  assert.deepEqual(ordem, { coluna: 'voltas', direcao: 'asc' });
  assert.equal(proximaOrdem(ordem, 'voltas'), null);

  assert.deepEqual(proximaOrdem(null, 'carro'), { coluna: 'carro', direcao: 'asc' });
  assert.deepEqual(proximaOrdem({ coluna: 'carro', direcao: 'asc' }, 'equipe'), { coluna: 'equipe', direcao: 'asc' },
    'another header starts over');
});

test('the header in use says so to a screen reader and with an arrow', () => {
  assert.deepEqual(indicadorDaOrdem({ coluna: 'voltas', direcao: 'desc' }, 'voltas'), { ariaSort: 'descending', seta: '▼' });
  assert.deepEqual(indicadorDaOrdem({ coluna: 'voltas', direcao: 'asc' }, 'voltas'), { ariaSort: 'ascending', seta: '▲' });
  assert.deepEqual(indicadorDaOrdem({ coluna: 'voltas', direcao: 'asc' }, 'carro'), { ariaSort: null, seta: '' });
});

const CARROS = [
  { carro: 7, posicao: 1, equipe: 'Zeta', voltas: 40, passagens: 41, melhor_volta_ms: 95000, classificado: true, status: 'correndo' },
  { carro: 12, posicao: 2, equipe: 'alfa', voltas: 39, passagens: 40, melhor_volta_ms: null, classificado: true, status: 'parado' },
  { carro: 3, posicao: 3, equipe: 'Beta', voltas: 39, passagens: 40, melhor_volta_ms: 91000, classificado: true, status: 'correndo' },
  { carro: 105, posicao: null, equipe: null, voltas: 0, passagens: 0, melhor_volta_ms: null, classificado: false },
  { carro: 2, posicao: null, equipe: 'Gama', voltas: 0, passagens: 0, melhor_volta_ms: 80000, classificado: false },
];
const numeros = (lista) => lista.map((carro) => carro.carro);
const contexto = { estado: estadoCom([]), colunas: [] };

test('with no choice the rows are the standings, cars not taking part at the end', () => {
  assert.deepEqual(numeros(ordenarCarros(CARROS, null, contexto)), [7, 12, 3, 105, 2]);
});

test('numbers sort as numbers, not as text', () => {
  assert.deepEqual(numeros(ordenarCarros(CARROS, { coluna: 'carro', direcao: 'asc' }, contexto)), [3, 7, 12, 2, 105]);
  assert.deepEqual(numeros(ordenarCarros(CARROS, { coluna: 'carro', direcao: 'desc' }, contexto)), [12, 7, 3, 105, 2]);
});

test('times sort with the cars that have none last, in either direction', () => {
  assert.deepEqual(numeros(ordenarCarros(CARROS, { coluna: 'melhor_volta', direcao: 'asc' }, contexto)), [3, 7, 12, 2, 105]);
  assert.deepEqual(numeros(ordenarCarros(CARROS, { coluna: 'melhor_volta', direcao: 'desc' }, contexto)), [7, 3, 12, 2, 105]);
});

test('a car that does not take part stays at the bottom whatever the order', () => {
  // Car 2 has the best lap of all, yet it is not classified.
  const ordenados = numeros(ordenarCarros(CARROS, { coluna: 'melhor_volta', direcao: 'asc' }, contexto));
  assert.deepEqual(ordenados.slice(-2).sort(), [105, 2].sort());
});

test('ties keep the standings order, so rows never swap between two redraws', () => {
  // Cars 12 and 3 both have 39 laps; 12 is ahead in the standings.
  assert.deepEqual(numeros(ordenarCarros(CARROS, { coluna: 'voltas', direcao: 'desc' }, contexto)).slice(0, 3), [7, 12, 3]);
  assert.deepEqual(numeros(ordenarCarros(CARROS, { coluna: 'voltas', direcao: 'asc' }, contexto)).slice(0, 3), [12, 3, 7]);
});

test('team names sort as a person would, ignoring case', () => {
  assert.deepEqual(numeros(ordenarCarros(CARROS, { coluna: 'equipe', direcao: 'asc' }, contexto)).slice(0, 3), [12, 3, 7]);
});

test('the status column puts a stopped car first', () => {
  assert.equal(ordenarCarros(CARROS, { coluna: 'situacao', direcao: 'asc' }, contexto)[0].carro, 12);
});

test('a source column sorts by the difference, the counter who missed most first', () => {
  const estado = estadoCom([fonte(1)]);
  const colunas = colunasDeFontes(estado);
  const carros = [
    { carro: 1, posicao: 1, voltas: 9, passagens: 10, contagem_por_fonte: { 1: 10 }, classificado: true },
    { carro: 2, posicao: 2, voltas: 9, passagens: 10, contagem_por_fonte: { 1: 7 }, classificado: true },
  ];
  assert.deepEqual(numeros(ordenarCarros(carros, { coluna: 'fonte:1', direcao: 'asc' }, { estado, colunas })), [2, 1]);
});

test('the "Avisos" column puts a car whose count is in doubt first', () => {
  const estado = estadoCom([fonte(1), fonte(2)], {
    alertas_abertos: [
      { id: 'a', tipo: 'repetida', carros: [1] },
      { id: 'b', tipo: 'repetida', carros: [1] },
      { id: 'c', tipo: 'solitaria', carros: [3], contagem_em_duvida: 'a_menos' },
    ],
  });
  const colunas = colunasDeFontes(estado);
  const carros = [1, 2, 3].map((numero, indice) => ({
    carro: numero, posicao: indice + 1, voltas: 5, passagens: 6, contagem_por_fonte: { 1: 6, 2: 6 }, classificado: true,
  }));
  assert.deepEqual(numeros(ordenarCarros(carros, { coluna: 'avisos', direcao: 'desc' }, { estado, colunas })), [3, 1, 2]);
});

test('an order about a source column that is gone falls back to the standings', () => {
  const colunas = colunasDeFontes(estadoCom([fonte(1)]));
  assert.equal(ordemEmVigor({ coluna: 'fonte:2', direcao: 'asc' }, colunas), null);
  assert.deepEqual(ordemEmVigor({ coluna: 'fonte:1', direcao: 'asc' }, colunas), { coluna: 'fonte:1', direcao: 'asc' });
  assert.equal(ordemEmVigor({ coluna: 'inventada', direcao: 'asc' }, colunas), null);
});

// ---- remembered across reloads ------------------------------------------------------

function armazenamentoFalso() {
  const dados = new Map();
  return {
    dados,
    getItem: (chave) => (dados.has(chave) ? dados.get(chave) : null),
    setItem: (chave, valor) => dados.set(chave, String(valor)),
    removeItem: (chave) => dados.delete(chave),
  };
}

test('the chosen order is remembered per prova, and going back to the standings forgets it', () => {
  const armazenamento = armazenamentoFalso();
  guardarOrdem(armazenamento, 'END', { coluna: 'melhor_volta', direcao: 'asc' });
  assert.deepEqual(lerOrdem(armazenamento, 'END'), { coluna: 'melhor_volta', direcao: 'asc' });
  assert.equal(lerOrdem(armazenamento, 'ACEL'), null, 'another prova has its own');
  guardarOrdem(armazenamento, 'END', null);
  assert.equal(armazenamento.dados.has(`${CHAVE_DA_ORDEM}:END`), false);
});

test('blocked or broken storage simply means the standings', () => {
  const bloqueado = {
    getItem() { throw new Error('SecurityError'); },
    setItem() { throw new Error('QuotaExceededError'); },
    removeItem() { throw new Error('SecurityError'); },
  };
  assert.equal(lerOrdem(bloqueado, 'END'), null);
  assert.doesNotThrow(() => guardarOrdem(bloqueado, 'END', { coluna: 'carro', direcao: 'asc' }));
  assert.equal(lerOrdem(null, 'END'), null, 'no storage at all');
  assert.doesNotThrow(() => guardarOrdem(null, 'END', null));

  const lixo = armazenamentoFalso();
  lixo.setItem(`${CHAVE_DA_ORDEM}:END`, '{"coluna": 3}');
  assert.equal(lerOrdem(lixo, 'END'), null);
  lixo.setItem(`${CHAVE_DA_ORDEM}:END`, 'não é JSON');
  assert.equal(lerOrdem(lixo, 'END'), null);
});

// At QA cars parked in the box overnight read "no box desde -9:39:47".
test('a stay that began before the start reads "desde antes da largada"', () => {
  assert.equal(desdeDoStatus({ status: 'box', status_desde_ms: LARGADA - 34_787_000 }, LARGADA), ' desde antes da largada');
  assert.equal(desdeDoStatus({ status: 'box', status_desde_ms: LARGADA + 3_725_000 }, LARGADA), ' desde 1:02:05');
  assert.equal(desdeDoStatus({ status: 'normal', status_desde_ms: null }, LARGADA), '');
});
