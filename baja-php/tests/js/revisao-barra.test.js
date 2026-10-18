// Tests for what the status bar of the review screen says.
//
// The bar is redrawn every second (field note N8). Its pills used to change
// width all the time: "publicado há agora", "há 9 s", "há 12 s"; "calculado
// há 4 s", "há 5 s"; a source chip that flipped between "ao vivo" and "ao
// vivo, sincronizado há 3 s" with every heartbeat. The bar wrapped onto a
// new line and back, and everything below it jumped. These tests pin the
// shape of each text: a fixed label, and ages that move in steps.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  HORARIO_SINCRONIZANDO, RELOGIO_SINCRONIZANDO, avisosDosResultados, descreverCalculo, descreverChip, descreverPontos, descreverPublicacao,
  textoDoHorario,
} from '../../default/js/contagem/revisao-barra.js';
import { relogio } from '../../default/js/contagem/revisao-formato.js';

const AGORA = 1_792_250_000_000;

// At QA the first line slid about 140 px sideways whenever the official
// clock dropped its anchor: "sincronizando…" is wider than a time.
test('while the clock synchronises, the clocks show dashes of their own shape', () => {
  assert.equal(textoDoHorario(null), HORARIO_SINCRONIZANDO);
  assert.equal(textoDoHorario(AGORA).length, HORARIO_SINCRONIZANDO.length);
  assert.equal(relogio(3600000 + 39 * 60000 + 5000).length, RELOGIO_SINCRONIZANDO.length);
});

test('just published reads "publicado agora", never "publicado há agora"', () => {
  const publicacao = descreverPublicacao({ publicado_em_ms: AGORA - 900 }, AGORA);
  assert.equal(publicacao.rotulo, 'publicado');
  assert.equal(publicacao.idade, 'agora');
});

test('no tooltip of the bar reads "há agora"', () => {
  assert.equal(descreverPublicacao({ publicado_em_ms: AGORA - 500 }, AGORA).titulo, 'publicado agora');
  const calculo = descreverCalculo({ calculado_em_ms: AGORA - 500, corrida: { estado: 'correndo' } }, AGORA);
  assert.match(calculo.titulo, /^Calculado agora\./);
});

test('the age of the publication moves in steps of 15 s, with the exact age in the title', () => {
  const idades = [0, 4, 9, 12, 14].map((segundos) => descreverPublicacao({ publicado_em_ms: AGORA - segundos * 1000 }, AGORA).idade);
  assert.deepEqual(new Set(idades), new Set(['agora']), 'nothing changes in the first 15 s');
  const depois = descreverPublicacao({ publicado_em_ms: AGORA - 40000 }, AGORA);
  assert.equal(depois.idade, 'há 30 s');
  assert.equal(depois.titulo, 'publicado há 40 s');
  assert.equal(descreverPublicacao({ publicado_em_ms: AGORA - 125000 }, AGORA).idade, 'há 2 min');
});

test('publishing switched off is a short red pill; the sentence goes under the bar', () => {
  const estado = { publicacao_desligada: true, publicado_em_ms: AGORA - 3 * 3_600_000 };
  const publicacao = descreverPublicacao(estado, AGORA);
  assert.equal(publicacao.rotulo, 'publicação desligada');
  assert.match(publicacao.classe, /desligada/);
  assert.equal(publicacao.idade, null, '"há 3 h" would read as a fault');
  assert.deepEqual(avisosDosResultados(estado), ['Publicação desligada: os resultados oficiais não estão sendo atualizados.']);
});

test('"calculado" moves in steps too while it is on time, and every second once late', () => {
  const estado = (segundos) => ({
    calculado_em_ms: AGORA - segundos * 1000, configuracoes: { recalculo_s: 5 }, corrida: { estado: 'correndo' },
  });
  assert.equal(descreverCalculo(estado(4), AGORA).idade, 'agora');
  assert.equal(descreverCalculo(estado(4), AGORA).classe, 'calculo');
  // Three recalculation intervals without a new one: something is stuck.
  const atrasado = descreverCalculo(estado(17), AGORA);
  assert.equal(atrasado.classe, 'calculo atrasado');
  assert.equal(atrasado.idade, 'há 17 s');
  assert.equal(descreverCalculo({ calculado_em_ms: null }, AGORA), null);
});

test('a quiet race is not late: after the finish the age simply grows, in steps', () => {
  const estado = { calculado_em_ms: AGORA - 50000, configuracoes: { recalculo_s: 5 }, corrida: { estado: 'encerrada' } };
  assert.deepEqual([descreverCalculo(estado, AGORA).classe, descreverCalculo(estado, AGORA).idade], ['calculo', 'há 45 s']);
});

// SITUATION (26SE): the classification field was DESCLAS, which the scoring
// formula also reads, and every car got 0 points. Round 1 put both warnings
// as long red texts in the bar, where they pushed everything onto a new line.
//
// EXPECTED: a short red pill in the bar ("pontos suspeitos", the sentences
// in its title), and the full sentences in the warning under the bar.
test('suspicious points are a short pill in the bar and full sentences under it', () => {
  const pontuacao = { campo_classificacao_na_formula: 'DESCLAS', todos_os_pontos_zero: true, recalculados_em_ms: AGORA - 60000 };
  const pontos = descreverPontos(pontuacao);
  assert.equal(pontos.texto, 'pontos suspeitos');
  assert.match(pontos.titulo, /DESCLAS/);
  assert.match(pontos.titulo, /todos os pontos publicados são 0/);

  const avisos = avisosDosResultados({ pontuacao });
  assert.equal(avisos.length, 2);
  assert.ok(avisos.every((aviso) => aviso.startsWith('Pontuação: ')));
});

test('with the points in order there is no pill and no warning', () => {
  const pontuacao = { campo_classificacao_na_formula: null, todos_os_pontos_zero: false, recalculados_em_ms: AGORA };
  assert.equal(descreverPontos(pontuacao), null);
  assert.deepEqual(avisosDosResultados({ pontuacao }), []);
  assert.deepEqual(avisosDosResultados({}), [], 'an answer from before the points check');
});

// ---- the source chips ------------------------------------------------------------

const LARGADA = AGORA - 3_600_000;

function contador(extra = {}) {
  return {
    id: 1, nome: 'Contagem 1', tipo: 'manual', ativa: true, papel: 'votante', principal: false,
    stats: { entradas: 300, offline: false, visto_em_ms: AGORA - 1000, nada_a_esperar: false, sincronizado_ate_ms: AGORA - 2000 },
    ...extra,
  };
}

test('a working source: its name and "ao vivo", nothing that changes with each heartbeat', () => {
  const estado = { corrida: { largada_ms: LARGADA }, dispositivos: [{ fonte_id: 1, situacao: 'ativo' }] };
  const chip = descreverChip(contador(), estado, AGORA);
  assert.equal(chip.texto, 'ao vivo');
  assert.equal(chip.idade, null);
  assert.equal(chip.dispositivos, null);
  assert.match(chip.classe, /\bao-vivo\b/);
});

test('the devices that are not simply working are a short fixed mark; which ones is in the title', () => {
  const estado = {
    corrida: { largada_ms: LARGADA },
    dispositivos: [{ fonte_id: 1, situacao: 'ativo' }, { fonte_id: 1, situacao: 'silencioso' }, { fonte_id: 2, situacao: 'revogado' }],
  };
  const chip = descreverChip(contador(), estado, AGORA);
  assert.equal(chip.dispositivos, '· 1 disp.');
  assert.match(chip.titulo, /dispositivos: 1 silencioso/);
});

test('the 26SE "Contagem 3" is red and says it has recorded nothing since the start', () => {
  const contagem3 = contador({
    id: 3, nome: 'Contagem 3',
    stats: { entradas: 0, offline: false, visto_em_ms: LARGADA - 12 * 3_600_000, nada_a_esperar: true, sincronizado_ate_ms: null },
  });
  const estado = { corrida: { largada_ms: LARGADA }, dispositivos: [{ fonte_id: 3, situacao: 'revogado' }] };
  const chip = descreverChip(contagem3, estado, AGORA);
  assert.match(chip.classe, /\bsilenciosa\b/);
  assert.doesNotMatch(chip.classe, /ao-vivo/);
  assert.equal(chip.texto, 'sem registros desde a largada');
});

test('an observer keeps a look of its own and is never the missing counter', () => {
  const camera = contador({
    id: 7, nome: 'Câmera', tipo: 'automatica', papel: 'observadora',
    stats: { entradas: 0, offline: false, visto_em_ms: null, nada_a_esperar: true, sincronizado_ate_ms: null },
  });
  const chip = descreverChip(camera, { corrida: { largada_ms: LARGADA } }, AGORA);
  assert.match(chip.classe, /\bobservadora\b/);
  assert.doesNotMatch(chip.classe, /silenciosa/);
});

test('an open-ended exclusion replaces the state word, in capitals', () => {
  const chip = descreverChip(contador(), { corrida: { largada_ms: LARGADA } }, AGORA, new Set([1]));
  assert.equal(chip.texto, 'EXCLUÍDA');
  assert.match(chip.classe, /\bexcluida\b/);
  assert.match(chip.titulo, /até segunda ordem/);
});
