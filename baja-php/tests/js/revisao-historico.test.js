// Tests for the decision history panel of the review screen: one list,
// filters over it, and what the system resolved on its own.
//
// Background for someone new to this. Every correction a reviewer makes
// (confirm an entry, void it, move it to another car, add a pass nobody
// typed, exclude a counter for a while, undo any of these) is stored as a
// "decision", and the history panel lists them. At 26SE (prova END) one
// race had 81 decisions, and the panel had two sections (the decisions in
// force on top, then everything, 50 at a time) and no filters. The
// reviewers asked to find "everything about car 7", "everything Contador1
// did", "only what is still in force". The engine's own resolutions (a
// double tap it ignored, a pit exit it assumed) were only in the flag queue.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import { descreverDecisao, fraseDoHistorico } from '../../default/js/contagem/revisao-decisoes.js';
import {
  FILTROS_VAZIOS, QUEM_AUTOMATICO, QUEM_CONTADOR, avisoDeHistoricoIncompleto, chaveDoHistorico, filtrarHistorico, guardarFiltros,
  historicoVazio, idadeDaLinha, lerFiltrosGuardados, linhasDoHistorico, normalizarFiltros, opcoesDosFiltros, resumoDoHistorico, temFiltro,
} from '../../default/js/contagem/revisao-historico.js';

const LARGADA_MS = 1760000000000;
const minuto = (n) => LARGADA_MS + n * 60000;

// A history row as DecisionHistory::describe() makes it, newest first.
function decisao(campos) {
  return {
    passagem_id: null, passagem_alvo_id: null, fonte_id: null, fonte_nome: null, carro_digitado: null, carro: null,
    passagem_ts_ms: null, equipe_numero: null, ts_ms: null, ts_fim_ms: null, desfaz_decisao_id: null,
    origem: 'revisor', usuario: 'jbresolin', motivo: null, ativa: true, pode_desfazer: true,
    ...campos,
  };
}

// A short race. Contador1 voided his own entry #352 on the phone and a
// reviewer undid that; car 3's entry #357 was confirmed; entry #400, typed
// as car 5, was moved to car 6; a pass was added for car 7; Contagem 3 was
// excluded until further notice. The engine ignored a double tap of car 7
// (#452) and assumed car 9 left the box.
const HISTORICO = [
  decisao({ id: 16, tipo: 'excluir_fonte', fonte_id: 3, fonte_nome: 'Contagem 3', ts_ms: minuto(50), criado_em_ms: minuto(55) }),
  decisao({ id: 15, tipo: 'adicionar', equipe_numero: 7, ts_ms: minuto(40), criado_em_ms: minuto(45) }),
  decisao({
    id: 14, tipo: 'reatribuir', passagem_id: 400, equipe_numero: 6, carro_digitado: 5, carro: 6, fonte_id: 2,
    fonte_nome: 'Contagem 2', passagem_ts_ms: minuto(30), criado_em_ms: minuto(35),
  }),
  decisao({
    id: 13, tipo: 'confirmar', passagem_id: 357, carro_digitado: 3, carro: 3, fonte_id: 1, fonte_nome: 'Contagem 1',
    passagem_ts_ms: minuto(20), criado_em_ms: minuto(25),
  }),
  decisao({
    id: 12, tipo: 'desfazer', desfaz_decisao_id: 11, passagem_id: null, carro_digitado: 3, carro: 3, fonte_id: 1,
    fonte_nome: 'Contagem 1', criado_em_ms: minuto(12), pode_desfazer: false,
  }),
  decisao({
    id: 11, tipo: 'anular', passagem_id: 352, carro_digitado: 3, carro: 3, fonte_id: 1, fonte_nome: 'Contagem 1',
    passagem_ts_ms: minuto(9), origem: 'contador', usuario: 'Contador1', motivo: 'digitei errado', criado_em_ms: minuto(10),
    ativa: false, pode_desfazer: false,
  }),
];

const REPETIDA_IGNORADA = {
  id: 'repetida:451-452', tipo: 'repetida', carros: [7], passagem_ids: [451, 452], fonte_id: 1, ts_ms: minuto(30.5),
  auto_resolvido: true, motivo: 'Contagem 1 registrou o carro 7 duas vezes em 1 s (toque duplo); a segunda passagem foi ignorada.',
};
const SAIDA_PRESUMIDA = {
  id: 'saida_presumida:600', tipo: 'saida_presumida', carros: [9], passagem_ids: [600, 601], fonte_id: null, ts_ms: minuto(15),
  auto_resolvido: false, motivo: 'O carro 9 constava como dentro do box…',
};

const ESTADO = {
  corrida: { largada_ms: LARGADA_MS },
  fontes: [{ id: 1, nome: 'Contagem 1' }, { id: 2, nome: 'Contagem 2' }, { id: 3, nome: 'Contagem 3' }],
  historico: HISTORICO,
  historico_completo: true,
  historico_em_vigor: [HISTORICO[0], HISTORICO[1]],
  alertas_resolvidos: [REPETIDA_IGNORADA],
  alertas_abertos: [{ id: 'solitaria:700', tipo: 'solitaria', carros: [4], passagem_ids: [700], ts_ms: minuto(50) }, SAIDA_PRESUMIDA],
  saidas_presumidas: [{ area: 'box', carro: 9, ts_ms: minuto(15) - 1000, entrada_ms: minuto(10) }],
};

const linhaDe = (linhas, chave) => linhas.find((linha) => linha.chave === chave);

// ---- one list ------------------------------------------------------------------

test('decisions and the system\'s own resolutions make one list, newest first', () => {
  const linhas = linhasDoHistorico(ESTADO);

  assert.deepEqual(linhas.map((linha) => linha.chave), [
    'decisao:16', 'decisao:15', 'decisao:14', 'alerta:repetida:451-452', 'decisao:13', 'alerta:saida_presumida:600',
    'decisao:12', 'decisao:11',
  ]);
  // An ordinary open flag is not something the system did: it is not in the history.
  assert.equal(linhaDe(linhas, 'alerta:solitaria:700'), undefined);
});

test('a decision still in force is marked "em vigor", an undone one "desfeita", and an undo is neither', () => {
  const linhas = linhasDoHistorico(ESTADO);

  assert.equal(linhaDe(linhas, 'decisao:13').emVigor, true);
  assert.equal(linhaDe(linhas, 'decisao:11').emVigor, false);
  assert.equal(linhaDe(linhas, 'decisao:11').desfeita, true);
  assert.equal(linhaDe(linhas, 'decisao:12').emVigor, false, 'an undo has no effect of its own to show');
  assert.equal(linhaDe(linhas, 'decisao:12').desfeita, false);
});

test('the system\'s resolutions are read-only lines that open their flag', () => {
  const linhas = linhasDoHistorico(ESTADO);
  const repetida = linhaDe(linhas, 'alerta:repetida:451-452');
  const saida = linhaDe(linhas, 'alerta:saida_presumida:600');

  assert.equal(repetida.texto, 'Sistema: ignorou a repetida #452 (carro 7, Contagem 1, toque duplo)');
  assert.equal(saida.texto, 'Sistema: presumiu a saída do box do carro 9 (as contagens o viram na linha)');
  for (const linha of [repetida, saida]) {
    assert.equal(linha.automatica, true);
    assert.equal(linha.decisao, null, 'nothing to undo');
    assert.deepEqual(linha.quem, [QUEM_AUTOMATICO]);
  }
  assert.deepEqual(repetida.alvo, { tipo: 'alerta', id: 'repetida:451-452' });
  assert.equal(repetida.tipo, 'repetida_ignorada');
});

test('the same car typed on two devices of one counter is said so', () => {
  const linhas = linhasDoHistorico({
    ...ESTADO,
    alertas_resolvidos: [{
      ...REPETIDA_IGNORADA,
      motivo: 'Contagem 1 registrou o carro 7 em dois aparelhos (troca de contador); a segunda passagem foi ignorada.',
    }],
  });
  assert.equal(linhaDe(linhas, 'alerta:repetida:451-452').texto, 'Sistema: ignorou a repetida #452 (carro 7, Contagem 1, troca de contador)');
});

test('an assumed exit of the fueling station names it', () => {
  const linhas = linhasDoHistorico({ ...ESTADO, saidas_presumidas: [{ area: 'abastecimento', carro: 9, ts_ms: minuto(14), entrada_ms: minuto(10) }] });
  assert.match(linhaDe(linhas, 'alerta:saida_presumida:600').texto, /presumiu a saída do abastecimento do carro 9/);
});

test('a history cut short by the server still holds every decision in force, and says it is incomplete', () => {
  const antiga = decisao({ id: 2, tipo: 'excluir_fonte', fonte_id: 3, ts_ms: minuto(1), criado_em_ms: minuto(2) });
  const estado = { ...ESTADO, historico: HISTORICO.slice(0, 3), historico_completo: false, historico_em_vigor: [HISTORICO[0], antiga] };
  const linhas = linhasDoHistorico(estado);

  assert.deepEqual(linhas.filter((linha) => linha.decisao).map((linha) => linha.decisao.id), [16, 15, 14, 2]);
  assert.match(avisoDeHistoricoIncompleto(estado), /^Só as 3 decisões mais recentes estão aqui/);
  assert.doesNotMatch(avisoDeHistoricoIncompleto(estado), /todas as que estão em vigor/);
  assert.equal(avisoDeHistoricoIncompleto(ESTADO), null);
});

test('a state stored before the whole history was sent still lists the older decisions in force', () => {
  // The old shape: the newest page, "historico_ha_mais", no "historico_completo".
  // A stored snapshot keeps it until the race changes again.
  const antiga = decisao({ id: 2, tipo: 'marcar_saida', ts_ms: minuto(1), criado_em_ms: minuto(2) });
  const { historico_completo: _, ...semCompleto } = ESTADO;
  const estado = { ...semCompleto, historico: HISTORICO.slice(0, 3), historico_ha_mais: true, historico_em_vigor: [HISTORICO[0], antiga] };

  assert.deepEqual(linhasDoHistorico(estado).filter((linha) => linha.decisao).map((linha) => linha.decisao.id), [16, 15, 14, 2]);
  assert.match(avisoDeHistoricoIncompleto(estado), /^Só as 3 decisões mais recentes estão aqui/);
  assert.equal(avisoDeHistoricoIncompleto({ ...semCompleto, historico_ha_mais: false }), null);
});

// ---- what each line says -----------------------------------------------------

test('every type of line says which car and counter, and an undo says what it undid', () => {
  const linhas = linhasDoHistorico(ESTADO);
  const texto = (id) => linhaDe(linhas, `decisao:${id}`).texto;

  assert.equal(texto(16), 'Excluiu a fonte Contagem 3 até segunda ordem: desde 0:50:00');
  assert.equal(texto(15), 'Adicionou uma passagem do carro 7 em 0:40:00');
  assert.equal(texto(14), 'Trocou a passagem #400 (carro 5, Contagem 2) para o carro 6');
  assert.equal(texto(13), 'Confirmou a passagem #357 (carro 3, Contagem 1)');
  assert.equal(texto(12), 'Desfez a decisão #11: anulou a passagem #352 (carro 3, Contagem 1)');
  assert.equal(texto(11), 'Anulou a passagem #352 (carro 3, Contagem 1) — digitei errado');
});

test('an undo of an added pass says which pass and when', () => {
  const adicao = decisao({ id: 20, tipo: 'adicionar', equipe_numero: 7, ts_ms: minuto(40), criado_em_ms: minuto(41), ativa: false });
  const desfeita = decisao({ id: 21, tipo: 'desfazer', desfaz_decisao_id: 20, criado_em_ms: minuto(42), pode_desfazer: false });
  const linhas = linhasDoHistorico({ ...ESTADO, historico: [desfeita, adicao], alertas_resolvidos: [], alertas_abertos: [] });

  assert.equal(linhaDe(linhas, 'decisao:21').texto, 'Desfez a decisão #20: adicionou uma passagem do carro 7 em 0:40:00');
  assert.deepEqual(linhaDe(linhas, 'decisao:21').carros, [7]);
  assert.deepEqual(linhaDe(linhas, 'decisao:21').alvo, { tipo: 'carro', numero: 7, momentoMs: minuto(40), passagemIds: [] });
});

test('"usar o tempo" reads well with and without a late entry joined to it', () => {
  const base = { tipo: 'usar_tempo', passagem_id: 531, carro_digitado: 1, carro: 1, fonte_nome: 'Contagem 2' };
  assert.equal(descreverDecisao({ ...base, passagem_alvo_id: 533 }),
    'Usou o tempo da passagem #531 (carro 1, Contagem 2) e juntou a #533 a ela (registrada com atraso)');
  assert.equal(descreverDecisao(base), 'Usou o horário da passagem #531 (carro 1, Contagem 2) para a volta');
});

test('"usar o tempo" with a late entry joined is found under either counter, and so is its undo', () => {
  const usar = decisao({
    id: 30, tipo: 'usar_tempo', passagem_id: 531, passagem_alvo_id: 533, carro_digitado: 1, carro: 1, fonte_id: 2,
    fonte_nome: 'Contagem 2', fonte_alvo_id: 1, fonte_alvo_nome: 'Contagem 1', passagem_ts_ms: minuto(60), criado_em_ms: minuto(61),
    ativa: false, pode_desfazer: false,
  });
  const desfez = decisao({
    id: 31, tipo: 'desfazer', desfaz_decisao_id: 30, carro_digitado: 1, carro: 1, fonte_id: 2, fonte_nome: 'Contagem 2',
    fonte_alvo_id: 1, criado_em_ms: minuto(62), pode_desfazer: false,
  });
  const linhas = linhasDoHistorico({ ...ESTADO, historico: [desfez, usar] });
  const com = (fonte) => ids(filtrarHistorico(linhas, { ...FILTROS_VAZIOS, fonte })).filter((chave) => ['decisao:30', 'decisao:31'].includes(chave));

  assert.deepEqual(com('2'), ['decisao:31', 'decisao:30'], 'the counter whose time was chosen');
  assert.deepEqual(com('1'), ['decisao:31', 'decisao:30'], 'the late counter, whose entry now counts as a witness');
  assert.deepEqual(com('3'), []);
});

test('an undo whose decision is not in the list still names the entry', () => {
  assert.equal(fraseDoHistorico({ tipo: 'desfazer', desfaz_decisao_id: 3, carro_digitado: 3, carro: 3, fonte_nome: 'Contagem 1' }),
    'Desfez a decisão #3 (carro 3, Contagem 1)');
});

test('a marked exit does not repeat its area as a reason', () => {
  assert.equal(
    fraseDoHistorico({ tipo: 'marcar_saida', equipe_numero: 41, ts_ms: minuto(5), motivo: 'box' }, { largadaMs: LARGADA_MS }),
    'Marcou a saída do carro 41 do box (ninguém registrou na hora) em 0:05:00',
  );
});

// ---- where a click goes ----------------------------------------------------------

test('a click on a line opens the car\'s timeline where the decision acts', () => {
  const linhas = linhasDoHistorico(ESTADO);

  assert.deepEqual(linhaDe(linhas, 'decisao:13').alvo, { tipo: 'carro', numero: 3, momentoMs: minuto(20), passagemIds: [357] });
  // A moved entry is under the car it counts for now.
  assert.deepEqual(linhaDe(linhas, 'decisao:14').alvo, { tipo: 'carro', numero: 6, momentoMs: minuto(30), passagemIds: [400] });
  // An added pass is drawn with minus its decision's id.
  assert.deepEqual(linhaDe(linhas, 'decisao:15').alvo, { tipo: 'carro', numero: 7, momentoMs: minuto(40), passagemIds: [-15] });
  assert.equal(linhaDe(linhas, 'decisao:16').alvo, null, 'a source exclusion is about no car');
  // The undo of a void opens the entry it restored.
  assert.deepEqual(linhaDe(linhas, 'decisao:12').alvo, { tipo: 'carro', numero: 3, momentoMs: minuto(9), passagemIds: [352] });
});

// ---- filters -------------------------------------------------------------------

const ids = (linhas) => linhas.map((linha) => linha.chave);

test('the car filter matches the car typed or the car the entry counts for now', () => {
  const linhas = linhasDoHistorico(ESTADO);

  assert.deepEqual(ids(filtrarHistorico(linhas, { ...FILTROS_VAZIOS, carro: '5' })), ['decisao:14'], 'typed as 5');
  assert.deepEqual(ids(filtrarHistorico(linhas, { ...FILTROS_VAZIOS, carro: '6' })), ['decisao:14'], 'counts for 6 now');
  assert.deepEqual(ids(filtrarHistorico(linhas, { ...FILTROS_VAZIOS, carro: '7' })), ['decisao:15', 'alerta:repetida:451-452']);
  assert.deepEqual(ids(filtrarHistorico(linhas, { ...FILTROS_VAZIOS, carro: '3' })), ['decisao:13', 'decisao:12', 'decisao:11']);
});

test('the counter, person and type filters, and "só em vigor"', () => {
  const linhas = linhasDoHistorico(ESTADO);
  const com = (filtros) => ids(filtrarHistorico(linhas, { ...FILTROS_VAZIOS, ...filtros }));

  assert.deepEqual(com({ fonte: '1' }), ['alerta:repetida:451-452', 'decisao:13', 'decisao:12', 'decisao:11']);
  assert.deepEqual(com({ fonte: '3' }), ['decisao:16']);
  assert.deepEqual(com({ quem: QUEM_CONTADOR }), ['decisao:11']);
  assert.deepEqual(com({ quem: 'usuario:Contador1' }), ['decisao:11']);
  assert.deepEqual(com({ quem: QUEM_AUTOMATICO }), ['alerta:repetida:451-452', 'alerta:saida_presumida:600']);
  assert.deepEqual(com({ tipo: 'confirmar' }), ['decisao:13']);
  assert.deepEqual(com({ tipo: 'repetida_ignorada' }), ['alerta:repetida:451-452']);
  assert.deepEqual(com({ soEmVigor: true }), [
    'decisao:16', 'decisao:15', 'decisao:14', 'alerta:repetida:451-452', 'decisao:13', 'alerta:saida_presumida:600',
  ]);
});

test('filters combine', () => {
  const linhas = linhasDoHistorico(ESTADO);
  assert.deepEqual(ids(filtrarHistorico(linhas, { ...FILTROS_VAZIOS, carro: '3', soEmVigor: true })), ['decisao:13']);
  assert.deepEqual(ids(filtrarHistorico(linhas, { ...FILTROS_VAZIOS, carro: '3', quem: QUEM_AUTOMATICO })), []);
});

test('the count, and the sentence when nothing is left', () => {
  const decisoes = (n) => Array.from({ length: n }, () => ({ automatica: false }));
  const doSistema = (n) => Array.from({ length: n }, () => ({ automatica: true }));
  const carro7 = { ...FILTROS_VAZIOS, carro: '7' };
  assert.equal(resumoDoHistorico(decisoes(8), decisoes(8), FILTROS_VAZIOS), '8 decisões');
  assert.equal(resumoDoHistorico(decisoes(1), decisoes(81), carro7), '1 de 81 decisões');
  assert.equal(resumoDoHistorico(decisoes(1), decisoes(1), FILTROS_VAZIOS), '1 decisão');
  // What the system resolved on its own is not a decision: counted apart.
  const todas = [...decisoes(81), ...doSistema(2)];
  assert.equal(resumoDoHistorico(todas, todas, FILTROS_VAZIOS), '81 decisões + 2 do sistema');
  assert.equal(resumoDoHistorico([...decisoes(3), ...doSistema(1)], todas, carro7), '3 de 81 decisões + 1 do sistema');
  assert.equal(resumoDoHistorico(decisoes(3), todas, carro7), '3 de 81 decisões');
  assert.equal(resumoDoHistorico(doSistema(2), todas, { ...FILTROS_VAZIOS, quem: QUEM_AUTOMATICO }), '0 de 81 decisões + 2 do sistema');
  assert.equal(historicoVazio(0), 'Nenhuma decisão ainda.');
  assert.match(historicoVazio(81), /^Nenhuma decisão com esses filtros/);
});

test('the lists offer every counter, every reviewer once, the counter on the phone and the system', () => {
  const opcoes = opcoesDosFiltros(ESTADO, linhasDoHistorico(ESTADO));

  assert.deepEqual(opcoes.fontes.map((opcao) => opcao.rotulo), ['Contagem 1', 'Contagem 2', 'Contagem 3']);
  // Contador1 only voided on the phone: that is "contador (no aparelho)",
  // not a person of the list.
  assert.deepEqual(opcoes.quem.map((opcao) => opcao.rotulo), ['jbresolin', 'contador (no aparelho)', 'automático']);
  assert.ok(opcoes.tipos.some((opcao) => opcao.valor === 'usar_tempo'));
});

test('a remembered choice that is not in this race is still shown', () => {
  const opcoes = opcoesDosFiltros(ESTADO, linhasDoHistorico(ESTADO), { ...FILTROS_VAZIOS, quem: 'usuario:outra', fonte: '9' });
  assert.equal(opcoes.quem.at(-1).valor, 'usuario:outra');
  assert.equal(opcoes.fontes.at(-1).valor, '9');
});

test('filters are cleaned up: a car must be a number, the rest strings', () => {
  assert.deepEqual(normalizarFiltros({ carro: ' 12 ', fonte: 2, quem: null, tipo: ['x'], soEmVigor: 'sim' }),
    { carro: '12', fonte: '2', quem: '', tipo: '', soEmVigor: false });
  assert.equal(normalizarFiltros({ carro: '1e3' }).carro, '');
  assert.equal(temFiltro(FILTROS_VAZIOS), false);
  assert.equal(temFiltro({ soEmVigor: true }), true);
});

// ---- remembered across reloads ---------------------------------------------------

function armazenamentoFalso() {
  const dados = new Map();
  return {
    dados,
    getItem: (chave) => (dados.has(chave) ? dados.get(chave) : null),
    setItem: (chave, valor) => dados.set(chave, String(valor)),
    removeItem: (chave) => dados.delete(chave),
  };
}

test('the filters are remembered per prova, and clearing them forgets them', () => {
  const armazenamento = armazenamentoFalso();
  guardarFiltros(armazenamento, 'END', { ...FILTROS_VAZIOS, carro: '7', soEmVigor: true });

  assert.deepEqual(lerFiltrosGuardados(armazenamento, 'END'), { ...FILTROS_VAZIOS, carro: '7', soEmVigor: true });
  assert.deepEqual(lerFiltrosGuardados(armazenamento, 'ACEL'), FILTROS_VAZIOS, 'another prova starts empty');

  guardarFiltros(armazenamento, 'END', FILTROS_VAZIOS);
  assert.equal(armazenamento.dados.size, 0);
});

test('storage that refuses or holds rubbish leaves the filters empty, without an error', () => {
  const recusa = { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('QuotaExceededError'); }, removeItem() {} };
  assert.deepEqual(lerFiltrosGuardados(recusa, 'END'), FILTROS_VAZIOS);
  assert.doesNotThrow(() => guardarFiltros(recusa, 'END', { carro: '7' }));
  assert.deepEqual(lerFiltrosGuardados(null, 'END'), FILTROS_VAZIOS);

  const lixo = armazenamentoFalso();
  lixo.setItem('contagem-revisao-historico:END', '{não é json');
  assert.deepEqual(lerFiltrosGuardados(lixo, 'END'), FILTROS_VAZIOS);
});

// ---- what is redrawn when ---------------------------------------------------------

test('a line\'s age reads "agora", never "há agora", and moves in the bar\'s steps', () => {
  const linha = { quandoMs: minuto(10) };
  const aos = (ms) => idadeDaLinha({ servidor_agora_ms: minuto(10) + ms }, linha);
  assert.equal(aos(500), 'agora');
  assert.equal(aos(1999), 'agora');
  assert.equal(aos(16000), 'há 15 s');
  assert.equal(aos(3 * 60000 + 5000), 'há 3 min');
});

test('the history is redrawn when a decision changes, not at every recalculation or status change', () => {
  // During a race the state's version changes every few seconds and cars go
  // "parado" and back; the history drew 2,700 nodes each time for nothing.
  const agora = minuto(120);
  const estado = { ...ESTADO, versao: 'a', servidor_agora_ms: agora, carros: [{ carro: 3, status: 'normal' }] };
  const chave = chaveDoHistorico(estado);

  assert.equal(chaveDoHistorico({ ...estado, versao: 'b', carros: [{ carro: 3, status: 'parado' }] }), chave, 'new version, new status');
  assert.equal(chaveDoHistorico({ ...estado, servidor_agora_ms: agora + 20000 }), chave, 'the same minute');
  assert.notEqual(chaveDoHistorico({ ...estado, servidor_agora_ms: agora + 60000 }), chave, 'the ages move once a minute');

  const desfeita = HISTORICO.map((linha) => (linha.id === 13 ? { ...linha, ativa: false, pode_desfazer: false } : linha));
  assert.notEqual(chaveDoHistorico({ ...estado, historico: desfeita }), chave, 'a decision undone');
  const nova = decisao({ id: 17, tipo: 'confirmar', passagem_id: 800, carro_digitado: 4, carro: 4, criado_em_ms: agora - 1000 });
  const comNova = { ...estado, historico: [nova, ...HISTORICO] };
  assert.notEqual(chaveDoHistorico(comNova), chave, 'a new decision');
  // A decision of the last minute: its age moves in 15 s steps.
  assert.notEqual(chaveDoHistorico({ ...comNova, servidor_agora_ms: agora + 15000 }), chaveDoHistorico(comNova));
  assert.equal(chaveDoHistorico({ ...comNova, servidor_agora_ms: agora + 5000 }), chaveDoHistorico(comNova));
});
