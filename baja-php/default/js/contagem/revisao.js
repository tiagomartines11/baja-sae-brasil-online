// Review screen: entry module. Polls the server and keeps track of what is
// selected. The state lives in revisao-tela.js, the decision buttons in
// revisao-acoes.js, what is redrawn when in revisao-desenho.js, the drawing
// itself in the other revisao-*.js modules, and the rules worth testing in
// the pure ones (revisao-fila.js, revisao-decisoes.js, revisao-conexao.js,
// revisao-raias-logica.js).

import { criarAcoes } from './revisao-acoes.js';
import { SessaoExpirada, buscarCarro, buscarEstado, buscarHistorico, buscarJanela } from './revisao-api.js';
import { desenharBarra, desenharBarraSemEstado } from './revisao-barra.js';
import {
  aplicarResposta, avisoDeConexao, criarOrdemDePedidos, historicoAposResposta, proximoAtrasoMs, versaoParaPedir,
} from './revisao-conexao.js';
import { alertaSelecionado, atualizarListaDeCarros, criarDesenho, janelaDesatualizada } from './revisao-desenho.js';
import { estaDigitandoEm } from './revisao-dom.js';
import { janelaDoAlerta, mostraCorridaInteira } from './revisao-fila.js';
import {
  agoraServidorMs, horarioOficialMs, mostrarAba, mostrarMensagem, pagina, relogioOficial, tela,
} from './revisao-tela.js';

// Puts the answers in order; see criarOrdemDePedidos().
const ordem = criarOrdemDePedidos();

const acoes = criarAcoes({
  novoPedido: ordem.novo,
  receberEstado,
  // Wrapped: desenharTudo only exists once criarDesenho() below has run.
  desenharTudo: () => desenharTudo(),
  carregarJanela,
  janelaDesatualizada,
  atualizarAgora,
});

const acoesDoDetalhe = {
  aceitar: acoes.aceitar,
  confirmarAlerta: acoes.confirmarAlerta,
  descartarPassagem: acoes.descartarPassagem,
  marcarSaida: acoes.marcarSaida,
  confirmarSaida: acoes.confirmarSaida,
  // "Era outro carro…" on a repeat: opens the entry's own box, where the
  // field to move it to another car is.
  eraOutroCarro: (id) => { tela.entradaSelecionadaId = id; desenharDetalhe(); },
  anular: acoes.anular,
  acaoNaEntrada: acoes.acaoNaEntrada,
  adicionar: acoes.adicionar,
  alternarManual: () => { tela.modoManual = !tela.modoManual; desenharDetalhe(); },
  anotarMotivo: (texto) => { tela.motivo = texto; },
  escolherEntrada: (id) => { tela.entradaSelecionadaId = tela.entradaSelecionadaId === id ? null : id; desenharDetalhe(); },
  recarregar: carregarJanela,
  fechar: () => { tela.selecao = null; tela.janela = null; desenharTudo(); mostrarAba('fila'); },
};

const { desenharTudo, desenharDetalhe } = criarDesenho({
  acoes,
  acoesDoDetalhe,
  escolherAlerta,
  escolherCarro,
  carregarHistoricoAntigo,
});

// Takes a full state. `numero` is the number of the request it answers;
// a state that answers an older request than one already applied is dropped.
// Returns whether it was applied.
function receberEstado(estado, numero) {
  if (!ordem.aceitar(numero)) {
    return false;
  }
  // Older history pages the reviewer loaded stay until a decision is made
  // or undone; a new calculation alone does not throw them away.
  const paginas = historicoAposResposta(tela.estado, estado, { antigas: tela.historicoAntigo, haMais: tela.historicoHaMais });
  tela.historicoAntigo = paginas.antigas;
  tela.historicoHaMais = paginas.haMais;
  tela.estado = estado;
  atualizarListaDeCarros();
  return true;
}

// "Carregar decisões mais antigas" in the history panel.
async function carregarHistoricoAntigo() {
  if (tela.historicoCarregando || !tela.estado) {
    return;
  }
  const carregadas = [...(tela.estado.historico ?? []), ...tela.historicoAntigo];
  if (carregadas.length === 0) {
    return;
  }
  const ultimaDecisao = tela.estado.ultima_decisao_id;
  tela.historicoCarregando = true;
  desenharTudo();
  try {
    const maisAntigas = await buscarHistorico(pagina.prova, carregadas[carregadas.length - 1].id);
    // Unless a decision arrived meanwhile: the page would then be appended
    // to a list that was just started afresh.
    if (tela.estado.ultima_decisao_id === ultimaDecisao) {
      tela.historicoAntigo = [...tela.historicoAntigo, ...(maisAntigas.linhas ?? [])];
      tela.historicoHaMais = Boolean(maisAntigas.ha_mais);
    }
  } catch (erro) {
    mostrarMensagem('Não foi possível carregar as decisões mais antigas. Tente de novo.', 'erro');
  }
  tela.historicoCarregando = false;
  desenharTudo();
}

// ---- selection and its timeline ------------------------------------------

async function carregarJanela() {
  const selecao = tela.selecao;
  if (!selecao || !tela.estado) {
    return;
  }
  const versao = tela.estado.versao;
  const alerta = alertaSelecionado();
  try {
    let janela = null;
    if (selecao.tipo === 'carro') {
      janela = await buscarCarro(pagina.prova, selecao.numero);
    } else if (mostraCorridaInteira(alerta)) {
      janela = await buscarCarro(pagina.prova, alerta.carros[0]);
    } else {
      const limites = janelaDoAlerta(alerta);
      // A flag with no moment (a difference to an imported total) has no timeline.
      janela = limites
        ? await buscarJanela(pagina.prova, limites.de, limites.ate)
        : { de_ms: null, ate_ms: null, raias: [], ocorrencias: [] };
    }
    if (tela.selecao !== selecao) {
      return; // the reviewer moved on while this was loading
    }
    tela.janela = janela;
    tela.janelaVersao = versao;
    tela.janelaAssinatura = alerta?.assinatura ?? null;
    tela.erroDaJanela = false;
  } catch (erro) {
    if (tela.selecao !== selecao) {
      return;
    }
    tela.erroDaJanela = true;
  }
  desenharDetalhe();
}

function selecionar(selecao) {
  tela.selecao = selecao;
  tela.janela = null;
  tela.erroDaJanela = false;
  tela.entradaSelecionadaId = null;
  tela.modoManual = false;
  tela.motivo = '';
  mostrarMensagem(null);
  desenharTudo();
  mostrarAba('alerta');
  carregarJanela();
}

function escolherAlerta(id) {
  selecionar({ tipo: 'alerta', id });
}

function escolherCarro(numero) {
  selecionar({ tipo: 'carro', numero });
}

// ---- polling -------------------------------------------------------------------

let proximaConsulta = null;
let consultando = false;

// One poll. `pedirTudo` asks for the whole state even if the version is the
// one the screen has.
async function consultar(pedirTudo = false) {
  clearTimeout(proximaConsulta);
  if (consultando) {
    return;
  }
  consultando = true;
  const numero = ordem.novo();
  try {
    const versao = pedirTudo ? null : versaoParaPedir(tela.estado);
    const enviadoPerfMs = performance.now();
    const resposta = await buscarEstado(pagina.prova, versao, tela.selecao?.tipo === 'alerta' ? tela.selecao.id : null);
    // Every answer says what time it is on the server. Paired with this
    // computer's monotonic clock, that is the official clock of the screen.
    relogioOficial.registrar({ servidorAgoraMs: resposta.servidor_agora_ms, enviadoPerfMs, recebidoPerfMs: performance.now() });

    if (tela.ocupado) {
      // A decision is on its way and its own answer brings the state as it
      // is AFTER the decision. This answer was made before that and would
      // show the flag as still open; it is left unused.
    } else if (!resposta.inalterado) {
      receberEstado(resposta, numero);
    } else if (ordem.aceitar(numero) && tela.estado && resposta.versao === tela.estado.versao) {
      tela.estado = aplicarResposta(tela.estado, resposta, pagina.usuario);
    }
    tela.falhas = 0;
    tela.sessaoExpirada = false;
    desenharTudo();

    if (janelaDesatualizada() && tela.selecao?.tipo === 'alerta'
      && !estaDigitandoEm(pagina.detalhe) && tela.entradaSelecionadaId === null) {
      carregarJanela();
    }
  } catch (erro) {
    tela.falhas += 1;
    tela.sessaoExpirada = erro instanceof SessaoExpirada;
    desenharTudo();
  }
  consultando = false;
  const aviso = avisoDeConexao(tela.falhas, tela.sessaoExpirada);
  pagina.avisoConexao.textContent = aviso ?? '';
  pagina.avisoConexao.className = aviso ? 'revisao-aviso erro' : 'revisao-aviso oculto';
  proximaConsulta = setTimeout(consultar, proximoAtrasoMs(tela.falhas));
}

// After a decision whose outcome is unknown: fetch the whole state now
// instead of waiting for the next poll.
function atualizarAgora() {
  if (consultando) {
    // A poll is on its way; ask again as soon as it is back.
    proximaConsulta = setTimeout(() => consultar(true), 300);
    return;
  }
  consultar(true);
}

// ---- wiring ----------------------------------------------------------------------

pagina.abas.addEventListener('click', (evento) => {
  const aba = evento.target.closest('button')?.dataset.aba;
  if (aba) {
    mostrarAba(aba);
  }
});

// A / K / V act on the selected flag. Ignored while typing in a field, so a
// car number or a time can contain anything.
document.addEventListener('keydown', (evento) => {
  if (evento.ctrlKey || evento.metaKey || evento.altKey || typeof evento.key !== 'string') {
    return;
  }
  if (['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement?.tagName)) {
    return;
  }
  // K does nothing on a flag that has no K button (a repeat): confirmarAlerta checks.
  const atalhos = { a: acoes.aceitar, k: acoes.confirmarAlerta, v: acoes.anular };
  const acao = atalhos[evento.key.toLowerCase()];
  const alerta = alertaSelecionado();
  // The actions themselves refuse while the timeline is missing or stale.
  if (acao && alerta && !alerta.auto_resolvido && !tela.ocupado) {
    evento.preventDefault();
    acao();
  }
});

// The clock and the "há 12 s" ages keep moving between two answers.
setInterval(() => {
  // The system clock is read here and only here, and only to notice that
  // the monotonic clock stalled (a laptop that slept). It never tells the time.
  relogioOficial.vigiar(performance.now(), Date.now());
  if (tela.estado) {
    desenharBarra(pagina.barra, tela.estado, agoraServidorMs(), horarioOficialMs());
  }
}, 1000);

desenharBarraSemEstado(pagina.barra);

consultar();
