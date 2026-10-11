// What each decision button does: build the request, send it, show what
// the server answered. The requests themselves are built by the pure
// functions of revisao-decisoes.js.

import { SessaoExpirada, enviarDecisao } from './revisao-api.js';
import {
  alvoDaAnulacao, comMotivo, decisaoAceitar, decisaoAdicionar, decisaoAnular, decisaoConfirmar, decisaoDesfazer,
  decisaoExcluirFonte, decisaoManterAmbas, decisaoReatribuir, mensagemDeConflito,
  pedidoParaAlerta, pedidoParaEntrada, pedidoSemAlvo, podeDecidirSobreAlerta, problemaAoAdicionar,
} from './revisao-decisoes.js';
import { encontrarAlerta } from './revisao-fila.js';
import { interpretarTempoDeProva } from './revisao-formato.js';
import { encontrarEntrada } from './revisao-raias-logica.js';
import { agoraServidorMs, mostrarMensagem, pagina, semConexao, tela } from './revisao-tela.js';

const DESFECHO_DESCONHECIDO = 'O servidor não respondeu como esperado. NÃO se sabe se a decisão foi registrada: '
  + 'a tela foi atualizada; confira o histórico antes de repetir.';

// `ganchos` is how this module reaches back into revisao.js without the two
// files importing each other:
//   novoPedido()                   the next number in the order of answers (see criarOrdemDePedidos)
//   receberEstado(estado, numero)  take a full state that came with an answer
//   desenharTudo()                 redraw the screen
//   carregarJanela()               reload the timeline of the selection
//   janelaDesatualizada()          whether the timeline on screen is behind the state
//   atualizarAgora()               ask the server for the whole state right away
export function criarAcoes(ganchos) {
  async function enviar(pedido) {
    if (tela.ocupado || !pedido) {
      return;
    }
    if (semConexao()) {
      mostrarMensagem('Sem conexão: não é possível decidir agora.', 'erro');
      return;
    }
    // What was typed is on its way. The screen does not redraw a form that
    // has the cursor in it (that would erase typing), so the cursor is
    // taken out: otherwise the answer could not be shown there, and the
    // "excluída até segunda ordem" banner would not appear after Enter.
    document.activeElement?.blur?.();
    tela.ocupado = true;
    ganchos.desenharTudo();
    let desfechoConhecido = true;
    try {
      const { status, corpo } = await enviarDecisao(pagina.prova, pagina.csrf, pedido);
      // Numbered when the answer ARRIVES, not when the request left: the
      // server worked this state out after the decision, so it is newer
      // than the answer to any poll that is still on its way.
      const numero = ganchos.novoPedido();
      if (status === 200) {
        ganchos.receberEstado(corpo.estado, numero);
        tela.motivo = '';
        mostrarMensagem('Decisão registrada.', 'sucesso');
      } else if (status === 409) {
        // Refused: somebody decided first or the entries changed. The
        // answer carries the fresh state, so the screen is right again.
        ganchos.receberEstado(corpo.estado, numero);
        mostrarMensagem(mensagemDeConflito(corpo), 'erro');
      } else if (status === 400 || status === 503) {
        // Both mean "nothing was stored" and say why.
        mostrarMensagem(corpo.mensagem ?? 'A decisão foi recusada.', 'erro');
      } else {
        desfechoConhecido = false;
        mostrarMensagem(corpo.mensagem ?? DESFECHO_DESCONHECIDO, 'erro');
      }
    } catch (erro) {
      if (erro instanceof SessaoExpirada) {
        // Refused before anything ran: the server checks the login first.
        mostrarMensagem('Sua sessão expirou. Recarregue a página; a decisão NÃO foi registrada.', 'erro');
      } else {
        // A timeout, a dropped connection or an error page. The request may
        // have reached the server and been stored all the same.
        desfechoConhecido = false;
        mostrarMensagem(DESFECHO_DESCONHECIDO, 'erro');
      }
    }
    tela.ocupado = false;
    tela.entradaSelecionadaId = null;
    ganchos.desenharTudo();
    if (desfechoConhecido) {
      ganchos.carregarJanela();
    } else {
      // Show what the server really has, so the reviewer can see whether
      // the decision is in the history.
      ganchos.atualizarAgora();
    }
  }

  function alertaSelecionado() {
    return tela.selecao?.tipo === 'alerta' ? encontrarAlerta(tela.estado, tela.selecao.id) : null;
  }

  // The A / K / V buttons act on the timeline the reviewer is looking at.
  // While that timeline is missing or behind the flag list, they do nothing
  // (the buttons are drawn disabled; this also covers the keyboard).
  function alertaQuePodeSerDecidido() {
    const alerta = alertaSelecionado();
    if (!alerta) {
      return null;
    }
    const pode = podeDecidirSobreAlerta({
      janelaCarregada: tela.janela !== null,
      desatualizada: ganchos.janelaDesatualizada(),
    });
    if (!pode) {
      mostrarMensagem('A linha do tempo está sendo atualizada. Confira de novo antes de decidir.', 'informacao');
      return null;
    }
    return alerta;
  }

  function decidirSobreAlerta(montarDecisao) {
    const alerta = alertaQuePodeSerDecidido();
    const decisao = alerta ? montarDecisao(alerta) : null;
    if (decisao) {
      enviar(pedidoParaAlerta(comMotivo(decisao, tela.motivo), alerta, tela.estado, tela.janelaAssinatura));
    }
  }

  function anular() {
    const alerta = alertaQuePodeSerDecidido();
    if (!alerta) {
      return;
    }
    const alvo = alvoDaAnulacao(alerta, tela.entradaSelecionadaId);
    if (alvo === null) {
      mostrarMensagem('Clique primeiro no registro que deve ser anulado.', 'informacao');
      return;
    }
    const decisao = comMotivo(decisaoAnular(alvo), tela.motivo);
    const pedidoDoAlerta = pedidoParaAlerta(decisao, alerta, tela.estado, tela.janelaAssinatura);
    if (pedidoDoAlerta) {
      enviar(pedidoDoAlerta);
      return;
    }
    // The chip clicked is on the timeline but is not one of the flag's
    // entries: it is voided as that one entry, with its own signature.
    const entrada = encontrarEntrada(tela.janela, alvo);
    if (entrada) {
      enviar(pedidoParaEntrada(decisao, entrada, tela.estado));
    }
  }

  // `opcao` is one of the items of acoesDaEntrada(); `carroNovo` is only
  // given for "reatribuir".
  function acaoNaEntrada(opcao, entrada, carroNovo) {
    const decisoes = {
      anular: () => decisaoAnular(entrada.id),
      confirmar: () => decisaoConfirmar(entrada.id),
      reatribuir: () => decisaoReatribuir(entrada.id, carroNovo),
      restaurar: () => decisaoDesfazer(opcao.decisaoId),
      remover: () => decisaoDesfazer(opcao.decisaoId),
      desfazer_troca: () => decisaoDesfazer(opcao.decisaoId),
    };
    enviar(pedidoParaEntrada(comMotivo(decisoes[opcao.acao](), tela.motivo), entrada, tela.estado));
  }

  // Reviewers type times as time of race; the server wants an absolute
  // moment. Null (with a message) when the text is not a time or the race
  // has no start to count from.
  function tempoDigitado(texto) {
    const largada = tela.estado.corrida.largada_ms;
    if (largada === null || largada === undefined) {
      mostrarMensagem('A corrida ainda não tem largada: não há tempo de prova.', 'erro');
      return null;
    }
    const desdeALargada = interpretarTempoDeProva(texto);
    if (desdeALargada === null) {
      mostrarMensagem('Tempo inválido. Use h:mm:ss, por exemplo 1:02:05.', 'erro');
      return null;
    }
    return largada + desdeALargada;
  }

  function adicionar(carro, textoDoTempo) {
    const corrida = tela.estado.corrida;
    const semLargada = corrida.largada_ms === null || corrida.largada_ms === undefined;
    const desdeALargada = semLargada ? null : interpretarTempoDeProva(textoDoTempo);
    const tsMs = desdeALargada === null ? null : corrida.largada_ms + desdeALargada;

    const problema = problemaAoAdicionar(tsMs, corrida, agoraServidorMs());
    if (problema) {
      mostrarMensagem(problema, 'erro');
      return;
    }
    enviar(pedidoSemAlvo(comMotivo(decisaoAdicionar(carro, tsMs), tela.motivo), tela.estado));
  }

  // `textoAte` empty means "até segunda ordem".
  function excluirFonte(fonte, textoDe, textoAte, motivo) {
    const de = tempoDigitado(textoDe);
    if (de === null) {
      return;
    }
    const semFim = String(textoAte ?? '').trim() === '';
    const ate = semFim ? null : tempoDigitado(textoAte);
    if (!semFim && ate === null) {
      return;
    }
    const pergunta = semFim
      ? `Ignorar todos os registros de "${fonte.nome}" a partir desse momento, ATÉ SEGUNDA ORDEM? `
        + 'A fonte continua excluída até alguém desfazer esta decisão.'
      : `Ignorar todos os registros de "${fonte.nome}" neste período? Pode ser desfeito pelo histórico.`;
    if (window.confirm(pergunta)) {
      enviar(pedidoSemAlvo(comMotivo(decisaoExcluirFonte(fonte.id, de, ate), motivo), tela.estado));
    }
  }

  function desfazer(decisao) {
    enviar(pedidoSemAlvo(decisaoDesfazer(decisao.id), tela.estado));
  }

  return {
    aceitar: () => decidirSobreAlerta(decisaoAceitar),
    manterAmbas: () => decidirSobreAlerta(decisaoManterAmbas),
    anular,
    acaoNaEntrada,
    adicionar,
    excluirFonte,
    desfazer,
  };
}
