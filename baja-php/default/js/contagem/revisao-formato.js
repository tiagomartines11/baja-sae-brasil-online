// Formatting for the review screen. Pure functions: no DOM, no clock, so
// they can be tested with `node --test`.
//
// Every time shown to a reviewer is in whole seconds. The data has
// milliseconds, but nobody typing car numbers by hand is that precise and
// the extra digits only make the screen harder to read.

export const SEM_VALOR = '—';

export const NOMES_DE_ALERTA = {
  troca_provavel: 'Troca provável',
  solitaria: 'Passagem solitária',
  repetida: 'Repetida',
  carro_desconhecido: 'Carro desconhecido',
  no_box: 'Passagem com o carro no box',
  volta_curta: 'Volta curta demais',
  divergencia_total: 'Diferença para o total importado',
  primeira_passagem_tardia: 'Primeira passagem tardia',
};

export const NOMES_DE_STATUS = {
  correndo: 'correndo',
  atrasado: 'atrasado',
  parado: 'parado',
  no_box: 'no box',
  fora: 'fora',
};

export const NOMES_DE_ESTADO = {
  aguardando: 'Aguardando a largada',
  correndo: 'Corrida em andamento',
  bandeira_vermelha: 'Bandeira vermelha',
  encerrada: 'Corrida encerrada',
};

function doisDigitos(numero) {
  return String(numero).padStart(2, '0');
}

// 3725000 -> "1:02:05". Used for the race clock and for "when in the race".
export function relogio(ms) {
  if (ms === null || ms === undefined) {
    return SEM_VALOR;
  }
  const negativo = ms < 0;
  const segundosTotais = Math.floor(Math.abs(ms) / 1000);
  const horas = Math.floor(segundosTotais / 3600);
  const minutos = Math.floor((segundosTotais % 3600) / 60);
  const segundos = segundosTotais % 60;
  return `${negativo ? '-' : ''}${horas}:${doisDigitos(minutos)}:${doisDigitos(segundos)}`;
}

// 125000 -> "2:05". Used for lap times, which never need the hours.
export function duracao(ms) {
  if (ms === null || ms === undefined) {
    return SEM_VALOR;
  }
  const segundosTotais = Math.floor(Math.abs(ms) / 1000);
  const minutos = Math.floor(segundosTotais / 60);
  return `${ms < 0 ? '-' : ''}${minutos}:${doisDigitos(segundosTotais % 60)}`;
}

// How long ago something happened: "agora", "12 s", "3 min", "2 h".
export function idade(ms) {
  if (ms === null || ms === undefined) {
    return SEM_VALOR;
  }
  const segundos = Math.floor(Math.max(0, ms) / 1000);
  if (segundos < 2) {
    return 'agora';
  }
  if (segundos < 60) {
    return `${segundos} s`;
  }
  if (segundos < 3600) {
    return `${Math.floor(segundos / 60)} min`;
  }
  return `${Math.floor(segundos / 3600)} h`;
}

// A moment of the race shown as time since the start. Before the start is
// known there is nothing sensible to show.
export function tempoDeProva(tsMs, largadaMs) {
  if (tsMs === null || tsMs === undefined || largadaMs === null || largadaMs === undefined) {
    return SEM_VALOR;
  }
  return relogio(tsMs - largadaMs);
}

// Wall-clock time of a moment, "14:03:27", in this computer's time zone.
// Used where race time means nothing: a device seen before the start.
export function horaDoDia(tsMs) {
  if (tsMs === null || tsMs === undefined) {
    return SEM_VALOR;
  }
  const data = new Date(tsMs);
  return `${doisDigitos(data.getHours())}:${doisDigitos(data.getMinutes())}:${doisDigitos(data.getSeconds())}`;
}

// The opposite of relogio(): what a reviewer types in "adicionar passagem".
// Accepts "75" (seconds), "1:15" (minutes:seconds) and "1:02:05".
// Returns milliseconds, or null when the text is not a time.
export function interpretarTempoDeProva(texto) {
  const limpo = String(texto ?? '').trim();
  if (!/^\d{1,3}(:\d{1,2}){0,2}$/.test(limpo)) {
    return null;
  }
  const partes = limpo.split(':').map(Number);
  // With two or three parts, minutes and seconds must look like a clock.
  const menores = partes.slice(1);
  if (menores.some((parte) => parte > 59)) {
    return null;
  }
  let segundos = 0;
  for (const parte of partes) {
    segundos = segundos * 60 + parte;
  }
  return segundos * 1000;
}

// The race clock right now. The timeline's own "decorrido_ms" is only
// correct at the instant the server answered; the screen ticks every second
// between two answers, so it recomputes from the start time.
export function relogioDaCorrida(corrida, agoraServidorMs) {
  if (!corrida || corrida.largada_ms === null || corrida.largada_ms === undefined) {
    return null;
  }
  const fim = corrida.encerramento_ms ?? null;
  const ate = fim !== null ? Math.min(agoraServidorMs, fim) : agoraServidorMs;
  return Math.max(0, ate - corrida.largada_ms);
}

// "12, 41" for a flag about two cars.
export function listaDeCarros(carros) {
  return (carros ?? []).join(', ');
}
