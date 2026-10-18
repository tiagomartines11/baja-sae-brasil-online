// The car table of the review screen: which sources get a column and which
// of them the count is compared with, what each row signals, what each
// counter's cell says, and the order of the rows. Pure functions, tested
// with `node --test`; the drawing is in revisao-tabela.js.

import { NOMES_DE_ALERTA, SEM_VALOR, tempoDeProva } from './revisao-formato.js';
import { exclusoesAbertas } from './revisao-decisoes.js';
import {
  compararNuloPorUltimo, contagemEmDuvida, fonteSilenciosa, fonteVotante, resumoDeAlertas,
} from './revisao-fila.js';
import { fontesDaTabela } from './revisao-observacao.js';

// ---- the source columns ----------------------------------------------------

// Excluded from the start (or before it) with no end: nothing it recorded
// counts, so its numbers say nothing about the count.
export function excluidaDaCorridaInteira(fonte, estado) {
  const largada = estado?.corrida?.largada_ms;
  if (largada === null || largada === undefined) {
    return false;
  }
  return exclusoesAbertas(estado).some((decisao) => decisao.fonte_id === fonte.id && decisao.ts_ms <= largada);
}

// The columns of the sources, left to right:
//   [{fonte, comparada, motivo}]
// `comparada`: the count is compared with what this source recorded (the
// car-level "contado acima de todos os contadores"). A source that recorded
// nothing at all since the start and has no device open gets no column: a
// column of zeros would only look like every car is wrong. One excluded for
// the whole race keeps its column, greyed, `motivo` saying why. So does one
// that recorded nothing since the start but still has a device open (a
// phone that died before the start and was never closed, an idle reader):
// it may still start counting, so its column stays, but its zeros say
// nothing about the count. A paper total is a number of laps and is never
// part of that comparison.
export function colunasDeFontes(estado, agoraServidorMs = null) {
  const largada = estado?.corrida?.largada_ms ?? null;
  const largou = largada !== null && (agoraServidorMs === null || agoraServidorMs >= largada);
  return fontesDaTabela(estado?.fontes)
    .filter((fonte) => !fonteSilenciosa(fonte, largada, agoraServidorMs))
    .map((fonte) => {
      if (fonte.tipo === 'totais') {
        return { fonte, comparada: false, motivo: null };
      }
      if (excluidaDaCorridaInteira(fonte, estado)) {
        return { fonte, comparada: false, motivo: 'excluída desde a largada: não é comparada com a contagem' };
      }
      if (largou && fonteVotante(fonte) && (fonte.stats?.entradas ?? 0) === 0) {
        return { fonte, comparada: false, motivo: 'não registrou nada desde a largada: não é comparada com a contagem' };
      }
      return { fonte, comparada: true, motivo: null };
    });
}

// −3, =, +2: a difference written so that a minus is a real minus sign.
export function diferenca(numero) {
  if (numero === 0) {
    return '=';
  }
  return numero > 0 ? `+${numero}` : `−${Math.abs(numero)}`;
}

// What a source's cell says for one car. The cell shows the DIFFERENCE to
// the official number, small and muted: equal is "=", and a counter who
// missed two of the car's passes reads "−2". The raw count is in the title.
// A counter types PASSES, the pass zero included, so it is compared with
// the car's official passes ("passagens"), not with its laps. A paper total
// is a number of laps and is compared with the laps.
//   {texto, titulo, valor}  `valor` is the difference, null for no value
export function celulaDaFonte(carro, coluna) {
  const { fonte } = coluna;
  if (fonte.tipo === 'totais') {
    const importado = (carro.totais_importados ?? {})[fonte.id];
    if (importado === null || importado === undefined) {
      return { texto: SEM_VALOR, titulo: `${fonte.nome}: este carro não está no total importado`, valor: null };
    }
    const valor = importado - carro.voltas;
    return { texto: diferenca(valor), titulo: `${fonte.nome}: ${importado} voltas no papel; oficiais: ${carro.voltas}`, valor };
  }
  const contado = (carro.contagem_por_fonte ?? {})[fonte.id] ?? 0;
  const oficiais = carro.passagens ?? carro.voltas;
  const valor = contado - oficiais;
  return {
    texto: diferenca(valor),
    titulo: `${fonte.nome}: ${contado} passagens registradas; oficiais: ${oficiais} (contando a passagem zero)`,
    valor,
  };
}

// ---- what a row signals ------------------------------------------------------

// The car-level signals, which took the place of highlighting each counter
// that differs (at 26SE every row had a highlighted cell, so none meant
// anything):
//   acima      how many official passes MORE than the counter that recorded
//              the most for this car, or null. Two counters agreeing is what
//              makes a pass count, so the count above every counter means a
//              pass counted on less than that: a lone entry confirmed twice
//              (26SE car 1: 38 official, 35 and 35 recorded), or an added
//              pass that never was. Null with no source to compare with.
//   alertas    open flags about the car; `emDuvida` of them put its count
//              in doubt (a held or pending pass), `tipos` their names
//   curtas     laps too short to be real that a reviewer confirmed
export function sinaisDoCarro(carro, estado, colunas) {
  const comparadas = colunas.filter((coluna) => coluna.comparada);
  let acima = null;
  if (comparadas.length > 0) {
    const maximo = Math.max(...comparadas.map((coluna) => (carro.contagem_por_fonte ?? {})[coluna.fonte.id] ?? 0));
    const oficiais = carro.passagens ?? carro.voltas;
    acima = oficiais > maximo ? oficiais - maximo : null;
  }
  const doCarro = (estado?.alertas_abertos ?? [])
    .filter((alerta) => !alerta.auto_resolvido && (alerta.carros ?? []).includes(carro.carro));
  const tipos = [...new Set(doCarro.map((alerta) => NOMES_DE_ALERTA[alerta.tipo] ?? alerta.tipo))];
  return {
    acima,
    alertas: doCarro.length,
    emDuvida: doCarro.filter(contagemEmDuvida).length,
    tipos,
    curtas: (carro.voltas_curtas_confirmadas ?? []).length,
  };
}

// " desde 1:23:45" after a car's status, or " desde antes da largada" for a
// stay that began before the start (a car parked in the box overnight read
// "no box desde -9:39:47"); empty when the moment is unknown.
export function desdeDoStatus(carro, largadaMs) {
  const desde = carro?.status_desde_ms;
  if (!desde) {
    return '';
  }
  if (largadaMs !== null && largadaMs !== undefined && desde < largadaMs) {
    return ' desde antes da largada';
  }
  return ` desde ${tempoDeProva(desde, largadaMs)}`;
}

export function textoDeAcima(acima) {
  return `contado acima de todos os contadores (+${acima})`;
}

export const DICA_DE_ACIMA = 'A contagem oficial tem mais passagens deste carro do que qualquer contador registrou. '
  + 'Costuma ser um registro solitário confirmado duas vezes, ou uma passagem adicionada que não existiu: '
  + 'clique no carro e confira as passagens confirmadas por revisor.';

// "2 alertas", and the title: which ones, and how many may change the count.
export function textoDosAlertas(sinais) {
  if (sinais.alertas === 0) {
    return null;
  }
  const duvida = sinais.emDuvida === 0 ? ''
    : ` — ${sinais.emDuvida === 1 ? '1 deles pode mudar' : `${sinais.emDuvida} deles podem mudar`} a contagem (passagem retida ou pendente)`;
  return { texto: resumoDeAlertas(sinais.alertas), titulo: `Alertas abertos: ${sinais.tipos.join(', ')}${duvida}` };
}

export function textoDeCurtas(curtas) {
  return curtas === 1 ? '1 volta curta confirmada' : `${curtas} voltas curtas confirmadas`;
}

// ---- sorting -------------------------------------------------------------------

// Each column sorts by a value of the row. The direction of the first click
// is the one a reviewer usually wants: most laps first, the car with the
// oldest last pass first (a stopped car), the most signals first.
export const DIRECAO_NATURAL = {
  posicao: 'asc',
  carro: 'asc',
  equipe: 'asc',
  voltas: 'desc',
  avisos: 'desc',
  ultima_passagem: 'asc',
  ultima_volta: 'asc',
  melhor_volta: 'asc',
  situacao: 'asc',
};

// A stopped car first, a car that is simply out last.
const GRAVIDADE_DA_SITUACAO = { parado: 0, atrasado: 1, no_box: 2, abastecendo: 3, correndo: 4, fora: 5 };

export const ehColunaDeFonte = (coluna) => typeof coluna === 'string' && coluna.startsWith('fonte:');
export const colunaDaFonte = (fonte) => `fonte:${fonte.id}`;

export function direcaoNatural(coluna) {
  return ehColunaDeFonte(coluna) ? 'asc' : DIRECAO_NATURAL[coluna] ?? 'asc';
}

// A click on a column header: the natural direction, then the other one,
// then back to the standings (null).
export function proximaOrdem(atual, coluna) {
  if (!atual || atual.coluna !== coluna) {
    return { coluna, direcao: direcaoNatural(coluna) };
  }
  if (atual.direcao === direcaoNatural(coluna)) {
    return { coluna, direcao: atual.direcao === 'asc' ? 'desc' : 'asc' };
  }
  return null;
}

// The value a row is sorted by; null (no value) always goes last.
// `contexto`: {estado, colunas} as the table drew them.
export function valorParaOrdenar(carro, coluna, contexto) {
  if (ehColunaDeFonte(coluna)) {
    const daFonte = (contexto.colunas ?? []).find((item) => colunaDaFonte(item.fonte) === coluna);
    return daFonte ? celulaDaFonte(carro, daFonte).valor : null;
  }
  switch (coluna) {
    case 'posicao': return carro.posicao ?? null;
    case 'carro': return carro.carro;
    case 'equipe': return carro.equipe || null;
    case 'voltas': return carro.voltas ?? null;
    case 'ultima_passagem': return carro.ultima_passagem_ms ?? null;
    case 'ultima_volta': return carro.ultima_volta_ms ?? null;
    case 'melhor_volta': return carro.melhor_volta_ms ?? null;
    case 'situacao': return GRAVIDADE_DA_SITUACAO[carro.status] ?? null;
    case 'avisos': {
      // In doubt first, then the number of flags, then the other signals.
      const sinais = sinaisDoCarro(carro, contexto.estado, contexto.colunas ?? []);
      const peso = sinais.emDuvida * 1e9 + sinais.alertas * 1e6 + (sinais.acima ?? 0) * 1e3 + sinais.curtas;
      return peso === 0 ? null : peso;
    }
    default: return null;
  }
}

function compararValores(a, b) {
  if (typeof a === 'string' && typeof b === 'string') {
    return a.localeCompare(b, 'pt-BR', { sensitivity: 'base', numeric: true });
  }
  return compararNuloPorUltimo(a, b);
}

// The rows in the order chosen. With no choice (null), the standings as the
// server sent them. Either way a car that does not take part (not
// classified, not present) stays below every car that does, and a tie keeps
// the standings order, so rows never swap places between two redraws.
export function ordenarCarros(carros, ordem, contexto) {
  const posicaoOriginal = new Map(carros.map((carro, indice) => [carro, indice]));
  const ordenar = (lista) => {
    if (!ordem) {
      return lista;
    }
    const sinal = ordem.direcao === 'desc' ? -1 : 1;
    return [...lista].sort((a, b) => {
      const valorA = valorParaOrdenar(a, ordem.coluna, contexto);
      const valorB = valorParaOrdenar(b, ordem.coluna, contexto);
      const vazio = (valor) => valor === null || valor === undefined;
      const comparacao = vazio(valorA) || vazio(valorB)
        ? compararNuloPorUltimo(valorA, valorB)
        : sinal * compararValores(valorA, valorB);
      return comparacao !== 0 ? comparacao : posicaoOriginal.get(a) - posicaoOriginal.get(b);
    });
  };
  return [
    ...ordenar(carros.filter((carro) => carro.classificado !== false)),
    ...ordenar(carros.filter((carro) => carro.classificado === false)),
  ];
}

// The order the table uses now: the one chosen, unless its column is gone
// (a source switched off since); then the standings.
export function ordemEmVigor(ordem, colunas) {
  if (!ordem) {
    return null;
  }
  if (ehColunaDeFonte(ordem.coluna)) {
    return colunas.some((coluna) => colunaDaFonte(coluna.fonte) === ordem.coluna) ? ordem : null;
  }
  return Object.hasOwn(DIRECAO_NATURAL, ordem.coluna) ? ordem : null;
}

// aria-sort of a header, and the arrow after its name.
export function indicadorDaOrdem(ordem, coluna) {
  if (!ordem || ordem.coluna !== coluna) {
    return { ariaSort: null, seta: '' };
  }
  return ordem.direcao === 'asc' ? { ariaSort: 'ascending', seta: '▲' } : { ariaSort: 'descending', seta: '▼' };
}

// ---- remembered on this computer -------------------------------------------------

// The chosen order outlives a reload. One per prova: the source columns of
// one prova mean nothing in another. Any failure (storage blocked, full, a
// value written by something else) simply means "the standings".
export const CHAVE_DA_ORDEM = 'contagem-revisao-ordem-dos-carros';

export function lerOrdem(armazenamento, prova) {
  try {
    const guardada = JSON.parse(armazenamento.getItem(`${CHAVE_DA_ORDEM}:${prova}`));
    if (guardada && typeof guardada.coluna === 'string' && (guardada.direcao === 'asc' || guardada.direcao === 'desc')) {
      return { coluna: guardada.coluna, direcao: guardada.direcao };
    }
  } catch (erro) {
    // blocked or not JSON: the standings
  }
  return null;
}

export function guardarOrdem(armazenamento, prova, ordem) {
  try {
    if (ordem) {
      armazenamento.setItem(`${CHAVE_DA_ORDEM}:${prova}`, JSON.stringify(ordem));
    } else {
      armazenamento.removeItem(`${CHAVE_DA_ORDEM}:${prova}`);
    }
  } catch (erro) {
    // blocked or full: the order lasts until the reload
  }
}
