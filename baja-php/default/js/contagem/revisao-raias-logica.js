// Geometry of the lane timeline: where each chip goes. Pure functions,
// tested with `node --test`; the drawing itself is in revisao-raias.js.

// Pixels per second. A flag shows about a minute and must spread the chips
// out; a car's whole race is hours long and must still fit a scroll bar.
export const ESCALA_ALERTA = 14;
export const ESCALA_CARRO = 0.5;

// Room left before the first and after the last thing drawn, in ms.
const MARGEM_MS = 10000;

// The stretch of time the lanes cover.
//
// A flag's window comes with its limits. A car's whole race comes without
// (de_ms and ate_ms are null), so the limits are taken from what there is
// to draw, with a small margin. Null when there is nothing to draw at all.
export function limitesDaJanela(janela) {
  if (janela.de_ms !== null && janela.de_ms !== undefined && janela.ate_ms !== null && janela.ate_ms !== undefined) {
    return { de: janela.de_ms, ate: janela.ate_ms };
  }
  const momentos = [];
  for (const raia of janela.raias ?? []) {
    // An entry of unknown time carries whatever its phone's clock said,
    // possibly days off; it must not stretch the timeline to there.
    for (const entrada of comHorarioConhecido(raia.entradas ?? [])) {
      momentos.push(entrada.ts_ms);
    }
  }
  for (const ocorrencia of janela.ocorrencias ?? []) {
    momentos.push(ocorrencia.ts_ms);
  }
  if (momentos.length === 0) {
    return null;
  }
  return { de: Math.min(...momentos) - MARGEM_MS, ate: Math.max(...momentos) + MARGEM_MS };
}

// Entries of unknown time ("desconhecido": the phone had no usable anchor)
// have no place on a time axis. The lanes draw the others; these are
// listed apart, under the lanes, so they can still be clicked.
export const temHorarioDesconhecido = (entrada) => entrada.qualidade_tempo === 'desconhecido';

export function comHorarioConhecido(entradas) {
  return entradas.filter((entrada) => !temHorarioDesconhecido(entrada));
}

// Every entry of unknown time of a window, with the name of its lane.
export function entradasDeHorarioDesconhecido(janela) {
  const lista = [];
  for (const raia of janela?.raias ?? []) {
    for (const entrada of raia.entradas ?? []) {
      if (temHorarioDesconhecido(entrada)) {
        lista.push({ ...entrada, raia: raia.nome });
      }
    }
  }
  return lista;
}

// The lanes under the counters': race control, the pit lane and (when the
// prova uses it, or something was logged there anyway) the fueling station.
// Each is {nome, classe, marcas, estadias}:
//   marcas    the events of that lane, in time order; a pit or fueling
//             event the engine could not pair is marked `sem_par`
//   estadias  the stays that were paired: {carro, de_ms, ate_ms}, ate_ms
//             null while the car is still inside
export function raiasDeOcorrencias(janela) {
  const ocorrencias = janela?.ocorrencias ?? [];
  const estadias = janela?.estadias ?? [];
  // An answer from before "area" existed only says `box`.
  const areaDe = (ocorrencia) => ocorrencia.area ?? (ocorrencia.box ? 'box' : null);
  const raia = (nome, classe, area) => ({
    nome,
    classe,
    marcas: ocorrencias.filter((ocorrencia) => areaDe(ocorrencia) === area),
    estadias: estadias.filter((estadia) => estadia.area === area),
  });

  const raias = [raia('Direção de prova', 'raia-direcao', null), raia('Box', 'raia-box', 'box')];
  const abastecimento = raia('Abastecimento', 'raia-abastecimento', 'abastecimento');
  if (janela?.abastecimento || abastecimento.marcas.length > 0 || abastecimento.estadias.length > 0) {
    raias.push(abastecimento);
  }
  return raias;
}

const NOMES_DE_PARADA = {
  box_entrada: 'entrou no box',
  box_saida: 'saiu do box',
  abastecimento_entrada: 'entrou no abastecimento',
  abastecimento_saida: 'saiu do abastecimento',
};

// "carro 12 entrou no box": a pit or fueling event, in words.
export function descreverParada(parada) {
  return `carro ${parada.carro} ${NOMES_DE_PARADA[parada.tipo] ?? parada.tipo}`;
}

// What a mark of the pit or fueling lane says, and its tooltip's warning.
//   texto   "12 saiu", "12 saiu (inferida)", "12 entrou (sem par)"
//   aviso   null, or the sentence that explains the marker
// An INFERRED exit was not logged at the pit lane: a reviewer marked it
// from a pass at the line, one second before that pass.
export function rotuloDaMarca(ocorrencia, nome) {
  if (ocorrencia.carro === null || ocorrencia.carro === undefined) {
    return { texto: nome, aviso: null };
  }
  if (ocorrencia.presumida) {
    // Not in the pit log at all: the engine assumed it, because the car was
    // seen lapping. Neither logged nor marked by anybody, and it says so.
    return {
      texto: `${ocorrencia.carro} saída presumida`,
      aviso: 'SAÍDA PRESUMIDA: ninguém registrou esta saída; o sistema a presumiu porque dois contadores viram o carro na linha '
        + 'em duas passagens seguidas. "Confirmar saída", no alerta ou no controle de corrida, grava a saída',
    };
  }
  if (ocorrencia.inferida) {
    return {
      texto: `${ocorrencia.carro} ${nome} (inferida)`,
      aviso: 'INFERIDA: saída marcada por um revisor a partir de uma passagem do carro na linha; ninguém a registrou na hora',
    };
  }
  if (ocorrencia.sem_par) {
    return {
      texto: `${ocorrencia.carro} ${nome} (sem par)`,
      aviso: 'SEM PAR: falta a entrada ou a saída correspondente (quem registrava na outra ponta não marcou)',
    };
  }
  return { texto: `${ocorrencia.carro} ${nome}`, aviso: null };
}

// Horizontal position of a moment, in pixels from the left edge.
export function posicaoPx(tsMs, deMs, escalaPxPorSegundo) {
  return Math.round(((tsMs - deMs) / 1000) * escalaPxPorSegundo);
}

// Two entries of the same source a second apart would be drawn on top of
// each other. This puts each chip on the first row of its lane where it
// does not touch the previous chip of that row.
//
// Returns the same entries, in time order, each with `x` (pixels) and
// `linha` (0 = first row), plus how many rows the lane needs.
export function distribuirEmLinhas(entradas, deMs, escalaPxPorSegundo, larguraDoChipPx) {
  const ordenadas = [...entradas].sort((a, b) => a.ts_ms - b.ts_ms || a.id - b.id);
  const fimDeCadaLinha = [];
  const posicionadas = ordenadas.map((entrada) => {
    const x = posicaoPx(entrada.ts_ms, deMs, escalaPxPorSegundo);
    let linha = fimDeCadaLinha.findIndex((fim) => fim <= x);
    if (linha === -1) {
      linha = fimDeCadaLinha.length;
    }
    fimDeCadaLinha[linha] = x + larguraDoChipPx;
    return { ...entrada, x, linha };
  });
  return { entradas: posicionadas, linhas: Math.max(1, fimDeCadaLinha.length) };
}

// The marks of the time ruler. The step is the first of a list of round
// values that keeps the marks at least `espacoMinimoPx` apart.
// Each mark is {ts_ms, x}; the caller writes the label.
export function marcasDeTempo(deMs, ateMs, escalaPxPorSegundo, espacoMinimoPx = 70) {
  const passosEmSegundos = [5, 10, 30, 60, 300, 600, 1800, 3600];
  const passo = passosEmSegundos.find((segundos) => segundos * escalaPxPorSegundo >= espacoMinimoPx)
    ?? passosEmSegundos[passosEmSegundos.length - 1];
  const passoMs = passo * 1000;

  const marcas = [];
  for (let ts = Math.ceil(deMs / passoMs) * passoMs; ts <= ateMs; ts += passoMs) {
    marcas.push({ ts_ms: ts, x: posicaoPx(ts, deMs, escalaPxPorSegundo) });
  }
  return marcas;
}

// The class names of a chip, from the markers the server sends.
export function classesDoChip(entrada, idsEmDestaque, entradaSelecionadaId) {
  const classes = ['chip'];
  if (entrada.anulada) {
    classes.push('anulada');
  } else if (entrada.anulada_auto) {
    classes.push('anulada-auto');
  } else if (entrada.fora_de_uso) {
    classes.push('fora-de-uso');
  }
  if (entrada.reatribuida) {
    classes.push('reatribuida');
  }
  if (entrada.adicionada_por_revisor) {
    classes.push('do-revisor');
  }
  if (entrada.grupo && entrada.grupo.contado) {
    classes.push('contada');
  }
  if (entrada.grupo?.volta_curta_confirmada) {
    classes.push('volta-curta-confirmada');
  }
  // Passes the rules of the race keep out of the lap count, and the pass
  // zero, must be told apart from laps at a glance.
  const papel = entrada.grupo?.papel;
  if (papel === 'inicial') {
    classes.push('passagem-zero');
  } else if (papel === 'antes_da_largada') {
    classes.push('antes-da-largada');
  } else if (papel === 'janela_largada') {
    classes.push('janela-largada');
  } else if (papel === 'apos_encerramento') {
    classes.push('apos-encerramento');
  } else if (papel === 'retida') {
    classes.push('retida');
  }
  if (entrada.qualidade_tempo === 'estimado') {
    classes.push('tempo-estimado');
  } else if (temHorarioDesconhecido(entrada)) {
    classes.push('tempo-desconhecido');
  }
  if (idsEmDestaque.includes(entrada.id)) {
    classes.push('em-destaque');
  }
  if (entrada.id === entradaSelecionadaId) {
    classes.push('selecionada');
  }
  return classes;
}

const MOTIVOS_FORA_DE_USO = {
  antes_da_largada: 'fora de uso: registrado antes da largada',
  fonte_inativa: 'fora de uso: a fonte estava desativada',
  fonte_excluida: 'fora de uso: a fonte foi excluída neste período',
  fonte_observadora: 'fonte observadora: só comparada com a contagem oficial, nunca conta',
  tempo_desconhecido: 'horário desconhecido: o aparelho não tinha o horário oficial; não entra na contagem sozinho',
};

// Why a pass two counters agree on is held back, for the chip's tooltip.
const MOTIVOS_DE_RETENCAO = {
  proxima_demais: 'RETIDA: perto demais da passagem anterior do carro para ser outra volta (não conta até um revisor confirmar)',
  dispersao: 'RETIDA: os registros dos contadores estão afastados demais entre si para valerem como a mesma passagem (não conta até um revisor confirmar)',
  no_box: 'RETIDA: o carro constava como dentro do box neste momento (não conta até um revisor marcar a saída do box ou descartar a passagem)',
  no_abastecimento: 'RETIDA: o carro constava como dentro do abastecimento neste momento (não conta até um revisor marcar a saída do abastecimento ou descartar a passagem)',
};

// What the pass group of an entry is to the count, in words.
export function descricaoDoPapel(grupo) {
  switch (grupo.papel) {
    case 'inicial':
      return 'passagem zero (primeira passagem do carro; não é volta)';
    case 'antes_da_largada':
      return grupo.contado
        ? 'antes da largada, confirmada pelo revisor como passagem zero'
        : 'antes da largada (não conta; se foi a passagem inicial do carro, confirme para valer como passagem zero)';
    case 'janela_largada':
      return 'dentro da janela de largada (não conta volta)';
    case 'apos_encerramento':
      return 'depois do encerramento (ignorada, não conta volta)';
    case 'volta':
      return `volta ${grupo.numero_volta}`;
    case 'pendente':
      return 'ainda não conta como volta';
    case 'retida':
      return MOTIVOS_DE_RETENCAO[grupo.motivo_retencao] ?? MOTIVOS_DE_RETENCAO.proxima_demais;
    default:
      // An answer from before "papel" existed.
      return grupo.contado ? `volta ${grupo.numero_volta}` : 'ainda não conta como volta';
  }
}

// Who voided an entry, and why: "anulada pelo contador (Contador1) —
// digitei errado", "anulada pela revisão (jbresolin)". A counter voiding
// their own entry on the phone usually typed it wrong and retyped it.
export function descricaoDaAnulacao(entrada) {
  const anulacao = (entrada.decisoes ?? []).find((decisao) => decisao.tipo === 'anular');
  if (!anulacao) {
    return 'anulada';
  }
  const quem = anulacao.origem === 'contador' ? 'anulada pelo contador' : 'anulada pela revisão';
  return `${quem}${anulacao.usuario ? ` (${anulacao.usuario})` : ''}${anulacao.motivo ? ` — ${anulacao.motivo}` : ''}`;
}

// What the tooltip of a chip says.
export function descricaoDoChip(entrada) {
  const partes = [entrada.adicionada_por_revisor ? 'passagem adicionada pelo revisor' : `passagem #${entrada.id}`];
  if (entrada.reatribuida) {
    partes.push(`digitado ${entrada.carro_digitado}, trocado para ${entrada.carro}`);
  }
  if (entrada.anulada) {
    partes.push(descricaoDaAnulacao(entrada));
  } else if (entrada.anulada_auto) {
    partes.push('anulada automaticamente (repetida)');
  } else if (entrada.fora_de_uso) {
    partes.push(MOTIVOS_FORA_DE_USO[entrada.motivo_fora_de_uso]
      ?? 'fora de uso (fonte excluída ou inativa, ou registro anterior à largada)');
  }
  if (entrada.qualidade_tempo === 'estimado') {
    partes.push('horário estimado (digitado sem conexão e carimbado depois de recarregar a página)');
  }
  if (entrada.grupo) {
    partes.push(descricaoDoPapel(entrada.grupo));
    const escolhido = entrada.grupo.tempo_escolhido_id;
    if (escolhido !== null && escolhido !== undefined) {
      partes.push(escolhido === entrada.id
        ? 'o horário desta passagem é o deste registro (escolhido pela revisão)'
        : 'não define o horário desta passagem (a revisão escolheu outro registro)');
    }
    if (entrada.grupo.volta_curta_confirmada) {
      partes.push('VOLTA CURTA CONFIRMADA: curta demais para ser real, conta porque a revisão confirmou');
    }
  }
  if (entrada.imagem_url) {
    partes.push('com imagem (clique para ver)');
  }
  return partes.join(' · ');
}

// Finds an entry by id in a loaded window.
export function encontrarEntrada(janela, entradaId) {
  for (const raia of janela?.raias ?? []) {
    for (const entrada of raia.entradas ?? []) {
      if (entrada.id === entradaId) {
        return entrada;
      }
    }
  }
  return null;
}
