// How the review screen keeps talking to the server: how long to wait
// between polls, when to ask for everything again, and how to fold an
// answer into what the screen already has.
// Pure functions, tested with `node --test`.

export const INTERVALO_NORMAL_MS = 2000;
export const INTERVALO_MAXIMO_MS = 30000;

// The server only says "inalterado" while the version is the same, but a
// few things on screen change without a new version (a source going
// offline, a car becoming "atrasado"). So every few polls the screen asks
// for the whole state regardless.
export const POLLS_ENTRE_ESTADOS_COMPLETOS = 5;

// Wait before the next poll. Every failure in a row doubles the wait, up to
// a ceiling: a server that is struggling must not be hit every 2 seconds by
// every open review screen, and a reviewer must not wait minutes to
// reconnect either.
//   0 failures -> 2 s, 1 -> 4 s, 2 -> 8 s, 3 -> 16 s, 4 or more -> 30 s
export function proximoAtrasoMs(falhasSeguidas) {
  const falhas = Math.max(0, Math.floor(falhasSeguidas));
  // Past a handful of failures the doubling is far over the ceiling anyway.
  const dobras = Math.min(falhas, 10);
  return Math.min(INTERVALO_MAXIMO_MS, INTERVALO_NORMAL_MS * 2 ** dobras);
}

// The "sem conexão" banner. Null while everything is fine.
export function avisoDeConexao(falhasSeguidas, sessaoExpirada = false) {
  if (sessaoExpirada) {
    return 'Sua sessão expirou. Recarregue a página e entre de novo.';
  }
  if (falhasSeguidas <= 0) {
    return null;
  }
  const segundos = Math.round(proximoAtrasoMs(falhasSeguidas) / 1000);
  return `Sem conexão. Os dados na tela podem estar desatualizados e não é possível decidir. Nova tentativa em ${segundos} s.`;
}

// Which version to send with the next poll. Null means "send me everything".
export function versaoParaPedir(estado, pollsDesdeEstadoCompleto) {
  if (!estado || pollsDesdeEstadoCompleto >= POLLS_ENTRE_ESTADOS_COMPLETOS) {
    return null;
  }
  return estado.versao;
}

// Difference between the server's clock and this computer's, so the race
// clock on screen is the server's even when this computer's is wrong.
export function desvioDoServidorMs(servidorAgoraMs, clienteAgoraMs) {
  return servidorAgoraMs - clienteAgoraMs;
}

function comQuemEstaOlhando(alertas, olhando) {
  return alertas.map((alerta) => ({ ...alerta, olhando: olhando[alerta.id] ?? [] }));
}

// Folds a poll answer into the state the screen has.
//
// A full answer simply replaces the state. An "inalterado" answer keeps it,
// but still carries who is looking at which flag (that changes without a
// new version), so only that part is updated.
//
// `meuUsuario` is removed from the "olhando" lists of an "inalterado"
// answer: the full state already comes without the viewer.
export function aplicarResposta(estadoAtual, resposta, meuUsuario = null) {
  if (!resposta.inalterado) {
    return resposta;
  }
  if (!estadoAtual) {
    return estadoAtual;
  }
  const olhando = {};
  for (const [alertaId, usuarios] of Object.entries(resposta.olhando ?? {})) {
    const outros = usuarios.filter((usuario) => usuario !== meuUsuario);
    if (outros.length > 0) {
      olhando[alertaId] = outros;
    }
  }
  return {
    ...estadoAtual,
    servidor_agora_ms: resposta.servidor_agora_ms ?? estadoAtual.servidor_agora_ms,
    alertas_abertos: comQuemEstaOlhando(estadoAtual.alertas_abertos ?? [], olhando),
    alertas_resolvidos: comQuemEstaOlhando(estadoAtual.alertas_resolvidos ?? [], olhando),
  };
}
