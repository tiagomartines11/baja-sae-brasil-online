// The three endpoints of the review screen. Nothing here is kept for later:
// decisions are online-only, a request that fails is reported and that is all.

const TEMPO_LIMITE_MS = 10000;

// Thrown when the answer is the login page instead of JSON: the forum
// session ended and polling again will not fix it.
export class SessaoExpirada extends Error {}

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
    const tipo = resposta.headers.get('Content-Type') ?? '';
    if (resposta.redirected || !tipo.includes('application/json')) {
      throw new SessaoExpirada();
    }
    return { status: resposta.status, corpo: await resposta.json() };
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

// Poll. `versao` null asks for the full state; `alertaId` tells the server
// which flag this reviewer has open, for "X está olhando".
export async function buscarEstado(prova, versao, alertaId) {
  const { status, corpo } = await pedirJson(comParametros('contagem_estado.php', { p: prova, versao, alerta: alertaId }));
  if (status !== 200) {
    throw new Error(`estado: HTTP ${status}`);
  }
  return corpo;
}

export async function buscarJanela(prova, deMs, ateMs) {
  const { status, corpo } = await pedirJson(comParametros('contagem_janela.php', { p: prova, de: deMs, ate: ateMs }));
  if (status !== 200) {
    throw new Error(`janela: HTTP ${status}`);
  }
  return corpo;
}

export async function buscarCarro(prova, carro) {
  const { status, corpo } = await pedirJson(comParametros('contagem_janela.php', { p: prova, carro }));
  if (status !== 200) {
    throw new Error(`carro: HTTP ${status}`);
  }
  return corpo;
}

// Returns {status, corpo}: 200 (stored), 409 (refused, with the fresh
// state) and 400 (bad request) are all answers the caller shows.
export async function enviarDecisao(prova, csrf, pedido) {
  return pedirJson(comParametros('contagem_decidir.php', { p: prova }), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Contagem-Csrf': csrf },
    body: JSON.stringify(pedido),
  });
}
