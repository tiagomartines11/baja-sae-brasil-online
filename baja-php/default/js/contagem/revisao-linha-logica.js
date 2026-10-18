// The permanent timeline of the review screen: which stretch of the race it
// shows, in which pieces it is loaded, and what it says about a flag. Pure
// functions, tested with `node --test`; the panel itself is revisao-linha.js.
//
// The timeline is always on screen. With nothing selected it follows the
// race as it happens; a flag scrolls and zooms the same timeline to its
// moment; a car shows that car. What is loaded is cut into pieces of a few
// minutes on a fixed grid ("pedaços"), each asked of contagem_janela.php on
// its own and kept until the state changes, so scrolling back and forth and
// opening one flag after another does not ask the server again.

import { duracao } from './revisao-formato.js';
import { mostraCorridaInteira } from './revisao-fila.js';
import { ESCALA_ALERTA, ESCALA_CARRO } from './revisao-raias-logica.js';

// One piece. The grid is fixed (multiples of this since the epoch), so the
// same stretch of the race is always the same piece, whoever asks.
export const PEDACO_MS = 2 * 60 * 1000;

// What the timeline shows with nothing selected: the last five minutes.
export const AO_VIVO_MS = 5 * 60 * 1000;

// Pixels per second with nothing selected: a chip is about five seconds
// wide, so a laptop shows the last two minutes and a phone the last 40 s.
export const ESCALA_AO_VIVO = 8;

// Kept in step with --largura-nome-raia: the lane names cover that much of
// the left of the scrolling area.
export const LARGURA_DO_NOME_PX = 110;

// At most this many pieces are kept on screen at once (half an hour); the
// ones furthest from where the reviewer is looking are let go.
export const MAXIMO_DE_PEDACOS = 15;

// A piece whose state changed is asked again at most this often. During a
// race the state changes with every upload, every few seconds, nearly
// always about cars the reviewer is not looking at.
export const ATUALIZAR_NO_MAXIMO_A_CADA_MS = 5000;

// The same for a piece of the past, older than the piece before "now": what
// changes there is a decision or a late upload, rare, and every piece asked
// costs the server a recount of every car typed in it. A reviewer's own
// decision reloads what is on screen at once, and a flag's pieces are
// loaded afresh when it is opened, whatever this says.
export const ATUALIZAR_O_PASSADO_A_CADA_MS = 30000;

// How far before the start the timeline lets the reviewer scroll back.
export const ANTES_DA_LARGADA_MS = 10 * 60 * 1000;

export function inicioDoPedaco(tsMs) {
  return Math.floor(tsMs / PEDACO_MS) * PEDACO_MS;
}

// What one piece asks of the server. Both ends are included there, so the
// last millisecond belongs to the next piece.
export function intervaloDoPedaco(inicio) {
  return { de: inicio, ate: inicio + PEDACO_MS - 1 };
}

// A stretch of pieces, {primeiro, ultimo} (their starts), and its pieces.
export function faixaEntre(deMs, ateMs) {
  return { primeiro: inicioDoPedaco(deMs), ultimo: inicioDoPedaco(Math.max(deMs, ateMs)) };
}

export function pedacosDaFaixa(faixa) {
  const inicios = [];
  for (let inicio = faixa.primeiro; inicio <= faixa.ultimo; inicio += PEDACO_MS) {
    inicios.push(inicio);
  }
  return inicios;
}

// The live timeline always reaches a little past the present, so "now"
// can sit near the right edge without the view running out of lanes.
export const ALEM_DO_AGORA_MS = PEDACO_MS / 2;

export const faixaAoVivo = (agoraMs) => faixaEntre(agoraMs - AO_VIVO_MS, agoraMs + ALEM_DO_AGORA_MS);

// Following the race, "now" is drawn at this fraction of the visible width.
export const FRACAO_DO_AGORA = 0.85;

// Still following the race after a scroll? Yes while "now" is not further
// left than a chip's width from where following puts it.
export const aindaSeguindo = (rolagemPx, rolagemDoAgoraPx) => rolagemPx >= rolagemDoAgoraPx - 40;

// The moments a stretch of pieces covers, for the lanes.
export const limitesDaFaixa = (faixa) => ({ de: faixa.primeiro, ate: faixa.ultimo + PEDACO_MS });

// What the timeline shows, from what is selected:
//   fonte   'pedacos' (pieces of time, all cars) or 'carro' (one car's whole race)
//   carro   the car of 'carro'
//   escala  pixels per second
//   aoVivo  follows the present moment
//   chave   changes exactly when the reviewer picked something else
//   momentoMs  for a car opened from a line of the history: that line's
//           moment, which the view goes to (null: the car's last pass)
// `soCarro` is the "só carro N" switch of a selected car.
export function modoDaLinha(selecao, alerta, soCarro = true) {
  if (selecao?.tipo === 'alerta' && alerta) {
    const chave = `alerta:${alerta.id}`;
    if (mostraCorridaInteira(alerta)) {
      return { fonte: 'carro', carro: alerta.carros[0], escala: ESCALA_CARRO, aoVivo: false, chave };
    }
    if (alerta.ts_ms === null || alerta.ts_ms === undefined) {
      // About the whole race (a paper total): no moment to go to.
      return { fonte: 'pedacos', carro: null, escala: ESCALA_AO_VIVO, aoVivo: true, chave };
    }
    return { fonte: 'pedacos', carro: null, escala: ESCALA_ALERTA, aoVivo: false, chave };
  }
  if (selecao?.tipo === 'carro') {
    // The moment is part of the key: another line of the history about the
    // same car moves the view, even with the car's race already on screen.
    const momentoMs = selecao.momentoMs ?? null;
    const noMomento = momentoMs === null ? '' : `@${momentoMs}`;
    return soCarro
      ? { fonte: 'carro', carro: selecao.numero, escala: ESCALA_CARRO, aoVivo: false, momentoMs, chave: `carro:${selecao.numero}:so${noMomento}` }
      : { fonte: 'pedacos', carro: null, escala: ESCALA_AO_VIVO, aoVivo: false, momentoMs, chave: `carro:${selecao.numero}${noMomento}` };
  }
  return { fonte: 'pedacos', carro: null, escala: ESCALA_AO_VIVO, aoVivo: true, chave: 'ao_vivo' };
}

// The moment marked with the dashed line across the lanes: a flag's
// moment while it is zoomed on, or the moment of the history line a car
// was opened from (a "saída marcada" has no chip of its own to outline).
// Null otherwise.
export function momentoEmDestaque(modo, alerta) {
  if (alerta) {
    return modo.fonte === 'pedacos' && !modo.aoVivo ? alerta.ts_ms ?? null : null;
  }
  return modo.momentoMs ?? null;
}

// Which entries stand out: the flag's own (strongly), and the cars whose
// other entries are drawn weakly while every other car is dimmed. In a
// car's own race nothing is dimmed: all of it is that car. A car opened
// from a line of the history has that line's entries (`idsEmDestaque` of
// the selection) outlined strongly, as a flag's.
export function destaqueDaLinha(selecao, alerta, modo) {
  if (selecao?.tipo === 'alerta' && alerta) {
    return { ids: alerta.passagem_ids ?? [], carros: alerta.carros ?? [] };
  }
  if (selecao?.tipo === 'carro') {
    const ids = selecao.idsEmDestaque ?? [];
    return { ids, carros: modo.fonte === 'pedacos' ? [selecao.numero] : [] };
  }
  return { ids: [], carros: [] };
}

// Where a car's whole race goes on screen when it is opened: its end (the
// latest passes), or the moment of the line of the history it was opened
// from, centred.
export function alvoDaCorridaDoCarro(modo) {
  return modo.momentoMs === null || modo.momentoMs === undefined
    ? { tipo: 'fim' }
    : { tipo: 'centro', ts: modo.momentoMs };
}

// The pieces to ask the server for, among `inicios` (those in view):
// the ones never loaded, and the ones loaded at another state version
// (or that failed) and not asked for in the last few seconds (half a
// minute for a piece of the past). One already on its way is not asked
// again.
//   cache          Map inicio -> {versao, buscadoEmMs, erro}
//   emVoo          Set of the pieces on their way
//   agoraMs        the clock of `buscadoEmMs`
//   corridaAgoraMs the race's "now", which tells the past from the present
export function pedacosAPedir(inicios, cache, emVoo, versao, agoraMs, corridaAgoraMs = null) {
  const recentesDesde = corridaAgoraMs === null ? -Infinity : inicioDoPedaco(corridaAgoraMs) - PEDACO_MS;
  return inicios.filter((inicio) => {
    if (emVoo.has(inicio)) {
      return false;
    }
    const guardado = cache.get(inicio);
    if (!guardado) {
      return true;
    }
    const velho = guardado.erro || guardado.versao !== versao;
    const pausaMs = inicio >= recentesDesde || guardado.erro ? ATUALIZAR_NO_MAXIMO_A_CADA_MS : ATUALIZAR_O_PASSADO_A_CADA_MS;
    return velho && agoraMs - guardado.buscadoEmMs >= pausaMs;
  });
}

// Whether a piece in view failed with nothing of it on screen: the header
// then says so and offers "Tentar de novo", instead of "carregando…" for
// ever. A piece that failed to refresh keeps what it showed: not an error.
export function faltaPorErro(inicios, cache) {
  return inicios.some((inicio) => {
    const guardado = cache.get(inicio);
    return Boolean(guardado?.erro && !guardado.janela);
  });
}

// The loaded pieces of a stretch, as one window like the server's.
// Lanes are matched by source, in the order they first appear; an entry or
// event on the border of two pieces is kept once.
//   janelas  the answers of the pieces, in time order (missing ones left out)
export function juntarPedacos(janelas, faixa) {
  const limites = limitesDaFaixa(faixa);
  const raias = new Map();
  const vistas = new Set();
  const ocorrencias = new Map();
  const estadias = new Map();
  const paradas = new Map();
  let base = {};
  for (const janela of janelas) {
    base = janela;
    for (const raia of janela.raias ?? []) {
      if (!raias.has(raia.fonte_id)) {
        raias.set(raia.fonte_id, { ...raia, entradas: [] });
      }
      const juntas = raias.get(raia.fonte_id);
      // The newest piece says what the source is now (its name, whether it is on).
      Object.assign(juntas, { ...raia, entradas: juntas.entradas });
      for (const entrada of raia.entradas ?? []) {
        if (!vistas.has(entrada.id)) {
          vistas.add(entrada.id);
          juntas.entradas.push(entrada);
        }
      }
    }
    for (const ocorrencia of janela.ocorrencias ?? []) {
      ocorrencias.set(`${ocorrencia.id ?? '-'}|${ocorrencia.tipo}|${ocorrencia.carro ?? '-'}|${ocorrencia.ts_ms}`, ocorrencia);
    }
    for (const estadia of janela.estadias ?? []) {
      estadias.set(`${estadia.area}|${estadia.carro}|${estadia.de_ms}`, estadia);
    }
    for (const parada of janela.paradas_sem_horario ?? []) {
      paradas.set(parada.ocorrencia_id, parada);
    }
  }
  return {
    de_ms: limites.de,
    ate_ms: limites.ate,
    carro: null,
    largada_ms: base.largada_ms ?? null,
    encerramento_ms: base.encerramento_ms ?? null,
    abastecimento: janelas.some((janela) => janela.abastecimento),
    raias: [...raias.values()],
    ocorrencias: [...ocorrencias.values()].sort((a, b) => a.ts_ms - b.ts_ms),
    estadias: [...estadias.values()].sort((a, b) => a.de_ms - b.de_ms),
    paradas_sem_horario: [...paradas.values()],
  };
}

// The moments in view: `rolagemPx` is the scroll position, `larguraPx` the
// width of the scrolling area (the lane names cover its left part).
export function trechoVisivel(rolagemPx, larguraPx, deMs, escala) {
  const visivelPx = Math.max(0, larguraPx - LARGURA_DO_NOME_PX);
  return {
    de: deMs + (rolagemPx / escala) * 1000,
    ate: deMs + ((rolagemPx + visivelPx) / escala) * 1000,
  };
}

// The scroll position that puts the moment `tsMs` at `fracao` of the
// visible width (0 = left edge, 1 = right edge). Never negative.
export function rolagemPara(tsMs, fracao, deMs, escala, larguraPx) {
  const visivelPx = Math.max(0, larguraPx - LARGURA_DO_NOME_PX);
  return Math.max(0, Math.round(((tsMs - deMs) / 1000) * escala - fracao * visivelPx));
}

// The scale a flag opens at: ESCALA_ALERTA, or less, so that what must be
// seen of it (trechoEssencialDoAlerta: the previous pass the gap is
// measured from, the late entry of a pair) fits the lanes in view with
// room for the last chip. For that whole stretch it zooms out no further
// than the live view's scale; when the stretch does not fit even then,
// the flag's own entries (`proprio`, first to last: momentosDoAlerta) must
// still fit, and for them it goes down to ESCALA_MINIMA_DO_ALERTA.
export function escalaDoAlerta(essencial, larguraPx, proprio = null) {
  const visivelPx = Math.max(0, larguraPx - LARGURA_DO_NOME_PX) - ESPACO_DO_CHIP_PX;
  const segundos = (essencial.ate - essencial.de) / 1000;
  if (visivelPx <= 0 || segundos <= 0) {
    return ESCALA_ALERTA;
  }
  const escala = Math.min(ESCALA_ALERTA, visivelPx / segundos);
  if (escala >= ESCALA_AO_VIVO || proprio === null) {
    return Math.max(ESCALA_AO_VIVO, escala);
  }
  const segundosProprios = (proprio.ate - proprio.de) / 1000;
  return segundosProprios <= 0
    ? ESCALA_AO_VIVO
    : Math.max(ESCALA_MINIMA_DO_ALERTA, Math.min(ESCALA_AO_VIVO, visivelPx / segundosProprios));
}

// How far a flag zooms out so that its own entries fit: the late entry of
// a "registro_tardio" is typed up to a minute after the flag's, and a
// phone (390 px) shows about 250 px of lanes, 190 px once the last chip has
// its room: 190 px / 60 s is just over 3 px/s. A chip (40 px) is then
// about 13 s wide; chips closer than that go on a second row.
export const ESCALA_MINIMA_DO_ALERTA = 3;

// Where a flag's window goes on screen. A window that fits is centred;
// else what must be seen of it (`essencial`), when that fits; else the
// flag's last entry goes near the right edge, so as much as possible of
// the lap before it is in view, with room left for that chip on a phone,
// but never so far right that the flag's first entry (`proprio.de`) is
// off the left edge.
export function rolagemParaAlerta(janelaDoAlerta, essencial, deMs, escala, larguraPx, proprio = null) {
  const visivelPx = Math.max(0, larguraPx - LARGURA_DO_NOME_PX);
  const larguraDaJanelaPx = ((janelaDoAlerta.ate - janelaDoAlerta.de) / 1000) * escala;
  if (larguraDaJanelaPx <= visivelPx) {
    return rolagemPara((janelaDoAlerta.de + janelaDoAlerta.ate) / 2, 0.5, deMs, escala, larguraPx);
  }
  const essencialPx = ((essencial.ate - essencial.de) / 1000) * escala + ESPACO_DO_CHIP_PX;
  if (essencialPx <= visivelPx) {
    return Math.max(0, rolagemPara(essencial.de, 0, deMs, escala, larguraPx) - Math.round((visivelPx - essencialPx) / 2));
  }
  const fracao = visivelPx > 0 ? Math.min(0.85, (visivelPx - ESPACO_DO_CHIP_PX) / visivelPx) : 0.85;
  const rolagem = rolagemPara(essencial.ate, Math.max(0, fracao), deMs, escala, larguraPx);
  return proprio === null ? rolagem : Math.min(rolagem, rolagemPara(proprio.de, 0, deMs, escala, larguraPx));
}

// A chip and its outline, in pixels.
const ESPACO_DO_CHIP_PX = 60;

// Where the view goes when "só o carro" is switched off: around the chip
// the reviewer picked in the car's race, or else the moment of the line of
// the history the car was opened from, or else the car's last pass, or
// else now. Never in the future.
export function centroAoVerTodos(entradaEscolhida, ultimaPassagemMs, agoraMs, momentoMs = null) {
  const centro = entradaEscolhida?.ts_ms ?? momentoMs ?? ultimaPassagemMs ?? agoraMs;
  return Math.min(centro, agoraMs);
}

// The stretch, grown by one piece where the view reached its edge: back in
// time down to `limiteAntes`, forward up to the piece of `agoraMs`. When it
// grew beyond MAXIMO_DE_PEDACOS, the piece furthest from the view is let
// go. Returns the same object when nothing changed.
export function estenderFaixa(faixa, trecho, limiteAntesMs, agoraMs) {
  const limites = limitesDaFaixa(faixa);
  const margemMs = PEDACO_MS / 2;
  let { primeiro, ultimo } = faixa;
  if (trecho.de - limites.de < margemMs && primeiro - PEDACO_MS >= inicioDoPedaco(limiteAntesMs)) {
    primeiro -= PEDACO_MS;
  }
  if (limites.ate - trecho.ate < margemMs && ultimo + PEDACO_MS <= inicioDoPedaco(agoraMs)) {
    ultimo += PEDACO_MS;
  }
  while ((ultimo - primeiro) / PEDACO_MS + 1 > MAXIMO_DE_PEDACOS) {
    const meioDaVista = (trecho.de + trecho.ate) / 2;
    if (meioDaVista - primeiro > ultimo + PEDACO_MS - meioDaVista) {
      primeiro += PEDACO_MS;
    } else {
      ultimo -= PEDACO_MS;
    }
  }
  return primeiro === faixa.primeiro && ultimo === faixa.ultimo ? faixa : { primeiro, ultimo };
}

// The pieces of a stretch that are in view, with half a piece to spare on
// each side so a small scroll finds them loaded.
export function pedacosVisiveis(faixa, trecho) {
  const margemMs = PEDACO_MS / 2;
  return pedacosDaFaixa(faixa).filter((inicio) => inicio + PEDACO_MS > trecho.de - margemMs && inicio <= trecho.ate + margemMs);
}

// The gap between a flag's moment and the car's previous official pass
// (round 1's "passagem_anterior", sent with a lone entry and a crossing
// typed late), with what a normal lap of the car takes:
// "76 s desde a passagem anterior; volta normal ~3:40". Null when the flag
// names no previous pass.
export function lacunaDaPassagemAnterior(alerta, voltaEsperadaMs = null) {
  const anteriorMs = alerta?.passagem_anterior?.ts_ms;
  if (anteriorMs === null || anteriorMs === undefined || alerta.ts_ms === null || alerta.ts_ms === undefined
    || anteriorMs >= alerta.ts_ms) {
    return null;
  }
  const lacunaMs = alerta.ts_ms - anteriorMs;
  // Seconds while that is easy to compare with a lap: "76 s", then "3:20".
  const quanto = lacunaMs < 120000 ? `${Math.round(lacunaMs / 1000)} s` : duracao(lacunaMs);
  const normal = voltaEsperadaMs ? `; volta normal ~${duracao(voltaEsperadaMs)}` : '';
  return { de: anteriorMs, ate: alerta.ts_ms, texto: `${quanto} desde a passagem anterior${normal}` };
}

// The one line above the lanes: what they show.
export function situacaoDaLinha(modo, selecao, alerta) {
  if (selecao?.tipo === 'alerta' && alerta) {
    if (modo.fonte === 'carro') {
      return `corrida inteira do carro ${modo.carro} (alerta sem horário)`;
    }
    return modo.aoVivo ? 'ao vivo (este alerta não tem um momento)' : `em volta do alerta${doCarro(alerta.carros ?? [])}`;
  }
  if (selecao?.tipo === 'carro') {
    return modo.fonte === 'carro' ? `corrida inteira do carro ${selecao.numero}` : `todos os carros; carro ${selecao.numero} em destaque`;
  }
  return 'ao vivo: últimos minutos';
}

// " do carro 5", " dos carros 5 e 7", " dos carros 3, 5 e 7"; nothing for no car.
function doCarro(carros) {
  if (carros.length === 0) {
    return '';
  }
  if (carros.length === 1) {
    return ` do carro ${carros[0]}`;
  }
  return ` dos carros ${carros.slice(0, -1).join(', ')} e ${carros[carros.length - 1]}`;
}
