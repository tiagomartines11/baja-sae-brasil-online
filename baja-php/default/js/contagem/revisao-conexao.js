// How the review screen keeps talking to the server: how long to wait
// between polls, which answer is the newest, and how to fold an answer into
// what the screen already has.
// Pure functions, tested with `node --test`.

export const INTERVALO_NORMAL_MS = 2000;
export const INTERVALO_MAXIMO_MS = 30000;

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
//
// The version is an opaque token: the server only ever checks whether it is
// the one it has. It carries no order. (It used to be a number, the screen
// dropped any state with a lower one, and when the server's counter started
// again from zero every open screen froze without a word.)
export function versaoParaPedir(estado) {
  return estado?.versao ?? null;
}

// Puts the answers in order, since the versions cannot.
//
// A poll gets a number when it is SENT. The answer to a decision gets its
// number when it ARRIVES, because the server worked that state out after
// the decision: it is newer than the answer to any poll still on its way.
// An answer is applied only if its number is higher than the highest one
// already applied, so a slow poll that left before a decision was answered
// cannot put the screen back to before it.
//
//   const ordem = criarOrdemDePedidos();
//   const numero = ordem.novo();
//   if (ordem.aceitar(numero)) { ... apply the answer ... }
export function criarOrdemDePedidos() {
  let ultimoEnviado = 0;
  let ultimoAplicado = 0;
  return {
    novo() {
      ultimoEnviado += 1;
      return ultimoEnviado;
    },
    aceitar(numero) {
      if (numero <= ultimoAplicado) {
        return false;
      }
      ultimoAplicado = numero;
      return true;
    },
  };
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
// A full answer simply replaces the state. An "inalterado" answer means the
// last calculation is still the one on screen, but it carries what changes
// without a new calculation: the race clock, which car is late now, which
// phone went quiet, who is looking at which flag. Those parts are replaced
// and everything else is kept.
//
// `meuUsuario` is removed from the "olhando" lists.
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
  const status = resposta.status_dos_carros ?? null;
  const contato = resposta.contato_das_fontes ?? null;

  const novo = {
    ...estadoAtual,
    alertas_abertos: comQuemEstaOlhando(estadoAtual.alertas_abertos ?? [], olhando),
    alertas_resolvidos: comQuemEstaOlhando(estadoAtual.alertas_resolvidos ?? [], olhando),
  };
  if (status) {
    novo.carros = (estadoAtual.carros ?? []).map((carro) => ({
      ...carro,
      status: status[carro.carro]?.status ?? carro.status,
      status_desde_ms: status[carro.carro] ? status[carro.carro].status_desde_ms : carro.status_desde_ms,
    }));
  }
  if (contato) {
    novo.fontes = (estadoAtual.fontes ?? []).map((fonte) => (
      contato[fonte.id] && fonte.stats ? { ...fonte, stats: { ...fonte.stats, ...contato[fonte.id] } } : fonte
    ));
  }
  // The same keys the full state has; taken over only when the answer brings them.
  for (const chave of ['servidor_agora_ms', 'calculado_em_ms', 'corrida', 'dispositivos', 'revisores', 'publicado_em_ms', 'publicacao_desligada']) {
    if (resposta[chave] !== undefined) {
      novo[chave] = resposta[chave];
    }
  }
  return novo;
}

// Everything in a state that can change without a new version, as one
// string. The screen redraws its lists when this changes, and only then.
export function chaveAoVivo(estado) {
  if (!estado) {
    return '';
  }
  return JSON.stringify([
    (estado.carros ?? []).map((carro) => [carro.status, carro.status_desde_ms]),
    (estado.fontes ?? []).map((fonte) => fonte.stats?.offline ?? null),
    (estado.dispositivos ?? []).map((dispositivo) => [dispositivo.id, dispositivo.situacao]),
    [...(estado.alertas_abertos ?? []), ...(estado.alertas_resolvidos ?? [])].map((alerta) => alerta.olhando ?? []),
    estado.corrida?.estado ?? null,
    estado.publicacao_desligada ?? false,
  ]);
}
