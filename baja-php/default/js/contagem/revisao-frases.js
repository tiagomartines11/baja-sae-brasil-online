// Sentences of the review screen: what the server refused, what the engine
// suggests, what each line of the history says, and the questions asked
// before a decision that changes a count.
// Pure functions, tested with `node --test` (through revisao-decisoes.js).

import { duracao, idade } from './revisao-formato.js';

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
    // Said as what it does: on a pass that does not count yet, confirming adds one.
    if (alerta.tipo === 'antes_da_largada') {
      return 'Sugestão: contar esta passagem como a passagem inicial do carro (+1 volta). Ela foi registrada antes da largada.';
    }
    return alerta.efeito_de_confirmar === 'mais_uma_passagem'
      ? 'Sugestão: contar esta passagem (+1 volta).'
      : 'Sugestão: confirmar a passagem.';
  }
  if (sugestao.tipo === 'adicionar') {
    return `Sugestão: adicionar uma passagem do carro ${sugestao.equipe_numero}.`;
  }
  if (sugestao.tipo === 'reatribuir_grupo') {
    // The other reading (the lone entry is the mistake) is in the reason
    // above; the suggestion says which of the two it proposes.
    return `Sugestão: mover os ${(sugestao.passagem_ids ?? []).length} registros para o carro ${sugestao.equipe_numero} `
      + '(os contadores teriam digitado o mesmo número errado). Confira na linha do tempo qual das duas leituras é a certa.';
  }
  if (sugestao.tipo === 'marcar_saida') {
    return `Sugestão: marcar a saída do carro ${sugestao.equipe_numero} do ${sugestao.area} um segundo antes desta passagem. `
      + 'Mais de um contador viu o carro na linha, então o mais provável é que a saída não foi registrada. Não muda a contagem de voltas.';
  }
  if (sugestao.tipo === 'descartar_passagem') {
    return 'Sugestão: descartar esta passagem. Só um contador a registrou, e o carro consta como dentro da área.';
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
    case 'marcar_saida':
      // The area is kept in the decision's "motivo".
      return `Marcou a saída do carro ${decisao.equipe_numero} do ${decisao.motivo ?? 'box'} (ninguém registrou na hora)`;
    default:
      return decisao.tipo;
  }
}

// The question asked before a decision that gives a car one more pass when
// the lap it would create is impossibly short. `voltaMs` is that lap.
export function perguntaSobreVoltaCurta(voltaMs) {
  return `Contar esta passagem criaria uma volta de ${duracao(voltaMs)}, curta demais para ser real. `
    + 'O mais provável é ser a mesma passagem registrada duas vezes. Contar mesmo assim?';
}

// ---- corrections made by the reconcile step --------------------------------

// One correction: "carro 12: VOLTAS publicado 7, calculado 8 (há 3 min)".
export function descreverCorrecao(correcao, agoraServidorMs) {
  const publicado = correcao.publicado === null || correcao.publicado === undefined ? 'nada' : correcao.publicado;
  const quando = agoraServidorMs === null || agoraServidorMs === undefined ? '' : ` (há ${idade(agoraServidorMs - correcao.quando_ms)})`;
  return `carro ${correcao.carro}: ${correcao.campo} publicado ${publicado}, calculado ${correcao.calculado}${quando}`;
}

// The warning shown while the server reports corrections: the fast count
// (made at each upload) and the full count disagreed about a published
// value, and the full count won. Null when there is none.
//
// It used to go to the server's error log only, where no reviewer looks.
export function avisoDeReconciliacao(correcoes, agoraServidorMs, mostrar = 5) {
  const lista = correcoes ?? [];
  if (lista.length === 0) {
    return null;
  }
  const vezes = lista.length === 1
    ? '1 valor publicado foi corrigido pelo recálculo'
    : `${lista.length} valores publicados foram corrigidos pelo recálculo`;
  const resto = lista.length > mostrar ? `; e mais ${lista.length - mostrar}` : '';
  return `A contagem rápida divergiu da contagem completa: ${vezes} nas últimas 24 h. `
    + 'Os resultados já estão certos, mas isso não deveria acontecer: avise o responsável pelo sistema. '
    + `Correções: ${lista.slice(0, mostrar).map((correcao) => descreverCorrecao(correcao, agoraServidorMs)).join('; ')}${resto}.`;
}
