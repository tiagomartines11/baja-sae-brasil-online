// Sentences of the review screen: what the server refused, what the engine
// suggests, what each line of the history says, and the questions asked
// before a decision that changes a count.
// Pure functions, tested with `node --test` (through revisao-decisoes.js).

import { duracao } from './revisao-formato.js';

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

// The question asked before a decision that gives a car one more pass when
// the lap it would create is impossibly short. `voltaMs` is that lap.
export function perguntaSobreVoltaCurta(voltaMs) {
  return `Contar esta passagem criaria uma volta de ${duracao(voltaMs)}, curta demais para ser real. `
    + 'O mais provável é ser a mesma passagem registrada duas vezes. Contar mesmo assim?';
}
