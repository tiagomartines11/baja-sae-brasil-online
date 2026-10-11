// What the review screen shows first and how it reads the state: the order
// of the flag queue, the state of each source, which counts disagree.
// Pure functions, tested with `node --test`.

import { idade } from './revisao-formato.js';

// Half of the "about one minute" a selected flag shows around its moment.
export const MEIA_JANELA_MS = 30000;

// Null sorts last: a flag with no standings position or no time is the
// least urgent thing in the queue.
function compararNuloPorUltimo(a, b) {
  const aVazio = a === null || a === undefined;
  const bVazio = b === null || b === undefined;
  if (aVazio && bVazio) {
    return 0;
  }
  if (aVazio) {
    return 1;
  }
  if (bVazio) {
    return -1;
  }
  return a - b;
}

// Order of the queue: first the flags that touch the top of the standings
// (lowest "prioridade"), and among equals the oldest first, because an old
// flag has been wrong on the public screen for longer. The id breaks the
// last ties so the queue never reshuffles between two polls.
export function ordenarFila(alertas) {
  return [...alertas].sort((a, b) => {
    const porPosicao = compararNuloPorUltimo(a.prioridade, b.prioridade);
    if (porPosicao !== 0) {
      return porPosicao;
    }
    const porIdade = compararNuloPorUltimo(a.ts_ms, b.ts_ms);
    if (porIdade !== 0) {
      return porIdade;
    }
    return String(a.id) < String(b.id) ? -1 : String(a.id) > String(b.id) ? 1 : 0;
  });
}

// The stretch of the race to load for a flag. Null when the flag has no
// moment (for example a difference to an imported total).
export function janelaDoAlerta(alerta, meiaJanelaMs = MEIA_JANELA_MS) {
  if (!alerta || alerta.ts_ms === null || alerta.ts_ms === undefined) {
    return null;
  }
  return { de: alerta.ts_ms - meiaJanelaMs, ate: alerta.ts_ms + meiaJanelaMs };
}

export function encontrarAlerta(estado, alertaId) {
  if (!estado || !alertaId) {
    return null;
  }
  const todos = [...(estado.alertas_abertos ?? []), ...(estado.alertas_resolvidos ?? [])];
  return todos.find((alerta) => alerta.id === alertaId) ?? null;
}

// The chip of one source in the status bar.
//   classe: ao-vivo | offline | nunca | inativa | totais
//   texto:  what to write next to the source's name
export function estadoDaFonte(fonte, agoraServidorMs) {
  if (!fonte.ativa) {
    return { classe: 'inativa', texto: 'inativa' };
  }
  if (fonte.tipo === 'totais') {
    return { classe: 'totais', texto: 'totais importados' };
  }
  const stats = fonte.stats ?? {};
  const visto = stats.visto_em_ms ?? null;
  if (visto === null) {
    return { classe: 'nunca', texto: 'nunca conectou' };
  }
  const semContato = idade(agoraServidorMs - visto);
  if (stats.offline) {
    return { classe: 'offline', texto: `offline há ${semContato}` };
  }
  if (stats.nada_a_esperar) {
    // In contact a moment ago, but no device is open any more (shift
    // closed, or revoked): nothing more is expected from this source.
    return { classe: 'ao-vivo', texto: 'sem dispositivo aberto' };
  }
  const sincronizado = stats.sincronizado_ate_ms ?? null;
  if (sincronizado === null) {
    return { classe: 'ao-vivo', texto: 'ao vivo, ainda sem sincronizar' };
  }
  const haQuanto = idade(agoraServidorMs - sincronizado);
  return { classe: 'ao-vivo', texto: haQuanto === 'agora' ? 'ao vivo' : `ao vivo, sincronizado há ${haQuanto}` };
}

// The devices of one source that are NOT simply working, as short words for
// the status bar: "1 silencioso, 1 revogado". Empty when all are fine.
//
// A reviewer wondering why a pass is still pending needs to know which
// phone the system is waiting for, and which ones it stopped waiting for.
export const NOMES_DE_SITUACAO = {
  ativo: 'ativo',
  sem_contato: 'sem contato',
  silencioso: 'silencioso',
  fechado: 'turno encerrado',
  revogado: 'revogado',
  nunca: 'nunca enviou',
};

export function dispositivosDaFonte(estado, fonteId) {
  return (estado?.dispositivos ?? []).filter((dispositivo) => dispositivo.fonte_id === fonteId);
}

export function resumoDosDispositivos(dispositivos) {
  const quantidades = {};
  for (const dispositivo of dispositivos) {
    if (dispositivo.situacao !== 'ativo') {
      quantidades[dispositivo.situacao] = (quantidades[dispositivo.situacao] ?? 0) + 1;
    }
  }
  return ['sem_contato', 'silencioso', 'fechado', 'revogado', 'nunca']
    .filter((situacao) => quantidades[situacao])
    .map((situacao) => `${quantidades[situacao]} ${NOMES_DE_SITUACAO[situacao]}`)
    .join(', ');
}

// Which sources disagree with a car's official count. Returns the ids of
// the sources whose cell should be highlighted in the car table.
//
// A counter types PASSES, and a car's first pass is its "pass zero", which
// is not a lap. So a counting source is compared with the car's official
// passes ("passagens": laps plus the pass zero), not with its laps; with
// laps every counter would look one ahead on every car. A "totais" source
// holds lap totals from paper and is compared with the laps.
export function fontesDivergentes(carro, fontes) {
  const passagensOficiais = carro.passagens ?? carro.voltas;
  const divergentes = [];
  for (const fonte of fontes) {
    if (!fonte.ativa) {
      continue;
    }
    if (fonte.tipo === 'totais') {
      const importado = (carro.totais_importados ?? {})[fonte.id];
      if (importado !== undefined && importado !== null && importado !== carro.voltas) {
        divergentes.push(fonte.id);
      }
      continue;
    }
    const contado = (carro.contagem_por_fonte ?? {})[fonte.id] ?? 0;
    if (contado !== passagensOficiais) {
      divergentes.push(fonte.id);
    }
  }
  return divergentes;
}

// The label of the open-flag counter: "nenhum alerta", "1 alerta", "7 alertas".
export function resumoDeAlertas(quantidade) {
  if (quantidade === 0) {
    return 'nenhum alerta';
  }
  return quantidade === 1 ? '1 alerta' : `${quantidade} alertas`;
}
