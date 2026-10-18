// Draws the lane timeline: one lane per source, then race control, the pit
// lane and the fueling station, and a time ruler. Where things go is decided by
// revisao-raias-logica.js; this file only turns that into elements.

import { el } from './revisao-dom.js';
import { relogio } from './revisao-formato.js';
import {
  classesDoChip, comHorarioConhecido, descreverParada, descricaoDoChip, distribuirEmLinhas, entradasDeHorarioDesconhecido,
  limitesDaJanela, marcasDeTempo, posicaoDaLinhaDoMomento, posicaoPx, raiasDeOcorrencias, rotuloDaMarca,
} from './revisao-raias-logica.js';
import { ehRaiaDeSensor, marcadorDoChip, textoDoChip } from './revisao-ilegivel.js';

const LARGURA_DO_CHIP_PX = 40;
const ALTURA_DA_LINHA_PX = 30;
// A timing sensor's mark is a tick at its exact moment, with room to be
// clicked: two marks a second apart share a row at the live view's scale.
const LARGURA_DA_MARCA_PX = 8;

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

// What goes inside a chip: the car (or "??"), the small marker of who gave
// a "??" its car, "S" when a sensor's mark times the pass, and the number
// of a "??" flag's candidate.
function conteudoDoChip(entrada, naRaiaDoSensor, opcoes) {
  const marcador = naRaiaDoSensor ? null : marcadorDoChip(entrada);
  const candidato = opcoes.candidatos?.get?.(entrada.carro);
  return [
    textoDoChip(entrada, naRaiaDoSensor),
    marcador ? el('span', { class: marcador.classe, 'aria-hidden': 'true' }, marcador.texto) : null,
    !naRaiaDoSensor && entrada.grupo?.tempo_do_sensor ? el('span', { class: 'marcador marcador-sensor', 'aria-hidden': 'true' }, 'S') : null,
    candidato && !(opcoes.idsEmDestaque ?? []).includes(entrada.id)
      ? el('span', { class: 'marcador marcador-candidato', 'aria-hidden': 'true' }, String(candidato)) : null,
  ];
}

// Only `opcoes.contextoDosIlegiveis` and the lane change the tooltip.
const contextoDaDica = (opcoes, naRaiaDoSensor) => ({ ...(opcoes.contextoDosIlegiveis ?? {}), naRaiaDoSensor });

function desenharRaia(raia, limites, opcoes) {
  const sensor = ehRaiaDeSensor(raia);
  const { entradas, linhas } = distribuirEmLinhas(comHorarioConhecido(raia.entradas ?? []), limites.de, opcoes.escala,
    sensor ? LARGURA_DA_MARCA_PX : LARGURA_DO_CHIP_PX);
  const extras = { naRaiaDoSensor: sensor, ligadas: opcoes.ligadas ?? [], candidatos: opcoes.candidatos };

  const chips = entradas.map((entrada) => el('button', {
    type: 'button',
    class: classesDoChip(entrada, opcoes.idsEmDestaque, opcoes.entradaSelecionadaId, opcoes.carrosEmFoco ?? [], extras).join(' '),
    title: `${descricaoDoChip(entrada, contextoDaDica(opcoes, sensor))} · ${relogio(entrada.ts_ms - (opcoes.largadaMs ?? entrada.ts_ms))}`,
    'aria-label': sensor ? `marca do sensor, ${relogio(entrada.ts_ms - (opcoes.largadaMs ?? entrada.ts_ms))}` : null,
    style: { left: `${entrada.x}px`, top: `${entrada.linha * ALTURA_DA_LINHA_PX}px` },
    // Lets a redraw give the keyboard focus back to the same entry.
    'data-entrada': entrada.id,
    onclick: () => opcoes.aoEscolherEntrada(entrada.id),
  }, conteudoDoChip(entrada, sensor, opcoes)));

  // An observer (a camera, say) is compared with the count and never
  // counts: its lane says so, in its own colour rather than greyed out.
  // A timing sensor's lane has a look of its own too: ticks, not chips.
  const observadora = raia.papel === 'observadora';
  const classe = ['raia', raia.ativa === false ? 'inativa' : '', observadora ? 'observadora' : '', sensor ? 'raia-sensor' : '']
    .filter(Boolean).join(' ');
  const dica = [raia.nome, sensor ? 'sensor de tempo: marca o horário de cada passagem, nunca o carro' : null,
    observadora ? 'fonte observadora, só comparada com a contagem oficial' : null].filter(Boolean).join(': ');
  return el('div', { class: classe },
    el('div', { class: 'raia-nome', title: dica },
      raia.nome, observadora ? el('small', {}, ' observadora') : null),
    el('div', { class: 'raia-trilho', style: { height: `${linhas * ALTURA_DA_LINHA_PX}px` } }, chips));
}

// One lane of events: race control, the pit lane or the fueling station.
// A paired stay is a bar from the way in to the way out; every event is a
// mark on top of it, and an event with no pair says so.
function desenharRaiaDeOcorrencias(raia, limites, opcoes) {
  const titulo = (tsMs) => relogio(tsMs - (opcoes.largadaMs ?? tsMs));

  // Only what overlaps the lanes: a stay elsewhere would stretch the scrolling area.
  const naJanela = (estadia) => estadia.de_ms <= limites.ate && (estadia.ate_ms ?? limites.ate) >= limites.de;
  const barras = raia.estadias.filter(naJanela).map((estadia) => {
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
    const rotulo = rotuloDaMarca(ocorrencia, nome);
    return el('span', {
      class: `${daParada ? 'marca-box' : 'marca-corrida'} ${ocorrencia.tipo}${ocorrencia.sem_par ? ' sem-par' : ''}${ocorrencia.inferida ? ' inferida' : ''}${ocorrencia.presumida ? ' presumida' : ''}`,
      style: { left: `${posicaoPx(ocorrencia.ts_ms, limites.de, opcoes.escala)}px` },
      title: titulo(ocorrencia.ts_ms) + (rotulo.aviso ? ` · ${rotulo.aviso}` : ''),
    }, rotulo.texto);
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
      // Listed, not placed on a time axis: even a sensor's mark is a chip here.
      class: `${classesDoChip(entrada, opcoes.idsEmDestaque, opcoes.entradaSelecionadaId, opcoes.carrosEmFoco ?? []).join(' ')} solto`,
      title: `${entrada.raia} · ${descricaoDoChip(entrada, contextoDaDica(opcoes, false))}`,
      onclick: () => opcoes.aoEscolherEntrada(entrada.id),
    }, conteudoDoChip(entrada, false, opcoes))));
}

// Pit and fueling events whose time nobody knows: the engine pairs none of
// them, so they are no stay and have no place on the lanes. Listed, flagged.
function desenharParadasSemHorario(janela) {
  const paradas = janela.paradas_sem_horario ?? [];
  if (paradas.length === 0) {
    return null;
  }
  return el('div', { class: 'horario-desconhecido paradas-sem-horario', role: 'note' },
    el('strong', {}, 'Box e abastecimento sem horário: '),
    paradas.map(descreverParada).join('; '),
    '. O aparelho não tinha o horário oficial: estes registros não entram em nenhuma parada. Confira com quem registrou.');
}

function desenharRegua(limites, opcoes) {
  const marcas = marcasDeTempo(limites.de, limites.ate, opcoes.escala).map((marca) => el('span', {
    class: 'marca-tempo',
    style: { left: `${marca.x}px` },
  }, opcoes.largadaMs === null || opcoes.largadaMs === undefined ? '' : relogio(marca.ts_ms - opcoes.largadaMs)));

  // The flag's moment, in the ruler; desenharLinhaDoMomento draws it
  // across the lanes.
  const linhaDoAlerta = opcoes.momento === null || opcoes.momento === undefined ? null : el('span', {
    class: 'linha-do-alerta',
    style: { left: `${posicaoDaLinhaDoMomento(opcoes.momento, limites.de, opcoes.escala, LARGURA_DO_CHIP_PX)}px` },
  });
  // The present moment, moved every second by revisao-linha.js. Hidden
  // while it is past the end of the lanes, which it would stretch.
  const linhaAgora = opcoes.agoraMs === null || opcoes.agoraMs === undefined ? null : el('span', {
    class: 'linha-agora',
    title: 'agora',
    hidden: opcoes.agoraMs > limites.ate,
    style: { left: `${posicaoPx(Math.min(opcoes.agoraMs, limites.ate), limites.de, opcoes.escala)}px` },
  });
  // Stretches still on their way from the server.
  const carregando = (opcoes.trechosCarregando ?? []).map((trecho) => el('span', {
    class: 'trecho-carregando',
    title: 'carregando…',
    style: {
      left: `${posicaoPx(Math.max(trecho.de, limites.de), limites.de, opcoes.escala)}px`,
      width: `${posicaoPx(Math.min(trecho.ate, limites.ate), Math.max(trecho.de, limites.de), opcoes.escala)}px`,
    },
  }));

  return el('div', { class: 'raia raia-regua' },
    el('div', { class: 'raia-nome' }, 'tempo de prova'),
    el('div', { class: 'raia-trilho' }, carregando, marcas, linhaDoAlerta, linhaAgora));
}

// The flag's moment (or a history line's) as a dashed line across every
// lane. It is not in the ruler: the sticky ruler is drawn over the lanes,
// and a line inside it was drawn over the lane names too when the moment
// is scrolled under them (a phone). Here it is under the names and the
// ruler, over the tracks.
function desenharLinhaDoMomento(limites, opcoes) {
  if (opcoes.momento === null || opcoes.momento === undefined) {
    return null;
  }
  const x = posicaoDaLinhaDoMomento(opcoes.momento, limites.de, opcoes.escala, LARGURA_DO_CHIP_PX);
  return el('span', { class: 'linha-do-alerta atravessa', style: { left: `calc(var(--largura-nome-raia) + ${x}px)` } });
}

// The gap between a lone entry and the car's previous official pass,
// under the ruler: "76 s desde a passagem anterior; volta normal ~3:40".
function desenharLacuna(lacuna, limites, opcoes) {
  const de = Math.max(lacuna.de, limites.de);
  return el('div', { class: 'raia raia-lacuna' },
    el('div', { class: 'raia-nome' }, 'desde a anterior'),
    el('div', { class: 'raia-trilho' },
      el('span', {
        class: 'lacuna',
        title: lacuna.texto,
        style: {
          left: `${posicaoPx(de, limites.de, opcoes.escala) + LARGURA_DO_CHIP_PX / 2}px`,
          width: `${Math.max(2, posicaoPx(Math.min(lacuna.ate, limites.ate), de, opcoes.escala))}px`,
        },
      }, lacuna.texto)));
}

// opcoes:
//   escala                 pixels per second
//   largadaMs              start of the race, for the labels
//   idsEmDestaque          entry ids of the selected flag, or of the history line a car was opened from
//   carrosEmFoco           cars whose other entries are drawn weakly (the rest dimmed)
//   entradaSelecionadaId   the chip the reviewer clicked
//   momento                momentoEmDestaque(): the moment of the line across the lanes, or null
//   agoraMs                the present moment (a line), or null
//   lacuna                 lacunaDaPassagemAnterior(), or null
//   trechosCarregando      [{de, ate}] still loading
//   aoEscolherEntrada(id)  called when a chip is clicked
//   contextoDosIlegiveis   {atribuicoes, nomeDaFonte}: the tooltip of a "??" the engine gave a car
//   candidatos             Map car -> 1..6: the cars a "??" flag offers, numbered on their chips
//   ligadas                ids tied to the picked chip (a sensor's mark and its pass)
// Returns {conteudo, extras, limites}: what goes in the scrolling area,
// what is listed under it (entries and stops of unknown time), and the
// moments the left and right edges of the lanes stand for (null when there
// is nothing to draw). Where it is scrolled to is the caller's business.
export function desenharRaias(janela, opcoes) {
  const limites = limitesDaJanela(janela);
  const extras = [desenharHorarioDesconhecido(janela, opcoes), desenharParadasSemHorario(janela)].filter(Boolean);
  if (limites === null) {
    return { conteudo: el('p', { class: 'vazio' }, 'Nenhum registro neste período.'), extras, limites };
  }

  const larguraPx = posicaoPx(limites.ate, limites.de, opcoes.escala) + LARGURA_DO_CHIP_PX;
  // Every track gets the same width through this custom property, so the
  // lanes line up and the container scrolls sideways as one.
  const conteudo = el('div', { class: 'raias-conteudo', style: { '--largura-trilho': `${larguraPx}px` } },
    desenharRegua(limites, opcoes),
    opcoes.lacuna ? desenharLacuna(opcoes.lacuna, limites, opcoes) : null,
    (janela.raias ?? []).map((raia) => desenharRaia(raia, limites, opcoes)),
    raiasDeOcorrencias(janela).map((raia) => desenharRaiaDeOcorrencias(raia, limites, opcoes)),
    desenharLinhaDoMomento(limites, opcoes));
  return { conteudo, extras, limites };
}
