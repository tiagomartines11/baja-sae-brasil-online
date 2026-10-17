// Tests for machine vision on the review screen: an observer source (a
// camera on trial, compared with the official count and never counted) and
// the pictures an automatic source may link to its entries.
//
// Both are off unless configured: with no observer the server sends no
// 'observacao', with no image base URL no 'imagem_url', and the screen must
// then look exactly as before.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  SEM_IMAGEM_NA_REDE, ehObservadora, fontesDaTabela, imagemDaEntrada, linhasDaObservacao,
} from '../../default/js/contagem/revisao-observacao.js';
import { descricaoDoChip } from '../../default/js/contagem/revisao-raias-logica.js';

const contador = { id: 1, nome: 'Contagem 1', tipo: 'manual', ativa: true, papel: 'votante' };
const camera = { id: 4, nome: 'Câmera', tipo: 'automatica', ativa: true, papel: 'observadora' };

test('an observer gets no column in the car table; the counting sources keep theirs', () => {
  // Its passes are no part of any car's count: a column of them would be
  // read as a disagreement with the official number.
  const desligada = { ...contador, id: 2, ativa: false };
  assert.deepEqual(fontesDaTabela([contador, camera, desligada]).map((fonte) => fonte.id), [1]);
  assert.equal(ehObservadora(camera), true);
  assert.equal(ehObservadora(contador), false);
});

test('a source from an answer without "papel" is a counting source, as before', () => {
  const antiga = { id: 3, nome: 'Contagem 3', tipo: 'manual', ativa: true };
  assert.deepEqual(fontesDaTabela([antiga]).map((fonte) => fonte.id), [3]);
});

test('the observer comparison is told in plain lines, offsets in seconds with a comma', () => {
  const linhas = linhasDaObservacao({
    entradas: 8, acertos: 6, faltas: 1, falsos_positivos: 1, leituras_trocadas: 1,
    desvio_mediano_ms: 300, desvio_p90_ms: 400,
    trocas_frequentes: [{ carro_lido: 8, carro_real: 6, vezes: 1 }],
  });
  assert.deepEqual(linhas, [
    ['acertos', '6 de 8'],
    ['passagens oficiais não vistas', '1'],
    ['falsos positivos', '1'],
    ['números lidos errado', '1'],
    ['trocas mais comuns', '6 lido como 8 (1×)'],
    ['diferença para o horário oficial', 'mediana +0,3 s; 90% dentro de 0,4 s'],
  ]);
});

test('an observer with no hit yet has no offset line, and a counting source has no comparison at all', () => {
  const linhas = linhasDaObservacao({
    entradas: 2, acertos: 0, faltas: 0, falsos_positivos: 2, leituras_trocadas: 0,
    desvio_mediano_ms: null, desvio_p90_ms: null, trocas_frequentes: [],
  });
  assert.equal(linhas.length, 4);
  assert.equal(linhasDaObservacao(undefined), null);
});

test('an entry shows a picture only when the server linked one', () => {
  assert.equal(imagemDaEntrada({ id: 1, carro: 41 }), null);
  assert.deepEqual(imagemDaEntrada({ id: 2, carro: 41, imagem_url: 'http://camera.local/img/abc', confianca: 0.873 }),
    { url: 'http://camera.local/img/abc', confianca: 'confiança 87%' });
  assert.deepEqual(imagemDaEntrada({ id: 3, carro: 41, imagem_url: 'http://camera.local/img/def', confianca: null }),
    { url: 'http://camera.local/img/def', confianca: null });
  assert.equal(SEM_IMAGEM_NA_REDE, 'imagem só na rede local');
});

test('a chip says it has a picture, and why an observer\'s entry is not in use', () => {
  const entrada = {
    id: 9, carro: 41, carro_digitado: 41, fora_de_uso: true, motivo_fora_de_uso: 'fonte_observadora',
    grupo: null, imagem_url: 'http://camera.local/img/x',
  };
  const texto = descricaoDoChip(entrada);
  assert.match(texto, /fonte observadora: só comparada com a contagem oficial, nunca conta/);
  assert.match(texto, /com imagem/);
  assert.doesNotMatch(descricaoDoChip({ ...entrada, imagem_url: undefined }), /imagem/);
});
