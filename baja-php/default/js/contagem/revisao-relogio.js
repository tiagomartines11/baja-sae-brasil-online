// The official clock of the review screen ("Horário oficial").
//
// The time of the SERVER, carried between two answers by this computer's
// monotonic clock (performance.now(), which only ever counts forward at a
// steady rate). The computer's own date and time take no part: a laptop set
// to the wrong day, or whose clock is corrected in the middle of the race,
// shows the same official time as every other screen.
//
// Pure functions and one small object, tested with `node --test`. The
// caller passes in every clock reading, so nothing here reads a clock.

// On some machines the monotonic clock stops while the computer sleeps.
// When it and the system clock disagree by more than this about how much
// time went by, the anchor is thrown away and the next answer makes a new one.
export const TOLERANCIA_DE_PARADA_MS = 2000;

const FUSO_DO_EVENTO = 'America/Sao_Paulo';

// criarRelogioOficial() returns:
//   registrar({servidorAgoraMs, enviadoPerfMs, recebidoPerfMs})
//       an answer arrived: the server said what time it was, and the
//       request left and came back at these monotonic readings
//   vigiar(perfMs, sistemaMs)
//       called every second with both clocks; discards the anchor when the
//       monotonic clock stalled
//   agora(perfMs)      the official time at that monotonic reading, or null
//   sincronizado()     false until the first answer (and after a stall)
export function criarRelogioOficial() {
  let ancora = null;
  let ultimaVigia = null;

  return {
    registrar({ servidorAgoraMs, enviadoPerfMs, recebidoPerfMs }) {
      if (!Number.isFinite(servidorAgoraMs) || !Number.isFinite(enviadoPerfMs) || !Number.isFinite(recebidoPerfMs)) {
        return;
      }
      const idaEVoltaMs = Math.max(0, recebidoPerfMs - enviadoPerfMs);
      // The server stamped its time somewhere between the two readings;
      // the middle is the best guess, and the shorter the round trip the
      // smaller the error. So the anchor with the shortest round trip is kept.
      if (ancora !== null && idaEVoltaMs >= ancora.idaEVoltaMs) {
        return;
      }
      ancora = { servidorMs: servidorAgoraMs, perfMs: enviadoPerfMs + idaEVoltaMs / 2, idaEVoltaMs };
    },

    vigiar(perfMs, sistemaMs) {
      if (ultimaVigia !== null) {
        const avancoPerf = perfMs - ultimaVigia.perfMs;
        const avancoSistema = sistemaMs - ultimaVigia.sistemaMs;
        if (Math.abs(avancoPerf - avancoSistema) > TOLERANCIA_DE_PARADA_MS) {
          // Either the monotonic clock stalled (the anchor is now wrong)
          // or the system clock was changed (the anchor is still right).
          // The two cannot be told apart from here, and a new anchor costs
          // one poll, so it is dropped either way.
          ancora = null;
        }
      }
      ultimaVigia = { perfMs, sistemaMs };
    },

    agora(perfMs) {
      return ancora === null ? null : Math.round(ancora.servidorMs + (perfMs - ancora.perfMs));
    },

    sincronizado() {
      return ancora !== null;
    },
  };
}

// "14:03:27" in the time zone of the event, whatever zone this computer is
// set to. Null (no anchor yet) reads "sincronizando…".
export function horarioOficial(agoraMs) {
  if (agoraMs === null || agoraMs === undefined) {
    return 'sincronizando…';
  }
  return new Intl.DateTimeFormat('pt-BR', {
    timeZone: FUSO_DO_EVENTO, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
  }).format(new Date(agoraMs));
}

// How old the numbers on screen are: "calculado há 4 s". Flags, lap times
// and best laps are recalculated at most every `recalculo_s`; three times
// that with no new calculation means something is stuck, and the text turns red.
// Only while the race is running: with nobody typing (before the start,
// after the finish, under a red flag) nothing changes, nothing is
// recalculated, and an old calculation is exactly what is expected.
// Returns {segundos, atrasado}, or null when the age cannot be known.
export function idadeDoCalculo(estado, agoraMs) {
  const calculadoEm = estado?.calculado_em_ms;
  if (calculadoEm === null || calculadoEm === undefined || agoraMs === null || agoraMs === undefined) {
    return null;
  }
  const segundos = Math.max(0, Math.floor((agoraMs - calculadoEm) / 1000));
  const recalculoS = Number(estado.configuracoes?.recalculo_s) || 5;
  return { segundos, atrasado: estado.corrida?.estado === 'correndo' && segundos > 3 * recalculoS };
}
