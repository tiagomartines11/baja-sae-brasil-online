// What each decision button does: build the request, send it, show what
// the server answered. The requests themselves are built by the pure
// functions of revisao-decisoes.js.

import { SessaoExpirada, enviarDecisao } from './revisao-api.js';
import {
  acaoDeAceitar, decisaoConfirmarAlerta, decisaoConfirmarSaida, decisaoMarcarSaida, perguntaAoConfirmar, perguntaAoDescartar, perguntaAoMarcarSaida,
  perguntaAoUsarTempo, temposParaEscolher,
} from './revisao-alertas.js';
import {
  alvoDaAnulacao, comMotivo, decisaoAceitar, decisaoAdicionar, decisaoAnular, decisaoConfirmar,
  decisaoDescartarPassagem, decisaoDesfazer, decisaoEncerrarExclusao, decisaoExcluirFonte, decisaoReatribuir,
  entradasDaDecisao, mensagemDeConflito, pedidoParaAlerta, pedidoParaEntrada, pedidoSemAlvo, pedidosDaSugestao,
  perguntaAoAgirNaEntrada, perguntaAoDesfazer, podeDecidirSobreAlerta, problemaAoAdicionar, problemaAoEncerrarExclusao,
} from './revisao-decisoes.js';
import { problemaAoMover } from './revisao-edicao-manual.js';
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

  // Sends a decision taken on the selected flag.
  //
  // Normally the reviewer was looking at the flag's entries and the request
  // says so. When the decision is about ONE entry that is on the timeline
  // but is not one of the flag's (the other pass of a short lap, a chip the
  // reviewer clicked), it is sent as a decision on that entry, with that
  // entry's own signature. Anything else cannot be checked by the server
  // and is not sent.
  function enviarSobreAlerta(decisao, alerta) {
    const comRazao = comMotivo(decisao, tela.motivo);
    const pedidoDoAlerta = pedidoParaAlerta(comRazao, alerta, tela.estado, tela.janelaAssinatura);
    if (pedidoDoAlerta) {
      enviar(pedidoDoAlerta);
      return;
    }
    const alvos = entradasDaDecisao(decisao);
    const entrada = alvos.length === 1 ? encontrarEntrada(tela.janela, alvos[0]) : null;
    if (entrada) {
      enviar(pedidoParaEntrada(comRazao, entrada, tela.estado));
      return;
    }
    mostrarMensagem('Esta ação envolve registros que não estão neste alerta. Use os registros da linha do tempo, um a um.', 'erro');
  }

  // A. Asks first whenever accepting gives a car a pass or takes one away.
  function aceitar() {
    const alerta = alertaQuePodeSerDecidido();
    const acao = alerta ? acaoDeAceitar(alerta, tela.estado) : null;
    const decisao = acao ? decisaoAceitar(alerta) : null;
    if (!decisao) {
      return;
    }
    if (acao.pergunta !== null && !window.confirm(acao.pergunta)) {
      return;
    }
    // The same builder the simulation runner uses (see pedidosDaSugestao).
    const pedidos = pedidosDaSugestao({ ...alerta, sugestao: comMotivo(decisao, tela.motivo) }, tela.estado, tela.janelaAssinatura);
    if (pedidos.length > 0) {
      enviar(pedidos[0]);
      return;
    }
    enviarSobreAlerta(decisao, alerta);
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
    enviarSobreAlerta(decisaoAnular(alvo), alerta);
  }

  // K. Asks first when it makes a pass typed before the start the car's
  // initial pass, and when it would give the car a lap too short to be real.
  function confirmarAlerta() {
    const alerta = alertaQuePodeSerDecidido();
    const decisao = alerta ? decisaoConfirmarAlerta(alerta) : null;
    if (!decisao) {
      return;
    }
    const pergunta = perguntaAoConfirmar(alerta, tela.estado);
    if (pergunta !== null && !window.confirm(pergunta)) {
      return;
    }
    enviarSobreAlerta(decisao, alerta);
  }

  // "Descartar ... passagem" on a flag: every entry of the pass chosen.
  // `passagem` is one item of passagensParaDescartar().
  function descartarPassagem(passagem) {
    const alerta = alertaQuePodeSerDecidido();
    if (!alerta || !passagem) {
      return;
    }
    if (window.confirm(perguntaAoDescartar(alerta, passagem))) {
      enviarSobreAlerta(decisaoDescartarPassagem(passagem.passagemId), alerta);
    }
  }

  // "Mover o registro … para o carro N" in the manual edit of a "troca
  // provável": the same decision as accepting an ordinary suggestion. Like
  // that one, and like "Trocar" on a chip, it is not asked about.
  function moverRegistro(mover, carroNovo) {
    const alerta = alertaQuePodeSerDecidido();
    if (!alerta) {
      return;
    }
    const problema = problemaAoMover(mover, carroNovo, tela.estado);
    if (problema) {
      mostrarMensagem(problema, 'erro');
      return;
    }
    enviarSobreAlerta(decisaoReatribuir(mover.passagemId, carroNovo), alerta);
  }

  // "Confirmar saída" on an exit the engine assumed: the exit is stored in
  // the reviewer's name. It changes no count (the passes after it were
  // counted already) and can be undone from the history, so nothing is asked.
  function confirmarSaida() {
    const alerta = alertaQuePodeSerDecidido();
    const decisao = alerta ? decisaoConfirmarSaida(alerta, tela.estado) : null;
    if (decisao) {
      enviarSobreAlerta(decisao, alerta);
    }
  }

  // "Marcar saída do box / do abastecimento": the car left and nobody
  // logged it. When that makes the held pass count (+1 volta) it is asked
  // about first, like everything else that changes a count.
  function marcarSaida() {
    const alerta = alertaQuePodeSerDecidido();
    const decisao = alerta ? decisaoMarcarSaida(alerta) : null;
    if (!decisao) {
      return;
    }
    const pergunta = perguntaAoMarcarSaida(alerta);
    if (pergunta !== null && !window.confirm(pergunta)) {
      return;
    }
    enviarSobreAlerta(decisao, alerta);
  }

  // "Usar o tempo de …" (or its key, 1, 2, 3) on the selected flag: the
  // pass counts once, timed by that entry. Asked first: it adds a pass.
  function usarTempo(indice) {
    const alerta = alertaQuePodeSerDecidido();
    const tempo = alerta ? temposParaEscolher(alerta, tela.estado)[indice] : null;
    if (!tempo) {
      return;
    }
    if (window.confirm(perguntaAoUsarTempo(alerta, tempo, tela.estado))) {
      enviarSobreAlerta(tempo.decisao, alerta);
    }
  }

  // `opcao` is one of the items of acoesDaEntrada(); `carroNovo` is only
  // given for "reatribuir". What is asked first is in perguntaAoAgirNaEntrada().
  function acaoNaEntrada(opcao, entrada, carroNovo) {
    const pergunta = perguntaAoAgirNaEntrada(opcao, entrada, tela.estado);
    if (pergunta !== null && !window.confirm(pergunta)) {
      return;
    }
    const decisoes = {
      anular: () => decisaoAnular(entrada.id),
      confirmar: () => decisaoConfirmar(entrada.id),
      descartar_passagem: () => decisaoDescartarPassagem(entrada.id),
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

  // "Desfazer" in the history (and "Desfazer a exclusão"). Undoing a
  // counter's own void asks first, as "Restaurar" on its chip does.
  function desfazer(decisao) {
    const pergunta = perguntaAoDesfazer(decisao);
    if (pergunta !== null && !window.confirm(pergunta)) {
      return;
    }
    enviar(pedidoSemAlvo(decisaoDesfazer(decisao.id), tela.estado));
  }

  // "Encerrar exclusão em h:mm:ss" on an open-ended exclusion.
  function encerrarExclusao(exclusao, textoAte) {
    const largada = tela.estado.corrida.largada_ms;
    const desdeALargada = largada === null || largada === undefined ? null : interpretarTempoDeProva(textoAte);
    const ate = desdeALargada === null ? null : largada + desdeALargada;
    const problema = problemaAoEncerrarExclusao(exclusao, ate, agoraServidorMs());
    if (problema) {
      mostrarMensagem(problema, 'erro');
      return;
    }
    enviar(pedidoSemAlvo(decisaoEncerrarExclusao(exclusao.id, ate), tela.estado));
  }

  return {
    aceitar,
    confirmarAlerta,
    descartarPassagem,
    marcarSaida,
    confirmarSaida,
    moverRegistro,
    usarTempo,
    anular,
    acaoNaEntrada,
    adicionar,
    excluirFonte,
    encerrarExclusao,
    desfazer,
  };
}
