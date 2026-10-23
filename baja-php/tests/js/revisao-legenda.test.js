// Tests for the legend under the timeline: sample chips instead of a wall
// of text. The user asked for "actual styled boxes": each sample must look
// exactly like the chips it explains, so it is built by the lanes' own
// functions, and these tests check every sample gets the class it is there
// to show.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  AMOSTRAS_DA_LEGENDA, CHAVE_DA_LEGENDA, chipDaAmostra, guardarLegendaAberta, itensDaLegenda, legendaAberta, oQueAProvaTem,
} from '../../default/js/contagem/revisao-legenda-logica.js';
import { classesDoChip, marcadoresDoChip } from '../../default/js/contagem/revisao-raias-logica.js';

test('every sample gets the class it explains, as classesDoChip gives it', () => {
  for (const amostra of AMOSTRAS_DA_LEGENDA) {
    const chip = chipDaAmostra(amostra);
    assert.ok(chip.classes.includes(amostra.marca), `${amostra.rotulo}: ${chip.classes.join(' ')} has no ${amostra.marca}`);
    const destaque = amostra.destaque ?? {};
    assert.deepEqual(chip.classes, classesDoChip(amostra.entrada, destaque.idsEmDestaque ?? [], destaque.selecionadaId ?? null,
      destaque.carrosEmFoco ?? [], { naRaiaDoSensor: Boolean(amostra.naRaiaDoSensor), candidatos: amostra.candidatos }));
    // Short words on screen, the explanation in the tooltip.
    assert.ok(amostra.rotulo.split(' ').length <= 3, `${amostra.rotulo} is more than three words`);
    assert.ok(amostra.dica.length > amostra.rotulo.length);
  }
});

test('no two samples show the same thing', () => {
  const marcas = AMOSTRAS_DA_LEGENDA.map((amostra) => amostra.marca);
  assert.equal(new Set(marcas).size, marcas.length);
});

test('the corner markers are the lanes\' own', () => {
  const porMarca = (marca) => chipDaAmostra(AMOSTRAS_DA_LEGENDA.find((amostra) => amostra.marca === marca));
  assert.deepEqual(porMarca('tempo-do-sensor').marcadores, [{ classe: 'marcador marcador-sensor', texto: 'S' }]);
  assert.deepEqual(porMarca('ilegivel-automatico').marcadores.map((m) => m.texto), ['auto']);
  assert.deepEqual(porMarca('ilegivel-contador').marcadores.map((m) => m.texto), ['C']);
  assert.deepEqual(porMarca('ilegivel-revisor').marcadores.map((m) => m.texto), ['R']);
  assert.deepEqual(porMarca('candidato').marcadores.map((m) => m.texto), ['1']);
  assert.equal(porMarca('ilegivel-pendente').texto, '??');
  // A sensor's mark is a tick with no text.
  assert.equal(porMarca('com-passagem').texto, '');
  assert.ok(porMarca('com-passagem').classes.includes('marca-sensor'));
  // The same function the lanes call.
  const amostra = AMOSTRAS_DA_LEGENDA.find((item) => item.marca === 'tempo-do-sensor');
  assert.deepEqual(porMarca('tempo-do-sensor').marcadores, marcadoresDoChip(amostra.entrada, false, {}));
});

test('a race with no "??", no sensor and no observer gets a legend without them', () => {
  const simples = itensDaLegenda(oQueAProvaTem({ fontes: [{ tipo: 'manual', papel: 'votante', stats: { ilegiveis: 0 } }] }));
  assert.deepEqual(simples.map((grupo) => grupo.id), ['passagens', 'anuladas', 'revisao', 'destaque']);
  assert.ok(!simples.flatMap((grupo) => grupo.itens).some((item) => item.marca === 'observadora'));
  // Short enough for one or two lines on a PC.
  assert.ok(simples.flatMap((grupo) => grupo.itens).length <= 18);
});

test('the "??" and sensor samples come with a "??", a camera or a sensor', () => {
  const comIlegivel = oQueAProvaTem({ fontes: [{ tipo: 'manual', stats: { ilegiveis: 3 } }] });
  assert.deepEqual(comIlegivel, { ilegivel: true, sensor: false, observadora: false });
  assert.equal(oQueAProvaTem({ fontes: [{ tipo: 'automatica', papel: 'observadora' }] }).ilegivel, true);
  assert.equal(oQueAProvaTem({ fontes: [], alertas_resolvidos: [{ tipo: 'numero_atribuido' }] }).ilegivel, true);

  const tudo = itensDaLegenda(oQueAProvaTem({ fontes: [{ tipo: 'sensor', papel: 'observadora' }, { tipo: 'manual', stats: { ilegiveis: 1 } }] }));
  assert.deepEqual(tudo.map((grupo) => grupo.id), ['passagens', 'anuladas', 'revisao', 'destaque', 'ilegivel', 'sensor']);
  assert.ok(tudo.flatMap((grupo) => grupo.itens).some((item) => item.marca === 'observadora'));
  assert.equal(itensDaLegenda().length, 4, 'without a state: the plain legend');
});

test('open or closed is remembered, and a blocked storage is no error', () => {
  const guardado = new Map();
  const armazenamento = { getItem: (chave) => guardado.get(chave) ?? null, setItem: (chave, valor) => guardado.set(chave, valor) };
  assert.equal(legendaAberta(armazenamento), false, 'closed the first time');
  guardarLegendaAberta(armazenamento, true);
  assert.equal(guardado.get(CHAVE_DA_LEGENDA), '1');
  assert.equal(legendaAberta(armazenamento), true);

  const bloqueado = { getItem: () => { throw new Error('SecurityError'); }, setItem: () => { throw new Error('QuotaExceeded'); } };
  assert.equal(legendaAberta(bloqueado), false);
  assert.doesNotThrow(() => guardarLegendaAberta(bloqueado, true));
  assert.equal(legendaAberta(null), false);
});
