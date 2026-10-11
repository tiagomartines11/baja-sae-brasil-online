// What each decision button does: build the request, send it, show what
// the server answered. The requests themselves are built by the pure
// functions of revisao-decisoes.js.

import { SessaoExpirada, enviarDecisao } from './revisao-api.js';
import {
  alvoDaAnulacao, decisaoAceitar, decisaoAdicionar, decisaoAnular, decisaoConfirmar, decisaoDesfazer,
  decisaoExcluirFonte, decisaoManterAmbas, decisaoReatribuir, mensagemDeConflito,
  pedidoParaAlerta, pedidoParaEntrada, pedidoSemAlvo,
} from './revisao-decisoes.js';
import { encontrarAlerta } from './revisao-fila.js';
import { interpretarTempoDeProva } from './revisao-formato.js';
import { mostrarMensagem, pagina, semConexao, tela } from './revisao-tela.js';

// `ganchos` is how this module reaches back into revisao.js without the two
// files importing each other:
//   receberEstado(estado)  take a full state that came with an answer
//   desenharTudo()         redraw the screen
//   carregarJanela()       reload the timeline of the selection
export function criarAcoes(ganchos) {
  async function enviar(pedido) {
    if (tela.ocupado || !pedido) {
      return;
    }
    if (semConexao()) {
      mostrarMensagem('Sem conexão: não é possível decidir agora.', 'erro');
      return;
    }
    tela.ocupado = true;
    ganchos.desenharTudo();
    try {
      const { status, corpo } = await enviarDecisao(pagina.prova, pagina.csrf, pedido);
      if (status === 200) {
        ganchos.receberEstado(corpo.estado);
        mostrarMensagem('Decisão registrada.', 'sucesso');
      } else if (status === 409) {
        // Refused: somebody decided first or the entries changed. The
        // answer carries the fresh state, so the screen is right again.
        ganchos.receberEstado(corpo.estado);
        mostrarMensagem(mensagemDeConflito(corpo), 'erro');
      } else {
        mostrarMensagem(corpo.mensagem ?? 'A decisão foi recusada.', 'erro');
      }
    } catch (erro) {
      mostrarMensagem(erro instanceof SessaoExpirada
        ? 'Sua sessão expirou. Recarregue a página; a decisão NÃO foi registrada.'
        : 'Sem resposta do servidor. A decisão pode não ter sido registrada: confira o histórico antes de repetir.', 'erro');
    }
    tela.ocupado = false;
    tela.entradaSelecionadaId = null;
    ganchos.desenharTudo();
    ganchos.carregarJanela();
  }

  function alertaSelecionado() {
    return tela.selecao?.tipo === 'alerta' ? encontrarAlerta(tela.estado, tela.selecao.id) : null;
  }

  function decidirSobreAlerta(montarDecisao) {
    const alerta = alertaSelecionado();
    const decisao = alerta ? montarDecisao(alerta) : null;
    if (decisao) {
      enviar(pedidoParaAlerta(decisao, alerta, tela.estado));
    }
  }

  function anular() {
    const alerta = alertaSelecionado();
    if (!alerta) {
      return;
    }
    const alvo = alvoDaAnulacao(alerta, tela.entradaSelecionadaId);
    if (alvo === null) {
      mostrarMensagem('Clique primeiro no registro que deve ser anulado.', 'informacao');
      return;
    }
    enviar(pedidoParaAlerta(decisaoAnular(alvo), alerta, tela.estado));
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
    enviar(pedidoParaEntrada(decisoes[opcao.acao](), entrada, tela.estado));
  }

  // Reviewers type times as time of race; the server wants an absolute moment.
  function tempoDigitado(texto) {
    const desdeALargada = interpretarTempoDeProva(texto);
    if (desdeALargada === null) {
      mostrarMensagem('Tempo inválido. Use h:mm:ss, por exemplo 1:02:05.', 'erro');
      return null;
    }
    return tela.estado.corrida.largada_ms + desdeALargada;
  }

  function adicionar(carro, textoDoTempo) {
    const tsMs = tempoDigitado(textoDoTempo);
    if (tsMs !== null) {
      enviar(pedidoSemAlvo(decisaoAdicionar(carro, tsMs), tela.estado));
    }
  }

  function excluirFonte(fonte, textoDe, textoAte) {
    const de = tempoDigitado(textoDe);
    const ate = de === null ? null : tempoDigitado(textoAte);
    if (de === null || ate === null) {
      return;
    }
    if (window.confirm(`Ignorar todos os registros de "${fonte.nome}" neste período? Pode ser desfeito pelo histórico.`)) {
      enviar(pedidoSemAlvo(decisaoExcluirFonte(fonte.id, de, ate), tela.estado));
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
