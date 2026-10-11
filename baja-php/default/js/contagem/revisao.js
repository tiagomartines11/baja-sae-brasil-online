// Review screen: entry module. Polls the server, keeps track of what is
// selected and redraws. The state lives in revisao-tela.js, the decision
// buttons in revisao-acoes.js, the drawing in the other revisao-*.js
// modules, and the rules worth testing in the pure ones (revisao-fila.js,
// revisao-decisoes.js, revisao-conexao.js, revisao-raias-logica.js).

import { criarAcoes } from './revisao-acoes.js';
import { SessaoExpirada, buscarCarro, buscarEstado, buscarJanela } from './revisao-api.js';
import { desenharBarra, desenharFila } from './revisao-barra.js';
import { aplicarResposta, avisoDeConexao, desvioDoServidorMs, proximoAtrasoMs, versaoParaPedir } from './revisao-conexao.js';
import { desenharDetalheDeAlerta, desenharDetalheDeCarro } from './revisao-detalhe.js';
import { el, estaDigitandoEm, substituir } from './revisao-dom.js';
import { encontrarAlerta, janelaDoAlerta } from './revisao-fila.js';
import { desenharFontes, desenharHistorico, desenharTabelaDeCarros } from './revisao-tabela.js';
import { agoraServidorMs, mostrarAba, mostrarMensagem, pagina, semConexao, tela } from './revisao-tela.js';

const acoes = criarAcoes({ receberEstado, desenharTudo, carregarJanela });

const acoesDoDetalhe = {
  aceitar: acoes.aceitar,
  manterAmbas: acoes.manterAmbas,
  anular: acoes.anular,
  acaoNaEntrada: acoes.acaoNaEntrada,
  adicionar: acoes.adicionar,
  alternarManual: () => { tela.modoManual = !tela.modoManual; desenharDetalhe(); },
  escolherEntrada: (id) => { tela.entradaSelecionadaId = tela.entradaSelecionadaId === id ? null : id; desenharDetalhe(); },
  recarregar: carregarJanela,
  fechar: () => { tela.selecao = null; tela.janela = null; desenharTudo(); mostrarAba('fila'); },
};

// ---- drawing ---------------------------------------------------------------

const alertaSelecionado = () => (tela.selecao?.tipo === 'alerta' ? encontrarAlerta(tela.estado, tela.selecao.id) : null);

// True when the loaded timeline no longer matches the state.
//
// For a flag that means its entries changed (its signature is different):
// the timeline is then reloaded on its own. For a car it means anything at
// all changed; during a race that is every few seconds, and reloading a
// whole race that often would be wasteful, so the panel offers a button.
function janelaDesatualizada() {
  if (tela.janela === null || !tela.selecao || !tela.estado) {
    return false;
  }
  if (tela.selecao.tipo === 'carro') {
    return tela.janelaVersao !== tela.estado.versao;
  }
  const alerta = alertaSelecionado();
  return alerta !== null && alerta.assinatura !== tela.janelaAssinatura;
}

// The poll calls desenharTudo() every two seconds. Redrawing parts that did
// not change would reset scroll positions and could swallow a click that
// lands while a button is being replaced, so each part is redrawn only when
// the things it shows are different. These two functions list those things.
function chaveDasListas() {
  const olhando = (tela.estado.alertas_abertos ?? []).map((alerta) => alerta.olhando ?? []);
  // estadosRecebidos and not the version: a full state with the same
  // version can still bring a source that went offline or a car now "parado".
  return JSON.stringify([tela.estadosRecebidos, tela.selecao, tela.ocupado, semConexao(), olhando]);
}

function chaveDoDetalhe() {
  return JSON.stringify([
    tela.selecao, tela.estado.versao, tela.janelaVersao, tela.janela !== null, tela.erroDaJanela,
    tela.entradaSelecionadaId, tela.modoManual, tela.ocupado, semConexao(), alertaSelecionado()?.olhando ?? null,
  ]);
}

const desenhado = { listas: null, detalhe: null, selecao: null };

function desenharDetalhe() {
  if (!tela.estado) {
    return;
  }
  // Keep the timeline where the reviewer scrolled it, unless the panel is
  // about to show something else.
  const raiasAntes = pagina.detalhe.querySelector('.raias');
  const rolagemAnterior = raiasAntes && desenhado.selecao === tela.selecao ? raiasAntes.scrollLeft : null;
  if (rolagemAnterior !== null) {
    // Two frames: the timeline's own "centre on the flag" runs in the first.
    requestAnimationFrame(() => requestAnimationFrame(() => {
      const raiasDepois = pagina.detalhe.querySelector('.raias');
      if (raiasDepois) {
        raiasDepois.scrollLeft = rolagemAnterior;
      }
    }));
  }

  const contexto = {
    estado: tela.estado,
    janela: tela.janela,
    erroDaJanela: tela.erroDaJanela,
    desatualizado: janelaDesatualizada(),
    entradaSelecionadaId: tela.entradaSelecionadaId,
    modoManual: tela.modoManual,
    ocupado: tela.ocupado || semConexao(),
    acoes: acoesDoDetalhe,
  };
  const alerta = alertaSelecionado();
  const carro = tela.selecao?.tipo === 'carro'
    ? (tela.estado.carros ?? []).find((linha) => linha.carro === tela.selecao.numero) ?? null
    : null;

  if (alerta) {
    desenharDetalheDeAlerta(pagina.detalhe, { ...contexto, alerta });
  } else if (carro) {
    desenharDetalheDeCarro(pagina.detalhe, { ...contexto, carro });
  } else if (tela.selecao?.tipo === 'alerta') {
    // Somebody (or a late upload) made the flag go away while it was open.
    tela.selecao = null;
    tela.janela = null;
    substituir(pagina.detalhe, el('p', { class: 'vazio' }, 'Este alerta não existe mais: foi resolvido. Escolha outro na fila.'));
  } else {
    substituir(pagina.detalhe, el('p', { class: 'vazio' }, 'Escolha um alerta na fila ou um carro na tabela.'));
  }
  desenhado.selecao = tela.selecao;
  desenhado.detalhe = chaveDoDetalhe();
}

function desenharTudo() {
  if (!tela.estado) {
    return;
  }
  desenharBarra(pagina.barra, tela.estado, agoraServidorMs());

  const chave = chaveDasListas();
  if (chave !== desenhado.listas) {
    const desligado = tela.ocupado || semConexao();
    desenharFila(pagina.fila, tela.estado, tela.selecao?.tipo === 'alerta' ? tela.selecao.id : null, escolherAlerta);
    desenharTabelaDeCarros(pagina.carros, tela.estado, tela.selecao?.tipo === 'carro' ? tela.selecao.numero : null, escolherCarro);
    desenharHistorico(pagina.historico, tela.estado, desligado, acoes.desfazer);
    // Redrawing a form somebody is typing in would erase what they typed.
    if (!estaDigitandoEm(pagina.fontes)) {
      desenharFontes(pagina.fontes, tela.estado, desligado, acoes.excluirFonte);
    }
    desenhado.listas = chave;
  }
  if (!estaDigitandoEm(pagina.detalhe) && chaveDoDetalhe() !== desenhado.detalhe) {
    desenharDetalhe();
  }
}

// The <datalist> behind every "número do carro" field.
function atualizarListaDeCarros() {
  let lista = document.getElementById('carros-validos');
  if (!lista) {
    lista = el('datalist', { id: 'carros-validos' });
    document.body.appendChild(lista);
  }
  substituir(lista, (tela.estado.carros_validos ?? []).map((carro) => el('option', { value: carro.numero }, carro.equipe ?? '')));
}

function receberEstado(estado) {
  tela.estado = estado;
  tela.estadosRecebidos += 1;
  tela.desvioMs = desvioDoServidorMs(estado.servidor_agora_ms, Date.now());
  tela.pollsDesdeCompleto = 0;
  atualizarListaDeCarros();
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

async function consultar() {
  try {
    const versao = versaoParaPedir(tela.estado, tela.pollsDesdeCompleto);
    const resposta = await buscarEstado(pagina.prova, versao, tela.selecao?.tipo === 'alerta' ? tela.selecao.id : null);

    if (resposta.inalterado) {
      tela.estado = aplicarResposta(tela.estado, resposta, pagina.usuario);
      tela.pollsDesdeCompleto += 1;
    } else if (!tela.ocupado && !(tela.estado && resposta.versao < tela.estado.versao)) {
      // Skipped while a decision is on its way (its own answer brings the
      // state) and when this answer is older than what a decision already
      // brought: a slow poll must not put the screen back in the past.
      receberEstado(resposta);
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
  const aviso = avisoDeConexao(tela.falhas, tela.sessaoExpirada);
  pagina.avisoConexao.textContent = aviso ?? '';
  pagina.avisoConexao.className = aviso ? 'revisao-aviso erro' : 'revisao-aviso oculto';
  setTimeout(consultar, proximoAtrasoMs(tela.falhas));
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
  const atalhos = { a: acoes.aceitar, k: acoes.manterAmbas, v: acoes.anular };
  const acao = atalhos[evento.key.toLowerCase()];
  const alerta = alertaSelecionado();
  if (acao && alerta && !alerta.auto_resolvido) {
    evento.preventDefault();
    acao();
  }
});

// The clock and the "há 12 s" ages keep moving between two answers.
setInterval(() => {
  if (tela.estado) {
    desenharBarra(pagina.barra, tela.estado, agoraServidorMs());
  }
}, 1000);

consultar();
