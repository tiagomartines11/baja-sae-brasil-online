// The review screen's own state and the elements it draws into. Shared by
// revisao.js (polling and drawing) and revisao-acoes.js (decisions).

import { criarRelogioOficial } from './revisao-relogio.js';

export const pagina = {
  prova: document.body.dataset.prova,
  csrf: document.body.dataset.csrf,
  usuario: document.body.dataset.usuario,
  barra: document.getElementById('barra-estado'),
  faixaSimulacao: document.getElementById('faixa-simulacao'),
  faixaPredefinicao: document.getElementById('faixa-predefinicao'),
  avisoReconciliacao: document.getElementById('aviso-reconciliacao'),
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
  historico: document.getElementById('tabela-historico'),
  historicoMais: document.getElementById('historico-mais'),
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
  historicoAntigo: [],     // older pages of the history, loaded on request
  historicoHaMais: false,  // whether an even older page exists
  historicoCarregando: false,
  ocupado: false,          // a decision is on its way; buttons are disabled
};

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
