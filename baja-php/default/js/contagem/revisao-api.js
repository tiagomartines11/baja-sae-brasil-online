// The endpoints of the review screen. Nothing here is kept for later:
// decisions are online-only, a request that fails is reported and that is all.

const TEMPO_LIMITE_MS = 10000;

// Thrown when the server no longer accepts this login: the forum session
// ended (or the permission was taken away) and polling again will not fix it.
export class SessaoExpirada extends Error {}

// Thrown when the server answered something that is not JSON: an error page,
// a PHP warning, a proxy timeout. The login is fine; the server is not.
// After a decision this means NOBODY KNOWS whether it was stored.
export class ErroDoServidor extends Error {}

// What kind of answer came back. Pure, so it can be tested:
//   'json'             an answer of ours, to be read (whatever its status)
//   'sessao_expirada'  redirected to the login page, or refused with 401/403
//   'erro_do_servidor' anything else that is not JSON
//
// Only a redirect or a 401/403 means the session ended. Treating every
// non-JSON answer as "sessão expirada" told reviewers to log in again when
// the server had merely printed an error, and hid that a decision might
// have been stored.
export function tipoDeResposta({ status, redirecionada, tipoDeConteudo }) {
  if (redirecionada || status === 401 || status === 403) {
    return 'sessao_expirada';
  }
  if (!String(tipoDeConteudo ?? '').includes('application/json')) {
    return 'erro_do_servidor';
  }
  return 'json';
}

async function pedirJson(url, opcoes = {}) {
  const cancelamento = new AbortController();
  const limite = setTimeout(() => cancelamento.abort(), TEMPO_LIMITE_MS);
  try {
    const resposta = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      signal: cancelamento.signal,
      ...opcoes,
    });
    const tipo = tipoDeResposta({
      status: resposta.status,
      redirecionada: resposta.redirected,
      tipoDeConteudo: resposta.headers.get('Content-Type'),
    });
    if (tipo === 'sessao_expirada') {
      throw new SessaoExpirada();
    }
    if (tipo === 'erro_do_servidor') {
      throw new ErroDoServidor(`HTTP ${resposta.status}`);
    }
    let corpo = null;
    try {
      corpo = await resposta.json();
    } catch (erro) {
      // Labelled as JSON but unreadable: cut short, or something was printed into it.
      throw new ErroDoServidor(`HTTP ${resposta.status}, corpo ilegível`);
    }
    return { status: resposta.status, corpo };
  } finally {
    clearTimeout(limite);
  }
}

function comParametros(pagina, parametros) {
  const consulta = new URLSearchParams();
  for (const [nome, valor] of Object.entries(parametros)) {
    if (valor !== null && valor !== undefined) {
      consulta.set(nome, String(valor));
    }
  }
  return `${pagina}?${consulta.toString()}`;
}

async function buscar(url, oQue) {
  const { status, corpo } = await pedirJson(url);
  if (status !== 200) {
    throw new ErroDoServidor(`${oQue}: HTTP ${status}`);
  }
  return corpo;
}

// Poll. `versao` null asks for the full state; `alertaId` tells the server
// which flag this reviewer has open, for "X está olhando".
export function buscarEstado(prova, versao, alertaId) {
  return buscar(comParametros('contagem_estado.php', { p: prova, versao, alerta: alertaId }), 'estado');
}

// One older page of the decision history: the decisions before `antesDoId`.
export function buscarHistorico(prova, antesDoId) {
  return buscar(comParametros('contagem_estado.php', { p: prova, historico_antes: antesDoId }), 'histórico');
}

export function buscarJanela(prova, deMs, ateMs) {
  return buscar(comParametros('contagem_janela.php', { p: prova, de: deMs, ate: ateMs }), 'janela');
}

export function buscarCarro(prova, carro) {
  return buscar(comParametros('contagem_janela.php', { p: prova, carro }), 'carro');
}

// Returns {status, corpo}: 200 (stored), 409 (refused, with the fresh
// state), 400 (bad request), 503 (busy, nothing stored) and 500 are all
// answers the caller shows.
export async function enviarDecisao(prova, csrf, pedido) {
  return pedirJson(comParametros('contagem_decidir.php', { p: prova }), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Contagem-Csrf': csrf },
    body: JSON.stringify(pedido),
  });
}
