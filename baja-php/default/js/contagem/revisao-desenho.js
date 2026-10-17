// Review screen: what is drawn when. Decides which parts of the page need
// redrawing after each answer from the server, and hands each part to the
// module that draws it.

import { desenharAvisoDosResultados, desenharBarra, desenharFila } from './revisao-barra.js';
import { chaveAoVivo, chaveDoDetalhe as chaveDoPainel } from './revisao-conexao.js';
import { podeDecidirSobreAlerta } from './revisao-decisoes.js';
import { desenharDetalheDeAlerta, desenharDetalheDeCarro, desenharDetalheDeEntrada } from './revisao-detalhe.js';
import { el, estaDigitandoEm, substituir } from './revisao-dom.js';
import { avisoDeReconciliacao } from './revisao-frases.js';
import { encontrarAlerta } from './revisao-fila.js';
import { encontrarEntrada } from './revisao-raias-logica.js';
import { desenharFontes, desenharHistorico, desenharTabelaDeCarros } from './revisao-tabela.js';
import {
  agoraServidorMs, escolherOrdemDosCarros, horarioOficialMs, pagina, semConexao, tela,
} from './revisao-tela.js';

export const alertaSelecionado = () => (tela.selecao?.tipo === 'alerta' ? encontrarAlerta(tela.estado, tela.selecao.id) : null);

// True when the loaded timeline no longer matches the state.
//
// For a flag that means its entries changed (its signature is different):
// the timeline is then reloaded on its own. For a car's whole race it means
// anything at all changed; during a race that is every few seconds, and
// reloading a whole race that often would be wasteful, so the timeline
// offers a button. Pieces of time follow the state on their own
// (revisao-linha.js) and are never behind.
export function janelaDesatualizada() {
  if (tela.janela === null || !tela.selecao || !tela.estado) {
    return false;
  }
  if (tela.selecao.tipo === 'carro') {
    return tela.janelaVersao !== null && tela.janelaVersao !== tela.estado.versao;
  }
  const alerta = alertaSelecionado();
  return alerta !== null && alerta.assinatura !== tela.janelaAssinatura;
}

// The <datalist> behind every "número do carro" field.
export function atualizarListaDeCarros() {
  let lista = document.getElementById('carros-validos');
  if (!lista) {
    lista = el('datalist', { id: 'carros-validos' });
    document.body.appendChild(lista);
  }
  substituir(lista, (tela.estado.carros_validos ?? []).map((carro) => el('option', { value: carro.numero }, carro.equipe ?? '')));
}

// `ganchos` is what a click on the drawn page calls back into revisao.js:
//   acoes                      the decision actions (see revisao-acoes.js)
//   acoesDoDetalhe             the actions of the detail panel
//   escolherAlerta(id), escolherCarro(numero, momento)
//   abrirDoHistorico(alvo)     a line of the history: its flag, or its car at its moment
//   desenharLinha()            the timeline at the top (revisao-linha.js)
// Returns {desenharTudo, desenharDetalhe}.
export function criarDesenho(ganchos) {
  // The poll calls desenharTudo() every two seconds. Redrawing parts that did
  // not change would reset scroll positions and could swallow a click that
  // lands while a button is being replaced, so each part is redrawn only when
  // the things it shows are different. These two functions list those things.
  function chaveDasListas() {
    // chaveAoVivo: an answer with the same version can still bring a source
    // that went offline or a car that is now "parado".
    return JSON.stringify([
      tela.estado.versao, chaveAoVivo(tela.estado), tela.selecao, tela.ocupado, semConexao(),
      tela.ordemDosCarros,
    ]);
  }

  function chaveDoDetalhe() {
    const carro = tela.selecao?.tipo === 'carro'
      ? (tela.estado.carros ?? []).find((linha) => linha.carro === tela.selecao.numero) ?? null
      : null;
    // Not the state's version: see chaveDoDetalhe() in revisao-conexao.js.
    // The chip the reviewer clicked is drawn from the timeline, which
    // refreshes on its own: its box follows what the chip says.
    const entrada = tela.entradaSelecionadaId === null ? null : encontrarEntrada(tela.janela, tela.entradaSelecionadaId);
    return JSON.stringify(entrada) + chaveDoPainel({
      selecao: tela.selecao,
      alerta: alertaSelecionado(),
      carro,
      janelaCarregada: tela.janela !== null,
      janelaAssinatura: tela.janelaAssinatura,
      desatualizada: janelaDesatualizada(),
      erroDaJanela: tela.erroDaJanela,
      entradaSelecionadaId: tela.entradaSelecionadaId,
      modoManual: tela.modoManual,
      ocupado: tela.ocupado,
      semConexao: semConexao(),
      largadaMs: tela.estado.corrida?.largada_ms ?? null,
    });
  }

  const desenhado = { listas: null, fontes: null, historico: null, detalhe: null };

  function desenharDetalhe() {
    if (!tela.estado) {
      return;
    }
    const contexto = {
      estado: tela.estado,
      janela: tela.janela,
      erroDaJanela: tela.erroDaJanela,
      desatualizado: janelaDesatualizada(),
      podeDecidir: podeDecidirSobreAlerta({ janelaCarregada: tela.janela !== null, desatualizada: janelaDesatualizada() }),
      entradaSelecionadaId: tela.entradaSelecionadaId,
      modoManual: tela.modoManual,
      motivo: tela.motivo,
      ocupado: tela.ocupado || semConexao(),
      acoes: ganchos.acoesDoDetalhe,
    };
    const alerta = alertaSelecionado();
    const carro = tela.selecao?.tipo === 'carro'
      ? (tela.estado.carros ?? []).find((linha) => linha.carro === tela.selecao.numero) ?? null
      : null;

    if (alerta) {
      desenharDetalheDeAlerta(pagina.detalhe, { ...contexto, alerta });
    } else if (carro) {
      // Opened from a line of the history, the timeline above goes to that
      // line's moment (revisao-linha.js); the panel itself is the same.
      desenharDetalheDeCarro(pagina.detalhe, { ...contexto, carro });
    } else if (tela.selecao?.tipo === 'alerta') {
      // Somebody (or a late upload) made the flag go away while it was open.
      tela.selecao = null;
      tela.janela = null;
      substituir(pagina.detalhe, el('p', { class: 'vazio' }, 'Este alerta não existe mais: foi resolvido. Escolha outro na fila.'));
    } else if (encontrarEntrada(tela.janela, tela.entradaSelecionadaId)) {
      // A chip clicked on the live timeline, with nothing else open.
      desenharDetalheDeEntrada(pagina.detalhe, contexto);
    } else {
      substituir(pagina.detalhe, el('p', { class: 'vazio' }, 'Escolha um alerta na fila, um carro na tabela ou um registro na linha do tempo.'));
    }
    desenhado.detalhe = chaveDoDetalhe();
  }

  function desenharTudo() {
    if (!tela.estado) {
      return;
    }
    // First: what the timeline loaded decides whether the flag's buttons are on.
    ganchos.desenharLinha();
    desenharBarra(pagina.barra, tela.estado, agoraServidorMs(), horarioOficialMs());
    // Permanent while the prova accepts simulated entries.
    pagina.faixaSimulacao?.classList.toggle('oculto', !tela.estado.simulacao);
    // Permanent while the test preset is applied: the time settings are a rehearsal's.
    pagina.faixaPredefinicao?.classList.toggle('oculto', !tela.estado.predefinicao_de_teste);
    // The fast count and the full count disagreed, and the full one won.
    const reconciliacao = avisoDeReconciliacao(tela.estado.correcoes_da_reconciliacao, agoraServidorMs());
    if (pagina.avisoReconciliacao) {
      pagina.avisoReconciliacao.textContent = reconciliacao ?? '';
      pagina.avisoReconciliacao.className = reconciliacao ? 'revisao-aviso erro' : 'revisao-aviso oculto';
    }
    // Publishing off, points that look wrong: the bar has a short pill, this says it all.
    desenharAvisoDosResultados(pagina.avisoPontuacao, tela.estado);

    const chave = chaveDasListas();
    if (chave !== desenhado.listas) {
      desenharFila(pagina.fila, tela.estado, tela.selecao?.tipo === 'alerta' ? tela.selecao.id : null, ganchos.escolherAlerta);
      desenharTabelaDeCarros(pagina.carros, tela.estado, tela.selecao?.tipo === 'carro' ? tela.selecao.numero : null, ganchos.escolherCarro,
        tela.ordemDosCarros, (coluna) => { escolherOrdemDosCarros(coluna); desenharTudo(); });
      desenhado.listas = chave;
    }
    // The history has its own key: changing a filter redraws it and nothing else.
    const chaveDoHistorico = JSON.stringify([chave, tela.filtrosDoHistorico]);
    if (chaveDoHistorico !== desenhado.historico) {
      desenharHistorico(pagina.historico, tela.estado, tela.filtrosDoHistorico, tela.ocupado || semConexao(), {
        desfazer: ganchos.acoes.desfazer, abrir: ganchos.abrirDoHistorico,
      });
      desenhado.historico = chaveDoHistorico;
    }
    // The source blocks have their own key: redrawing a form somebody is
    // typing in would erase what they typed, so that redraw waits, and it
    // must then happen as soon as the cursor leaves, not at the next change.
    if (chave !== desenhado.fontes && !estaDigitandoEm(pagina.fontes)) {
      desenharFontes(pagina.fontes, tela.estado, tela.ocupado || semConexao(), {
        excluirFonte: ganchos.acoes.excluirFonte, encerrarExclusao: ganchos.acoes.encerrarExclusao, desfazer: ganchos.acoes.desfazer,
      });
      desenhado.fontes = chave;
    }
    if (!estaDigitandoEm(pagina.detalhe) && chaveDoDetalhe() !== desenhado.detalhe) {
      desenharDetalhe();
    }
  }

  return { desenharTudo, desenharDetalhe };
}
