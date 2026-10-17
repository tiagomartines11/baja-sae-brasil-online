// The review screen's own state and the elements it draws into. Shared by
// revisao.js (polling and drawing) and revisao-acoes.js (decisions).

import { guardarOrdem, lerOrdem, proximaOrdem } from './revisao-carros.js';
import { FILTROS_VAZIOS } from './revisao-historico.js';
import { criarRelogioOficial } from './revisao-relogio.js';

// Some privacy settings make the mere mention of localStorage throw. Null
// then: what is remembered (the car table's order, the history's filters)
// lasts until the page is reloaded.
export function armazenamentoLocal() {
  try {
    return window.localStorage;
  } catch (bloqueado) {
    return null;
  }
}

export const pagina = {
  prova: document.body.dataset.prova,
  csrf: document.body.dataset.csrf,
  usuario: document.body.dataset.usuario,
  barra: document.getElementById('barra-estado'),
  faixaSimulacao: document.getElementById('faixa-simulacao'),
  faixaPredefinicao: document.getElementById('faixa-predefinicao'),
  avisoReconciliacao: document.getElementById('aviso-reconciliacao'),
  avisoPontuacao: document.getElementById('aviso-pontuacao'),
  avisoConexao: document.getElementById('aviso-conexao'),
  avisoMensagem: document.getElementById('aviso-mensagem'),
  detalhe: document.getElementById('painel-detalhe'),
  zonas: document.querySelector('.revisao-zonas'),
  abas: document.getElementById('abas'),
  fila: {
    abertos: document.getElementById('fila-abertos'),
    resolvidos: document.getElementById('fila-resolvidos'),
    contagemAbertos: document.getElementById('contagem-alertas'),
    contagemResolvidos: document.getElementById('contagem-resolvidos'),
  },
  carros: document.getElementById('tabela-carros'),
  fontes: document.getElementById('estatisticas-fontes'),
  historico: {
    formulario: document.getElementById('historico-filtros'),
    resumo: document.getElementById('historico-resumo'),
    tabela: document.getElementById('tabela-historico'),
    aviso: document.getElementById('historico-aviso'),
  },
};

export const tela = {
  estado: null,            // last full state from the server, with later "inalterado" answers folded in
  falhas: 0,               // polls failed in a row
  sessaoExpirada: false,
  selecao: null,           // {tipo: 'alerta', id} or {tipo: 'carro', numero}
  janela: null,            // timeline loaded for the selection
  janelaVersao: null,      // state version the timeline was loaded at
  janelaAssinatura: null,  // signature the selected flag had when it was loaded
  erroDaJanela: false,
  entradaSelecionadaId: null,
  modoManual: false,
  motivo: '',              // optional reason typed for the next decision in the detail panel
  // The history's filters (see revisao-historico.js); revisao.js fills them
  // with the ones this browser remembers for the prova.
  filtrosDoHistorico: { ...FILTROS_VAZIOS },
  ocupado: false,          // a decision is on its way; buttons are disabled
  // order of the car table, {coluna, direcao} or null for the standings;
  // remembered on this computer (revisao-carros.js)
  ordemDosCarros: lerOrdem(armazenamentoLocal(), pagina.prova),
};

// A click on a header of the car table: the next order, remembered.
export function escolherOrdemDosCarros(coluna) {
  tela.ordemDosCarros = proximaOrdem(tela.ordemDosCarros, coluna);
  guardarOrdem(armazenamentoLocal(), pagina.prova, tela.ordemDosCarros);
}

// The official clock: the server's time, carried by this computer's
// monotonic clock. The system clock (Date.now()) is never used to tell the
// time here; see revisao-relogio.js.
export const relogioOficial = criarRelogioOficial();

// Null while the clock has no anchor (before the first answer, or after
// the monotonic clock stalled).
export const horarioOficialMs = () => relogioOficial.agora(performance.now());

// "Now" for everything that needs a number: the official clock, or, in the
// moments it has no anchor, the time the server stated in its last answer.
export const agoraServidorMs = () => horarioOficialMs() ?? tela.estado?.servidor_agora_ms ?? null;

// Decisions are online-only: while this is true every decision button is off.
export const semConexao = () => tela.falhas > 0 || tela.sessaoExpirada;

// The one-line message under the status bar. `classe`: informacao | sucesso | erro.
export function mostrarMensagem(texto, classe = 'informacao') {
  pagina.avisoMensagem.textContent = texto ?? '';
  pagina.avisoMensagem.className = texto ? `revisao-aviso ${classe}` : 'revisao-aviso oculto';
}

// On a phone the three zones are tabs; on a PC all three are visible and
// this only moves the highlight.
export function mostrarAba(nome) {
  pagina.zonas.dataset.abaAtiva = nome;
  for (const botao of pagina.abas.querySelectorAll('button')) {
    botao.classList.toggle('ativa', botao.dataset.aba === nome);
  }
}
