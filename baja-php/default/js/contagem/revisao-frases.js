// Sentences of the review screen: what the server refused, what the engine
// suggests, what each line of the history says, and the questions asked
// before a decision that changes a count.
// Pure functions, tested with `node --test` (through revisao-decisoes.js).

import { duracao, horaDoDia, idade, relogio } from './revisao-formato.js';

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
  if (alerta?.tipo === 'no_box' || alerta?.tipo === 'no_abastecimento') {
    // No default on purpose (see acaoDeAceitar): the two ways out are put side by side.
    const area = alerta.tipo === 'no_box' ? 'box' : 'abastecimento';
    return `Sem sugestão automática. Se o carro saiu do ${area} e ninguém registrou, marque a saída; `
      + 'se a passagem não aconteceu, descarte-a.';
  }
  if (alerta?.tipo === 'saida_presumida') {
    return 'Nada a corrigir na contagem. Se o carro saiu mesmo, use "Confirmar saída": a saída presumida fica gravada no seu nome '
      + 'e o alerta fecha. Se ele não saiu, descarte a passagem.';
  }
  if (alerta?.tipo === 'registro_tardio') {
    return 'Sem sugestão automática. Diga de quem é o horário certo (normalmente o de quem registrou primeiro): '
      + 'a passagem conta uma vez, nesse horário, e o outro registro fica junto dela.';
  }
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

// "(carro 3, Contagem 1)": which entry a decision was about, from the
// fields the server adds to a history row. For a reassignment the car as
// typed; otherwise the car the entry counts for now, with the typed one
// when they differ. Empty when the row says nothing about an entry.
function doRegistro(decisao, comCarroAtual = true) {
  const digitado = decisao.carro_digitado ?? null;
  const atual = decisao.carro ?? digitado;
  const partes = [];
  if (digitado !== null) {
    partes.push(!comCarroAtual || atual === digitado ? `carro ${digitado}` : `carro ${atual}, digitado ${digitado}`);
  }
  if (decisao.fonte_nome && digitado !== null) {
    partes.push(decisao.fonte_nome);
  }
  return partes.length === 0 ? '' : ` (${partes.join(', ')})`;
}

// One line of the history panel.
export function descreverDecisao(decisao, nomeDaFonte = () => null) {
  switch (decisao.tipo) {
    case 'anular':
      return `Anulou a passagem #${decisao.passagem_id}${doRegistro(decisao)}`;
    case 'reatribuir':
      return `Trocou a passagem #${decisao.passagem_id}${doRegistro(decisao, false)} para o carro ${decisao.equipe_numero}`;
    case 'confirmar':
      return `Confirmou a passagem #${decisao.passagem_id}${doRegistro(decisao)}`;
    case 'usar_tempo':
      return `Usou o tempo da passagem #${decisao.passagem_id}${doRegistro(decisao)}`
        + `${decisao.passagem_alvo_id ? ` e juntou a #${decisao.passagem_alvo_id} a ela (registrada com atraso)` : ''}`;
    case 'adicionar':
      return `Adicionou uma passagem do carro ${decisao.equipe_numero}`;
    case 'excluir_fonte':
      return `Excluiu a fonte ${nomeDaFonte(decisao.fonte_id) ?? `#${decisao.fonte_id}`} ${
        decisao.ts_fim_ms === null || decisao.ts_fim_ms === undefined ? 'até segunda ordem' : 'por um período'}`;
    case 'desfazer':
      return `Desfez a decisão #${decisao.desfaz_decisao_id}${doRegistro(decisao)}`;
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

// The question before undoing a decision; null when nothing is asked.
//
// A counter who voids their own entry on the phone knows something the
// reviewer does not: they typed it wrong. At a race a reviewer undid two
// such voids and confirmed the retyped entries a second later, and two cars
// got a lap they never drove. So undoing a counter's own void asks first,
// wherever the button is (the history, the chip's "Restaurar").
// `decisao` is a history row, or a chip's decision ({id, tipo, origem,
// usuario, criado_em_ms, motivo}).
export function perguntaAoDesfazer(decisao) {
  if (decisao?.tipo !== 'anular' || decisao.origem !== 'contador') {
    return null;
  }
  const quem = [decisao.usuario, decisao.criado_em_ms ? horaDoDia(decisao.criado_em_ms) : null].filter(Boolean).join(', ');
  const motivo = decisao.motivo ? ` Motivo dado: "${decisao.motivo}".` : '';
  return `O contador anulou este registro no próprio aparelho${quem ? ` (${quem})` : ''}.${motivo} `
    + 'Normalmente ele digitou errado e registrou de novo logo depois. Desfazer mesmo assim?';
}

// The button of one time a reviewer may choose ("usar_tempo"):
// "Usar o tempo de Contagem 1 (0:04:20)".
export function rotuloDeUsarTempo(tempo, nomeDaFonte, largadaMs) {
  const nome = nomeDaFonte(tempo.fonte_id) ?? `fonte #${tempo.fonte_id}`;
  const quando = largadaMs === null || largadaMs === undefined ? '' : ` (${relogio(tempo.ts_ms - largadaMs)})`;
  return `Usar o tempo de ${nome}${quando}`;
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

// What is wrong with the points, from the state's `pontuacao`: a
// classification field the scoring formula reads (the 26SE case, DESCLAS),
// and a race with laps where every published points value is 0. Empty when
// all is well, or when the server does not send it.
export function avisosDaPontuacao(pontuacao) {
  const avisos = [];
  const campo = pontuacao?.campo_classificacao_na_formula;
  if (campo) {
    avisos.push(`campo de classificação ${campo} é lido pela fórmula de pontuação: os pontos podem estar errados `
      + '(veja Fontes e configurações)');
  }
  if (pontuacao?.todos_os_pontos_zero) {
    avisos.push('todos os pontos publicados são 0 — confira o campo de classificação e a fórmula');
  }
  return avisos;
}

// "pontos recalculados às 14:03:27", or null when the time is not known.
export function recalculoDosPontos(pontuacao) {
  const quando = pontuacao?.recalculados_em_ms;
  return quando === null || quando === undefined ? null : `pontos recalculados às ${horaDoDia(quando)}`;
}
