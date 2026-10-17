// Tests for the review screen's answers to what went wrong at 26SE (prova END).
//
// Background for someone new to this. Three counters type the number of
// every car that crosses the line; a reviewer settles what they disagree
// on. At that race:
//   - Contador1 voided two of his own entries on the phone (he had typed
//     them wrong) and retyped them. The reviewer, not knowing, undid both
//     voids and confirmed the retyped entries within four seconds: three
//     cars each got a lap they never drove (laps of 75 s, 71 s and 104 s).
//     Nothing asked first: not the undo, and not "Contar esta passagem" on
//     the chip, although K on a flag would have asked about the short lap.
//   - The flag's timeline showed one minute; the car's previous pass, 77 s
//     back, was not on screen.
//   - Car 1 was typed by one counter 23 s after the other: one crossing,
//     two lone entries. The screen now offers "Usar o tempo de …".
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  acaoDeConfirmar, acoesDaEntrada, descreverDecisao, descreverSugestao, perguntaAoAgirNaEntrada, perguntaAoDesfazer,
  perguntaAoUsarTempo, perguntaSobreVoltaCurta, temposParaEscolher,
} from '../../default/js/contagem/revisao-decisoes.js';
import { perguntaAoConfirmar } from '../../default/js/contagem/revisao-alertas.js';
import {
  ALCANCE_SEM_RITMO_MS, MARGEM_ANTES_DA_ANTERIOR_MS, avisoDeVoltasCurtas, janelaDoAlerta, voltaEsperadaDoAlerta,
} from '../../default/js/contagem/revisao-fila.js';
import { horaDoDia } from '../../default/js/contagem/revisao-formato.js';
import { classesDoChip, descricaoDoChip } from '../../default/js/contagem/revisao-raias-logica.js';

const LARGADA_MS = 1760000000000;

// The state the server sends, with what these tests need: the settings
// behind "impossibly short" and car 3, which laps in about three minutes.
const ESTADO = {
  configuracoes: { janela_ms: 20000, fracao_volta_minima: 0.5 },
  carros: [{ carro: 3, volta_esperada_ms: 180000 }, { carro: 1, volta_esperada_ms: 180000 }],
  fontes: [{ id: 1, nome: 'Contagem 1' }, { id: 2, nome: 'Contagem 2' }],
  corrida: { largada_ms: LARGADA_MS },
};

// Contador1 voided entry 352 on his phone (decision 40), saying why.
const ANULACAO_DO_CONTADOR = {
  id: 40, tipo: 'anular', origem: 'contador', usuario: 'Contador1', criado_em_ms: LARGADA_MS + 3600000, motivo: 'digitei errado',
};

// ---- undoing a counter's own void --------------------------------------------

test('undoing a void the counter made on the phone asks first, saying who and when', () => {
  const pergunta = perguntaAoDesfazer(ANULACAO_DO_CONTADOR);

  assert.ok(pergunta.startsWith(`O contador anulou este registro no próprio aparelho (Contador1, ${horaDoDia(LARGADA_MS + 3600000)}).`));
  assert.match(pergunta, /"digitei errado"/);
  assert.match(pergunta, /Desfazer mesmo assim\?$/);
});

test('undoing a reviewer\'s void, or anything else, asks nothing new', () => {
  assert.equal(perguntaAoDesfazer({ ...ANULACAO_DO_CONTADOR, origem: 'revisor' }), null);
  assert.equal(perguntaAoDesfazer({ id: 41, tipo: 'confirmar', origem: 'revisor' }), null);
  assert.equal(perguntaAoDesfazer({ id: 42, tipo: 'excluir_fonte', origem: 'revisor' }), null);
});

// The history row and the chip are the two ways to undo a void. The
// history's "Desfazer" asks perguntaAoDesfazer(row) with the row the server
// sends (origem, usuario, criado_em_ms); the chip's "Restaurar" asks the
// same through perguntaAoAgirNaEntrada.
test('"Restaurar" on the chip asks the same question as "Desfazer" in the history', () => {
  const entrada = { id: 352, carro: 3, anulada: true, decisoes: [ANULACAO_DO_CONTADOR] };
  const [restaurar] = acoesDaEntrada(entrada);
  const linhaDoHistorico = { ...ANULACAO_DO_CONTADOR, passagem_id: 352, ativa: true, pode_desfazer: true };

  assert.equal(restaurar.acao, 'restaurar');
  assert.equal(perguntaAoAgirNaEntrada(restaurar, entrada, ESTADO), perguntaAoDesfazer(linhaDoHistorico));
  assert.notEqual(perguntaAoAgirNaEntrada(restaurar, entrada, ESTADO), null);

  const pelaRevisao = { ...entrada, decisoes: [{ ...ANULACAO_DO_CONTADOR, origem: 'revisor', usuario: 'jbresolin' }] };
  assert.equal(perguntaAoAgirNaEntrada(acoesDaEntrada(pelaRevisao)[0], pelaRevisao, ESTADO), null);
});

// ---- "Contar esta passagem" on a chip -----------------------------------------

test('counting a lone entry on its chip asks the short-lap question K asks', () => {
  // Entry 357: car 3, alone; counting it makes a 71 s lap, under half of 180 s.
  const entrada = {
    id: 357, carro: 3, decisoes: [], grupo: { contado: false, papel: 'pendente', passagem_ids: [357], soma_ao_confirmar: true, volta_resultante_ms: 71000 },
  };
  const confirmar = acoesDaEntrada(entrada).find((opcao) => opcao.acao === 'confirmar');
  const alerta = {
    tipo: 'solitaria', carros: [3], passagem_ids: [357], efeito_de_confirmar: 'mais_uma_passagem', volta_resultante_ms: 71000,
  };

  assert.equal(perguntaAoAgirNaEntrada(confirmar, entrada, ESTADO), perguntaSobreVoltaCurta(71000));
  assert.equal(perguntaAoAgirNaEntrada(confirmar, entrada, ESTADO), perguntaAoConfirmar(alerta, ESTADO), 'one question for both paths');
});

test('a plausible lap on the chip still gets the "+1 volta" question, and an entry voided as a repeat its own', () => {
  const plausivel = {
    id: 400, carro: 3, decisoes: [], grupo: { contado: false, papel: 'pendente', passagem_ids: [400], soma_ao_confirmar: true, volta_resultante_ms: 170000 },
  };
  assert.match(perguntaAoAgirNaEntrada({ acao: 'confirmar', maisUmaPassagem: true }, plausivel, ESTADO), /MAIS UMA PASSAGEM \(\+1 volta\) com base neste único registro/);

  const repetida = { id: 401, carro: 3, decisoes: [], anulada_auto: true, volta_resultante_ms: 3000 };
  assert.equal(perguntaAoAgirNaEntrada({ acao: 'confirmar', maisUmaPassagem: true }, repetida, ESTADO), perguntaSobreVoltaCurta(3000));
});

test('confirming a pass that counts already asks nothing', () => {
  assert.equal(perguntaAoAgirNaEntrada({ acao: 'confirmar', maisUmaPassagem: false }, { id: 5, carro: 3, decisoes: [] }, ESTADO), null);
});

// ---- the flag's timeline reaches back to the previous pass ------------------

test('a lone entry\'s timeline starts a little before the car\'s previous pass', () => {
  const ts = LARGADA_MS + 3600000;
  const alerta = { tipo: 'solitaria', carros: [3], ts_ms: ts, passagem_anterior: { passagem_id: 300, ts_ms: ts - 77000 } };

  const janela = janelaDoAlerta(alerta, 30000, voltaEsperadaDoAlerta(alerta, ESTADO));

  assert.deepEqual(janela, { de: ts - 77000 - MARGEM_ANTES_DA_ANTERIOR_MS, ate: ts + 30000 });
});

test('the timeline reaches back at most one expected lap and half a minute', () => {
  const ts = LARGADA_MS + 3600000;
  // The car stood for ten minutes: its previous pass is far back.
  const alerta = { tipo: 'solitaria', carros: [3], ts_ms: ts, passagem_anterior: { passagem_id: 300, ts_ms: ts - 600000 } };

  assert.equal(janelaDoAlerta(alerta, 30000, 180000).de, ts - 210000);
  assert.equal(janelaDoAlerta(alerta, 30000, null).de, ts - ALCANCE_SEM_RITMO_MS, 'pace unknown: five minutes');
  // A flag with no previous pass keeps its minute.
  assert.deepEqual(janelaDoAlerta({ ts_ms: ts }), { de: ts - 30000, ate: ts + 30000 });
});

// ---- a crossing typed late: "Usar o tempo de …" -------------------------------

// Contagem 2 typed car 1 at 1:00:00 (entry 531); Contagem 1 23 s later (533).
const REGISTRO_TARDIO = {
  id: 'registro_tardio:531',
  tipo: 'registro_tardio',
  carros: [1],
  passagem_ids: [531, 533],
  sugestao: null,
  efeito_de_confirmar: 'mais_uma_passagem',
  volta_resultante_ms: 180000,
  tempos_possiveis: [
    { passagem_id: 531, fonte_id: 2, ts_ms: LARGADA_MS + 3600000, passagem_alvo_id: 533 },
    { passagem_id: 533, fonte_id: 1, ts_ms: LARGADA_MS + 3623400, passagem_alvo_id: 531 },
  ],
};

test('a late pair offers one button per time, numbered for the keyboard, and no K', () => {
  const tempos = temposParaEscolher(REGISTRO_TARDIO, ESTADO);

  assert.deepEqual(tempos.map((tempo) => [tempo.rotulo, tempo.tecla]), [
    ['Usar o tempo de Contagem 2 (1:00:00)', '1'],
    ['Usar o tempo de Contagem 1 (1:00:23)', '2'],
  ]);
  assert.deepEqual(tempos[0].decisao, { tipo: 'usar_tempo', passagem_id: 531, passagem_alvo_id: 533 });
  assert.equal(acaoDeConfirmar(REGISTRO_TARDIO), null, 'K would count one entry and leave the other');
  assert.match(descreverSugestao(REGISTRO_TARDIO), /de quem é o horário certo/);
});

test('using one time asks first, because the car gains a lap', () => {
  const [tempo] = temposParaEscolher(REGISTRO_TARDIO, ESTADO);
  assert.match(perguntaAoUsarTempo(REGISTRO_TARDIO, tempo, ESTADO), /^Usar o tempo de Contagem 2 \(1:00:00\)\? A passagem do carro 1 conta UMA vez, nesse horário \(\+1 volta\)/);
  // When the lap it makes is impossibly short, the short-lap question instead.
  assert.equal(perguntaAoUsarTempo({ ...REGISTRO_TARDIO, volta_resultante_ms: 60000 }, tempo, ESTADO), perguntaSobreVoltaCurta(60000));
});

test('a held, widely spread pass offers its times with no other entry to join', () => {
  const alerta = {
    tipo: 'dispersao_alta', carros: [20], passagem_ids: [253, 254],
    tempos_possiveis: [
      { passagem_id: 253, fonte_id: 1, ts_ms: LARGADA_MS + 60000, passagem_alvo_id: null },
      { passagem_id: 254, fonte_id: 2, ts_ms: LARGADA_MS + 70000, passagem_alvo_id: null },
    ],
  };
  assert.deepEqual(temposParaEscolher(alerta, ESTADO).map((tempo) => tempo.decisao), [
    { tipo: 'usar_tempo', passagem_id: 253 }, { tipo: 'usar_tempo', passagem_id: 254 },
  ]);
  // A set-apart entry (two others agree) has no times to choose from.
  assert.deepEqual(temposParaEscolher({ tipo: 'dispersao_alta', passagem_ids: [9], tempos_possiveis: null }, ESTADO), []);
});

// ---- the history names the car and the counter ---------------------------------

test('history lines name the entry\'s car and counter, and "usar_tempo" says what it did', () => {
  assert.equal(
    descreverDecisao({ tipo: 'confirmar', passagem_id: 357, carro_digitado: 3, carro: 3, fonte_nome: 'Contagem 1' }),
    'Confirmou a passagem #357 (carro 3, Contagem 1)',
  );
  assert.equal(
    descreverDecisao({ tipo: 'anular', passagem_id: 12, carro_digitado: 5, carro: 6, fonte_nome: 'Contagem 2' }),
    'Anulou a passagem #12 (carro 6, digitado 5, Contagem 2)',
  );
  assert.equal(
    descreverDecisao({ tipo: 'reatribuir', passagem_id: 12, equipe_numero: 6, carro_digitado: 5, carro: 6, fonte_nome: 'Contagem 2' }),
    'Trocou a passagem #12 (carro 5, Contagem 2) para o carro 6',
  );
  assert.equal(
    descreverDecisao({ tipo: 'usar_tempo', passagem_id: 531, passagem_alvo_id: 533, carro_digitado: 1, carro: 1, fonte_nome: 'Contagem 2' }),
    'Usou o tempo da passagem #531 (carro 1, Contagem 2) e juntou a #533 a ela (registrada com atraso)',
  );
  assert.equal(descreverDecisao({ tipo: 'desfazer', desfaz_decisao_id: 3, carro_digitado: 3, carro: 3, fonte_nome: 'Contagem 1' }),
    'Desfez a decisão #3 (carro 3, Contagem 1)');
});

// ---- the chips: who voided, and short laps that were confirmed --------------------

test('a voided chip says who voided it and why', () => {
  const doContador = { id: 352, anulada: true, decisoes: [ANULACAO_DO_CONTADOR] };
  assert.match(descricaoDoChip(doContador), /anulada pelo contador \(Contador1\) — digitei errado/);

  const daRevisao = { id: 353, anulada: true, decisoes: [{ id: 41, tipo: 'anular', origem: 'revisor', usuario: 'jbresolin', motivo: null }] };
  assert.match(descricaoDoChip(daRevisao), /anulada pela revisão \(jbresolin\)/);
  assert.doesNotMatch(descricaoDoChip(daRevisao), /—/);

  assert.match(descricaoDoChip({ id: 354, anulada_auto: true, decisoes: [] }), /anulada automaticamente \(repetida\)/);
});

test('a pass that ends a short lap a reviewer confirmed is marked, on the chip and in the car table', () => {
  const entrada = {
    id: 357, carro: 3, decisoes: [], grupo: { contado: true, papel: 'volta', numero_volta: 12, volta_curta_confirmada: true },
  };
  assert.ok(classesDoChip(entrada, [], null).includes('volta-curta-confirmada'));
  assert.match(descricaoDoChip(entrada), /VOLTA CURTA CONFIRMADA/);

  const carro = { carro: 3, voltas_curtas_confirmadas: [{ numero_volta: 12, ancora: 357, ts_ms: LARGADA_MS + 3600000, volta_ms: 71000 }] };
  assert.match(avisoDeVoltasCurtas(carro, LARGADA_MS), /volta 12 \(71 s, em 1:00:00\)/);
  assert.equal(avisoDeVoltasCurtas({ carro: 4, voltas_curtas_confirmadas: [] }), null);
});

test('a chip says whether its entry times the pass', () => {
  const grupo = { contado: true, papel: 'volta', numero_volta: 5, tempo_escolhido_id: 531 };
  assert.match(descricaoDoChip({ id: 531, decisoes: [], grupo }), /o horário desta passagem é o deste registro/);
  assert.match(descricaoDoChip({ id: 533, decisoes: [], grupo }), /não define o horário desta passagem/);
});
