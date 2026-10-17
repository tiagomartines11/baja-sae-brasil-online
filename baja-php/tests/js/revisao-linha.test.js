// Tests for the permanent timeline of the review screen.
//
// Until 26SE the timeline only existed inside the detail panel, and only
// after a flag or a car was picked; "Fechar" threw it away, and it sat
// under the flag's title and buttons, so those moved from flag to flag.
// Now it is always on screen, above everything: with nothing picked it
// follows the race; a flag scrolls and zooms it to the flag's moment; a
// car shows that car. What it shows is loaded in pieces of two minutes on
// a fixed grid and kept, so scrolling back and forth, or opening one flag
// after another, does not ask the server again every time.
//
// These tests check which stretch is shown, which pieces are asked for and
// when, how the pieces are joined, where the view scrolls to, and what the
// timeline says about a lone entry's previous pass. The drawing itself
// needs a browser and is not tested here.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  ALEM_DO_AGORA_MS, ATUALIZAR_NO_MAXIMO_A_CADA_MS, ESCALA_AO_VIVO, LARGURA_DO_NOME_PX, MAXIMO_DE_PEDACOS, PEDACO_MS,
  aindaSeguindo, centroAoVerTodos, destaqueDaLinha, estenderFaixa, faixaAoVivo, faixaEntre, inicioDoPedaco, intervaloDoPedaco, juntarPedacos,
  lacunaDaPassagemAnterior, limitesDaFaixa, modoDaLinha, pedacosAPedir, pedacosDaFaixa, pedacosVisiveis, rolagemPara,
  rolagemParaAlerta, situacaoDaLinha, trechoVisivel,
} from '../../default/js/contagem/revisao-linha-logica.js';
import { ESCALA_ALERTA, ESCALA_CARRO } from '../../default/js/contagem/revisao-raias-logica.js';

const MIN = 60 * 1000;
// A moment on the grid, so the arithmetic below is easy to follow.
const T0 = 1_700_000_040_000 - (1_700_000_040_000 % PEDACO_MS);

// ---- what is shown ----------------------------------------------------------------

test('with nothing picked the timeline follows the race at a readable scale', () => {
  const modo = modoDaLinha(null, null);
  assert.equal(modo.fonte, 'pedacos');
  assert.equal(modo.aoVivo, true);
  assert.equal(modo.escala, ESCALA_AO_VIVO);
  assert.match(situacaoDaLinha(modo, null, null), /ao vivo/);
});

test('a flag zooms the same timeline to its moment instead of replacing it', () => {
  const alerta = { id: 'solitaria:12', tipo: 'solitaria', carros: [12], ts_ms: T0, passagem_ids: [5] };
  const modo = modoDaLinha({ tipo: 'alerta', id: alerta.id }, alerta);
  assert.equal(modo.fonte, 'pedacos', 'still the pieces of time, all cars');
  assert.equal(modo.aoVivo, false);
  assert.equal(modo.escala, ESCALA_ALERTA);
});

test('a flag of unknown time and a flag about the whole race keep their old views', () => {
  // An entry whose time is only its phone's guess: the car's whole race.
  const semHorario = { id: 'tempo_desconhecido:9', tipo: 'tempo_desconhecido', carros: [9], ts_ms: T0 };
  const modo = modoDaLinha({ tipo: 'alerta', id: semHorario.id }, semHorario);
  assert.deepEqual([modo.fonte, modo.carro, modo.escala], ['carro', 9, ESCALA_CARRO]);

  // A difference to a paper total has no moment: the timeline stays live.
  const total = { id: 'divergencia_total:4', tipo: 'divergencia_total', carros: [4], ts_ms: null };
  assert.equal(modoDaLinha({ tipo: 'alerta', id: total.id }, total).aoVivo, true);
});

test('a car shows its own race, and "só o carro" off shows it among all the others', () => {
  const selecao = { tipo: 'carro', numero: 20 };
  const so = modoDaLinha(selecao, null, true);
  assert.deepEqual([so.fonte, so.carro, so.escala], ['carro', 20, ESCALA_CARRO]);

  const todos = modoDaLinha(selecao, null, false);
  assert.equal(todos.fonte, 'pedacos');
  assert.notEqual(todos.chave, so.chave, 'switching is a change of view');
  assert.deepEqual(destaqueDaLinha(selecao, null, todos), { ids: [], carros: [20] }, 'the car stands out, the others are dimmed');
  assert.deepEqual(destaqueDaLinha(selecao, null, so), { ids: [], carros: [] }, 'in its own race nothing is dimmed');
});

test('a flag highlights its entries strongly and its cars weakly', () => {
  const alerta = { id: 'troca_provavel:7', carros: [7, 17], ts_ms: T0, passagem_ids: [70, 71] };
  const modo = modoDaLinha({ tipo: 'alerta', id: alerta.id }, alerta);
  assert.deepEqual(destaqueDaLinha({ tipo: 'alerta', id: alerta.id }, alerta, modo), { ids: [70, 71], carros: [7, 17] });
  assert.deepEqual(destaqueDaLinha(null, null, modoDaLinha(null, null)), { ids: [], carros: [] });
});

// ---- the pieces --------------------------------------------------------------------

test('the race is cut into pieces on a fixed grid that do not overlap', () => {
  assert.equal(inicioDoPedaco(T0 + 1), T0);
  assert.equal(inicioDoPedaco(T0 + PEDACO_MS), T0 + PEDACO_MS);
  // The server includes both ends, so a piece stops a millisecond short of the next.
  assert.deepEqual(intervaloDoPedaco(T0), { de: T0, ate: T0 + PEDACO_MS - 1 });
  assert.deepEqual(pedacosDaFaixa(faixaEntre(T0 + 10, T0 + 2 * PEDACO_MS + 5)), [T0, T0 + PEDACO_MS, T0 + 2 * PEDACO_MS]);
  assert.ok(PEDACO_MS <= 30 * MIN, 'the server refuses windows longer than 30 minutes');
});

test('live, the timeline holds the last five minutes and a little past now', () => {
  const agora = T0 + 7 * MIN + 20000;
  const faixa = faixaAoVivo(agora);
  const limites = limitesDaFaixa(faixa);
  assert.ok(limites.de <= agora - 5 * MIN);
  assert.ok(limites.ate >= agora + ALEM_DO_AGORA_MS, 'now never sits at the very edge of the lanes');
  assert.ok(pedacosDaFaixa(faixa).length <= 5);
});

test('a piece is asked for once, then only again when the state changed, and not on every poll', () => {
  const cache = new Map();
  const emVoo = new Set();
  // Never loaded: asked for.
  assert.deepEqual(pedacosAPedir([T0, T0 + PEDACO_MS], cache, emVoo, 'v1', 1000), [T0, T0 + PEDACO_MS]);

  // One loaded at this version, the other on its way: nothing to ask.
  cache.set(T0, { versao: 'v1', buscadoEmMs: 1000, erro: false });
  emVoo.add(T0 + PEDACO_MS);
  assert.deepEqual(pedacosAPedir([T0, T0 + PEDACO_MS], cache, emVoo, 'v1', 2000), []);

  // The state moved on a second later (an upload about another car): not yet.
  assert.deepEqual(pedacosAPedir([T0], cache, emVoo, 'v2', 2000), []);
  // A few seconds later: asked again.
  assert.deepEqual(pedacosAPedir([T0], cache, emVoo, 'v2', 1000 + ATUALIZAR_NO_MAXIMO_A_CADA_MS), [T0]);

  // A piece that failed is tried again after the same pause.
  cache.set(T0, { versao: 'v2', buscadoEmMs: 9000, erro: true });
  assert.deepEqual(pedacosAPedir([T0], cache, emVoo, 'v2', 9500), []);
  assert.deepEqual(pedacosAPedir([T0], cache, emVoo, 'v2', 9000 + ATUALIZAR_NO_MAXIMO_A_CADA_MS), [T0]);
});

test('only the pieces in view are asked for', () => {
  const faixa = faixaEntre(T0, T0 + 10 * PEDACO_MS);
  const trecho = { de: T0 + 4 * PEDACO_MS + 70000, ate: T0 + 5 * PEDACO_MS + 10000 };
  assert.deepEqual(pedacosVisiveis(faixa, trecho), [T0 + 4 * PEDACO_MS, T0 + 5 * PEDACO_MS]);
  // Half a piece to spare on each side, so a small scroll finds it loaded.
  const naBorda = { de: T0 + 4 * PEDACO_MS + 10000, ate: T0 + 5 * PEDACO_MS + 10000 };
  assert.deepEqual(pedacosVisiveis(faixa, naBorda), [T0 + 3 * PEDACO_MS, T0 + 4 * PEDACO_MS, T0 + 5 * PEDACO_MS]);
});

test('pieces are joined into one window: lanes by source, an entry on the border kept once', () => {
  const lane = (fonte, nome, entradas) => ({ fonte_id: fonte, nome, ativa: true, papel: 'votante', entradas });
  const primeiro = {
    de_ms: T0, ate_ms: T0 + PEDACO_MS - 1, largada_ms: T0 - 10 * MIN, abastecimento: false,
    raias: [lane(1, 'Contagem 1', [{ id: 10, ts_ms: T0 + 1000 }]), lane(2, 'Contagem 2', [{ id: 11, ts_ms: T0 + 1500 }])],
    ocorrencias: [{ id: 3, tipo: 'box_entrada', carro: 5, ts_ms: T0 + 2000 }],
    estadias: [{ area: 'box', carro: 5, de_ms: T0 + 2000, ate_ms: null }],
    paradas_sem_horario: [],
  };
  const segundo = {
    de_ms: T0 + PEDACO_MS, ate_ms: T0 + 2 * PEDACO_MS - 1, largada_ms: T0 - 10 * MIN, abastecimento: true,
    raias: [
      lane(2, 'Contagem 2 (troca)', [{ id: 11, ts_ms: T0 + 1500 }, { id: 13, ts_ms: T0 + PEDACO_MS + 100 }]),
      lane(1, 'Contagem 1', [{ id: 12, ts_ms: T0 + PEDACO_MS + 50 }]),
      lane(-1, 'Revisor', [{ id: -4, ts_ms: T0 + PEDACO_MS + 900 }]),
    ],
    ocorrencias: [{ id: 3, tipo: 'box_entrada', carro: 5, ts_ms: T0 + 2000 }, { id: 4, tipo: 'box_saida', carro: 5, ts_ms: T0 + PEDACO_MS + 10 }],
    estadias: [{ area: 'box', carro: 5, de_ms: T0 + 2000, ate_ms: T0 + PEDACO_MS + 10 }],
    paradas_sem_horario: [{ ocorrencia_id: 9, area: 'box', tipo: 'box_saida', carro: 8 }],
  };
  const junta = juntarPedacos([primeiro, segundo], faixaEntre(T0, T0 + PEDACO_MS));

  assert.deepEqual([junta.de_ms, junta.ate_ms], [T0, T0 + 2 * PEDACO_MS], 'the window is the whole stretch');
  assert.deepEqual(junta.raias.map((raia) => raia.fonte_id), [1, 2, -1], 'lanes in the order they first appear');
  assert.equal(junta.raias[1].nome, 'Contagem 2 (troca)', 'the newest piece says what the source is now');
  assert.deepEqual(junta.raias.map((raia) => raia.entradas.map((entrada) => entrada.id)), [[10, 12], [11, 13], [-4]]);
  assert.deepEqual(junta.ocorrencias.map((ocorrencia) => ocorrencia.id), [3, 4]);
  assert.deepEqual(junta.estadias, [{ area: 'box', carro: 5, de_ms: T0 + 2000, ate_ms: T0 + PEDACO_MS + 10 }],
    'a stay seen by both pieces once, as the newer one says it ended');
  assert.equal(junta.abastecimento, true);
  assert.equal(junta.paradas_sem_horario.length, 1);
  assert.equal(junta.largada_ms, T0 - 10 * MIN);
});

test('no piece loaded yet still gives the stretch, with no lanes', () => {
  const junta = juntarPedacos([], faixaEntre(T0, T0));
  assert.deepEqual([junta.de_ms, junta.ate_ms, junta.raias.length], [T0, T0 + PEDACO_MS, 0]);
});

// ---- scrolling ---------------------------------------------------------------------

test('what is in view, and where a moment goes on screen', () => {
  // 1110 px wide, of which the lane names cover 110: 1000 px of lanes, 125 s at 8 px/s.
  const largura = 1000 + LARGURA_DO_NOME_PX;
  const trecho = trechoVisivel(800, largura, T0, 8);
  assert.deepEqual(trecho, { de: T0 + 100000, ate: T0 + 225000 });

  assert.equal(rolagemPara(T0 + 225000, 1, T0, 8, largura), 800, 'at the right edge');
  assert.equal(rolagemPara(T0 + 10000, 0.5, T0, 8, largura), 0, 'never before the start of the lanes');
});

test('a flag of about a minute is centred; one reaching back to the previous pass keeps the flag near the right edge', () => {
  const largura = 1000 + LARGURA_DO_NOME_PX;
  // 60 s at 14 px/s = 840 px: fits. Its middle at the middle of the view.
  const curta = { de: T0 + 100000, ate: T0 + 160000 };
  assert.equal(rolagemParaAlerta(curta, T0 + 130000, T0, 14, largura), rolagemPara(T0 + 130000, 0.5, T0, 14, largura));

  // 3 min back to the previous pass: 2520 px, too wide. The flag at 85%.
  const longa = { de: T0, ate: T0 + 180000 };
  assert.equal(rolagemParaAlerta(longa, T0 + 150000, T0, 14, largura), rolagemPara(T0 + 150000, 0.85, T0, 14, largura));

  // On a phone (252 px of lanes) 85% would cut the flag's own chip in half: it keeps 60 px.
  const telefone = 252 + LARGURA_DO_NOME_PX;
  assert.equal(rolagemParaAlerta(longa, T0 + 150000, T0, 14, telefone), 2100 - (252 - 60));
});

test('switching "só o carro" off goes to the chip picked, or the car\'s last pass', () => {
  assert.equal(centroAoVerTodos({ id: 4, ts_ms: T0 + 5000 }, T0 + 90000, T0 + 100000), T0 + 5000);
  assert.equal(centroAoVerTodos(null, T0 + 90000, T0 + 100000), T0 + 90000);
  assert.equal(centroAoVerTodos(null, null, T0 + 100000), T0 + 100000, 'a car that never crossed: now');
});

test('scrolling to an edge grows the stretch by one piece, never past now or long before the start', () => {
  const faixa = faixaEntre(T0 + 10 * PEDACO_MS, T0 + 12 * PEDACO_MS);
  const agora = T0 + 12 * PEDACO_MS + 30000;

  // Near the left edge: one more piece before.
  const antes = estenderFaixa(faixa, { de: T0 + 10 * PEDACO_MS + 5000, ate: T0 + 11 * PEDACO_MS }, T0, agora);
  assert.deepEqual(antes, { primeiro: T0 + 9 * PEDACO_MS, ultimo: T0 + 12 * PEDACO_MS });

  // Down at the limit (ten minutes before the start): stays.
  const noLimite = estenderFaixa(faixa, { de: T0 + 10 * PEDACO_MS + 5000, ate: T0 + 11 * PEDACO_MS }, T0 + 10 * PEDACO_MS, agora);
  assert.equal(noLimite, faixa, 'the same object: nothing to redraw');

  // At the right edge with now inside the last piece: nothing after it yet.
  assert.equal(estenderFaixa(faixa, { de: T0 + 11 * PEDACO_MS, ate: T0 + 13 * PEDACO_MS - 1000 }, T0, agora), faixa);
  // Once now reaches the next piece, the live view grows with it.
  const depois = estenderFaixa(faixa, { de: T0 + 11 * PEDACO_MS, ate: T0 + 13 * PEDACO_MS - 1000 }, T0, T0 + 13 * PEDACO_MS + 1);
  assert.deepEqual(depois, { primeiro: T0 + 10 * PEDACO_MS, ultimo: T0 + 13 * PEDACO_MS });
});

test('a long scroll back keeps at most half an hour, letting go of what is furthest from the view', () => {
  const faixa = { primeiro: T0, ultimo: T0 + (MAXIMO_DE_PEDACOS - 1) * PEDACO_MS };
  const agora = T0 + 100 * PEDACO_MS;
  const nova = estenderFaixa(faixa, { de: T0 + 1000, ate: T0 + PEDACO_MS }, T0 - 50 * PEDACO_MS, agora);
  assert.deepEqual(nova, { primeiro: T0 - PEDACO_MS, ultimo: T0 + (MAXIMO_DE_PEDACOS - 2) * PEDACO_MS });
});

test('the live view keeps following until the reviewer scrolls back', () => {
  assert.equal(aindaSeguindo(1000, 1000), true);
  assert.equal(aindaSeguindo(985, 1000), true, 'a few pixels short is still following');
  assert.equal(aindaSeguindo(600, 1000), false);
});

// ---- the lap before a lone entry -------------------------------------------------------

// SITUATION (26SE): a lone entry for car 12 came 76 s after the car's
// previous official pass, and the car laps in about 3:40. At a race a 75 s
// lap was confirmed as real: nothing on screen said how short it was.
//
// EXPECTED: the timeline draws the gap and says it in words, with the lap
// the car normally does (the expected lap the car table already has).
test('the gap to the car\'s previous official pass is drawn and said in words', () => {
  const alerta = { ts_ms: T0 + 76000, passagem_anterior: { passagem_id: 40, ts_ms: T0 } };
  assert.deepEqual(lacunaDaPassagemAnterior(alerta, 220000), {
    de: T0, ate: T0 + 76000, texto: '76 s desde a passagem anterior; volta normal ~3:40',
  });
  assert.equal(lacunaDaPassagemAnterior({ ...alerta, ts_ms: T0 + 200000 }, null).texto, '3:20 desde a passagem anterior',
    'without the pace, only the gap');
  assert.equal(lacunaDaPassagemAnterior({ ts_ms: T0, passagem_anterior: null }, 220000), null);
  assert.equal(lacunaDaPassagemAnterior({ ts_ms: T0 }, 220000), null);
});
