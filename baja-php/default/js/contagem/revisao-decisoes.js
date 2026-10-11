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

// ---- the K button ---------------------------------------------------------
//
// K means "confirmar" on the flag's first entry. What that DOES depends on
// the flag, and the button has to say it:
//
//   - on most flags the pass already counts, and confirming only tells the
//     engine to stop flagging it;
//   - on a lone entry, a probable swap, an unclassified car and the like the
//     pass does not count yet, and confirming gives the car ONE MORE PASS on
//     a single counter's word.
//
// The second kind used to be labelled "Manter ambas" like the first, and a
// reviewer who meant "leave it alone" added a lap. The server says which
// kind a flag is ("efeito_de_confirmar"); for a flag from before that
// existed the type decides.

const TIPOS_QUE_SOMAM_PASSAGEM = [
  'solitaria', 'troca_provavel', 'carro_nao_classificado', 'antes_da_largada', 'tempo_desconhecido',
];

const ROTULOS_DE_CONTAR = {
  carro_nao_classificado: 'Contar mesmo assim (+1 volta)',
  antes_da_largada: 'Contar como passagem inicial',
};

// What K does on a flag. Null when the flag has no K at all:
//   - "repetida": the two choices are to void the second entry (A or V) or
//     to say it was another car; "keep both" would count the car twice;
//   - a flag about no entry.
// Otherwise {rotulo, maisUmaPassagem}.
export function acaoDeConfirmar(alerta) {
  if (!alerta || alerta.tipo === 'repetida' || (alerta.passagem_ids ?? []).length === 0) {
    return null;
  }
  const efeito = alerta.efeito_de_confirmar
    ?? (TIPOS_QUE_SOMAM_PASSAGEM.includes(alerta.tipo) ? 'mais_uma_passagem' : 'nenhum');
  if (efeito === 'mais_uma_passagem') {
    return { rotulo: ROTULOS_DE_CONTAR[alerta.tipo] ?? 'Contar esta passagem (+1 volta)', maisUmaPassagem: true };
  }
  return { rotulo: 'Está correto, parar de avisar', maisUmaPassagem: false };
}

// The decision K sends, or null when the flag has no K.
export function decisaoConfirmarAlerta(alerta) {
  return acaoDeConfirmar(alerta) ? { tipo: 'confirmar', passagem_id: alerta.passagem_ids[0] } : null;
}

// The same rule as the engine's Settings::isImpossiblyShortLap(): under two
// matching windows whatever the pace, or under the configured fraction of
// the lap expected of that car.
export function voltaImpossivelmenteCurta(voltaMs, configuracoes, voltaEsperadaMs) {
  if (voltaMs === null || voltaMs === undefined) {
    return false;
  }
  const janelaMs = Number(configuracoes?.janela_ms) || 20000;
  if (voltaMs < 2 * janelaMs) {
    return true;
  }
  const fracao = Number(configuracoes?.fracao_volta_minima) || 0.5;
  return voltaEsperadaMs !== null && voltaEsperadaMs !== undefined && voltaMs < fracao * voltaEsperadaMs;
}

// Must the reviewer be asked "are you sure?" before K on this flag? Yes
// when K adds a pass and the lap it creates is impossibly short. Returns
// that lap in ms (for the question), or null when no question is needed.
export function voltaCurtaAoContar(alerta, estado) {
  if (!acaoDeConfirmar(alerta)?.maisUmaPassagem) {
    return null;
  }
  const voltaMs = alerta.volta_resultante_ms ?? null;
  const carro = (estado?.carros ?? []).find((linha) => linha.carro === (alerta.carros ?? [])[0]);
  return voltaImpossivelmenteCurta(voltaMs, estado?.configuracoes, carro?.volta_esperada_ms ?? null) ? voltaMs : null;
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

// "Descartar esta passagem": the server voids every entry of the pass group
// that `passagemId` is in, in one step.
export function decisaoDescartarPassagem(passagemId) {
  return { tipo: 'descartar_passagem', passagem_id: passagemId };
}

// "Encerrar exclusão em h:mm:ss": the open-ended exclusion `decisaoId` gets
// an end. The server replaces it with a bounded one in one step.
export function decisaoEncerrarExclusao(decisaoId, ateMs) {
  return { tipo: 'encerrar_exclusao', desfaz_decisao_id: decisaoId, ts_fim_ms: ateMs };
}

// Is this a possible end for the open exclusion? Returns the sentence to
// show, or null when it is fine. `ateMs` null means the text typed was not
// a time. The server checks the same again.
export function problemaAoEncerrarExclusao(exclusao, ateMs, agoraServidorMs) {
  if (ateMs === null || ateMs === undefined) {
    return 'Tempo inválido. Use h:mm:ss, por exemplo 1:02:05.';
  }
  if (ateMs <= exclusao.ts_ms) {
    return 'O fim da exclusão deve ser depois do início dela.';
  }
  if (ateMs > agoraServidorMs) {
    return 'Esse tempo de prova ainda não chegou. Informe o tempo desde a largada (h:mm:ss) em que a fonte voltou a contar.';
  }
  return null;
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

// May "Aceitar", K and "Anular" be used on the selected flag?
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
//   {acao: 'anular'} {acao: 'reatribuir'}
//   {acao: 'confirmar', maisUmaPassagem}  true when the entry's pass does not
//        count today, so confirming it gives the car one more pass (a double
//        tap the engine voided, a lone entry, a pass before the start)
//   {acao: 'descartar_passagem', registros}  void the whole pass group at
//        once; offered when the pass has more than one entry
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
    acoes.push({ acao: 'confirmar', maisUmaPassagem: !entrada.grupo?.contado });
  }
  const registrosDoGrupo = (entrada.grupo?.passagem_ids ?? []).length;
  if (registrosDoGrupo > 1) {
    acoes.push({ acao: 'descartar_passagem', registros: registrosDoGrupo });
  }
  return acoes;
}

export {
  descreverDecisao, descreverSugestao, mensagemDeConflito, perguntaSobreVoltaCurta,
} from './revisao-frases.js';
