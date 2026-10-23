// The legend under the timeline: one small sample chip per look a chip can
// have, with a word or two beside it and the long explanation in its
// tooltip. Pure functions, tested with `node --test`; drawn by
// revisao-legenda.js.
//
// Each sample is a made-up entry run through the very functions the lanes
// use (classesDoChip, textoDoChip, marcadoresDoChip), so a sample always
// looks like the chips on the timeline: change how a state is drawn and
// the legend follows. `marca` is the class the sample is there to show;
// the tests check each sample really gets it.

import { ehIlegivel, textoDoChip } from './revisao-ilegivel.js';
import { classesDoChip, marcadoresDoChip } from './revisao-raias-logica.js';

// A pass of car 7 that counts, to build the other samples on.
const volta = (mais = {}) => ({ contado: true, papel: 'volta', numero_volta: 4, passagem_ids: [1, 2], ...mais });
const entrada = (mais = {}) => ({ id: 1, carro: 7, decisoes: [], grupo: volta(), ...mais });
const anulacao = (origem) => ({ id: 9, tipo: 'anular', origem });
const reatribuicao = (origem) => ({ id: 9, tipo: 'reatribuir', origem });

// The groups, in the order they are shown.
export const GRUPOS_DA_LEGENDA = [
  { id: 'passagens', titulo: 'Passagens' },
  { id: 'anuladas', titulo: 'Anuladas' },
  { id: 'revisao', titulo: 'Revisão' },
  { id: 'destaque', titulo: 'Destaque' },
  { id: 'ilegivel', titulo: '"??"' },
  { id: 'sensor', titulo: 'Sensor' },
];

// Every sample. `quando` says what the prova needs for the sample to be
// worth showing (see oQueAProvaTem); none means always.
//   entrada, naRaiaDoSensor, destaque {idsEmDestaque, carrosEmFoco, selecionadaId}, candidatos
export const AMOSTRAS_DA_LEGENDA = [
  {
    grupo: 'passagens', marca: 'contada', rotulo: 'conta',
    dica: 'Verde: passagem oficial, conta na contagem.',
    entrada: entrada(),
  },
  {
    grupo: 'passagens', marca: 'solitaria-pendente', rotulo: '1 contador',
    dica: 'Borda pontilhada escura: só um contador registrou; ainda não conta.',
    entrada: entrada({ grupo: { contado: false, papel: 'pendente', passagem_ids: [1] } }),
  },
  {
    grupo: 'passagens', marca: 'passagem-zero', rotulo: 'passagem zero',
    dica: '"0" no canto: passagem zero, a primeira do carro. É oficial, mas não é volta.',
    entrada: entrada({ grupo: volta({ papel: 'inicial' }) }),
  },
  {
    grupo: 'passagens', marca: 'janela-largada', rotulo: 'fora do horário',
    dica: 'Tracejado âmbar: não conta por ser antes da largada, na janela de largada ou depois do encerramento.',
    entrada: entrada({ grupo: { contado: false, papel: 'janela_largada', passagem_ids: [1, 2] } }),
  },
  {
    grupo: 'passagens', marca: 'retida', rotulo: 'retida',
    dica: 'Tracejado vermelho com fundo: passagem retida. Dois contadores concordam, mas não conta até um revisor decidir.',
    entrada: entrada({ grupo: { contado: false, papel: 'retida', passagem_ids: [1, 2] } }),
  },
  {
    grupo: 'passagens', marca: 'tempo-estimado', rotulo: 'estimado',
    dica: '"~" no canto: horário estimado (digitado sem conexão e carimbado depois). "?" no canto: horário desconhecido; esses ficam listados abaixo da linha do tempo.',
    entrada: entrada({ qualidade_tempo: 'estimado' }),
  },
  {
    grupo: 'passagens', marca: 'observadora', rotulo: 'observadora', quando: 'observadora',
    dica: 'Verde-azulado em itálico: registro de uma fonte observadora. Só é comparado com a contagem; nunca conta nem dá horário.',
    entrada: entrada({ fora_de_uso: true, motivo_fora_de_uso: 'fonte_observadora', grupo: null }),
  },
  {
    grupo: 'anuladas', marca: 'anulada', rotulo: 'pela revisão',
    dica: 'Riscado com borda vermelha: anulado por um revisor.',
    entrada: entrada({ anulada: true, grupo: null, decisoes: [anulacao('revisor')] }),
  },
  {
    grupo: 'anuladas', marca: 'anulada-pelo-contador', rotulo: 'pelo contador',
    dica: 'Riscado com "C": anulado pelo próprio contador no aparelho (em geral digitou errado e digitou de novo).',
    entrada: entrada({ anulada: true, grupo: null, decisoes: [anulacao('contador')] }),
  },
  {
    grupo: 'anuladas', marca: 'anulada-auto', rotulo: 'repetição',
    dica: 'Riscado e pontilhado: repetição anulada automaticamente.',
    entrada: entrada({ anulada_auto: true, grupo: null }),
  },
  {
    grupo: 'revisao', marca: 'confirmada-pelo-revisor', rotulo: 'confirmada',
    dica: 'Canto azul: conta porque um revisor confirmou.',
    entrada: entrada({ grupo: volta({ confirmado_por_revisor: true }) }),
  },
  {
    grupo: 'revisao', marca: 'tempo-escolhido', rotulo: 'usar o tempo',
    dica: 'Traço azul embaixo: o horário da passagem é o deste registro, escolhido pela revisão ("Usar o tempo").',
    entrada: entrada({ grupo: volta({ tempo_escolhido_id: 1 }) }),
  },
  {
    grupo: 'revisao', marca: 'volta-curta-confirmada', rotulo: 'volta curta',
    dica: 'Âmbar com borda grossa: volta curta demais para ser real que conta porque a revisão confirmou. O registro oferece desfazer a confirmação.',
    entrada: entrada({ grupo: volta({ volta_curta_confirmada: true, confirmado_por_revisor: true }) }),
  },
  {
    grupo: 'revisao', marca: 'do-revisor', rotulo: 'adicionada',
    dica: 'Azul: passagem adicionada por um revisor.',
    entrada: entrada({ id: -1, adicionada_por_revisor: true }),
  },
  {
    grupo: 'destaque', marca: 'em-destaque', rotulo: 'do alerta',
    dica: 'Com um alerta ou um carro aberto, contorno âmbar grosso: registro do alerta. Contorno azul: o registro clicado.',
    entrada: entrada(), destaque: { idsEmDestaque: [1], carrosEmFoco: [7] },
  },
  {
    grupo: 'destaque', marca: 'do-carro', rotulo: 'mesmo carro',
    dica: 'Com um alerta ou um carro aberto, contorno âmbar fino: outro registro do mesmo carro.',
    entrada: entrada(), destaque: { carrosEmFoco: [7] },
  },
  {
    grupo: 'destaque', marca: 'apagada', rotulo: 'outro carro',
    dica: 'Com um alerta ou um carro aberto, apagado: registro de outro carro.',
    entrada: entrada(), destaque: { carrosEmFoco: [8] },
  },
  {
    grupo: 'ilegivel', marca: 'ilegivel-pendente', rotulo: 'sem carro', quando: 'ilegivel',
    dica: 'Roxo tracejado "??": passagem sem número, ainda sem carro. Não conta até alguém dizer qual foi; não fica apagada com outro carro em destaque.',
    entrada: entrada({ carro: null, ilegivel: true, fora_de_uso: true, motivo_fora_de_uso: 'ilegivel', grupo: null }),
  },
  {
    grupo: 'ilegivel', marca: 'ilegivel-automatico', rotulo: 'auto', quando: 'ilegivel',
    dica: '"auto" no canto: o sistema deu este carro a um "??".',
    entrada: entrada({ ilegivel: true, atribuida_automaticamente: true }),
  },
  {
    grupo: 'ilegivel', marca: 'ilegivel-contador', rotulo: 'contador disse', quando: 'ilegivel',
    dica: '"C" no canto, sem risco: o contador informou o número no aparelho.',
    entrada: entrada({ ilegivel: true, reatribuida: true, decisoes: [reatribuicao('contador')] }),
  },
  {
    grupo: 'ilegivel', marca: 'ilegivel-revisor', rotulo: 'revisão disse', quando: 'ilegivel',
    dica: '"R" no canto: a revisão informou o número.',
    entrada: entrada({ ilegivel: true, reatribuida: true, decisoes: [reatribuicao('revisor')] }),
  },
  {
    grupo: 'ilegivel', marca: 'candidato', rotulo: 'carro possível', quando: 'ilegivel',
    dica: 'Num alerta "??": os registros dos carros possíveis, numerados como os botões do alerta (teclas 1 a 6).',
    entrada: entrada({ carro: 12, grupo: { contado: false, papel: 'pendente', passagem_ids: [1, 2] } }), candidatos: new Map([[12, 1]]),
  },
  {
    grupo: 'sensor', marca: 'tempo-do-sensor', rotulo: 'horário do sensor', quando: 'sensor',
    dica: '"S" no canto: o horário desta passagem é o da marca do sensor, não o dos contadores.',
    entrada: entrada({ grupo: volta({ tempo_do_sensor: true }) }),
  },
  {
    grupo: 'sensor', marca: 'com-passagem', rotulo: 'marca usada', quando: 'sensor', naRaiaDoSensor: true,
    dica: 'Raia do sensor, traço verde: marca que dá o horário de uma passagem (os registros dela levam "S").',
    entrada: entrada({ carro: null, ilegivel: true, marca_do_sensor: true }),
  },
  {
    grupo: 'sensor', marca: 'sem-passagem', rotulo: 'marca solta', quando: 'sensor', naRaiaDoSensor: true,
    dica: 'Raia do sensor, traço claro: marca sem passagem. Não dá o horário de nenhuma passagem e não conta.',
    entrada: entrada({ carro: null, ilegivel: true, fora_de_uso: true, motivo_fora_de_uso: 'marca_do_sensor', grupo: null }),
  },
];

// What of the "??", the sensor and the observers the prova has, from the
// state: {ilegivel, sensor, observadora}. A race with none of them gets a
// legend without their samples.
export function oQueAProvaTem(estado) {
  const fontes = estado?.fontes ?? [];
  const alertas = [...(estado?.alertas_abertos ?? []), ...(estado?.alertas_resolvidos ?? [])];
  return {
    ilegivel: fontes.some((fonte) => fonte.tipo === 'automatica' || (fonte.stats?.ilegiveis ?? 0) > 0)
      || alertas.some((alerta) => alerta.tipo === 'numero_ilegivel' || alerta.tipo === 'numero_atribuido'),
    sensor: fontes.some((fonte) => fonte.tipo === 'sensor'),
    observadora: fontes.some((fonte) => fonte.papel === 'observadora'),
  };
}

// The classes, the text and the corner markers of a sample, exactly as the
// lanes would draw that entry.
export function chipDaAmostra(amostra) {
  const destaque = amostra.destaque ?? {};
  const naRaiaDoSensor = Boolean(amostra.naRaiaDoSensor);
  const opcoes = { naRaiaDoSensor, candidatos: amostra.candidatos, idsEmDestaque: destaque.idsEmDestaque ?? [] };
  return {
    classes: classesDoChip(amostra.entrada, destaque.idsEmDestaque ?? [], destaque.selecionadaId ?? null, destaque.carrosEmFoco ?? [], opcoes),
    texto: textoDoChip(amostra.entrada, naRaiaDoSensor),
    marcadores: marcadoresDoChip(amostra.entrada, naRaiaDoSensor, opcoes),
    sensor: naRaiaDoSensor,
    ilegivel: ehIlegivel(amostra.entrada),
  };
}

// The legend for this prova: [{id, titulo, itens: [{marca, rotulo, dica, classes, texto, marcadores, sensor}]}],
// empty groups left out. `tem` is oQueAProvaTem().
export function itensDaLegenda(tem = {}) {
  return GRUPOS_DA_LEGENDA.map((grupo) => ({
    ...grupo,
    itens: AMOSTRAS_DA_LEGENDA
      .filter((amostra) => amostra.grupo === grupo.id && (!amostra.quando || tem[amostra.quando]))
      .map((amostra) => ({ marca: amostra.marca, rotulo: amostra.rotulo, dica: amostra.dica, ...chipDaAmostra(amostra) })),
  })).filter((grupo) => grupo.itens.length > 0);
}

// The legend is open or closed as this browser left it. A blocked or
// failing storage leaves it closed, and the page works the same.
export const CHAVE_DA_LEGENDA = 'contagem.revisao.legendaAberta';

export function legendaAberta(armazenamento) {
  try {
    return armazenamento?.getItem(CHAVE_DA_LEGENDA) === '1';
  } catch (bloqueado) {
    return false;
  }
}

export function guardarLegendaAberta(armazenamento, aberta) {
  try {
    armazenamento?.setItem(CHAVE_DA_LEGENDA, aberta ? '1' : '0');
  } catch (bloqueado) {
    // Remembered until the page is reloaded only.
  }
}
