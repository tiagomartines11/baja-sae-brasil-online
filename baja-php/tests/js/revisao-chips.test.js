// Tests for what a chip of the timeline tells at a glance, in its tooltip
// and in its menu.
//
// A chip is one entry: "counter 2 typed car 41 at this moment". At 26SE
// the user noted that chips said too little: a counter's own void looked
// like a reviewer's, a pass counted on a reviewer's word looked like one
// two counters agreed on, the tooltip said "volta 22" without the lap time,
// and the short laps a reviewer confirmed (how cars 1, 3 and 20 got a lap
// too many) were only undone by digging in the history.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import { classesDoChip, descricaoDoChip } from '../../default/js/contagem/revisao-raias-logica.js';
import { acoesDaEntrada, perguntaAoAgirNaEntrada, rotuloDeDesfazerConfirmacao } from '../../default/js/contagem/revisao-decisoes.js';

const anulacao = (origem, usuario, motivo = null) => ({ id: 30, tipo: 'anular', origem, usuario, criado_em_ms: 1_700_000_000_000, motivo });

// ---- voids --------------------------------------------------------------------------

test('a counter\'s own void, a reviewer\'s void and an automatic one look different', () => {
  const doContador = { id: 1, anulada: true, grupo: null, decisoes: [anulacao('contador', 'Contador1', 'digitei errado')] };
  const daRevisao = { id: 2, anulada: true, grupo: null, decisoes: [anulacao('revisor', 'jbresolin')] };
  const automatica = { id: 3, anulada_auto: true, grupo: null, decisoes: [] };

  assert.deepEqual(classesDoChip(doContador, [], null), ['chip', 'anulada', 'anulada-pelo-contador']);
  assert.deepEqual(classesDoChip(daRevisao, [], null), ['chip', 'anulada']);
  assert.deepEqual(classesDoChip(automatica, [], null), ['chip', 'anulada-auto']);

  // The tooltip names who and why.
  assert.match(descricaoDoChip(doContador), /anulada pelo contador \(Contador1\) — digitei errado/);
  assert.match(descricaoDoChip(daRevisao), /anulada pela revisão \(jbresolin\)/);
  assert.match(descricaoDoChip(automatica), /anulada automaticamente \(repetida\)/);
});

// ---- how a pass came to count ------------------------------------------------------------

test('a pass counted on a reviewer\'s word is marked, and says so', () => {
  const entrada = { id: 5, carro: 3, decisoes: [], grupo: { contado: true, papel: 'volta', numero_volta: 8, confirmado_por_revisor: true, passagem_ids: [5] } };
  assert.ok(classesDoChip(entrada, [], null).includes('confirmada-pelo-revisor'));
  assert.match(descricaoDoChip(entrada), /conta porque um revisor confirmou/);

  const pelosContadores = { ...entrada, grupo: { ...entrada.grupo, confirmado_por_revisor: false } };
  assert.ok(!classesDoChip(pelosContadores, [], null).includes('confirmada-pelo-revisor'));
  assert.doesNotMatch(descricaoDoChip(pelosContadores), /revisor confirmou/);

  // A pass a reviewer added is a reviewer's pass already: no second mark.
  const adicionada = { id: -4, carro: 3, adicionada_por_revisor: true, decisoes: [], grupo: { ...entrada.grupo, passagem_ids: [-4] } };
  assert.ok(!classesDoChip(adicionada, [], null).includes('confirmada-pelo-revisor'));
});

test('a lone entry still waiting has a dotted outline of its own', () => {
  const sozinha = { id: 6, carro: 9, decisoes: [], grupo: { contado: false, papel: 'pendente', passagem_ids: [6] } };
  assert.ok(classesDoChip(sozinha, [], null).includes('solitaria-pendente'));
  assert.match(descricaoDoChip(sozinha), /só um contador registrou/);

  // Two entries waiting (a mode that needs the primary source, say): not lone.
  const duas = { ...sozinha, grupo: { ...sozinha.grupo, passagem_ids: [6, 7] } };
  assert.ok(!classesDoChip(duas, [], null).includes('solitaria-pendente'));
  // A held pass has its own look already.
  const retida = { ...sozinha, grupo: { ...sozinha.grupo, papel: 'retida' } };
  assert.ok(!classesDoChip(retida, [], null).includes('solitaria-pendente'));
});

test('a counted chip\'s tooltip gives the lap number and the lap time', () => {
  const entrada = { id: 7, carro: 3, decisoes: [], grupo: { contado: true, papel: 'volta', numero_volta: 22, volta_ms: 75400, passagem_ids: [7, 8] } };
  assert.match(descricaoDoChip(entrada), /volta 22 · 1:15/);
  // An answer without the lap time says what it can.
  assert.match(descricaoDoChip({ ...entrada, grupo: { ...entrada.grupo, volta_ms: null } }), /volta 22$/);
});

test('the entry a reviewer chose to time the pass is marked', () => {
  const grupo = { contado: true, papel: 'volta', numero_volta: 5, tempo_escolhido_id: 531, passagem_ids: [531, 533] };
  assert.ok(classesDoChip({ id: 531, decisoes: [], grupo }, [], null).includes('tempo-escolhido'));
  assert.ok(!classesDoChip({ id: 533, decisoes: [], grupo }, [], null).includes('tempo-escolhido'));
});

test('an observer\'s entry has a look of its own, not the grey of an entry out of use', () => {
  const daCamera = { id: 9, carro: 12, fora_de_uso: true, motivo_fora_de_uso: 'fonte_observadora', decisoes: [], grupo: null, imagem_url: 'http://box/x.jpg' };
  const classes = classesDoChip(daCamera, [], null);
  assert.ok(classes.includes('observadora'));
  assert.ok(!classes.includes('fora-de-uso'));
  assert.match(descricaoDoChip(daCamera), /fonte observadora/);
  assert.match(descricaoDoChip(daCamera), /com imagem \(clique para ver\)/, 'its picture is still one click away');

  const desligada = { id: 10, fora_de_uso: true, motivo_fora_de_uso: 'fonte_inativa', decisoes: [], grupo: null };
  assert.ok(classesDoChip(desligada, [], null).includes('fora-de-uso'));
});

// ---- what stands out when a flag or a car is picked ---------------------------------------

test('the flag\'s entries strongly, its car\'s other entries weakly, other cars dimmed', () => {
  const doAlerta = { id: 20, carro: 12, decisoes: [], grupo: null };
  const anteriorDoCarro = { id: 21, carro: 12, decisoes: [], grupo: null };
  const outroCarro = { id: 22, carro: 40, decisoes: [], grupo: null };
  // Typed as 12 and moved to 21: it still concerns car 12.
  const trocada = { id: 23, carro: 21, carro_digitado: 12, reatribuida: true, decisoes: [], grupo: null };

  assert.ok(classesDoChip(doAlerta, [20], null, [12]).includes('em-destaque'));
  assert.ok(!classesDoChip(doAlerta, [20], null, [12]).includes('do-carro'));
  assert.ok(classesDoChip(anteriorDoCarro, [20], null, [12]).includes('do-carro'));
  assert.ok(classesDoChip(outroCarro, [20], null, [12]).includes('apagada'));
  assert.ok(classesDoChip(trocada, [20], null, [12]).includes('do-carro'));

  // Nothing picked: nothing dimmed.
  assert.deepEqual(classesDoChip(outroCarro, [], null), ['chip']);
});

// ---- the chip menu of a short lap a reviewer confirmed ---------------------------------------

// SITUATION (26SE): a reviewer confirmed three lone entries in four seconds;
// each made a lap of about a minute, and cars 1, 3 and 20 were one lap
// over with nothing open. The pass is marked "volta curta confirmada".
//
// EXPECTED: its chip menu offers "Desfazer a confirmação" first, and asks
// before doing it (it takes a lap away).
test('a confirmed short lap offers to undo the confirmation, first, asking', () => {
  const entrada = {
    id: 357, carro: 3, assinatura: 'x',
    decisoes: [{ id: 88, tipo: 'confirmar', origem: 'revisor', usuario: 'jbresolin' }],
    grupo: { contado: true, papel: 'volta', numero_volta: 12, volta_curta_confirmada: true, confirmado_por_revisor: true, passagem_ids: [357] },
  };
  const opcoes = acoesDaEntrada(entrada);
  assert.deepEqual(opcoes[0], { acao: 'desfazer_confirmacao', decisaoId: 88, tipo: 'confirmar' });
  assert.equal(rotuloDeDesfazerConfirmacao(opcoes[0]), 'Desfazer a confirmação (−1 volta)');
  assert.match(perguntaAoAgirNaEntrada(opcoes[0], entrada), /Desfazer a confirmação desta passagem do carro 3\?.*−1 volta/);

  // An ordinary confirmed pass (a normal lap) does not get it up front.
  const normal = { ...entrada, grupo: { ...entrada.grupo, volta_curta_confirmada: false } };
  assert.ok(!acoesDaEntrada(normal).some((opcao) => opcao.acao === 'desfazer_confirmacao'));
});

test('the confirmation is found on another entry of the same pass', () => {
  // K on a held pass stores the confirmation on the flag's first entry; the
  // reviewer may click the other counter's chip.
  const confirmada = { id: 500, carro: 1, decisoes: [{ id: 91, tipo: 'confirmar' }], grupo: { passagem_ids: [500, 501] } };
  const outra = {
    id: 501, carro: 1, decisoes: [],
    grupo: { contado: true, papel: 'volta', numero_volta: 4, volta_curta_confirmada: true, passagem_ids: [500, 501] },
  };
  const janela = { raias: [{ entradas: [confirmada] }, { entradas: [outra] }] };
  assert.deepEqual(acoesDaEntrada(outra, new Set(), janela)[0], { acao: 'desfazer_confirmacao', decisaoId: 91, tipo: 'confirmar' });
  // Not on screen: nothing to offer (the history still has it).
  assert.ok(!acoesDaEntrada(outra).some((opcao) => opcao.acao === 'desfazer_confirmacao'));
});

// The server marks a short lap "volta curta confirmada" in two more cases
// than a "confirmar": the reviewer chose the pass's time ("usar o tempo",
// which confirms too), or added a pass by hand that joined a counter's lone
// entry. The amber chip must offer the undo there as well.
test('a short lap vouched for by "usar o tempo" offers to undo that, asking', () => {
  const escolhida = { id: 600, carro: 20, decisoes: [{ id: 95, tipo: 'usar_tempo', origem: 'revisor' }], grupo: { passagem_ids: [600, 601] } };
  const outra = {
    id: 601, carro: 20, decisoes: [{ id: 95, tipo: 'usar_tempo', origem: 'revisor' }],
    grupo: { contado: true, papel: 'volta', numero_volta: 9, volta_curta_confirmada: true, confirmado_por_revisor: true, passagem_ids: [600, 601] },
  };
  const janela = { raias: [{ entradas: [escolhida, outra] }] };
  const opcao = acoesDaEntrada(outra, new Set(), janela)[0];
  assert.deepEqual(opcao, { acao: 'desfazer_confirmacao', decisaoId: 95, tipo: 'usar_tempo' });
  assert.equal(rotuloDeDesfazerConfirmacao(opcao), 'Desfazer o "usar o tempo" (−1 volta)');
  assert.match(perguntaAoAgirNaEntrada(opcao, outra), /Desfazer o "usar o tempo" desta passagem do carro 20\?.*−1 volta/);

  // A "confirmar" on the pass is what is undone first.
  const confirmada = { ...escolhida, decisoes: [...escolhida.decisoes, { id: 97, tipo: 'confirmar' }] };
  assert.equal(acoesDaEntrada(outra, new Set(), { raias: [{ entradas: [confirmada, outra] }] })[0].decisaoId, 97);
});

test('a short lap made by a pass a reviewer added offers to remove that pass', () => {
  // The added pass's id is minus the id of its "adicionar" decision.
  const adicionada = { id: -42, carro: 1, adicionada_por_revisor: true, decisoes: [], grupo: { passagem_ids: [700, -42] } };
  const doContador = {
    id: 700, carro: 1, decisoes: [],
    grupo: { contado: true, papel: 'volta', numero_volta: 3, volta_curta_confirmada: true, confirmado_por_revisor: false, passagem_ids: [700, -42] },
  };
  const opcao = acoesDaEntrada(doContador, new Set(), { raias: [{ entradas: [doContador] }, { entradas: [adicionada] }] })[0];
  assert.deepEqual(opcao, { acao: 'desfazer_confirmacao', decisaoId: 42, tipo: 'adicionar' });
  assert.equal(rotuloDeDesfazerConfirmacao(opcao), 'Remover a passagem adicionada (−1 volta)');
  assert.match(perguntaAoAgirNaEntrada(opcao, doContador), /^Remover a passagem que um revisor adicionou ao carro 1/);
});
