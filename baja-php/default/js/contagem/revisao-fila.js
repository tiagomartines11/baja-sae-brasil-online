// What the review screen shows first and how it reads the state: the order
// of the flag queue, the state of each source, which counts disagree.
// Pure functions, tested with `node --test`.

import { idade, tempoDeProva } from './revisao-formato.js';

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

// Is a car's official count possibly wrong right now because of this flag?
export const contagemEmDuvida = (alerta) => alerta?.contagem_em_duvida === 'a_mais' || alerta?.contagem_em_duvida === 'a_menos';

// Order of the queue:
//   1. the flags that put a count in doubt (a held pass, two counted passes
//      too close together): a standings number may be wrong while they are
//      open, which an informational flag never means;
//   2. then the flags that touch the top of the standings (lowest "prioridade");
//   3. among equals the oldest first, because an old flag has been wrong on
//      the public screen for longer.
// The id breaks the last ties so the queue never reshuffles between two polls.
export function ordenarFila(alertas) {
  return [...alertas].sort((a, b) => {
    const porDuvida = Number(contagemEmDuvida(b)) - Number(contagemEmDuvida(a));
    if (porDuvida !== 0) {
      return porDuvida;
    }
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

// Room left before the car's previous pass when the window reaches back to it.
export const MARGEM_ANTES_DA_ANTERIOR_MS = 5000;

// How far back a window reaches for the previous pass when the car's
// expected lap is not known: a slow lap of a slow car.
export const ALCANCE_SEM_RITMO_MS = 5 * 60 * 1000;

// The stretch of the race to load for a flag. Null when the flag has no
// moment (for example a difference to an imported total).
//
// About a minute around the flag's moment. A flag about a lone entry
// names the car's previous official pass ("passagem_anterior"), and the
// window then starts a little before it, so the lap the entry would make
// is on screen: at a race the previous pass was 77 s back, outside the
// minute, and a 75 s lap was confirmed as real. Never further back than
// one expected lap of the car plus half a minute (five minutes when the
// pace is not known): a car that was stopped would otherwise load half an
// hour of race at this scale.
export function janelaDoAlerta(alerta, meiaJanelaMs = MEIA_JANELA_MS, voltaEsperadaMs = null) {
  if (!alerta || alerta.ts_ms === null || alerta.ts_ms === undefined) {
    return null;
  }
  let de = alerta.ts_ms - meiaJanelaMs;
  const anteriorMs = alerta.passagem_anterior?.ts_ms;
  if (anteriorMs !== null && anteriorMs !== undefined && anteriorMs < alerta.ts_ms) {
    const alcanceMs = voltaEsperadaMs ? voltaEsperadaMs + meiaJanelaMs : ALCANCE_SEM_RITMO_MS;
    de = Math.min(de, Math.max(anteriorMs - MARGEM_ANTES_DA_ANTERIOR_MS, alerta.ts_ms - alcanceMs));
  }
  return { de, ate: alerta.ts_ms + meiaJanelaMs };
}

// The expected lap of the (first) car of a flag, from the car table; null when unknown.
export function voltaEsperadaDoAlerta(alerta, estado) {
  const carro = (alerta?.carros ?? [])[0];
  return (estado?.carros ?? []).find((linha) => linha.carro === carro)?.volta_esperada_ms ?? null;
}

// The tooltip of a car's laps in the table when some of them are too
// short to be real and count on a reviewer's confirmation (the server's
// "voltas_curtas_confirmadas"); null when none is.
export function avisoDeVoltasCurtas(carro, largadaMs = null) {
  const curtas = carro?.voltas_curtas_confirmadas ?? [];
  if (curtas.length === 0) {
    return null;
  }
  const lista = curtas.map((volta) => `volta ${volta.numero_volta}`
    + ` (${Math.round((volta.volta_ms ?? 0) / 1000)} s${largadaMs === null ? '' : `, em ${tempoDeProva(volta.ts_ms, largadaMs)}`})`).join(', ');
  return `Volta curta demais confirmada pela revisão: ${lista}. Ninguém dá a volta tão rápido; confira se a passagem confirmada não é a mesma registrada duas vezes.`;
}

// A flag about an entry of unknown time has no moment worth a one-minute
// window: the time the entry carries is its phone's own guess. What helps
// the reviewer decide is the car's whole race, so that is what is loaded.
export function mostraCorridaInteira(alerta) {
  return alerta?.tipo === 'tempo_desconhecido' && (alerta.carros ?? []).length > 0;
}

// When a flag happened, for the queue and the panel's title.
export function quandoDoAlerta(alerta, largadaMs) {
  return mostraCorridaInteira(alerta) ? 'sem horário' : tempoDeProva(alerta.ts_ms, largadaMs);
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

// A car of the table that does not take part in the race: the short label
// for its row and the sentence for its panel.
//   present, not classified   the classification field is blank or NÃO
//   registered, not present   a real team nobody marked as present at the
//                             event. Calling this "número desconhecido" sent
//                             reviewers looking for a typing mistake
//   not registered            no team of the event has this number: a typo
export function situacaoDoCarroFora(carro) {
  if (carro.presente) {
    return {
      etiqueta: 'não classificado',
      frase: 'Carro presente, mas NÃO classificado para esta prova: as passagens dele não contam, a não ser as que um revisor confirmar.',
    };
  }
  // An answer from before "inscrito" existed says nothing: keep the old words.
  if (carro.inscrito) {
    return {
      etiqueta: 'equipe não marcada como presente',
      frase: 'Esta equipe é do evento, mas NÃO está marcada como presente: as passagens dela não contam. '
        + 'Se o carro está na pista, marque a presença da equipe no cadastro do evento.',
    };
  }
  return { etiqueta: 'número desconhecido', frase: 'Este número não é de nenhuma equipe do evento.' };
}

// The label of the open-flag counter: "nenhum alerta", "1 alerta", "7 alertas".
export function resumoDeAlertas(quantidade) {
  if (quantidade === 0) {
    return 'nenhum alerta';
  }
  return quantidade === 1 ? '1 alerta' : `${quantidade} alertas`;
}
