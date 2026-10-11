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
    for (const entrada of raia.entradas ?? []) {
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
  // Passes the rules of the race keep out of the lap count, and the pass
  // zero, must be told apart from laps at a glance.
  const papel = entrada.grupo?.papel;
  if (papel === 'inicial') {
    classes.push('passagem-zero');
  } else if (papel === 'janela_largada') {
    classes.push('janela-largada');
  } else if (papel === 'apos_encerramento') {
    classes.push('apos-encerramento');
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
};

// What the pass group of an entry is to the count, in words.
export function descricaoDoPapel(grupo) {
  switch (grupo.papel) {
    case 'inicial':
      return 'passagem zero (primeira passagem do carro; não é volta)';
    case 'janela_largada':
      return 'dentro da janela de largada (não conta volta)';
    case 'apos_encerramento':
      return 'depois do encerramento (ignorada, não conta volta)';
    case 'volta':
      return `volta ${grupo.numero_volta}`;
    case 'pendente':
      return 'ainda não conta como volta';
    default:
      // An answer from before "papel" existed.
      return grupo.contado ? `volta ${grupo.numero_volta}` : 'ainda não conta como volta';
  }
}

// What the tooltip of a chip says.
export function descricaoDoChip(entrada) {
  const partes = [entrada.adicionada_por_revisor ? 'passagem adicionada pelo revisor' : `passagem #${entrada.id}`];
  if (entrada.reatribuida) {
    partes.push(`digitado ${entrada.carro_digitado}, trocado para ${entrada.carro}`);
  }
  if (entrada.anulada) {
    partes.push('anulada');
  } else if (entrada.anulada_auto) {
    partes.push('anulada automaticamente (repetida)');
  } else if (entrada.fora_de_uso) {
    partes.push(MOTIVOS_FORA_DE_USO[entrada.motivo_fora_de_uso]
      ?? 'fora de uso (fonte excluída ou inativa, ou registro anterior à largada)');
  }
  if (entrada.grupo) {
    partes.push(descricaoDoPapel(entrada.grupo));
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
