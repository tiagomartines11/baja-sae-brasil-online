// Draws the lane timeline: one lane per source, then race control, the pit
// lane and the fueling station, and a time ruler. Where things go is decided by
// revisao-raias-logica.js; this file only turns that into elements.

import { el } from './revisao-dom.js';
import { relogio } from './revisao-formato.js';
import {
  classesDoChip, comHorarioConhecido, descricaoDoChip, distribuirEmLinhas, entradasDeHorarioDesconhecido,
  limitesDaJanela, marcasDeTempo, posicaoPx, raiasDeOcorrencias,
} from './revisao-raias-logica.js';

const LARGURA_DO_CHIP_PX = 40;
const ALTURA_DA_LINHA_PX = 30;

const NOMES_DE_OCORRENCIA = {
  largada: 'largada',
  bandeira_vermelha: 'bandeira vermelha',
  relargada: 'relargada',
  encerramento: 'encerramento',
  box_entrada: 'entrou',
  box_saida: 'saiu',
  abastecimento_entrada: 'entrou',
  abastecimento_saida: 'saiu',
};

function desenharRaia(raia, limites, opcoes) {
  const { entradas, linhas } = distribuirEmLinhas(comHorarioConhecido(raia.entradas ?? []), limites.de, opcoes.escala, LARGURA_DO_CHIP_PX);

  const chips = entradas.map((entrada) => el('button', {
    type: 'button',
    class: classesDoChip(entrada, opcoes.idsEmDestaque, opcoes.entradaSelecionadaId).join(' '),
    title: `${descricaoDoChip(entrada)} · ${relogio(entrada.ts_ms - (opcoes.largadaMs ?? entrada.ts_ms))}`,
    style: { left: `${entrada.x}px`, top: `${entrada.linha * ALTURA_DA_LINHA_PX}px` },
    onclick: () => opcoes.aoEscolherEntrada(entrada.id),
  }, String(entrada.carro)));

  return el('div', { class: raia.ativa === false ? 'raia inativa' : 'raia' },
    el('div', { class: 'raia-nome' }, raia.nome),
    el('div', { class: 'raia-trilho', style: { height: `${linhas * ALTURA_DA_LINHA_PX}px` } }, chips));
}

// One lane of events: race control, the pit lane or the fueling station.
// A paired stay is a bar from the way in to the way out; every event is a
// mark on top of it, and an event with no pair says so.
function desenharRaiaDeOcorrencias(raia, limites, opcoes) {
  const titulo = (tsMs) => relogio(tsMs - (opcoes.largadaMs ?? tsMs));

  const barras = raia.estadias.map((estadia) => {
    const de = Math.max(estadia.de_ms, limites.de);
    const ate = Math.min(estadia.ate_ms ?? limites.ate, limites.ate);
    return el('span', {
      class: estadia.ate_ms === null || estadia.ate_ms === undefined ? 'estadia aberta' : 'estadia',
      style: {
        left: `${posicaoPx(de, limites.de, opcoes.escala)}px`,
        width: `${Math.max(2, posicaoPx(ate, de, opcoes.escala))}px`,
      },
      title: `carro ${estadia.carro}: de ${titulo(estadia.de_ms)} `
        + (estadia.ate_ms === null || estadia.ate_ms === undefined ? 'até agora (ainda dentro)' : `até ${titulo(estadia.ate_ms)}`),
    });
  });

  const marcas = raia.marcas.map((ocorrencia) => {
    const nome = NOMES_DE_OCORRENCIA[ocorrencia.tipo] ?? ocorrencia.tipo;
    const daParada = ocorrencia.carro !== null && ocorrencia.carro !== undefined;
    return el('span', {
      class: `${daParada ? 'marca-box' : 'marca-corrida'} ${ocorrencia.tipo}${ocorrencia.sem_par ? ' sem-par' : ''}`,
      style: { left: `${posicaoPx(ocorrencia.ts_ms, limites.de, opcoes.escala)}px` },
      title: titulo(ocorrencia.ts_ms) + (ocorrencia.sem_par
        ? ' · SEM PAR: falta a entrada ou a saída correspondente (quem registrava na outra ponta não marcou)'
        : ''),
    }, daParada ? `${ocorrencia.carro} ${nome}${ocorrencia.sem_par ? ' (sem par)' : ''}` : nome);
  });

  return el('div', { class: `raia ${raia.classe}` },
    el('div', { class: 'raia-nome' }, raia.nome),
    el('div', { class: 'raia-trilho', style: { height: `${ALTURA_DA_LINHA_PX}px` } }, barras, marcas));
}

// Entries whose time nobody knows: listed under the lanes, not placed on them.
function desenharHorarioDesconhecido(janela, opcoes) {
  const entradas = entradasDeHorarioDesconhecido(janela);
  if (entradas.length === 0) {
    return null;
  }
  return el('div', { class: 'horario-desconhecido' },
    el('strong', {}, 'Horário desconhecido: '),
    'registros enviados sem o horário oficial. Não estão na linha do tempo e não contam sozinhos. ',
    entradas.map((entrada) => el('button', {
      type: 'button',
      class: `${classesDoChip(entrada, opcoes.idsEmDestaque, opcoes.entradaSelecionadaId).join(' ')} solto`,
      title: `${entrada.raia} · ${descricaoDoChip(entrada)}`,
      onclick: () => opcoes.aoEscolherEntrada(entrada.id),
    }, String(entrada.carro))));
}

function desenharRegua(limites, opcoes) {
  const marcas = marcasDeTempo(limites.de, limites.ate, opcoes.escala).map((marca) => el('span', {
    class: 'marca-tempo',
    style: { left: `${marca.x}px` },
  }, opcoes.largadaMs === null || opcoes.largadaMs === undefined ? '' : relogio(marca.ts_ms - opcoes.largadaMs)));

  // A vertical line at the flag's moment. It lives in the ruler and is
  // simply tall enough to cross every lane below it.
  const linhaDoAlerta = opcoes.centrarEmMs === null || opcoes.centrarEmMs === undefined ? null : el('span', {
    class: 'linha-do-alerta',
    style: { left: `${posicaoPx(opcoes.centrarEmMs, limites.de, opcoes.escala) + LARGURA_DO_CHIP_PX / 2}px` },
  });

  return el('div', { class: 'raia raia-regua' },
    el('div', { class: 'raia-nome' }, 'tempo de prova'),
    el('div', { class: 'raia-trilho' }, marcas, linhaDoAlerta));
}

// opcoes:
//   escala                 pixels per second
//   largadaMs              start of the race, for the labels
//   idsEmDestaque          entry ids of the selected flag
//   entradaSelecionadaId   the chip the reviewer clicked
//   centrarEmMs            moment to scroll to (the flag's), or null
//   aoEscolherEntrada(id)  called when a chip is clicked
export function desenharRaias(janela, opcoes) {
  const limites = limitesDaJanela(janela);
  if (limites === null) {
    return desenharHorarioDesconhecido(janela, opcoes) ?? el('p', { class: 'vazio' }, 'Nenhum registro neste período.');
  }

  const larguraPx = posicaoPx(limites.ate, limites.de, opcoes.escala) + LARGURA_DO_CHIP_PX;
  // Every track gets the same width through this custom property, so the
  // lanes line up and the container scrolls sideways as one.
  const conteudo = el('div', { class: 'raias-conteudo', style: { '--largura-trilho': `${larguraPx}px` } },
    desenharRegua(limites, opcoes),
    (janela.raias ?? []).map((raia) => desenharRaia(raia, limites, opcoes)),
    raiasDeOcorrencias(janela).map((raia) => desenharRaiaDeOcorrencias(raia, limites, opcoes)));

  const rolagem = el('div', { class: 'raias' }, conteudo);
  const desconhecidos = desenharHorarioDesconhecido(janela, opcoes);

  // The scroll position can only be set once the element is on the page.
  if (opcoes.centrarEmMs !== null && opcoes.centrarEmMs !== undefined) {
    requestAnimationFrame(() => {
      const alvo = posicaoPx(opcoes.centrarEmMs, limites.de, opcoes.escala);
      rolagem.scrollLeft = Math.max(0, alvo - rolagem.clientWidth / 2);
    });
  }
  return desconhecidos ? el('div', {}, rolagem, desconhecidos) : rolagem;
}
