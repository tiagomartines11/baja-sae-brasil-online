// Tests for what the review screen offers on each kind of flag (round 4).
//
// Background for someone new to this. Three counters type the number of
// every car that crosses the line; a pass counts when two of them agree.
// Where something looks wrong the reviewer gets a "flag" (alerta) with up to
// three keys:
//
//   A  accept what the system suggests
//   K  "confirmar": vouch for the flag's pass
//   V  void one entry
//
// The safe mode of the count must never give a car a lap it did not drive.
// So every button here that ADDS a pass says "+1 volta" and is asked about
// when one key would otherwise do it, and a button that adds nothing must
// not say "+1 volta".
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  acaoDeAceitar, acaoDeConfirmar, acoesExtrasDoAlerta, decisaoMarcarSaida, fraseDeDuvida, passagensParaDescartar,
  perguntaAoConfirmar, perguntaAoDescartar, perguntaAoMarcarSaida, rotuloDeDescartar, voltasAoMarcarSaida,
} from '../../default/js/contagem/revisao-alertas.js';
import { aplicarResposta } from '../../default/js/contagem/revisao-conexao.js';
import {
  acoesDaEntrada, confirmarSomaPassagem, decisaoAceitar, decisaoDescartarPassagem, descreverDecisao, descreverSugestao,
  pedidoParaAlerta, pedidosDaSugestao, repeticoesEmAberto, sugestaoPedeConfirmacao,
} from '../../default/js/contagem/revisao-decisoes.js';
import { ordenarFila, situacaoDoCarroFora } from '../../default/js/contagem/revisao-fila.js';
import { avisoDeReconciliacao } from '../../default/js/contagem/revisao-frases.js';
import {
  classesDoChip, descreverParada, descricaoDoPapel, rotuloDaMarca,
} from '../../default/js/contagem/revisao-raias-logica.js';

const LARGADA = 1_760_000_000_000;
const CONFIGURACOES = { janela_ms: 20000, fracao_volta_minima: 0.5 };
const ESTADO = { ultima_decisao_id: 9, configuracoes: CONFIGURACOES, carros: [{ carro: 23, volta_esperada_ms: 230000 }] };

// ---- 1.4: a count in doubt ---------------------------------------------------

// SITUATION: three flags are open. Car 23 (8th in the standings) has a pass
// HELD: two counters typed it 12 s after its previous pass. Car 5 (the
// leader) has an entry whose time was estimated. Car 9 (3rd) has two counted
// passes 30 s apart.
//
// WHAT WENT WRONG: the queue was sorted by standings position only. The
// leader's informational flag came first, and the two flags that mean "a
// published lap count may be wrong right now" sat below it.
//
// EXPECTED: the flags that put a count in doubt come first, then the others
// by standings position. Each says in words which way the count may be off.
test('flags that put a count in doubt are listed first, and say which way', () => {
  const retida = { id: 'r', tipo: 'passagem_proxima_demais', carros: [23], prioridade: 8, ts_ms: 3000, contagem_em_duvida: 'a_menos' };
  const estimado = { id: 'e', tipo: 'tempo_estimado', carros: [5], prioridade: 1, ts_ms: 1000, contagem_em_duvida: null };
  const curta = { id: 'c', tipo: 'volta_curta', carros: [9], prioridade: 3, ts_ms: 2000, contagem_em_duvida: 'a_mais' };

  assert.deepEqual(ordenarFila([retida, estimado, curta]).map((alerta) => alerta.id), ['c', 'r', 'e']);

  assert.equal(fraseDeDuvida(retida), 'O carro 23 pode estar com uma volta a menos.');
  assert.equal(fraseDeDuvida(curta), 'O carro 9 pode estar com uma volta a mais.');
  assert.equal(fraseDeDuvida(estimado), null, 'an informational flag says nothing about the count');
});

// ---- 1.1: a held pass ---------------------------------------------------------

// SITUATION: counters 1 and 2 both typed car 23 twelve seconds after its
// previous pass (two stray entries, or one crossing typed twice). The engine
// holds that pass: it does not count until a person says so.
//
// EXPECTED: K on the flag is "Contar esta passagem (+1 volta)" and asks
// first, because the lap it would create is impossibly short. The flag also
// offers to discard the held pass.
test('K on a held pass counts it, says "+1 volta", and asks first', () => {
  const retida = {
    tipo: 'passagem_proxima_demais', carros: [23], passagem_ids: [70, 71], sugestao: null,
    efeito_de_confirmar: 'mais_uma_passagem', volta_resultante_ms: 12000, contagem_em_duvida: 'a_menos',
  };

  assert.deepEqual(acaoDeConfirmar(retida), { rotulo: 'Contar esta passagem (+1 volta)', maisUmaPassagem: true });
  assert.match(perguntaAoConfirmar(retida, ESTADO), /volta de 0:12, curta demais/);

  const extras = acoesExtrasDoAlerta(retida, LARGADA);
  assert.deepEqual(extras.map((extra) => extra.acao), ['descartar_passagem']);
  assert.equal(extras[0].rotulo, 'Descartar esta passagem (anula os 2 registros)');
  assert.doesNotMatch(perguntaAoDescartar(retida, extras[0].passagem), /perde uma volta/, 'a held pass was never counted');
});

// ---- 1.3: suggestions and shortcuts that add a lap -----------------------------

// SITUATION: the start was recorded a little late. Counters 1 and 2 typed
// car 9 thirty seconds BEFORE it. The engine suggests counting that pass as
// the car's initial pass.
//
// WHAT WENT WRONG: the A button read "Aceitar sugestão" and stored the
// decision at one keystroke. On a one-counter stray, that added a lap with
// one key and no word about it.
//
// EXPECTED: A and K both read "Contar como passagem inicial (+1 volta)" and
// both ALWAYS ask first, also when the lap it creates looks normal.
test('counting a pass from before the start always asks, on A and on K', () => {
  const antes = {
    tipo: 'antes_da_largada', carros: [9], passagem_ids: [50, 51], sugestao: { tipo: 'confirmar', passagem_id: 50 },
    efeito_de_confirmar: 'mais_uma_passagem', volta_resultante_ms: 240000,
  };

  const aceitar = acaoDeAceitar(antes, ESTADO);
  assert.equal(aceitar.rotulo, 'Contar como passagem inicial (+1 volta)');
  assert.match(aceitar.pergunta, /PASSAGEM INICIAL/);
  assert.match(aceitar.pergunta, /\+1 volta/);

  assert.match(descreverSugestao(antes), /passagem inicial do carro \(\+1 volta\)/, 'the suggestion is worded as what it does');
  assert.equal(acaoDeConfirmar(antes).rotulo, 'Contar como passagem inicial (+1 volta)');
  assert.match(perguntaAoConfirmar(antes, ESTADO), /ANTES da largada/, 'asked although a 4-minute lap is plausible');
});

// A lone entry with a normal lap: K is not asked about (round 3's rule; the
// button already says "+1 volta"). The question is kept for the cases above.
test('K on a lone entry with a plausible lap sends without a question', () => {
  const solitaria = { tipo: 'solitaria', carros: [23], passagem_ids: [300], efeito_de_confirmar: 'mais_uma_passagem', volta_resultante_ms: 220000 };
  assert.equal(perguntaAoConfirmar(solitaria, ESTADO), null);
});

// SITUATION: counter 1 typed car 30 twice, 12 s apart (entries 200 and 201).
// The flag "repetida" is open; it has no K, because "keep both" would count
// the car twice. The reviewer clicks the second chip instead.
//
// WHAT WENT WRONG: the chip's own box offered "Está correto, parar de
// avisar", which is "keep both" under another name.
//
// EXPECTED: the later entry of an open repeat has no "confirmar" on its
// chip. The first entry, and entries of other passes, keep theirs.
test('the later entry of an open repeat cannot be confirmed from its chip', () => {
  const estado = {
    alertas_abertos: [
      { tipo: 'repetida', carros: [30], passagem_ids: [200, 201], sugestao: { tipo: 'anular', passagem_id: 201 } },
      { tipo: 'solitaria', carros: [7], passagem_ids: [300], sugestao: null },
    ],
  };
  const repeticoes = repeticoesEmAberto(estado);
  const grupo = { contado: true, passagem_ids: [200], soma_ao_confirmar: false };

  const daSegunda = acoesDaEntrada({ id: 201, decisoes: [], grupo: { ...grupo, passagem_ids: [201] } }, repeticoes);
  assert.equal(daSegunda.some((opcao) => opcao.acao === 'confirmar'), false);
  assert.deepEqual(daSegunda.map((opcao) => opcao.acao), ['anular', 'reatribuir'], 'void it, or say it was another car');

  const daPrimeira = acoesDaEntrada({ id: 200, decisoes: [], grupo }, repeticoes);
  assert.equal(daPrimeira.some((opcao) => opcao.acao === 'confirmar'), true);
});

// SITUATION: a phone uploaded an entry for car 12 with no usable clock
// ("horário desconhecido"). Nobody knows when it was typed.
//
// WHAT WENT WRONG: K counted the pass at the phone's own clock time, a time
// the screen does not even show.
//
// EXPECTED: the flag has no K. It offers "Adicionar passagem…", which opens
// the form where the reviewer types the time. The chip of such an entry has
// no "confirmar" either.
test('an entry of unknown time has no K: the pass is added by hand with a typed time', () => {
  const semHorario = { tipo: 'tempo_desconhecido', carros: [12], passagem_ids: [90], sugestao: null, efeito_de_confirmar: 'nenhum', ts_ms: 123 };

  assert.equal(acaoDeConfirmar(semHorario), null);
  assert.equal(acaoDeConfirmar({ ...semHorario, efeito_de_confirmar: 'mais_uma_passagem' }), null, 'whatever an old server says');
  assert.deepEqual(acoesExtrasDoAlerta(semHorario).map((extra) => extra.acao), ['adicionar_passagem']);

  const chip = acoesDaEntrada({ id: 90, decisoes: [], grupo: null, qualidade_tempo: 'desconhecido', fora_de_uso: true });
  assert.equal(chip.some((opcao) => opcao.acao === 'confirmar'), false);
});

// ---- 5.5: "+1 volta" only where confirming adds a pass -------------------------

// SITUATION: the reviewer clicks a chip of a pass typed after the finish
// that the rules leave out ("depois do encerramento"), and a chip typed
// inside the start window.
//
// WHAT WENT WRONG: the box offered "Contar esta passagem (+1 volta)" on any
// entry whose pass did not count. Confirming those changes nothing: the
// rules of the race leave them out whoever confirms.
//
// EXPECTED: the server says per pass whether confirming adds one
// ("soma_ao_confirmar"), and only then is the button the "+1 volta" one.
test('"+1 volta" is only promised where confirming really adds a pass', () => {
  const depoisDoFim = { id: 1, decisoes: [], grupo: { papel: 'apos_encerramento', contado: false, passagem_ids: [1], soma_ao_confirmar: false } };
  const pendente = { id: 2, decisoes: [], grupo: { papel: 'pendente', contado: false, passagem_ids: [2], soma_ao_confirmar: true } };
  const retida = { id: 3, decisoes: [], grupo: { papel: 'retida', contado: false, passagem_ids: [3, 4], soma_ao_confirmar: true } };

  assert.equal(confirmarSomaPassagem(depoisDoFim), false);
  assert.equal(confirmarSomaPassagem(pendente), true);
  assert.equal(confirmarSomaPassagem(retida), true);
  assert.equal(acoesDaEntrada(depoisDoFim).find((opcao) => opcao.acao === 'confirmar').maisUmaPassagem, false);
});

// ---- 5.0: a pass while the car is logged in the pit lane ------------------------

// SITUATION: car 41 was logged INTO the pit lane and nobody logged it out.
// Then counters 1 and 2 both see it cross the line (entries 10 and 11).
//
// WHAT WENT WRONG (round 3): the flag offered only "Está correto, parar de
// avisar". The stay stayed open, so every later pass of car 41 was flagged
// again and its status and stop statistics stayed wrong.
//
// SINCE ROUND 4b the pass is HELD while the flag is open: it does not count
// until a person decides. That makes both ways out change the race, so
// there is no default.
//
// EXPECTED: no A (and nothing to send for it), no K. Two buttons, always
// both: "Marcar saída do box (+1 volta)", which asks first and says what it
// does (the exit one second before the pass, the stay closed, the pass
// counts), and "Descartar esta passagem", which asks too and does NOT say
// the car loses a lap: a held pass was never counted.
test('a pass seen by two counters while the car is logged in the pit lane: no default, two buttons', () => {
  const noBox = {
    tipo: 'no_box', carros: [41], passagem_ids: [10, 11], ts_ms: LARGADA + 600000, assinatura: 'sig',
    sugestao: null, efeito_de_confirmar: 'mais_uma_passagem', contagem_em_duvida: 'a_menos', voltas_ao_marcar_saida: 1,
  };

  assert.equal(acaoDeAceitar(noBox, ESTADO), null, 'A is drawn disabled');
  assert.deepEqual(pedidosDaSugestao(noBox, ESTADO), [], 'and the A key sends nothing');
  assert.equal(acaoDeConfirmar(noBox), null, 'no K, although confirming would add a pass');

  const extras = acoesExtrasDoAlerta(noBox, LARGADA);
  assert.deepEqual(extras.map((extra) => extra.rotulo), ['Marcar saída do box (+1 volta)', 'Descartar esta passagem']);

  assert.equal(voltasAoMarcarSaida(noBox), 1);
  const pergunta = perguntaAoMarcarSaida(noBox);
  assert.match(pergunta, /um segundo antes desta passagem/);
  assert.match(pergunta, /a parada é encerrada/);
  assert.match(pergunta, /passa a CONTAR.*ganha uma volta \(\+1 volta\)/);
  assert.deepEqual(decisaoMarcarSaida(noBox), { tipo: 'marcar_saida', area: 'box', equipe_numero: 41, ts_ms: LARGADA + 599000 });

  const descartar = perguntaAoDescartar(noBox, extras[1].passagem);
  assert.match(descartar, /Os 2 registros dela são anulados de uma vez\./);
  assert.doesNotMatch(descartar, /perde uma volta/, 'a held pass does not count today');

  // The request says which entries the reviewer was looking at, like any other.
  const pedido = pedidoParaAlerta(decisaoMarcarSaida(noBox), noBox, ESTADO, 'abc');
  assert.deepEqual(pedido.passagem_ids, [10, 11]);
  assert.equal(pedido.assinatura, 'abc');
});

// A server from before round 4b suggested "marcar saída" on such a flag. A
// screen that is newer than the server must still not offer it on A.
test('a suggestion on a pit-lane flag from an older server is not offered on A', () => {
  const antigo = {
    tipo: 'no_box', carros: [41], passagem_ids: [10, 11], ts_ms: LARGADA + 600000, assinatura: 'sig',
    sugestao: { tipo: 'marcar_saida', area: 'box', equipe_numero: 41, ts_ms: LARGADA + 599000 },
  };
  assert.equal(acaoDeAceitar(antigo, ESTADO), null);
  assert.deepEqual(pedidosDaSugestao(antigo, ESTADO), []);
  // Such a state does not say how many laps the exit would release, so the
  // button promises none (see the test about the real number below).
  assert.deepEqual(acoesExtrasDoAlerta(antigo, LARGADA).map((extra) => extra.rotulo), ['Marcar saída do box', 'Descartar esta passagem']);
});

// SITUATION: car 11 was logged into the pit lane at 0:08:20 and nobody
// logged it out. It kept racing: five more crossings, each typed by all
// three counters, each HELD because the log says the car is inside. Five
// flags are open. The reviewer opens the first one.
//
// WHAT WENT WRONG: the button read "Marcar saída do box (+1 volta)". One
// press released all five held passes: the car went from 3 laps to 8, and
// the button had promised one.
//
// EXPECTED: the server says on each flag how many held passes the exit
// would release ("voltas_ao_marcar_saida": 5 on the first flag, 2 on the
// fourth, 1 on the last), and the button and its question carry exactly
// that number. At the fueling station the wording is the fueling one. With
// 0, or with no number at all (one counter only, or a state from before the
// field existed), the button has no parenthesis and asks nothing: it must
// not promise laps on a guess.
test('the exit button shows how many laps marking the exit really adds', () => {
  const noBox = (voltas) => ({
    tipo: 'no_box', carros: [11], passagem_ids: [10, 11, 12], ts_ms: LARGADA + 526000, sugestao: null,
    contagem_em_duvida: 'a_menos', ...(voltas === undefined ? {} : { voltas_ao_marcar_saida: voltas }),
  });
  const rotulo = (alerta) => acoesExtrasDoAlerta(alerta, LARGADA)[0].rotulo;

  assert.equal(rotulo(noBox(5)), 'Marcar saída do box (+5 voltas)');
  assert.equal(rotulo(noBox(2)), 'Marcar saída do box (+2 voltas)');
  assert.equal(rotulo(noBox(1)), 'Marcar saída do box (+1 volta)');
  assert.equal(rotulo(noBox(0)), 'Marcar saída do box');
  assert.equal(rotulo(noBox(undefined)), 'Marcar saída do box', 'no number from the server: no promise');
  assert.equal(rotulo({ ...noBox(3), tipo: 'no_abastecimento' }), 'Marcar saída do abastecimento (+3 voltas)');

  const pergunta = perguntaAoMarcarSaida(noBox(5));
  assert.match(pergunta, /Marcar a saída do carro 11 do box\?/);
  assert.match(pergunta, /as seguintes dele retidas pelo mesmo motivo passam a CONTAR, 5 ao todo/);
  assert.match(pergunta, /ganha 5 voltas \(\+5 voltas\)/);
  assert.doesNotMatch(pergunta, /\+1 volta\b/);
  assert.match(perguntaAoMarcarSaida({ ...noBox(2), tipo: 'no_abastecimento' }), /do abastecimento\?.*\(\+2 voltas\)/);

  assert.equal(perguntaAoMarcarSaida(noBox(0)), null, 'no lap is added: nothing to ask');
  assert.equal(perguntaAoMarcarSaida(noBox(undefined)), null);
  // Only a whole number above zero is a number of laps.
  for (const estranho of [-1, 1.5, '3', null]) {
    assert.equal(voltasAoMarcarSaida(noBox(estranho)), 0, JSON.stringify(estranho));
  }
  assert.equal(voltasAoMarcarSaida({ tipo: 'solitaria', voltas_ao_marcar_saida: 4 }), 0, 'only for a car logged inside an area');
});

// SITUATION: the same, at the fueling station, but only ONE counter typed
// the pass (entry 20). That is a lone entry: it does not count, and it would
// not count after the exit is marked either (it still needs a second
// witness).
//
// EXPECTED: the same two buttons, but "Marcar saída do abastecimento" has no
// "(+1 volta)" and asks nothing, because it changes no count.
test('a pass seen by one counter while the car is at the fueling station: marking the exit promises no lap', () => {
  const noAbastecimento = {
    tipo: 'no_abastecimento', carros: [7], passagem_ids: [20], ts_ms: LARGADA + 900000,
    sugestao: null, efeito_de_confirmar: 'mais_uma_passagem', contagem_em_duvida: null, voltas_ao_marcar_saida: 0,
  };

  assert.equal(acaoDeAceitar(noAbastecimento, ESTADO), null);
  assert.deepEqual(acoesExtrasDoAlerta(noAbastecimento, LARGADA).map((extra) => extra.rotulo),
    ['Marcar saída do abastecimento', 'Descartar esta passagem']);
  assert.equal(voltasAoMarcarSaida(noAbastecimento), 0);
  assert.equal(perguntaAoMarcarSaida(noAbastecimento), null, 'no count changes, nothing to ask');
  assert.deepEqual(decisaoMarcarSaida(noAbastecimento),
    { tipo: 'marcar_saida', area: 'abastecimento', equipe_numero: 7, ts_ms: LARGADA + 899000 });
  assert.equal(decisaoMarcarSaida({ tipo: 'solitaria', carros: [7], ts_ms: 1 }), null, 'only for a car logged inside an area');
});

// The chip of a pass held because the car was logged inside says so.
test('a pass held because the car is logged in the pit lane says why on its chip', () => {
  assert.match(descricaoDoPapel({ papel: 'retida', motivo_retencao: 'no_box' }), /RETIDA: o carro constava como dentro do box/);
  assert.match(descricaoDoPapel({ papel: 'retida', motivo_retencao: 'no_abastecimento' }), /dentro do abastecimento/);
});

// SITUATION (a new shape of "troca provável"): counters 1 and 2 typed car
// 14 (entries 101, 102) and each of them skipped car 41, which the others
// saw. The flag names both cars, has no lone entry and no suggestion.
//
// EXPECTED: it is drawn like any flag without a suggestion: A disabled,
// nothing sent for A, K as the server says, and no extra button that would
// act on a pass by guesswork. The entries are moved one by one from their
// chips.
test('a swap flag with two cars and no suggestion offers no default', () => {
  const troca = {
    tipo: 'troca_provavel', carros: [14, 41], passagem_ids: [101, 102], assinatura: 'sig', sugestao: null,
    efeito_de_confirmar: 'nenhum',
  };
  assert.equal(acaoDeAceitar(troca, ESTADO), null);
  assert.deepEqual(pedidosDaSugestao(troca, ESTADO), []);
  assert.deepEqual(acaoDeConfirmar(troca), { rotulo: 'Está correto, parar de avisar', maisUmaPassagem: false });
  assert.deepEqual(acoesExtrasDoAlerta(troca, LARGADA), []);
  assert.match(descreverSugestao(troca), /Sem sugestão automática/);
  const chip = acoesDaEntrada({ id: 101, decisoes: [], grupo: { contado: true, passagem_ids: [101, 102], soma_ao_confirmar: false } });
  assert.ok(chip.some((opcao) => opcao.acao === 'reatribuir'), 'each entry can be moved to the other car');
});

test('the lane says when an exit was marked by a reviewer, and the history says who did it', () => {
  assert.deepEqual(rotuloDaMarca({ carro: 41, tipo: 'box_saida', inferida: true }, 'saiu').texto, '41 saiu (inferida)');
  assert.match(rotuloDaMarca({ carro: 41, tipo: 'box_saida', inferida: true }, 'saiu').aviso, /marcada por um revisor/);
  assert.equal(rotuloDaMarca({ carro: 41, tipo: 'box_saida' }, 'saiu').texto, '41 saiu');
  assert.equal(rotuloDaMarca({ carro: 41, tipo: 'box_entrada', sem_par: true }, 'entrou').texto, '41 entrou (sem par)');

  assert.equal(
    descreverDecisao({ tipo: 'marcar_saida', equipe_numero: 41, ts_ms: 5000, motivo: 'box' }),
    'Marcou a saída do carro 41 do box (ninguém registrou na hora)',
  );
});

// A pit event whose phone had no usable clock is kept out of the pairing by
// the engine. The screen lists it in words instead of placing it on a lane.
test('a pit event of unknown time is described in words', () => {
  assert.equal(descreverParada({ carro: 12, tipo: 'box_entrada' }), 'carro 12 entrou no box');
  assert.equal(descreverParada({ carro: 12, tipo: 'abastecimento_saida' }), 'carro 12 saiu do abastecimento');
});

// ---- 1.2: a short lap names both passes; the reverse suggestion ---------------

// SITUATION: car 5 has two official passes 30 s apart: entries 60, 61 (at
// 0:10:00) and 62, 63 (at 0:10:30). One of the two did not happen.
//
// WHAT WENT WRONG: the flag assumed the LATER pass was the mistake, and
// "Descartar esta passagem" voided that one. When two counters had typed a
// wrong number for the EARLIER one, the reviewer discarded the real pass.
//
// EXPECTED: the flag carries both passes and the screen offers one button
// for each, with its time. Each sends "descartar_passagem" naming an entry
// of the pass chosen.
test('a short lap offers to discard either of its two passes', () => {
  const voltaCurta = {
    tipo: 'volta_curta', carros: [5], passagem_ids: [60, 61, 62, 63], efeito_de_confirmar: 'nenhum', contagem_em_duvida: 'a_mais',
    passagens: [
      { passagem_ids: [60, 61], ts_ms: LARGADA + 600000 },
      { passagem_ids: [62, 63], ts_ms: LARGADA + 630000 },
    ],
  };

  const passagens = passagensParaDescartar(voltaCurta);
  assert.deepEqual(passagens.map((passagem) => [passagem.qual, passagem.passagemId, passagem.registros]),
    [['primeira', 60, 2], ['segunda', 62, 2]]);
  assert.equal(rotuloDeDescartar(passagens[0], LARGADA), 'Descartar a primeira passagem (0:10:00, anula os 2 registros)');
  assert.equal(rotuloDeDescartar(passagens[1], LARGADA), 'Descartar a segunda passagem (0:10:30, anula os 2 registros)');
  assert.match(perguntaAoDescartar(voltaCurta, passagens[0]), /Descartar a primeira passagem do carro 5\?.*perde uma volta/);

  const pedido = pedidoParaAlerta(decisaoDescartarPassagem(passagens[0].passagemId), voltaCurta, ESTADO, 'abc');
  assert.deepEqual(pedido.decisao, { tipo: 'descartar_passagem', passagem_id: 60 });

  // A state from before the server sent both passes: one button, as before.
  const antiga = { tipo: 'volta_curta', carros: [5], passagem_ids: [62, 63] };
  assert.deepEqual(passagensParaDescartar(antiga).map((passagem) => passagem.qual), [null]);
});

// SITUATION: car 41 crosses. Counters 1 and 2 BOTH type 14 (entries 101 and
// 102); counter 3 types 41 (entry 103). Car 14 was not there.
//
// WHAT WENT WRONG: the only suggestion was to move counter 3's correct entry
// onto car 14. Accepting it left car 41 a lap short and car 14 a lap over,
// with no flag open.
//
// EXPECTED: when the server suggests the reverse ("reatribuir_grupo": move
// the two entries to car 41), A says exactly that, asks first with both
// readings, and sends ONE decision naming both entries. The request is not
// built when one of those entries is not among the flag's (the server could
// not check that it is unchanged).
test('the reverse suggestion moves both entries in one decision, after asking', () => {
  const troca = {
    tipo: 'troca_provavel', carros: [14, 41], passagem_ids: [101, 102, 103],
    sugestao: { tipo: 'reatribuir_grupo', passagem_ids: [101, 102], equipe_numero: 41 },
  };

  const aceitar = acaoDeAceitar(troca, ESTADO);
  assert.equal(aceitar.rotulo, 'Mover os 2 registros para o carro 41');
  assert.match(aceitar.pergunta, /MESMO número errado/);
  assert.match(aceitar.pergunta, /NÃO aceite/, 'the other reading is put to the reviewer too');
  assert.match(descreverSugestao(troca), /mover os 2 registros para o carro 41/);

  const pedido = pedidoParaAlerta(decisaoAceitar(troca), troca, ESTADO, 'abc');
  assert.deepEqual(pedido.decisao, { tipo: 'reatribuir_grupo', passagem_ids: [101, 102], equipe_numero: 41 });
  assert.deepEqual(pedido.passagem_ids, [101, 102, 103]);

  const foraDoAlerta = { ...troca, passagem_ids: [103] };
  assert.equal(pedidoParaAlerta(decisaoAceitar(foraDoAlerta), foraDoAlerta, ESTADO, 'abc'), null);
});

test('a held pass is drawn and described as held', () => {
  const entrada = { id: 70, grupo: { papel: 'retida', contado: false, motivo_retencao: 'proxima_demais' } };
  assert.ok(classesDoChip(entrada, [], null).includes('retida'));
  assert.match(descricaoDoPapel(entrada.grupo), /RETIDA: perto demais da passagem anterior/);
  assert.match(descricaoDoPapel({ papel: 'retida', motivo_retencao: 'dispersao' }), /afastados demais entre si/);
});

// ---- 3.7: corrections made by the reconcile step --------------------------------

// SITUATION: the full recalculation found that car 12 was published with 7
// laps while the count says 8, and put it right.
//
// WHAT WENT WRONG: that went to the server's error log only. The reviewers,
// who are the ones watching the results, never heard of it.
//
// EXPECTED: the state carries the corrections of the last day and the
// screen shows them in a warning, also when the answer is the small
// "inalterado" one (a correction changes no flag, so no new version).
test('a correction made by the recalculation is shown to the reviewers', () => {
  const agora = LARGADA + 3600000;
  const correcoes = [{ carro: 12, campo: 'VOLTAS', publicado: 7, calculado: 8, quando_ms: agora - 180000 }];

  const aviso = avisoDeReconciliacao(correcoes, agora);
  assert.match(aviso, /A contagem rápida divergiu da contagem completa: 1 valor publicado foi corrigido/);
  assert.match(aviso, /carro 12: VOLTAS publicado 7, calculado 8 \(há 3 min\)/);
  assert.equal(avisoDeReconciliacao([], agora), null);
  assert.match(avisoDeReconciliacao([{ ...correcoes[0], publicado: null }], agora), /publicado nada, calculado 8/);

  const estado = { versao: 'v1', alertas_abertos: [], alertas_resolvidos: [], correcoes_da_reconciliacao: [] };
  const depois = aplicarResposta(estado, { inalterado: true, versao: 'v1', correcoes_da_reconciliacao: correcoes, predefinicao_de_teste: true });
  assert.deepEqual(depois.correcoes_da_reconciliacao, correcoes);
  assert.equal(depois.predefinicao_de_teste, true, 'the test-preset banner follows the server too');
});

// ---- 5.5: no team marked present ----------------------------------------------

// SITUATION: nobody marked the teams as present at the event yet. A counter
// types car 41, a real team.
//
// WHAT WENT WRONG: the table called it "número desconhecido", as if 41 were
// a typo. Every car of the race was called that.
//
// EXPECTED: a team of the event that is not marked present is called that,
// and only a number no team has is "número desconhecido".
test('a team that is not marked present is not called an unknown number', () => {
  assert.equal(situacaoDoCarroFora({ carro: 41, presente: false, inscrito: true }).etiqueta, 'equipe não marcada como presente');
  assert.equal(situacaoDoCarroFora({ carro: 999, presente: false, inscrito: false }).etiqueta, 'número desconhecido');
  assert.equal(situacaoDoCarroFora({ carro: 9, presente: true, inscrito: true }).etiqueta, 'não classificado');
});

// ---- what "Aceitar" sends, for the screen and for the simulation runner ---------

// The Node simulation runner accepts every suggestion "as the screen would".
// It used to guess the request shapes. Now the screen's A button and the
// runner build their requests with the same function, and a second one says
// whether the screen asks a person first.
test('the request for a suggestion is built in one place, and says whether the screen asks first', () => {
  const troca = {
    tipo: 'troca_provavel', carros: [14, 41], passagem_ids: [101, 102, 103], assinatura: 'sig',
    sugestao: { tipo: 'reatribuir_grupo', passagem_ids: [101, 102], equipe_numero: 41 },
  };
  assert.deepEqual(pedidosDaSugestao(troca, ESTADO), [{
    decisao: { tipo: 'reatribuir_grupo', passagem_ids: [101, 102], equipe_numero: 41 },
    passagem_ids: [101, 102, 103], assinatura: 'sig', ultima_decisao_id: 9,
  }]);
  assert.equal(sugestaoPedeConfirmacao(troca), true);

  const anular = { tipo: 'repetida', carros: [30], passagem_ids: [200, 201], assinatura: 'sig2', sugestao: { tipo: 'anular', passagem_id: 201 } };
  assert.deepEqual(pedidosDaSugestao(anular, ESTADO).map((pedido) => pedido.decisao), [anular.sugestao]);
  assert.equal(sugestaoPedeConfirmacao(anular), false, 'voiding the second entry of a repeat is not asked about');
  const saida = anular;

  const antes = { tipo: 'antes_da_largada', carros: [9], passagem_ids: [50], sugestao: { tipo: 'confirmar', passagem_id: 50 }, efeito_de_confirmar: 'mais_uma_passagem' };
  assert.equal(sugestaoPedeConfirmacao(antes), true);

  // The screen sends the signature of the timeline it shows, not the flag's latest.
  assert.equal(pedidosDaSugestao(saida, ESTADO, 'da-janela')[0].assinatura, 'da-janela');
  assert.deepEqual(pedidosDaSugestao({ ...saida, sugestao: null }, ESTADO), []);
});
