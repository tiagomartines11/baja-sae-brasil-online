// Turns what a reviewer clicked into the request contagem_decidir.php
// expects, and turns the server's refusals into sentences.
// Pure functions, tested with `node --test`.
//
// A request always says what the reviewer was looking at ("passagem_ids"
// and their "assinatura"). If any of it changed on the server in the
// meantime the decision is refused with HTTP 409 instead of being applied
// to a situation the reviewer never saw.

// ---- the decisions themselves -------------------------------------------

// "Aceitar sugestão" (A): store exactly what the engine suggested.
// Null when the flag has no suggestion; the button is then disabled.
export function decisaoAceitar(alerta) {
  if (!alerta || !alerta.sugestao) {
    return null;
  }
  return { ...alerta.sugestao };
}

// "Manter ambas" (K): the reviewer says the entries are fine as they are.
// By convention that is a "confirmar" on the flag's first entry; the engine
// then counts the pass and stops flagging it.
export function decisaoManterAmbas(alerta) {
  const ids = alerta?.passagem_ids ?? [];
  if (ids.length === 0) {
    return null;
  }
  return { tipo: 'confirmar', passagem_id: ids[0] };
}

// Which entry "Anular" (V) acts on:
//   1. the chip the reviewer clicked, if any;
//   2. else the entry the engine suggests voiding;
//   3. else the flag's only entry.
// Null when there are several candidates and none was chosen: the screen
// then asks the reviewer to click one first.
export function alvoDaAnulacao(alerta, entradaSelecionadaId) {
  if (entradaSelecionadaId !== null && entradaSelecionadaId !== undefined) {
    return entradaSelecionadaId;
  }
  const sugestao = alerta?.sugestao;
  if (sugestao && sugestao.tipo === 'anular' && sugestao.passagem_id) {
    return sugestao.passagem_id;
  }
  const ids = alerta?.passagem_ids ?? [];
  return ids.length === 1 ? ids[0] : null;
}

export function decisaoAnular(passagemId) {
  return { tipo: 'anular', passagem_id: passagemId };
}

export function decisaoConfirmar(passagemId) {
  return { tipo: 'confirmar', passagem_id: passagemId };
}

export function decisaoReatribuir(passagemId, carro) {
  return { tipo: 'reatribuir', passagem_id: passagemId, equipe_numero: carro };
}

export function decisaoAdicionar(carro, tsMs) {
  return { tipo: 'adicionar', equipe_numero: carro, ts_ms: tsMs };
}

// `ateMs` null means "até segunda ordem": excluded until somebody undoes it.
export function decisaoExcluirFonte(fonteId, deMs, ateMs = null) {
  return { tipo: 'excluir_fonte', fonte_id: fonteId, ts_ms: deMs, ts_fim_ms: ateMs };
}

export function decisaoDesfazer(decisaoId) {
  return { tipo: 'desfazer', desfaz_decisao_id: decisaoId };
}

// ---- wrapping a decision into a request ----------------------------------

function pedido(decisao, estado, passagemIds, assinatura) {
  return {
    decisao,
    passagem_ids: passagemIds,
    assinatura,
    ultima_decisao_id: estado?.ultima_decisao_id ?? null,
  };
}

// Adds the optional reason the reviewer typed. An empty reason is left out.
export function comMotivo(decisao, motivo) {
  const texto = String(motivo ?? '').trim();
  return decisao && texto !== '' ? { ...decisao, motivo: texto } : decisao;
}

// May "Aceitar", "Manter ambas" and "Anular" be used on the selected flag?
//
// Only while the timeline on screen is the one the flag currently has. The
// flag list is refreshed every two seconds but the timeline is loaded once,
// so the two can differ; a decision taken then would be about entries the
// reviewer is not looking at.
export function podeDecidirSobreAlerta({ janelaCarregada, desatualizada }) {
  return Boolean(janelaCarregada) && !desatualizada;
}

// A decision taken on a flag: the reviewer was looking at all of the
// flag's entries.
//
// `assinaturaDaJanela` is the signature the flag had when the timeline now
// on screen was loaded. That one is sent, not the flag's latest: the server
// must compare with what the reviewer SAW, and the latest signature would
// always match and let a decision through that was made on an old picture.
//
// Null when the decision is about an entry that is not one of the flag's:
// the server refuses those (it could not check that the entry is unchanged),
// so such a decision has to be sent as a decision on that one chip instead.
export function pedidoParaAlerta(decisao, alerta, estado, assinaturaDaJanela) {
  const ids = [...(alerta.passagem_ids ?? [])];
  const alvo = decisao?.passagem_id;
  if (alvo !== undefined && alvo !== null && !ids.includes(alvo)) {
    return null;
  }
  return pedido(decisao, estado, ids, assinaturaDaJanela ?? null);
}

// A decision taken on one chip of the timeline.
export function pedidoParaEntrada(decisao, entrada, estado) {
  return pedido(decisao, estado, [entrada.id], entrada.assinatura ?? null);
}

// Adding a pass, excluding a source, undoing: there is no entry on screen
// that could have changed.
export function pedidoSemAlvo(decisao, estado) {
  return pedido(decisao, estado, [], null);
}

// "Adicionar passagem": is this moment possible? Returns the sentence to
// show, or null when it is fine. The server checks the same again.
//
// `tsMs` null means the text typed was not a time at all.
export function problemaAoAdicionar(tsMs, corrida, agoraServidorMs) {
  if (corrida?.largada_ms === null || corrida?.largada_ms === undefined) {
    return 'A corrida ainda não tem largada: não é possível adicionar uma passagem.';
  }
  if (tsMs === null || tsMs === undefined) {
    return 'Tempo inválido. Use h:mm:ss, por exemplo 1:02:05.';
  }
  if (tsMs > agoraServidorMs) {
    return 'Esse tempo de prova ainda não chegou. Informe o tempo desde a largada (h:mm:ss), não a hora do relógio.';
  }
  return null;
}

// ---- what can be done with one chip ---------------------------------------

// Source exclusions with no end that are still in force, from the state's
// "historico_em_vigor". These are shown on top of everything: the source is
// being ignored right now and will be until somebody undoes the decision.
export function exclusoesAbertas(estado) {
  return (estado?.historico_em_vigor ?? []).filter((decisao) => decisao.tipo === 'excluir_fonte'
    && (decisao.ts_fim_ms === null || decisao.ts_fim_ms === undefined));
}

// Returns the list of actions offered for a timeline entry:
//   {acao: 'remover', decisaoId}    a pass added by a reviewer: undo the addition
//   {acao: 'restaurar', decisaoId}  an entry somebody voided: undo the void
//   {acao: 'desfazer_troca', decisaoId}  a reassigned entry: back to the typed car
//   {acao: 'anular'} {acao: 'reatribuir'} {acao: 'confirmar'}
export function acoesDaEntrada(entrada) {
  if (entrada.adicionada_por_revisor) {
    // Its id is minus the id of the "adicionar" decision that created it.
    return [{ acao: 'remover', decisaoId: -entrada.id }];
  }
  const decisoes = entrada.decisoes ?? [];
  const anulacao = decisoes.find((decisao) => decisao.tipo === 'anular');
  if (anulacao) {
    return [{ acao: 'restaurar', decisaoId: anulacao.id }];
  }
  const acoes = [{ acao: 'anular' }, { acao: 'reatribuir' }];
  const troca = decisoes.find((decisao) => decisao.tipo === 'reatribuir');
  if (troca) {
    acoes.push({ acao: 'desfazer_troca', decisaoId: troca.id });
  }
  if (!decisoes.some((decisao) => decisao.tipo === 'confirmar')) {
    acoes.push({ acao: 'confirmar' });
  }
  return acoes;
}

// ---- sentences -------------------------------------------------------------

// What the server answered with HTTP 409, as one sentence.
export function mensagemDeConflito(corpo) {
  if (corpo?.motivo === 'ja_decidido') {
    return `Já decidido por ${corpo.por ?? 'outra pessoa'}. A tela foi atualizada.`;
  }
  return 'Os dados mudaram desde que você abriu este item. A tela foi atualizada; confira e decida de novo.';
}

// The engine's suggestion as one sentence. The reason comes from the server
// already written out ("motivo").
export function descreverSugestao(alerta) {
  const sugestao = alerta?.sugestao;
  if (!sugestao) {
    return 'Sem sugestão automática: decida olhando a linha do tempo.';
  }
  if (sugestao.tipo === 'reatribuir') {
    return `Sugestão: trocar a passagem para o carro ${sugestao.equipe_numero}.`;
  }
  if (sugestao.tipo === 'anular') {
    return 'Sugestão: anular a passagem.';
  }
  if (sugestao.tipo === 'confirmar') {
    return 'Sugestão: confirmar a passagem.';
  }
  if (sugestao.tipo === 'adicionar') {
    return `Sugestão: adicionar uma passagem do carro ${sugestao.equipe_numero}.`;
  }
  return `Sugestão: ${sugestao.tipo}.`;
}

// One line of the history panel.
export function descreverDecisao(decisao, nomeDaFonte = () => null) {
  switch (decisao.tipo) {
    case 'anular':
      return `Anulou a passagem #${decisao.passagem_id}`;
    case 'reatribuir':
      return `Trocou a passagem #${decisao.passagem_id} para o carro ${decisao.equipe_numero}`;
    case 'confirmar':
      return `Confirmou a passagem #${decisao.passagem_id}`;
    case 'adicionar':
      return `Adicionou uma passagem do carro ${decisao.equipe_numero}`;
    case 'excluir_fonte':
      return `Excluiu a fonte ${nomeDaFonte(decisao.fonte_id) ?? `#${decisao.fonte_id}`} ${
        decisao.ts_fim_ms === null || decisao.ts_fim_ms === undefined ? 'até segunda ordem' : 'por um período'}`;
    case 'desfazer':
      return `Desfez a decisão #${decisao.desfaz_decisao_id}`;
    default:
      return decisao.tipo;
  }
}
