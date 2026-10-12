// What the review screen offers on each kind of flag: what A and K do there,
// what their buttons say, what is asked first, and which other buttons the
// flag gets. Pure functions, tested with `node --test`.
//
// The rule behind all of it: a button that changes a car's lap count says so
// in its own words, and a decision that ADDS a pass on a suggestion's word
// is never one keystroke away.

import { duracao, relogio } from './revisao-formato.js';

// ---- "the count is in doubt" ------------------------------------------------

// The server marks a flag with `contagem_em_duvida` when the car's official
// count may be wrong RIGHT NOW because of it:
//   'a_mais'   two counted passes closer together than a lap can be: one of
//              them may be the same crossing counted twice
//   'a_menos'  a pass is held back and may be real
// Returns the sentence shown on the flag, or null when the count is not in doubt.
export function fraseDeDuvida(alerta) {
  const carro = (alerta?.carros ?? [])[0];
  const quem = carro === undefined ? 'O carro' : `O carro ${carro}`;
  if (alerta?.contagem_em_duvida === 'a_mais') {
    return `${quem} pode estar com uma volta a mais.`;
  }
  if (alerta?.contagem_em_duvida === 'a_menos') {
    return `${quem} pode estar com uma volta a menos.`;
  }
  return null;
}

// ---- the K button -----------------------------------------------------------
//
// K means "confirmar" on the flag's first entry. What that DOES depends on
// the flag, and the button has to say it:
//
//   - on most flags the pass already counts, and confirming only tells the
//     engine to stop flagging it;
//   - on a lone entry, a probable swap, a held pass, an unclassified car and
//     the like the pass does not count yet, and confirming gives the car ONE
//     MORE PASS.
//
// The server says which kind a flag is ("efeito_de_confirmar"); for a flag
// from before that existed the type decides.

const TIPOS_QUE_SOMAM_PASSAGEM = [
  'solitaria', 'troca_provavel', 'carro_nao_classificado', 'antes_da_largada', 'passagem_proxima_demais',
];

// Flags with no K at all:
//   repetida             "keep both" would count the car twice; the choices
//                        are to void the second entry or to say it was
//                        another car
//   tempo_desconhecido   K used to count the pass at the phone's own clock
//                        time, which the screen does not even show. The pass
//                        is added by hand instead, with a time the reviewer
//                        types ("Adicionar passagem")
//   no_box, no_abastecimento
//                        "está correto" explained nothing: either the car
//                        left (mark the exit) or the pass did not happen
//                        (discard it). The pass is HELD meanwhile, and K
//                        would count it while leaving the car logged inside
//   saida_presumida      the engine assumed an exit nobody logged and went on
//                        counting. "Confirmar" there has one meaning, and
//                        it is the button "Confirmar saída": the exit is
//                        STORED. A K that only hid the flag would leave the
//                        log wrong under the same word
const TIPOS_SEM_CONFIRMAR = ['repetida', 'tempo_desconhecido', 'no_box', 'no_abastecimento', 'saida_presumida'];

const ROTULOS_DE_CONTAR = {
  carro_nao_classificado: 'Contar mesmo assim (+1 volta)',
  antes_da_largada: 'Contar como passagem inicial (+1 volta)',
};

// Would a "confirmar" on this flag give the car one more official pass?
function somaPassagem(alerta) {
  const efeito = alerta.efeito_de_confirmar
    ?? (TIPOS_QUE_SOMAM_PASSAGEM.includes(alerta.tipo) ? 'mais_uma_passagem' : 'nenhum');
  return efeito === 'mais_uma_passagem';
}

const rotuloDeContar = (alerta) => ROTULOS_DE_CONTAR[alerta.tipo] ?? 'Contar esta passagem (+1 volta)';

// What K does on a flag. Null when the flag has no K (see above, and a flag
// about no entry). Otherwise {rotulo, maisUmaPassagem}.
export function acaoDeConfirmar(alerta) {
  if (!alerta || TIPOS_SEM_CONFIRMAR.includes(alerta.tipo) || (alerta.passagem_ids ?? []).length === 0) {
    return null;
  }
  if (somaPassagem(alerta)) {
    return { rotulo: rotuloDeContar(alerta), maisUmaPassagem: true };
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

// When K adds a pass and the lap it creates is impossibly short: that lap
// in ms (for the question). Null otherwise.
export function voltaCurtaAoContar(alerta, estado) {
  if (!acaoDeConfirmar(alerta)?.maisUmaPassagem) {
    return null;
  }
  const voltaMs = alerta.volta_resultante_ms ?? null;
  const carro = (estado?.carros ?? []).find((linha) => linha.carro === (alerta.carros ?? [])[0]);
  return voltaImpossivelmenteCurta(voltaMs, estado?.configuracoes, carro?.volta_esperada_ms ?? null) ? voltaMs : null;
}

function perguntaDePassagemInicial(alerta) {
  return `Contar esta passagem do carro ${(alerta.carros ?? []).join(', ')} como a PASSAGEM INICIAL dele? `
    + 'Ela foi registrada ANTES da largada e hoje não conta. Contando, o carro ganha uma passagem oficial: '
    + 'todas as voltas dele sobem em 1 (+1 volta). Contar?';
}

function perguntaDeVoltaCurta(voltaMs) {
  return `Contar esta passagem criaria uma volta de ${duracao(voltaMs)}, curta demais para ser real. `
    + 'O mais provável é ser a mesma passagem registrada duas vezes. Contar mesmo assim?';
}

// What must be asked before K on this flag; null when nothing.
export function perguntaAoConfirmar(alerta, estado) {
  const confirmar = acaoDeConfirmar(alerta);
  if (!confirmar) {
    return null;
  }
  // A pass typed before the start never counts by itself, and making it
  // the car's initial pass raises every later lap of that car by one: that
  // is always asked, however plausible the lap.
  if (confirmar.maisUmaPassagem && alerta.tipo === 'antes_da_largada') {
    return perguntaDePassagemInicial(alerta);
  }
  const voltaCurtaMs = voltaCurtaAoContar(alerta, estado);
  return voltaCurtaMs === null ? null : perguntaDeVoltaCurta(voltaCurtaMs);
}

// ---- the A button -----------------------------------------------------------

const NOMES_DE_AREA = { box: 'box', abastecimento: 'abastecimento' };

// The area a "pass while logged inside" flag is about.
export function areaDoAlerta(alerta) {
  if (alerta?.tipo === 'no_box') {
    return 'box';
  }
  return alerta?.tipo === 'no_abastecimento' ? 'abastecimento' : null;
}

// A pass at the line while the car is logged inside an area is HELD when
// two or more counters agree on it: it does not count until a reviewer
// either marks the exit (then it counts) or discards it. With one counter
// only, the entry is a lone entry like any other: marking the exit closes
// the stay but the pass still needs a second witness.
//
// One missed exit holds EVERY later pass of that stay, and one mark
// releases them all (seen in a rehearsal: 3 laps became 8). The server
// counts that for each such flag, in "voltas_ao_marcar_saida": how many
// agreed held passes start counting if the exit is marked just before this
// flag's pass. That number, and nothing else, is what the button promises.
//
// Returns 0 when marking the exit adds no lap, and when the server did not
// say (one counter only, or a state from before the field existed): a
// button must not promise laps on a guess.
export function voltasAoMarcarSaida(alerta) {
  if (areaDoAlerta(alerta) === null) {
    return 0;
  }
  const voltas = alerta.voltas_ao_marcar_saida;
  return Number.isInteger(voltas) && voltas > 0 ? voltas : 0;
}

// "+1 volta", "+5 voltas".
const maisVoltas = (voltas) => (voltas === 1 ? '+1 volta' : `+${voltas} voltas`);

// "Marcar saída do box (+5 voltas)", "(+1 volta)", or without the
// parenthesis when marking the exit makes no pass count. Called with an
// area alone it gives the plain label.
export function rotuloDeMarcarSaida(area, voltas = 0) {
  return `Marcar saída do ${NOMES_DE_AREA[area] ?? area}${voltas > 0 ? ` (${maisVoltas(voltas)})` : ''}`;
}

// What is asked before "marcar saída"; null when it changes no count.
export function perguntaAoMarcarSaida(alerta) {
  const area = areaDoAlerta(alerta);
  const voltas = voltasAoMarcarSaida(alerta);
  if (voltas === 0) {
    return null;
  }
  const carro = (alerta.carros ?? []).join(', ');
  const efeito = voltas === 1
    ? `A passagem, que hoje está retida, passa a CONTAR: o carro ${carro} ganha uma volta (+1 volta). `
    : `Esta passagem e as seguintes dele retidas pelo mesmo motivo passam a CONTAR, ${voltas} ao todo: `
      + `o carro ${carro} ganha ${voltas} voltas (${maisVoltas(voltas)}). `;
  return `Marcar a saída do carro ${carro} do ${NOMES_DE_AREA[area]}? `
    + 'A saída é registrada um segundo antes desta passagem e a parada é encerrada. '
    + efeito
    + 'Se o carro não saiu e a passagem foi um engano dos contadores, use "Descartar esta passagem". Pode ser desfeito pelo histórico.';
}

// ---- an exit the engine assumed ------------------------------------------------
//
// The pit log had the car inside while two counters agreed on two passes of
// it a lap apart. The engine assumed the exit nobody logged (one second
// before the first of those passes), counted both, and raised the
// informational flag "saida_presumida" on that first pass. Nothing is stored.
//
// The flag does not carry the area or the time of the exit; the state lists
// every assumed exit ("saidas_presumidas": area, carro, ts_ms, entrada_ms).
// The flag's is the latest one of its car at or before the flag's pass.
// Null when the state has none (the log was put right meanwhile).
export function saidaPresumidaDoAlerta(alerta, estado) {
  if (alerta?.tipo !== 'saida_presumida' || alerta.ts_ms === null || alerta.ts_ms === undefined) {
    return null;
  }
  const carro = (alerta.carros ?? [])[0];
  const doCarro = (estado?.saidas_presumidas ?? [])
    .filter((saida) => saida.carro === carro && saida.ts_ms <= alerta.ts_ms)
    .sort((a, b) => b.ts_ms - a.ts_ms);
  return doCarro[0] ?? null;
}

// "Confirmar saída": stores the assumed exit as an exit marked by the
// reviewer, with the same decision "Marcar saída" uses. Null when the flag
// has no assumed exit in the state.
export function decisaoConfirmarSaida(alerta, estado) {
  const saida = saidaPresumidaDoAlerta(alerta, estado);
  return saida ? { tipo: 'marcar_saida', area: saida.area, equipe_numero: saida.carro, ts_ms: saida.ts_ms } : null;
}

// What A does on a flag: {rotulo, pergunta}. Null when the flag has no
// suggestion (the button is then drawn disabled).
//
// `pergunta` is asked before the suggestion is stored, and is never null
// for a suggestion that gives a car a pass or takes one away: A is one key,
// and one key must not change a count by itself.
export function acaoDeAceitar(alerta, estado = null) {
  const sugestao = alerta?.sugestao;
  // A pass while the car is logged inside an area has NO default: both
  // ways out change the race (one adds a lap, the other throws a pass
  // away), and which is right is for a person to see. Whatever an older
  // server suggests there is not offered on A.
  if (!sugestao || areaDoAlerta(alerta) !== null) {
    return null;
  }
  const carros = (alerta.carros ?? []).join(', ');
  switch (sugestao.tipo) {
    case 'confirmar': {
      // A is K here. It says so, and asks: always for a pass before the
      // start, and whenever accepting adds a pass.
      if (!somaPassagem(alerta)) {
        return { rotulo: 'Aceitar sugestão', pergunta: null };
      }
      return {
        rotulo: rotuloDeContar(alerta),
        pergunta: perguntaAoConfirmar(alerta, estado)
          ?? `Contar esta passagem dá ao carro ${carros} MAIS UMA PASSAGEM (+1 volta). Contar?`,
      };
    }
    case 'anular':
      return { rotulo: alerta.tipo === 'repetida' ? 'Anular a segunda' : 'Aceitar sugestão', pergunta: null };
    case 'descartar_passagem':
      return {
        rotulo: 'Descartar esta passagem',
        pergunta: `Descartar esta passagem do carro ${carros}? Todos os registros dela são anulados de uma vez. Pode ser desfeito pelo histórico.`,
      };
    case 'reatribuir_grupo': {
      const quantos = (sugestao.passagem_ids ?? []).length;
      return {
        rotulo: `Mover os ${quantos} registros para o carro ${sugestao.equipe_numero}`,
        pergunta: `Mover ${quantos} registros para o carro ${sugestao.equipe_numero}? `
          + `Isso vale se os contadores digitaram o MESMO número errado: o carro ${sugestao.equipe_numero} ganha esta passagem e o outro carro a perde. `
          + 'Se eles digitaram certo e o engano foi do registro sozinho, NÃO aceite: troque só aquele registro. Pode ser desfeito pelo histórico.',
      };
    }
    default:
      return { rotulo: 'Aceitar sugestão', pergunta: null };
  }
}

// ---- the other buttons of a flag ----------------------------------------------

// "Marcar saída": the suggestion when there is one of that kind, otherwise
// worked out from the flag (one second before the pass, as the server does).
// Null when the flag is not about a car logged inside an area.
export function decisaoMarcarSaida(alerta) {
  const area = areaDoAlerta(alerta);
  if (area === null) {
    return null;
  }
  if (alerta.sugestao?.tipo === 'marcar_saida') {
    return { ...alerta.sugestao };
  }
  const carro = (alerta.carros ?? [])[0];
  if (carro === undefined || alerta.ts_ms === null || alerta.ts_ms === undefined) {
    return null;
  }
  return { tipo: 'marcar_saida', area, equipe_numero: carro, ts_ms: alerta.ts_ms - 1000 };
}

// The passes a "volta curta" flag is about, earlier first:
// [{passagemId, registros, tsMs, qual}]. The flag does not know which of
// the two is the mistake, so the screen offers both and the reviewer picks.
// A flag from before the server sent both passes gives one item about all
// of its entries.
export function passagensParaDescartar(alerta) {
  const passagens = (alerta?.passagens ?? []).filter((passagem) => (passagem.passagem_ids ?? []).length > 0);
  if (passagens.length >= 2) {
    const nomes = ['primeira', 'segunda'];
    return passagens.slice(0, 2).map((passagem, indice) => ({
      passagemId: passagem.passagem_ids[0],
      registros: passagem.passagem_ids.length,
      tsMs: passagem.ts_ms ?? null,
      qual: nomes[indice],
    }));
  }
  const ids = alerta?.passagem_ids ?? [];
  return ids.length === 0 ? [] : [{ passagemId: ids[0], registros: ids.length, tsMs: alerta.ts_ms ?? null, qual: null }];
}

// "Descartar a segunda passagem (0:12:31, anula os 2 registros)".
export function rotuloDeDescartar(passagem, largadaMs) {
  const registros = passagem.registros === 1 ? 'anula 1 registro' : `anula os ${passagem.registros} registros`;
  if (passagem.qual === null) {
    return `Descartar esta passagem (${registros})`;
  }
  const quando = passagem.tsMs === null || largadaMs === null || largadaMs === undefined ? '' : `${relogio(passagem.tsMs - largadaMs)}, `;
  return `Descartar a ${passagem.qual} passagem (${quando}${registros})`;
}

// The buttons a flag gets besides A, K and V, in the order they are drawn:
//   {acao: 'marcar_saida', rotulo}
//   {acao: 'descartar_passagem', passagem, rotulo}
//   {acao: 'adicionar_passagem', rotulo}   opens the form where the time is typed
//   {acao: 'confirmar_saida', rotulo}      on an exit the engine assumed; needs `estado`
// An action that is already what A does on this flag is left out.
//
// A pass while the car is logged inside an area always gets both of its
// buttons, in this order: "Marcar saída do … (+N voltas)" (the parenthesis
// only when it makes passes count) and "Descartar esta passagem".
export function acoesExtrasDoAlerta(alerta, largadaMs = null, estado = null) {
  const sugerido = alerta?.sugestao?.tipo ?? null;
  const acoes = [];
  const area = areaDoAlerta(alerta);

  if (alerta?.tipo === 'saida_presumida') {
    // The car left (store the exit) or it did not (the pass did not happen).
    if (decisaoConfirmarSaida(alerta, estado)) {
      acoes.push({ acao: 'confirmar_saida', rotulo: 'Confirmar saída' });
    }
    for (const passagem of passagensParaDescartar({ ...alerta, passagens: [] })) {
      acoes.push({ acao: 'descartar_passagem', passagem, rotulo: 'Descartar esta passagem' });
    }
  }

  if (area !== null) {
    if (decisaoMarcarSaida(alerta)) {
      acoes.push({ acao: 'marcar_saida', rotulo: rotuloDeMarcarSaida(area, voltasAoMarcarSaida(alerta)) });
    }
    for (const passagem of passagensParaDescartar({ ...alerta, passagens: [] })) {
      acoes.push({ acao: 'descartar_passagem', passagem, rotulo: 'Descartar esta passagem' });
    }
  }
  if (alerta?.tipo === 'volta_curta') {
    for (const passagem of passagensParaDescartar(alerta)) {
      acoes.push({ acao: 'descartar_passagem', passagem, rotulo: rotuloDeDescartar(passagem, largadaMs) });
    }
  }
  if (alerta?.tipo === 'passagem_proxima_demais' && sugerido !== 'descartar_passagem') {
    // The held pass is the flag's own; discarding it settles the doubt the other way.
    for (const passagem of passagensParaDescartar({ ...alerta, passagens: [] })) {
      acoes.push({ acao: 'descartar_passagem', passagem, rotulo: rotuloDeDescartar(passagem, largadaMs) });
    }
  }
  if (alerta?.tipo === 'tempo_desconhecido') {
    acoes.push({ acao: 'adicionar_passagem', rotulo: 'Adicionar passagem…' });
  }
  return acoes;
}

// The question before "Descartar" on a flag.
export function perguntaAoDescartar(alerta, passagem) {
  const qual = passagem.qual === null ? 'esta passagem' : `a ${passagem.qual} passagem`;
  const registros = passagem.registros === 1 ? 'O registro dela é anulado' : `Os ${passagem.registros} registros dela são anulados de uma vez`;
  // Only a pass that counts today takes a lap with it: one of the two of a
  // short lap. A HELD pass (too close to the previous one, or typed while
  // the car is logged in the pit lane) does not count, so nothing is lost.
  // A pass counted on an assumed exit counts today as well.
  const contaHoje = alerta.tipo === 'volta_curta' || alerta.tipo === 'saida_presumida' || alerta.contagem_em_duvida === 'a_mais';
  return `Descartar ${qual} do carro ${(alerta.carros ?? []).join(', ')}? ${registros}`
    + `${contaHoje ? ' e o carro perde uma volta' : ''}. Pode ser desfeito pelo histórico.`;
}
