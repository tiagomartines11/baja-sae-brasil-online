// Turns what a reviewer clicked into the request contagem_decidir.php
// expects, and turns the server's refusals into sentences.
// Pure functions, tested with `node --test`.
//
import { acaoDeAceitar } from './revisao-alertas.js';

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

// The entries a decision is about: one ("passagem_id"), or several for
// "reatribuir_grupo" ("passagem_ids"). Empty for a decision about no entry.
export function entradasDaDecisao(decisao) {
  if (Array.isArray(decisao?.passagem_ids)) {
    return decisao.passagem_ids;
  }
  return decisao?.passagem_id === undefined || decisao?.passagem_id === null ? [] : [decisao.passagem_id];
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
  if (entradasDaDecisao(decisao).some((alvo) => !ids.includes(alvo))) {
    return null;
  }
  return pedido(decisao, estado, ids, assinaturaDaJanela ?? null);
}

// What "Aceitar sugestão" (A) sends for a flag: the list of request bodies
// for contagem_decidir.php, in order. Today always one request (a suggestion
// that moves several entries is still ONE decision, stored in one step), and
// [] when the flag has no suggestion or its suggestion cannot be sent as a
// decision on the flag (it names an entry that is not one of the flag's).
//
// No question is asked here; see sugestaoPedeConfirmacao(). The screen and
// the simulation runner (scripts/contagem-simular.mjs) both build their
// requests with this, so the runner accepts suggestions exactly as a
// reviewer's click does.
//
// `assinaturaDaJanela`: the signature the flag had when the timeline on
// screen was loaded. A caller with no timeline sends the flag's own.
export function pedidosDaSugestao(alerta, estado, assinaturaDaJanela = alerta?.assinatura ?? null) {
  // A flag whose A is not offered (no suggestion, or a pass while the car
  // is logged inside an area, which has no default) sends nothing.
  const decisao = acaoDeAceitar(alerta, estado) ? decisaoAceitar(alerta) : null;
  const pedidoDoAlerta = decisao ? pedidoParaAlerta(decisao, alerta, estado, assinaturaDaJanela) : null;
  return pedidoDoAlerta ? [pedidoDoAlerta] : [];
}

// Does the screen ask "are you sure?" before sending the flag's suggestion?
// True for every suggestion that gives a car a pass or takes one away.
// `estado` is only needed for the wording, not for the answer.
export function sugestaoPedeConfirmacao(alerta, estado = null) {
  return (acaoDeAceitar(alerta, estado)?.pergunta ?? null) !== null;
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

// The entries of open "repetida" flags that are the REPEAT: the later one
// of each pair. "Está correto, parar de avisar" on such an entry is "keep
// both" under another name, and the flag itself has no such button for that
// reason; the chip must not bring it back.
export function repeticoesEmAberto(estado) {
  const ids = new Set();
  for (const alerta of estado?.alertas_abertos ?? []) {
    if (alerta.tipo !== 'repetida') {
      continue;
    }
    if (alerta.sugestao?.tipo === 'anular' && alerta.sugestao.passagem_id) {
      ids.add(alerta.sugestao.passagem_id);
    } else {
      (alerta.passagem_ids ?? []).slice(1).forEach((id) => ids.add(id));
    }
  }
  return ids;
}

// Would "confirmar" on this entry give its car one more official pass?
//   - an entry the engine voided as a repeat: yes, it comes back as a pass;
//   - an entry in a pass group: what the server says ("soma_ao_confirmar":
//     pending, held, or typed before the start). A pass the rules of the
//     race leave out (start window, after the finish) stays out whoever
//     confirms it, so the button must not promise a lap there.
export function confirmarSomaPassagem(entrada) {
  if (entrada.grupo) {
    return entrada.grupo.soma_ao_confirmar ?? !entrada.grupo.contado;
  }
  return Boolean(entrada.anulada_auto);
}

// Returns the list of actions offered for a timeline entry:
//   {acao: 'remover', decisaoId}    a pass added by a reviewer: undo the addition
//   {acao: 'restaurar', decisaoId}  an entry somebody voided: undo the void
//   {acao: 'desfazer_troca', decisaoId}  a reassigned entry: back to the typed car
//   {acao: 'anular'} {acao: 'reatribuir'}
//   {acao: 'confirmar', maisUmaPassagem}  true when confirming gives the car
//        one more pass (see confirmarSomaPassagem)
//   {acao: 'descartar_passagem', registros}  void the whole pass group at
//        once; offered when the pass has more than one entry
//
// "confirmar" is NOT offered:
//   - on the later entry of an open repeat (`repeticoes`, see repeticoesEmAberto);
//   - on an entry of unknown time: it cannot count (nobody knows when it
//     was), the pass is added by hand with a typed time instead;
//   - on an entry that is in no pass and was not voided as a repeat (its
//     source was off or excluded, or it is before the start): there is
//     nothing to confirm.
export function acoesDaEntrada(entrada, repeticoes = new Set()) {
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
  const podeConfirmar = !decisoes.some((decisao) => decisao.tipo === 'confirmar')
    && !repeticoes.has(entrada.id)
    && entrada.qualidade_tempo !== 'desconhecido'
    && (Boolean(entrada.grupo) || Boolean(entrada.anulada_auto));
  if (podeConfirmar) {
    acoes.push({ acao: 'confirmar', maisUmaPassagem: confirmarSomaPassagem(entrada) });
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
export {
  acaoDeConfirmar, decisaoConfirmarAlerta, voltaCurtaAoContar, voltaImpossivelmenteCurta,
} from './revisao-alertas.js';
