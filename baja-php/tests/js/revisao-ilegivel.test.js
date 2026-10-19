// Tests for how the review screen shows passes recorded without a number
// ("??") and a timing sensor's marks.
//
// Background for someone new to this. A counter (or a camera) that sees a
// car cross and cannot read it records "??": an entry with no car. The
// engine gives it a car by itself when the other counters' readings make
// that safe ("numero_atribuido", resolved automatically), and otherwise
// raises "numero_ilegivel" with the cars it may have been ("candidatos").
// The counter may also say on the phone which car it was, and a reviewer
// may. A timing sensor marks the moment of every crossing and never the
// car: a mark the engine matched times the counters' pass ("tempo do
// sensor"); one with no pass is usually noise.
//
// Run with:  node --test baja-php/tests/js/

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  atribuicaoDoAceitar, atribuicoesAutomaticas, candidatosDoAlerta, candidatosParaTrocar, carrosDoAlertaIlegivel, efeitoDeAtribuir, indiceDeCandidato,
  linhasDoSensor, listaDeNomes, marcadorDoChip, nomeDoAlertaIlegivel, problemaAoAtribuir, situacaoDoIlegivel, textoDoChip,
  textoDosIlegiveis, dicaDosIlegiveisDaFonte, resumoDoSensor,
} from '../../default/js/contagem/revisao-ilegivel.js';
import { classesDoChip, descricaoDoChip, entradasLigadas } from '../../default/js/contagem/revisao-raias-logica.js';
import { acaoDeAceitar, acaoDeConfirmar, fraseDeDuvida } from '../../default/js/contagem/revisao-alertas.js';
import { acoesDaEntrada, descreverDecisao, fraseDoHistorico } from '../../default/js/contagem/revisao-decisoes.js';
import { linhasDoHistorico, opcoesDosFiltros } from '../../default/js/contagem/revisao-historico.js';
import { destaqueDaLinha } from '../../default/js/contagem/revisao-linha-logica.js';
import { momentosDoAlerta } from '../../default/js/contagem/revisao-fila.js';
import { fontesDaTabela, linhasDaObservacao } from '../../default/js/contagem/revisao-observacao.js';
import { colunasDeFontes, sinaisDoCarro } from '../../default/js/contagem/revisao-carros.js';
import { descreverChip } from '../../default/js/contagem/revisao-barra.js';

const LARGADA_MS = 1_760_000_000_000;
const em = (segundos) => LARGADA_MS + segundos * 1000;

const FONTES = [
  { id: 1, nome: 'Contagem 1', tipo: 'manual', ativa: true, papel: 'votante', stats: {} },
  { id: 2, nome: 'Contagem 2', tipo: 'manual', ativa: true, papel: 'votante', stats: {} },
  { id: 3, nome: 'Contagem 3', tipo: 'manual', ativa: true, papel: 'votante', stats: {} },
  { id: 9, nome: 'Sensor', tipo: 'sensor', ativa: true, papel: 'votante', stats: {} },
];
const nomeDaFonte = (id) => FONTES.find((fonte) => fonte.id === id)?.nome ?? null;

// An entry as TimelineWindow::describeEntry sends it.
function entrada(campos) {
  return {
    id: 1, fonte_id: 2, carro: null, carro_digitado: null, ts_ms: em(600), qualidade_tempo: 'exato',
    anulada: false, anulada_auto: false, reatribuida: false, ilegivel: false, atribuida_automaticamente: false,
    fora_de_uso: false, motivo_fora_de_uso: null, adicionada_por_revisor: false, decisoes: [], grupo: null,
    ...campos,
  };
}

const grupoContado = (ids, campos = {}) => ({
  passagem_ids: ids, papel: 'volta', contado: true, numero_volta: 9, volta_ms: 230000, soma_ao_confirmar: false, ...campos,
});

const pendente = () => entrada({ id: 123, ilegivel: true, fora_de_uso: true, motivo_fora_de_uso: 'ilegivel' });
const automatico = () => entrada({ id: 123, ilegivel: true, carro: 7, atribuida_automaticamente: true, grupo: grupoContado([11, 31, 123]) });
const troca = (origem, id = 40) => ({ id, tipo: 'reatribuir', origem, usuario: origem === 'contador' ? 'Contador2' : 'jbresolin' });

const ATRIBUICAO = { carro: 7, ts_ms: em(600) - 400, desvio_ms: 400, margem_ms: null, passagem_ids: [11, 31], fonte_ids: [1, 3] };
const resolvido = () => ({
  id: 'numero_atribuido:123', tipo: 'numero_atribuido', carros: [7], passagem_ids: [123, 11, 31], fonte_id: 2, ts_ms: em(600),
  auto_resolvido: true, atribuicao: ATRIBUICAO, motivo: 'Contagem 2 registrou uma passagem sem número ("??"); …',
});

// ---- the chips ---------------------------------------------------------------------

test('a "??" nobody gave a car shows "??" in a look of its own, never the grey of a void', () => {
  const chip = pendente();
  const classes = classesDoChip(chip, [], null);
  assert.ok(classes.includes('ilegivel-pendente'));
  assert.ok(!classes.includes('fora-de-uso'));
  assert.ok(!classes.includes('anulada'));
  assert.equal(textoDoChip(chip), '??');
  assert.equal(situacaoDoIlegivel(chip), 'pendente');
  assert.equal(marcadorDoChip(chip), null);
  assert.match(descricaoDoChip(chip), /^passagem sem número \("\?\?"\) #123 · ainda sem carro: não conta até alguém dizer qual foi$/);
});

test('a pending "??" is not dimmed when a car is in focus: it may be that car', () => {
  const classes = classesDoChip(pendente(), [], null, [7]);
  assert.ok(!classes.includes('apagada'));
  // An entry of another car still is.
  assert.ok(classesDoChip(entrada({ id: 5, carro: 12, carro_digitado: 12 }), [], null, [7]).includes('apagada'));
  // An auto-assigned one belongs to its car: dimmed under another car, drawn weakly under its own.
  assert.ok(classesDoChip(automatico(), [], null, [12]).includes('apagada'));
  assert.ok(classesDoChip(automatico(), [], null, [7]).includes('do-carro'));
});

test('a "??" the engine gave a car shows the car with an "auto" marker, and the tooltip says why', () => {
  const chip = automatico();
  assert.equal(textoDoChip(chip), '7');
  assert.deepEqual(marcadorDoChip(chip), { texto: 'auto', classe: 'marcador marcador-auto', titulo: 'atribuído automaticamente' });
  const classes = classesDoChip(chip, [], null);
  assert.ok(classes.includes('ilegivel-automatico'));
  assert.ok(classes.includes('contada'));
  assert.ok(!classes.includes('reatribuida'));

  const contexto = { atribuicoes: atribuicoesAutomaticas({ alertas_resolvidos: [resolvido()] }), nomeDaFonte };
  assert.match(descricaoDoChip(chip, contexto),
    /atribuído automaticamente ao carro 7: Contagem 1 e 3 leram o carro 7 a 0,4 s · volta 9 · 3:50/);
  // Without the flag (an older state) it still says what happened.
  assert.match(descricaoDoChip(chip), /atribuído automaticamente ao carro 7/);
});

test('a "??" a person gave a car: "C" for its own counter on the phone, "R" for a reviewer', () => {
  const doContador = entrada({ id: 123, ilegivel: true, carro: 7, reatribuida: true, decisoes: [troca('contador')] });
  const doRevisor = entrada({ id: 124, ilegivel: true, carro: 7, reatribuida: true, decisoes: [troca('revisor')] });
  assert.equal(marcadorDoChip(doContador).texto, 'C');
  assert.equal(marcadorDoChip(doRevisor).texto, 'R');
  assert.ok(classesDoChip(doContador, [], null).includes('ilegivel-contador'));
  assert.ok(classesDoChip(doRevisor, [], null).includes('ilegivel-revisor'));
  assert.match(descricaoDoChip(doContador), /o contador informou no aparelho: carro 7 \(Contador2\)/);
  assert.match(descricaoDoChip(doRevisor), /atribuído ao carro 7 pela revisão \(jbresolin\)/);
  // Never "digitado null, trocado para 7".
  assert.doesNotMatch(descricaoDoChip(doRevisor), /digitado/);

  // The counter fixed it three times: 17, 71, 17. The newest counts.
  const varias = entrada({ id: 125, ilegivel: true, carro: 17, reatribuida: true, decisoes: [troca('contador', 41), troca('revisor', 39)] });
  assert.equal(situacaoDoIlegivel(varias), 'contador');
});

test('a voided "??" is a void like any other', () => {
  const anulado = entrada({ id: 123, ilegivel: true, anulada: true, decisoes: [{ id: 3, tipo: 'anular', origem: 'revisor', usuario: 'ana' }] });
  assert.deepEqual(classesDoChip(anulado, [], null), ['chip', 'anulada']);
  assert.equal(textoDoChip(anulado), '??');
  assert.match(descricaoDoChip(anulado), /anulada pela revisão \(ana\)/);
});

// ---- the sensor ----------------------------------------------------------------------

test('a sensor mark that times a pass is a solid tick tied to it; one with no pass is faint', () => {
  const daPassagem = entrada({ id: 90, fonte_id: 9, ilegivel: true, marca_do_sensor: true, grupo: grupoContado([11, 31], { tempo_do_sensor: true, marca_sensor_id: 90 }) });
  const semPassagem = entrada({ id: 91, fonte_id: 9, ilegivel: true, fora_de_uso: true, motivo_fora_de_uso: 'marca_do_sensor' });

  assert.deepEqual(classesDoChip(daPassagem, [], null, [], { naRaiaDoSensor: true }), ['marca-sensor', 'com-passagem', 'contada']);
  assert.deepEqual(classesDoChip(semPassagem, [], null, [], { naRaiaDoSensor: true }), ['marca-sensor', 'sem-passagem']);
  assert.equal(textoDoChip(daPassagem, true), '');
  assert.match(descricaoDoChip(daPassagem, { naRaiaDoSensor: true }), /marca do sensor: tempo desta passagem · volta 9/);
  assert.match(descricaoDoChip(semPassagem, { naRaiaDoSensor: true }), /marca sem passagem/);
  assert.doesNotMatch(descricaoDoChip(semPassagem, { naRaiaDoSensor: true }), /fora de uso/);

  // The counters' chips of that pass get "S", and their tooltip says whose time it is.
  const doContador = entrada({ id: 11, fonte_id: 1, carro: 7, carro_digitado: 7, grupo: daPassagem.grupo });
  assert.ok(classesDoChip(doContador, [], null).includes('tempo-do-sensor'));
  assert.match(descricaoDoChip(doContador), /horário desta passagem: a marca do sensor \(preciso\)/);

  // Picking one shows the other: the mark and the counters' entries are tied.
  const janela = { raias: [{ fonte_id: 1, entradas: [doContador] }, { fonte_id: 9, tipo: 'sensor', entradas: [daPassagem, semPassagem] }] };
  assert.deepEqual(entradasLigadas(janela, 11), [90]);
  assert.deepEqual(entradasLigadas(janela, 90), [11, 31]);
  assert.deepEqual(entradasLigadas(janela, 91), []);
  assert.ok(classesDoChip(daPassagem, [], 11, [], { naRaiaDoSensor: true, ligadas: [90] }).includes('ligada'));
});

test('a sensor mark is voided or given a car, never confirmed or discarded with the counters\' pass', () => {
  const daPassagem = entrada({ id: 90, fonte_id: 9, ilegivel: true, marca_do_sensor: true, grupo: grupoContado([11, 31], { soma_ao_confirmar: false }) });
  assert.deepEqual(acoesDaEntrada(daPassagem).map((opcao) => opcao.acao), ['anular', 'reatribuir']);
});

test('a sensor mark that times a pass is not a "??" to identify: it belongs to its pass\'s car', () => {
  const daPassagem = entrada({ id: 90, fonte_id: 9, ilegivel: true, marca_do_sensor: true, grupo: grupoContado([11, 31], { carro: 7 }) });
  const semPassagem = entrada({ id: 91, fonte_id: 9, ilegivel: true, fora_de_uso: true, motivo_fora_de_uso: 'marca_do_sensor' });
  // The panel asks "which car?" only of a pending one (partesDoIlegivel).
  assert.equal(situacaoDoIlegivel(daPassagem), 'marca');
  assert.equal(situacaoDoIlegivel(semPassagem), 'pendente');
  assert.equal(marcadorDoChip(daPassagem), null);
  // Under car focus it goes with its pass: weak under car 7, dimmed under car 12.
  assert.ok(classesDoChip(daPassagem, [], null, [7], { naRaiaDoSensor: true }).includes('do-carro'));
  assert.ok(classesDoChip(daPassagem, [], null, [12], { naRaiaDoSensor: true }).includes('apagada'));
  // A mark with no pass may be any car's: never dimmed.
  assert.ok(!classesDoChip(semPassagem, [], null, [12], { naRaiaDoSensor: true }).includes('apagada'));
});

// ---- the candidates of a "??" flag -----------------------------------------------------

// A "??" of Contagem 2 at 10:00; Contagem 1 and 3 read car 7 0.4 s before
// (a pass that counts), Contagem 3 alone read car 5 1.5 s after.
function cenario() {
  const ilegivel = pendente();
  const grupo7 = grupoContado([11, 31]);
  const sozinho5 = { passagem_ids: [32], papel: 'pendente', contado: false, soma_ao_confirmar: true, volta_resultante_ms: 30000 };
  const janela = {
    raias: [
      { fonte_id: 1, tipo: 'manual', entradas: [entrada({ id: 11, fonte_id: 1, carro: 7, carro_digitado: 7, ts_ms: em(600) - 500, grupo: grupo7 })] },
      { fonte_id: 2, tipo: 'manual', entradas: [ilegivel] },
      { fonte_id: 3, tipo: 'manual', entradas: [
        entrada({ id: 31, fonte_id: 3, carro: 7, carro_digitado: 7, ts_ms: em(600) - 300, grupo: grupo7 }),
        entrada({ id: 32, fonte_id: 3, carro: 5, carro_digitado: 5, ts_ms: em(601) + 500, grupo: sozinho5 }),
      ] },
    ],
  };
  const alerta = {
    id: 'numero_ilegivel:123', tipo: 'numero_ilegivel', carros: [7, 12, 3], passagem_ids: [123], fonte_id: 2, ts_ms: em(600),
    sugestao: { tipo: 'reatribuir', passagem_id: 123, equipe_numero: 7 },
    contagem_em_duvida: null,
    candidatos: [
      { carro: 7, motivo: 'outros_contadores', score: 0.95, ts_ms: em(600) - 400 },
      { carro: 5, motivo: 'outros_contadores', score: 0.8, ts_ms: em(601) + 500 },
      { carro: 12, motivo: 'carro_esperado', score: 0.4, ts_ms: null },
      { carro: 3, motivo: 'volta_faltando', score: 0.3, ts_ms: null },
    ],
  };
  const estado = {
    fontes: FONTES,
    configuracoes: { dispersao_alerta_ms: 4000, janela_ms: 20000, fracao_volta_minima: 0.5 },
    carros: [{ carro: 5, volta_esperada_ms: 230000 }, { carro: 12, ultima_passagem_ms: em(400), volta_esperada_ms: 230000 }],
    carros_validos: [5, 7, 12, 3].map((numero) => ({ numero })),
    corrida: { largada_ms: LARGADA_MS },
  };
  return { ilegivel, janela, alerta, estado };
}

test('the candidates are big buttons, likeliest first, numbered 1 to 6, each saying why', () => {
  const { janela, alerta, estado } = cenario();
  const candidatos = candidatosDoAlerta(alerta, janela, estado);
  assert.deepEqual(candidatos.map((candidato) => [candidato.tecla, candidato.rotulo]), [
    ['1', 'Carro 7 — Contagem 1 e 3 leram o carro 7 a 0,4 s'],
    ['2', 'Carro 5 — Contagem 3 leu o carro 5 a 1,5 s (+1 volta)'],
    ['3', 'Carro 12 — esperado por volta deste horário'],
    ['4', 'Carro 3 — ficaria sem esta volta'],
  ]);
  // While the timeline loads, who read it is not known yet.
  assert.equal(candidatosDoAlerta(alerta, null, estado)[0].rotulo, 'Carro 7 — outra contagem leu o carro 7 a 0,4 s');
  // Keys 1 to 6 press them; anything else does nothing.
  assert.equal(indiceDeCandidato('2', candidatos), 1);
  assert.equal(indiceDeCandidato('5', candidatos), null);
  assert.equal(indiceDeCandidato('a', candidatos), null);
});

test('what giving the "??" each car does, and what is asked first', () => {
  const { ilegivel, janela, estado } = cenario();
  // Joins a pass that counts already: nothing changes, nothing asked.
  assert.deepEqual(efeitoDeAtribuir(ilegivel, 7, { janela, estado }),
    { voltas: 0, pergunta: null, nota: 'junta-se à passagem do carro 7, que já conta' });
  // The only other reading is one counter's: two witnesses now, it may count; asked, with the short lap it would make.
  const comCinco = efeitoDeAtribuir(ilegivel, 5, { janela, estado });
  assert.equal(comCinco.voltas, 1);
  assert.match(comCinco.pergunta, /^Atribuir este "\?\?" ao carro 5\? Só Contagem 3 registrou o carro 5 aqui; .*\(\+1 volta\)\. Além disso, ela criaria uma volta de 0:30, curta demais/);
  // Nobody read the car: a lone entry, which does not count by itself.
  assert.deepEqual(efeitoDeAtribuir(ilegivel, 12, { janela, estado }),
    { voltas: 0, pergunta: null, nota: 'fica como registro solitário do carro 12: sozinho, ainda não conta' });
  // A sensor's mark counts on the reviewer's word: always asked.
  const marca = entrada({ id: 91, fonte_id: 9, ilegivel: true, ts_ms: em(600) });
  const daMarca = efeitoDeAtribuir(marca, 12, { janela, estado, sensor: true });
  assert.equal(daMarca.voltas, 1);
  assert.match(daMarca.pergunta, /^Atribuir esta marca do sensor ao carro 12\? Nenhum contador registrou esta passagem: ela passa a CONTAR/);
  assert.doesNotMatch(daMarca.pergunta, /curta demais/, 'its last pass was 200 s before');
});

test('"Não é o carro 7": the other cars read near a "??" the engine assigned', () => {
  const { janela, estado } = cenario();
  const auto = { ...automatico(), ts_ms: em(600) };
  janela.raias[1].entradas = [auto];
  assert.deepEqual(candidatosParaTrocar(auto, janela, estado).map((candidato) => candidato.rotulo),
    ['Carro 5 — Contagem 3 leu o carro 5 a 1,5 s (+1 volta)']);
});

test('"Não é o carro 7" never offers a car the "??"\'s own counter typed right there (that would be a double tap)', () => {
  const { janela, estado } = cenario();
  const auto = { ...automatico(), ts_ms: em(600) };
  // Contagem 2 itself typed car 5 two seconds after its "??".
  const proprio5 = entrada({ id: 21, fonte_id: 2, carro: 5, carro_digitado: 5, ts_ms: em(602) + 100 });
  janela.raias[1].entradas = [auto, proprio5];
  assert.deepEqual(candidatosParaTrocar(auto, janela, estado), []);
  // Voided, it is no reading: car 5 is offered again.
  janela.raias[1].entradas = [auto, { ...proprio5, anulada: true }];
  assert.deepEqual(candidatosParaTrocar(auto, janela, estado).map((candidato) => candidato.carro), [5]);
  // Car 5 typed by Contagem 2 a lap earlier says nothing about this crossing.
  janela.raias[1].entradas = [auto, { ...proprio5, ts_ms: em(600) - 230000 }];
  assert.deepEqual(candidatosParaTrocar(auto, janela, estado).map((candidato) => candidato.carro), [5]);
});

test('a car typed for a "??" is checked before anything is sent', () => {
  const { estado } = cenario();
  assert.equal(problemaAoAtribuir(null, pendente(), estado), 'Informe o número do carro que passou.');
  assert.equal(problemaAoAtribuir(7, pendente(), estado), null);
  assert.match(problemaAoAtribuir(99, pendente(), estado), /não é de uma equipe presente/);
  // The engine's own car may be confirmed: it becomes a person's word.
  assert.equal(problemaAoAtribuir(7, automatico(), estado), null);
  const doRevisor = entrada({ id: 124, ilegivel: true, carro: 7, reatribuida: true, decisoes: [troca('revisor')] });
  assert.equal(problemaAoAtribuir(7, doRevisor, estado), 'Este registro já é do carro 7.');
});

test('A and K on a "??" say what they do there, and the doubt is about the likeliest car', () => {
  const { alerta } = cenario();
  assert.deepEqual(acaoDeAceitar(alerta), { rotulo: 'Atribuir ao carro 7', pergunta: null });
  const sozinho = { ...alerta, contagem_em_duvida: 'a_menos' };
  assert.match(acaoDeAceitar(sozinho).pergunta, /pode passar a CONTAR \(\+1 volta\)/);
  assert.deepEqual(acaoDeConfirmar({ ...alerta, efeito_de_confirmar: 'nenhum' }),
    { rotulo: 'Deixar sem carro, parar de avisar', maisUmaPassagem: false });
  assert.equal(fraseDeDuvida(sozinho), 'Se esta passagem foi do carro 7, ele pode estar com uma volta a menos.');
  // On the screen A presses the suggested candidate's button (the same questions, the short lap among them).
  assert.deepEqual(atribuicaoDoAceitar(alerta), { entradaId: 123, carro: 7 });
  assert.equal(atribuicaoDoAceitar({ ...alerta, tipo: 'numero_atribuido' }), null);
  assert.equal(atribuicaoDoAceitar({ ...alerta, sugestao: { tipo: 'confirmar' } }), null);
  assert.equal(fraseDeDuvida(alerta), null);
});

test('the timeline of a "??" flag shows the passes of all its candidates, numbered as the buttons', () => {
  const { alerta } = cenario();
  const destaque = destaqueDaLinha({ tipo: 'alerta', id: alerta.id }, alerta, { fonte: 'pedacos' });
  assert.deepEqual(destaque.ids, [123]);
  assert.deepEqual(destaque.carros, [7, 12, 3, 5]);
  assert.deepEqual([...destaque.candidatos], [[7, 1], [5, 2], [12, 3], [3, 4]]);
  const doCinco = entrada({ id: 32, fonte_id: 3, carro: 5, carro_digitado: 5 });
  assert.ok(classesDoChip(doCinco, destaque.ids, null, destaque.carros, { candidatos: destaque.candidatos }).includes('candidato'));
  // And the stretch loaded reaches every crossing it names.
  assert.deepEqual(momentosDoAlerta(alerta), { primeiroMs: em(600) - 400, ultimoMs: em(601) + 500 });
  // Any other flag is as before.
  assert.deepEqual(destaqueDaLinha({ tipo: 'alerta', id: 'x' }, { passagem_ids: [1], carros: [4] }, { fonte: 'pedacos' }), { ids: [1], carros: [4] });
});

test('the queue names a "??" by what it is, with the cars it may have been', () => {
  const { alerta, estado } = cenario();
  assert.equal(nomeDoAlertaIlegivel(alerta, estado), 'Passagem sem número (??)');
  assert.equal(nomeDoAlertaIlegivel({ ...alerta, fonte_id: 9 }, estado), 'Marca do sensor sem passagem');
  assert.equal(carrosDoAlertaIlegivel(alerta), 'carro ?? (7, 12 ou 3)');
  assert.equal(carrosDoAlertaIlegivel({ ...alerta, carros: [] }), 'carro ??');
  assert.equal(nomeDoAlertaIlegivel(resolvido(), estado), 'Número atribuído automaticamente');
  assert.equal(carrosDoAlertaIlegivel(resolvido()), 'carro 7 (era ??)');
  assert.equal(nomeDoAlertaIlegivel({ tipo: 'solitaria' }, estado), null);
});

test('in the car table a "??" is a flag of each car it may be, in doubt only for the likeliest', () => {
  const { alerta, estado } = cenario();
  const comDuvida = { ...estado, alertas_abertos: [{ ...alerta, contagem_em_duvida: 'a_menos' }] };
  assert.equal(sinaisDoCarro({ carro: 7, voltas: 3 }, comDuvida, []).emDuvida, 1);
  const do12 = sinaisDoCarro({ carro: 12, voltas: 3 }, comDuvida, []);
  assert.equal(do12.alertas, 1);
  assert.equal(do12.emDuvida, 0);
});

// ---- the history -------------------------------------------------------------------------

test('what the system assigned is a line of the history, "automático", and opens its flag', () => {
  const estado = { fontes: FONTES, historico: [], alertas_resolvidos: [resolvido()], alertas_abertos: [], servidor_agora_ms: em(900) };
  const [linha] = linhasDoHistorico(estado);
  assert.equal(linha.texto, 'Sistema: atribuiu o ?? #123 de Contagem 2 ao carro 7');
  assert.equal(linha.automatica, true);
  assert.deepEqual(linha.quem, ['automatico']);
  assert.equal(linha.tipo, 'numero_atribuido');
  assert.deepEqual(linha.carros, [7]);
  assert.equal(linha.fonteId, 2);
  assert.deepEqual(linha.alvo, { tipo: 'alerta', id: 'numero_atribuido:123' });
  assert.ok(opcoesDosFiltros(estado, [linha]).tipos.some((tipo) => tipo.valor === 'numero_atribuido'));
});

test('a person\'s decision on a "??" says it was one', () => {
  const linha = {
    id: 40, tipo: 'reatribuir', passagem_id: 123, fonte_id: 2, fonte_nome: 'Contagem 2', carro_digitado: null, carro: 7,
    passagem_ts_ms: em(600), equipe_numero: 7, origem: 'revisor',
  };
  assert.equal(descreverDecisao(linha), 'Atribuiu o ?? #123 (Contagem 2) ao carro 7');
  assert.equal(descreverDecisao({ ...linha, fonte_id: 9, fonte_nome: 'Sensor' }, () => null, null, (id) => (id === 9 ? 'sensor' : 'manual')),
    'Atribuiu a marca do sensor #123 (Sensor) ao carro 7');
  assert.equal(descreverDecisao({ ...linha, tipo: 'anular', carro: null, equipe_numero: null }), 'Anulou a passagem #123 (??, Contagem 2)');
  assert.equal(descreverDecisao({ ...linha, tipo: 'confirmar' }), 'Confirmou a passagem #123 (carro 7, era ??, Contagem 2)');
  // An undo of it says what it undid.
  assert.equal(fraseDoHistorico({ id: 41, tipo: 'desfazer', desfaz_decisao_id: 40, carro_digitado: null, passagem_ts_ms: em(600) }, { desfeita: linha }),
    'Desfez a decisão #40: atribuiu o ?? #123 (Contagem 2) ao carro 7');
});

// ---- the sources ---------------------------------------------------------------------------

test('each source says how many "??" it recorded and how many got a car', () => {
  assert.equal(textoDosIlegiveis({ ilegiveis: 12, ilegiveis_atribuidas: 9 }), 'ilegíveis: 12 (9 atribuídos)');
  assert.equal(textoDosIlegiveis({ ilegiveis: 1, ilegiveis_atribuidas: 1 }), 'ilegíveis: 1 (1 atribuído)');
  assert.equal(textoDosIlegiveis({ entradas: 40 }), null);
  // In the bar, only in the tooltip: the chip's text keeps its shape.
  const fonte = { ...FONTES[1], stats: { ilegiveis: 3, ilegiveis_atribuidas: 2, visto_em_ms: em(890), sincronizado_ate_ms: em(895) } };
  const chip = descreverChip(fonte, { corrida: { largada_ms: LARGADA_MS }, dispositivos: [] }, em(900));
  assert.equal(chip.texto, 'ao vivo');
  assert.match(chip.titulo, /ilegíveis: 3 \(2 atribuídos\)/);
});

test('a counting sensor shows what it did with its marks', () => {
  const sensor = { marcas: 412, atribuidas: 398, ambiguas: 6, sem_passagem: 8, passagens_sem_marca: 5, desvio_mediano_ms: -300, desvio_p90_ms: 800 };
  // Short labels (the block is narrow), what each means in its tooltip.
  assert.deepEqual(linhasDoSensor(sensor).map(([rotulo, valor]) => [rotulo, valor]), [
    ['marcas', '412'],
    ['atribuídas', '398'],
    ['ambíguas', '6'],
    ['sem passagem', '8'],
    ['passagens sem horário', '5'],
    ['desvio', '−0,3 s (90% até 0,8 s)'],
  ]);
  assert.ok(linhasDoSensor(sensor).every(([, , dica]) => dica.length > 10));
  assert.equal(linhasDoSensor(undefined), null);
  assert.equal(dicaDosIlegiveisDaFonte({ stats: { sensor } }), 'sensor: 412 marcas, 398 atribuídas');
  // It reads no car: no column in the car table.
  assert.deepEqual(fontesDaTabela(FONTES).map((fonte) => fonte.id), [1, 2, 3]);
  assert.deepEqual(colunasDeFontes({ fontes: FONTES, corrida: {} }).map((coluna) => coluna.fonte.id), [1, 2, 3]);
});

test('an observer keeps its comparison, with its "??" counted apart; a sensor on trial in a sensor\'s words', () => {
  const observacao = {
    entradas: 50, acertos: 40, falsos_positivos: 2, leituras_trocadas: 3, faltas: 4, ilegiveis: 5, ilegiveis_com_passagem: 4,
    desvio_mediano_ms: null, desvio_p90_ms: null, trocas_frequentes: [],
  };
  const camera = linhasDaObservacao(observacao, { tipo: 'automatica' });
  assert.deepEqual(camera.at(-1), ['ilegíveis', '5 (4 numa passagem oficial)']);
  assert.deepEqual(linhasDaObservacao({ ...observacao, ilegiveis: 0 }), linhasDaObservacao({ ...observacao, ilegiveis: 0 }, { tipo: 'automatica' }));
  assert.ok(!linhasDaObservacao({ ...observacao, ilegiveis: 0 }).some(([rotulo]) => rotulo === 'ilegíveis'));

  const sensor = linhasDaObservacao({ ...observacao, leituras_trocadas: 0, ilegiveis: 0 }, { tipo: 'sensor' });
  assert.deepEqual(sensor.map(([rotulo]) => rotulo), ['marcas numa passagem oficial', 'passagens oficiais sem marca', 'marcas sem passagem']);
});

test('names that differ only in their last word are said once', () => {
  assert.equal(listaDeNomes(['Contagem 1', 'Contagem 2']), 'Contagem 1 e 2');
  assert.equal(listaDeNomes(['Contagem 1', 'Contagem 2', 'Contagem 3']), 'Contagem 1, 2 e 3');
  assert.equal(listaDeNomes(['Ana', 'Contagem 2']), 'Ana e Contagem 2');
  assert.equal(listaDeNomes(['Contagem 1']), 'Contagem 1');
  assert.equal(listaDeNomes([]), 'outra contagem');
});

// The user could not tell, from the page, whether a sensor's times replace
// the counters'. The block says it in one line: no count, and the pass time
// is the sensor's in N of the M passes, the counters' in the others.
test('a counting sensor says in one line what it does to the count and to the times', () => {
  const sensor = { marcas: 412, atribuidas: 398, ambiguas: 6, sem_passagem: 8, passagens_sem_marca: 5 };
  assert.equal(resumoDoSensor(sensor),
    'Sensor de tempo. Contagem: não conta. Horário oficial: o do sensor em 398 de 403 passagens até agora; nas outras, o dos contadores.');
  // Nothing timed yet: the rule, without numbers.
  assert.match(resumoDoSensor({ atribuidas: 0, passagens_sem_marca: 0 }), /o do sensor em cada passagem em que uma marca dele coincide; nas outras, o dos contadores/);
  assert.match(resumoDoSensor(undefined), /Contagem: não conta/);
});
