// Review screen: entry module. Polls the server and keeps track of what is
// selected. The state lives in revisao-tela.js, the decision buttons in
// revisao-acoes.js, what is redrawn when in revisao-desenho.js, the timeline
// at the top in revisao-linha.js, the drawing itself in the other
// revisao-*.js modules, and the rules worth testing in the pure ones
// (revisao-fila.js, revisao-decisoes.js, revisao-conexao.js,
// revisao-raias-logica.js, revisao-linha-logica.js).

import { criarAcoes } from './revisao-acoes.js';
import { indiceDeUsarTempo } from './revisao-alertas.js';
import { candidatosDoAlerta, candidatosParaTrocar, indiceDeCandidato } from './revisao-ilegivel.js';
import { encontrarEntrada } from './revisao-raias-logica.js';
import { SessaoExpirada, buscarEstado } from './revisao-api.js';
import { desenharBarra, desenharBarraSemEstado } from './revisao-barra.js';
import {
  aplicarResposta, avisoDeConexao, criarOrdemDePedidos, estadoDaResposta, pedidoDeEstado, proximaConsultaEm,
} from './revisao-conexao.js';
import { alertaSelecionado, atualizarListaDeCarros, criarDesenho, janelaDesatualizada } from './revisao-desenho.js';
import { estaDigitandoEm } from './revisao-dom.js';
import { guardarFiltros, lerFiltrosGuardados, normalizarFiltros } from './revisao-historico.js';
import { criarLinhaDoTempo } from './revisao-linha.js';
import { ligarFiltrosDoHistorico } from './revisao-tabela.js';
import {
  agoraServidorMs, armazenamentoLocal, horarioOficialMs, mostrarAba, mostrarMensagem, pagina, relogioOficial, tela,
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
  moverRegistro: acoes.moverRegistro,
  usarTempo: acoes.usarTempo,
  // "Era outro carro…" on a repeat: opens the entry's own box, where the
  // field to move it to another car is.
  eraOutroCarro: (id) => { tela.entradaSelecionadaId = id; desenharDetalhe(); },
  anular: acoes.anular,
  acaoNaEntrada: acoes.acaoNaEntrada,
  atribuir: acoes.atribuir,
  anularEntrada: acoes.anularEntrada,
  // "Não é o carro 7…" on a "??" the engine gave a car: opens (or closes) the other cars.
  alternarTroca: (id) => { tela.trocandoAtribuicao = tela.trocandoAtribuicao === id ? null : id; desenharDetalhe(); },
  adicionar: acoes.adicionar,
  alternarManual: () => { tela.modoManual = !tela.modoManual; desenharDetalhe(); },
  anotarMotivo: (texto) => { tela.motivo = texto; },
  escolherEntrada,
  recarregar: carregarJanela,
  // The timeline stays, back to following the race.
  fechar: () => { fecharSelecao(); mostrarAba('fila'); },
};

// The permanent timeline at the top of the page.
const linha = criarLinhaDoTempo({
  desenharTudo: () => desenharTudo(),
  irParaAgora: fecharSelecao,
  escolherEntrada,
});

const { desenharTudo, desenharDetalhe } = criarDesenho({
  acoes,
  acoesDoDetalhe,
  escolherAlerta,
  escolherCarro,
  abrirDoHistorico,
  desenharLinha: linha.atualizar,
});

// Takes a full state. `numero` is the number of the request it answers;
// a state that answers an older request than one already applied is dropped.
// Returns whether it was applied.
function receberEstado(estado, numero) {
  if (!ordem.aceitar(numero)) {
    return false;
  }
  tela.estado = estado;
  atualizarListaDeCarros();
  return true;
}

// ---- the history's filters -------------------------------------------------------

// Remembered per prova in this browser, so a reload keeps them.
tela.filtrosDoHistorico = lerFiltrosGuardados(armazenamentoLocal(), pagina.prova);

ligarFiltrosDoHistorico(pagina.historico.formulario, (filtros) => {
  tela.filtrosDoHistorico = normalizarFiltros(filtros);
  guardarFiltros(armazenamentoLocal(), pagina.prova, tela.filtrosDoHistorico);
  desenharTudo();
});

// ---- selection and its timeline ------------------------------------------

// Loads again what the timeline shows (after a decision, or when the open
// flag's entries changed). What it loads, and when the result counts as
// loaded for the buttons, is up to revisao-linha.js.
function carregarJanela() {
  linha.recarregar();
}

// The timeline is not dropped: it shows the new selection as soon as
// desenharTudo() runs, from what it already has where it can.
function selecionar(selecao) {
  tela.selecao = selecao;
  tela.janela = null;
  tela.erroDaJanela = false;
  tela.entradaSelecionadaId = null;
  tela.modoManual = false;
  tela.trocandoAtribuicao = null;
  tela.motivo = '';
  mostrarMensagem(null);
  linha.novaSelecao();
  desenharTudo();
  mostrarAba('alerta');
}

function fecharSelecao() {
  tela.selecao = null;
  tela.janela = null;
  tela.entradaSelecionadaId = null;
  tela.modoManual = false;
  tela.trocandoAtribuicao = null;
  desenharTudo();
}

// A chip of the timeline: clicked again, it is let go. On a phone its box
// is on the Detalhe tab.
function escolherEntrada(id) {
  tela.entradaSelecionadaId = tela.entradaSelecionadaId === id ? null : id;
  desenharTudo();
  if (tela.entradaSelecionadaId !== null) {
    mostrarAba('alerta');
  }
}

function escolherAlerta(id) {
  selecionar({ tipo: 'alerta', id });
}

// `momento` (optional): {momentoMs, passagemIds} when the car is opened
// from a line of the history: the timeline goes to that moment and outlines
// those entries (modoDaLinha / destaqueDaLinha in revisao-linha-logica.js).
function escolherCarro(numero, momento = null) {
  selecionar(momento
    ? { tipo: 'carro', numero, momentoMs: momento.momentoMs ?? null, idsEmDestaque: momento.passagemIds ?? [] }
    : { tipo: 'carro', numero });
}

// A line of the history: the flag it is about, or the car at its moment.
// The history is far below the timeline, which is brought into view.
function abrirDoHistorico(alvo) {
  if (alvo.tipo === 'alerta') {
    escolherAlerta(alvo.id);
  } else {
    escolherCarro(alvo.numero, alvo);
  }
  mostrarLinhaDoTempo();
}

// Scrolls the page up to the timeline when its top is not in sight (under
// the sticky status bar, or below the fold).
function mostrarLinhaDoTempo() {
  const zona = pagina.zonas.querySelector('.zona-linha');
  if (!zona) {
    return;
  }
  const tampado = Math.max(pagina.barra?.getBoundingClientRect().bottom ?? 0, pagina.abas.getBoundingClientRect().bottom);
  const topo = zona.getBoundingClientRect().top;
  if (topo < tampado || topo > window.innerHeight * 0.6) {
    window.scrollBy({ top: topo - tampado - 6, behavior: 'smooth' });
  }
}

// ---- polling -------------------------------------------------------------------

let proximaConsulta = null;
let consultando = false;

// One poll. `pedirTudo` asks for the whole state, history included, even if
// the version is the one the screen has.
async function consultar(pedirTudo = false) {
  clearTimeout(proximaConsulta);
  if (consultando) {
    return;
  }
  consultando = true;
  const numero = ordem.novo();
  // A history difference that did not fit: ask again for everything, now.
  let pedirTudoJa = false;
  try {
    const { versao, historico } = pedidoDeEstado(tela.estado, pedirTudo);
    const enviadoPerfMs = performance.now();
    const resposta = await buscarEstado(pagina.prova, versao, tela.selecao?.tipo === 'alerta' ? tela.selecao.id : null, historico);
    // Every answer says what time it is on the server. Paired with this
    // computer's monotonic clock, that is the official clock of the screen.
    relogioOficial.registrar({ servidorAgoraMs: resposta.servidor_agora_ms, enviadoPerfMs, recebidoPerfMs: performance.now() });

    if (tela.ocupado) {
      // A decision is on its way and its own answer brings the state as it
      // is AFTER the decision. This answer was made before that and would
      // show the flag as still open; it is left unused.
    } else if (!resposta.inalterado) {
      // Only the decisions after the newest one the screen holds, when it
      // asked for that; see estadoDaResposta.
      const { estado, pedirTudo: semEncaixe } = estadoDaResposta(tela.estado, resposta);
      if (estado) {
        receberEstado(estado, numero);
      }
      pedirTudoJa = semEncaixe;
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
  const proxima = proximaConsultaEm(pedirTudoJa, tela.falhas);
  proximaConsulta = setTimeout(() => consultar(proxima.pedirTudo), proxima.atrasoMs);
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
  // 1, 2, 3 are the "Usar o tempo de …" buttons, and only where the flag
  // has that button: elsewhere the key is left alone. On a "??" 1 to 6
  // are its candidates, and on an assignment the engine made, once
  // "Não é o carro N…" is open, the other cars.
  const alerta = alertaSelecionado();
  const candidato = alerta ? candidatoDaTecla(evento.key, alerta) : null;
  if (candidato && !tela.ocupado) {
    evento.preventDefault();
    acoes.atribuir(alerta.passagem_ids[0], candidato.carro);
    return;
  }
  const atalhos = { a: acoes.aceitar, k: acoes.confirmarAlerta, v: acoes.anular };
  const indice = alerta ? indiceDeUsarTempo(evento.key, alerta, tela.estado) : null;
  const acao = indice !== null ? () => acoes.usarTempo(indice) : atalhos[evento.key.toLowerCase()];
  // The actions themselves refuse while the timeline is missing or stale.
  if (acao && alerta && !alerta.auto_resolvido && !tela.ocupado) {
    evento.preventDefault();
    acao();
  }
});

// The candidate a key 1 to 6 presses on the selected flag, or null.
function candidatoDaTecla(tecla, alerta) {
  let candidatos = [];
  if (alerta.tipo === 'numero_ilegivel') {
    candidatos = candidatosDoAlerta(alerta, tela.janela, tela.estado);
  } else if (alerta.tipo === 'numero_atribuido' && tela.trocandoAtribuicao === alerta.passagem_ids?.[0]) {
    const entrada = encontrarEntrada(tela.janela, tela.trocandoAtribuicao);
    candidatos = entrada ? candidatosParaTrocar(entrada, tela.janela, tela.estado) : [];
  }
  const indice = indiceDeCandidato(tecla, candidatos);
  return indice === null ? null : candidatos[indice];
}

// The clock and the "há 12 s" ages keep moving between two answers.
setInterval(() => {
  // The system clock is read here and only here, and only to notice that
  // the monotonic clock stalled (a laptop that slept). It never tells the time.
  relogioOficial.vigiar(performance.now(), Date.now());
  if (tela.estado) {
    desenharBarra(pagina.barra, tela.estado, agoraServidorMs(), horarioOficialMs());
    // The live timeline follows the race.
    linha.aCadaSegundo();
  }
}, 1000);

desenharBarraSemEstado(pagina.barra);

consultar();
