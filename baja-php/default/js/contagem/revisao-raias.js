// Draws the lane timeline: one lane per source, one for the pit lane and
// race control, and a time ruler. Where things go is decided by
// revisao-raias-logica.js; this file only turns that into elements.

import { el } from './revisao-dom.js';
import { relogio } from './revisao-formato.js';
import {
  classesDoChip, descricaoDoChip, distribuirEmLinhas, limitesDaJanela, marcasDeTempo, posicaoPx,
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
};

function desenharRaia(raia, limites, opcoes) {
  const { entradas, linhas } = distribuirEmLinhas(raia.entradas ?? [], limites.de, opcoes.escala, LARGURA_DO_CHIP_PX);

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

function desenharRaiaDeOcorrencias(ocorrencias, limites, opcoes) {
  const marcas = ocorrencias.map((ocorrencia) => el('span', {
    class: ocorrencia.box ? `marca-box ${ocorrencia.tipo}` : `marca-corrida ${ocorrencia.tipo}`,
    style: { left: `${posicaoPx(ocorrencia.ts_ms, limites.de, opcoes.escala)}px` },
    title: relogio(ocorrencia.ts_ms - (opcoes.largadaMs ?? ocorrencia.ts_ms)),
  }, ocorrencia.box
    ? `${ocorrencia.carro} ${NOMES_DE_OCORRENCIA[ocorrencia.tipo]}`
    : NOMES_DE_OCORRENCIA[ocorrencia.tipo] ?? ocorrencia.tipo));

  return el('div', { class: 'raia raia-box' },
    el('div', { class: 'raia-nome' }, 'Box / direção'),
    el('div', { class: 'raia-trilho', style: { height: `${ALTURA_DA_LINHA_PX}px` } }, marcas));
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
    return el('p', { class: 'vazio' }, 'Nenhum registro neste período.');
  }

  const larguraPx = posicaoPx(limites.ate, limites.de, opcoes.escala) + LARGURA_DO_CHIP_PX;
  // Every track gets the same width through this custom property, so the
  // lanes line up and the container scrolls sideways as one.
  const conteudo = el('div', { class: 'raias-conteudo', style: { '--largura-trilho': `${larguraPx}px` } },
    desenharRegua(limites, opcoes),
    (janela.raias ?? []).map((raia) => desenharRaia(raia, limites, opcoes)),
    desenharRaiaDeOcorrencias(janela.ocorrencias ?? [], limites, opcoes));

  const rolagem = el('div', { class: 'raias' }, conteudo);

  // The scroll position can only be set once the element is on the page.
  if (opcoes.centrarEmMs !== null && opcoes.centrarEmMs !== undefined) {
    requestAnimationFrame(() => {
      const alvo = posicaoPx(opcoes.centrarEmMs, limites.de, opcoes.escala);
      rolagem.scrollLeft = Math.max(0, alvo - rolagem.clientWidth / 2);
    });
  }
  return rolagem;
}
