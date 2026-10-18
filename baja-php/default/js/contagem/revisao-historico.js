// The decision history panel: one list, newest first, with what the system
// resolved on its own merged in, and the filters over it.
// Pure functions, tested with `node --test`.
//
// The server sends the whole history (see DecisionHistory::all), so a
// filter sees every decision of the race, not only the ones loaded so far.
// At 26SE a race had 81 decisions, and the reviewers asked for exactly
// these filters: by car, by counter, by person and by type.

import { saidaPresumidaDoAlerta } from './revisao-alertas.js';
import { haQuanto } from './revisao-formato.js';
import { descreverResolucaoAutomatica, fraseDoHistorico } from './revisao-frases.js';

// The "Quem" of what the system did on its own, and of a void the counter
// made on the phone (whoever was logged in there).
export const QUEM_AUTOMATICO = 'automatico';
export const QUEM_CONTADOR = 'contador';

// The types of the "Tipo" filter, in the order offered. The last two are
// the system's own resolutions, which are not decisions (nothing is stored).
export const TIPOS_DO_HISTORICO = [
  { valor: 'confirmar', rotulo: 'Confirmação' },
  { valor: 'anular', rotulo: 'Anulação' },
  { valor: 'reatribuir', rotulo: 'Troca de carro' },
  { valor: 'usar_tempo', rotulo: 'Escolha do horário (usar o tempo)' },
  { valor: 'adicionar', rotulo: 'Passagem adicionada' },
  { valor: 'marcar_saida', rotulo: 'Saída marcada' },
  { valor: 'excluir_fonte', rotulo: 'Exclusão de fonte' },
  { valor: 'desfazer', rotulo: 'Desfazer' },
  { valor: 'repetida_ignorada', rotulo: 'Repetida ignorada (sistema)' },
  { valor: 'saida_presumida', rotulo: 'Saída presumida (sistema)' },
];

export const FILTROS_VAZIOS = Object.freeze({ carro: '', fonte: '', quem: '', tipo: '', soEmVigor: false });

// Where the timeline of a decision is: the car it is about, at the moment
// it acts, with the entries to outline. Null for a decision about no car
// (a source exclusion). `desfeita` is what an undo undid, when known.
function alvoDaDecisao(decisao, desfeita) {
  const carroDoRegistro = decisao.carro ?? decisao.carro_digitado ?? null;
  if (decisao.passagem_id !== null && decisao.passagem_id !== undefined && carroDoRegistro !== null) {
    return {
      tipo: 'carro',
      numero: carroDoRegistro,
      momentoMs: decisao.passagem_ts_ms ?? null,
      passagemIds: [decisao.passagem_id, decisao.passagem_alvo_id].filter((id) => id !== null && id !== undefined),
    };
  }
  if (decisao.tipo === 'adicionar' || decisao.tipo === 'marcar_saida') {
    // A pass a reviewer added is drawn with minus the id of its decision.
    return {
      tipo: 'carro',
      numero: decisao.equipe_numero,
      momentoMs: decisao.ts_ms ?? null,
      passagemIds: decisao.tipo === 'adicionar' ? [-decisao.id] : [],
    };
  }
  if (decisao.tipo === 'desfazer' && desfeita && desfeita.tipo !== 'desfazer') {
    // An undone addition is no longer on the timeline; its moment still is.
    const alvo = alvoDaDecisao(desfeita, null);
    return alvo && desfeita.tipo === 'adicionar' ? { ...alvo, passagemIds: [] } : alvo;
  }
  return null;
}

// The cars a row is about, for the car filter: the car typed and the car
// the entry counts for now (a reassignment moved it), or the car named by
// the decision itself. An undo is about what it undid.
function carrosDaDecisao(decisao, desfeita) {
  const carros = [decisao.carro_digitado, decisao.carro];
  if (decisao.tipo === 'reatribuir' || decisao.tipo === 'adicionar' || decisao.tipo === 'marcar_saida') {
    carros.push(decisao.equipe_numero);
  }
  if (decisao.tipo === 'desfazer' && desfeita && desfeita.tipo !== 'desfazer') {
    carros.push(...carrosDaDecisao(desfeita, null));
  }
  return [...new Set(carros.filter((carro) => carro !== null && carro !== undefined))];
}

function linhaDeDecisao(decisao, porId, contexto) {
  const desfeita = decisao.tipo === 'desfazer' ? porId.get(decisao.desfaz_decisao_id) ?? null : null;
  const quem = decisao.origem === QUEM_CONTADOR ? [QUEM_CONTADOR] : [];
  if (decisao.usuario) {
    quem.push(`usuario:${decisao.usuario}`);
  }
  return {
    chave: `decisao:${decisao.id}`,
    automatica: false,
    decisao,
    alerta: null,
    tipo: decisao.tipo,
    quandoMs: decisao.criado_em_ms ?? 0,
    quem,
    carros: carrosDaDecisao(decisao, desfeita),
    fonteId: decisao.fonte_id ?? (desfeita?.fonte_id ?? null),
    // A "usar_tempo" that joined a late entry changed how that counter's
    // entry counts too: the "Contador" filter finds it under either.
    fonteAlvoId: decisao.fonte_alvo_id ?? (desfeita?.fonte_alvo_id ?? null),
    // An undo in force has no effect of its own to show: the decision it
    // undid reads "desfeita".
    emVigor: Boolean(decisao.ativa) && decisao.tipo !== 'desfazer',
    desfeita: !decisao.ativa,
    texto: fraseDoHistorico(decisao, { ...contexto, desfeita }),
    alvo: alvoDaDecisao(decisao, desfeita),
  };
}

function linhaAutomatica(alerta, estado, contexto) {
  const area = alerta.tipo === 'saida_presumida' ? saidaPresumidaDoAlerta(alerta, estado)?.area ?? null : null;
  return {
    chave: `alerta:${alerta.id}`,
    automatica: true,
    decisao: null,
    alerta,
    tipo: alerta.tipo === 'repetida' ? 'repetida_ignorada' : alerta.tipo,
    quandoMs: alerta.ts_ms ?? 0,
    quem: [QUEM_AUTOMATICO],
    carros: [...(alerta.carros ?? [])],
    fonteId: alerta.fonte_id ?? null,
    fonteAlvoId: null,
    // What the system did stands for as long as the flag is there: it is
    // worked out afresh at every calculation.
    emVigor: true,
    desfeita: false,
    texto: descreverResolucaoAutomatica(alerta, { nomeDaFonte: contexto.nomeDaFonte, area }),
    alvo: { tipo: 'alerta', id: alerta.id },
  };
}

// Is this flag something the system resolved on its own? A repeat it
// voided ("Resolvidos automaticamente"), or an exit it assumed: that one is
// listed among the open flags, for a reviewer to confirm, but the count
// already went on as if the car had left.
function ehResolucaoAutomatica(alerta) {
  return Boolean(alerta.auto_resolvido) || alerta.tipo === 'saida_presumida';
}

// Every line of the history panel, newest first: the decisions, and the
// system's own resolutions among them as read-only lines. Each line:
//   {chave, automatica, decisao, alerta, tipo, quandoMs, quem: string[],
//    carros: number[], fonteId, fonteAlvoId, emVigor, desfeita, texto, alvo}
// `alvo` is what a click opens: {tipo: 'carro', numero, momentoMs,
// passagemIds} or {tipo: 'alerta', id}; null when there is nothing to open.
//
// A decision is placed by when it was made, a resolution by the moment of
// its entry, which is when the system first saw it.
export function linhasDoHistorico(estado) {
  const historico = estado?.historico ?? [];
  // The decisions in force that have no entry come in their own list too,
  // whole. They are merged in always: a history cut short at the server's
  // limit, or a state stored before the whole history was sent (only the
  // newest 50), would otherwise lose the older ones and their "Desfazer".
  const presentes = new Set(historico.map((decisao) => decisao.id));
  const decisoes = [...historico, ...(estado?.historico_em_vigor ?? []).filter((decisao) => !presentes.has(decisao.id))];

  const fontes = new Map((estado?.fontes ?? []).map((fonte) => [fonte.id, fonte.nome]));
  const contexto = {
    nomeDaFonte: (id) => fontes.get(id) ?? null,
    largadaMs: estado?.corrida?.largada_ms ?? null,
  };
  const porId = new Map(decisoes.map((decisao) => [decisao.id, decisao]));

  const automaticas = new Map();
  for (const alerta of [...(estado?.alertas_resolvidos ?? []), ...(estado?.alertas_abertos ?? [])]) {
    if (ehResolucaoAutomatica(alerta) && !automaticas.has(alerta.id)) {
      automaticas.set(alerta.id, alerta);
    }
  }

  const linhas = [
    ...decisoes.map((decisao) => linhaDeDecisao(decisao, porId, contexto)),
    ...[...automaticas.values()].map((alerta) => linhaAutomatica(alerta, estado, contexto)),
  ];
  return linhas.sort((a, b) => b.quandoMs - a.quandoMs
    || (b.decisao?.id ?? 0) - (a.decisao?.id ?? 0)
    || (a.chave < b.chave ? -1 : a.chave > b.chave ? 1 : 0));
}

// The filters as typed or stored, made safe: anything unknown or of the
// wrong kind falls back to "no filter".
export function normalizarFiltros(filtros) {
  const texto = (valor) => (typeof valor === 'string' || typeof valor === 'number' ? String(valor).trim() : '');
  const carro = texto(filtros?.carro);
  return {
    carro: /^\d{1,6}$/.test(carro) ? carro : '',
    fonte: texto(filtros?.fonte),
    quem: texto(filtros?.quem),
    tipo: texto(filtros?.tipo),
    soEmVigor: filtros?.soEmVigor === true,
  };
}

export function temFiltro(filtros) {
  const f = normalizarFiltros(filtros);
  return f.carro !== '' || f.fonte !== '' || f.quem !== '' || f.tipo !== '' || f.soEmVigor;
}

// The lines that pass every filter set (they combine: AND).
export function filtrarHistorico(linhas, filtros) {
  const f = normalizarFiltros(filtros);
  const carro = f.carro === '' ? null : Number(f.carro);
  return linhas.filter((linha) => (carro === null || linha.carros.includes(carro))
    && (f.fonte === '' || String(linha.fonteId ?? '') === f.fonte || String(linha.fonteAlvoId ?? '') === f.fonte)
    && (f.quem === '' || linha.quem.includes(f.quem))
    && (f.tipo === '' || linha.tipo === f.tipo)
    && (!f.soEmVigor || linha.emVigor));
}

// The choices of the three lists, from what the state has:
//   {fontes, quem, tipos}, each [{valor, rotulo}], without the "todos".
// A value the reviewer chose that is no longer there (stored from another
// race, a counter renamed) is kept as a choice, or the list could not show it.
export function opcoesDosFiltros(estado, linhas, filtros = FILTROS_VAZIOS) {
  const f = normalizarFiltros(filtros);
  const comEscolhido = (opcoes, valor) => (valor === '' || opcoes.some((opcao) => opcao.valor === valor)
    ? opcoes
    : [...opcoes, { valor, rotulo: `${valor} (não está nesta prova)` }]);

  const fontes = (estado?.fontes ?? []).map((fonte) => ({ valor: String(fonte.id), rotulo: fonte.nome }));
  // People are the reviewers. Whoever was logged in on a counter's phone
  // is under "contador (no aparelho)", not a person of their own.
  const usuarios = [...new Set(linhas
    .filter((linha) => linha.decisao && linha.decisao.origem !== QUEM_CONTADOR)
    .flatMap((linha) => linha.quem)
    .filter((quem) => quem.startsWith('usuario:'))
    .map((quem) => quem.slice('usuario:'.length)))]
    .sort((a, b) => a.localeCompare(b, 'pt-BR'));
  const quem = [
    ...usuarios.map((usuario) => ({ valor: `usuario:${usuario}`, rotulo: usuario })),
    { valor: QUEM_CONTADOR, rotulo: 'contador (no aparelho)' },
    { valor: QUEM_AUTOMATICO, rotulo: 'automático' },
  ];
  return {
    fontes: comEscolhido(fontes, f.fonte),
    quem: comEscolhido(quem, f.quem),
    tipos: comEscolhido(TIPOS_DO_HISTORICO, f.tipo),
  };
}

// The "Quando" of a line: "agora", "há 15 s", "há 3 min", in the status
// bar's steps (never "há agora").
export const idadeDaLinha = (estado, linha) => haQuanto((estado?.servidor_agora_ms ?? 0) - linha.quandoMs);

// Everything the history panel shows, as one string: the panel is redrawn
// only when this changes. Not the state's version, which changes with every
// recalculation (every few seconds during a race), nor the cars' live
// status: rebuilding about 2,700 nodes that often cost a tablet a tenth of
// a second each time, and replaced "Desfazer" under the reviewer's pointer.
// The ages of the last minute's lines move in the bar's 15 s steps; older
// ones once a minute.
export function chaveDoHistorico(estado, linhas = linhasDoHistorico(estado)) {
  const agoraMs = estado?.servidor_agora_ms ?? 0;
  return JSON.stringify([
    linhas.map((linha) => [
      linha.chave, linha.texto, linha.tipo, linha.emVigor, linha.desfeita, linha.decisao?.pode_desfazer ?? null,
      linha.alvo, linha.quem, linha.carros, linha.fonteId, linha.fonteAlvoId,
      agoraMs - linha.quandoMs < 60000 ? idadeDaLinha(estado, linha) : null,
    ]),
    Math.floor(agoraMs / 60000),
    (estado?.fontes ?? []).map((fonte) => [fonte.id, fonte.nome]),
    avisoDeHistoricoIncompleto(estado),
  ]);
}

// "12 de 81 decisões" while a filter is set, "81 decisões" without. The
// system's lines are not decisions and are counted apart: "81 decisões
// + 2 do sistema" (the shown ones while filtering, left out when none).
//   mostradas, linhas: lines of linhasDoHistorico
export function resumoDoHistorico(mostradas, linhas, filtros) {
  const decisoes = (lista) => lista.filter((linha) => !linha.automatica).length;
  const total = decisoes(linhas);
  const nome = total === 1 ? 'decisão' : 'decisões';
  const filtrando = temFiltro(filtros);
  const doSistema = (filtrando ? mostradas : linhas).length - decisoes(filtrando ? mostradas : linhas);
  return (filtrando ? `${decisoes(mostradas)} de ${total} ${nome}` : `${total} ${nome}`)
    + (doSistema > 0 ? ` + ${doSistema} do sistema` : '');
}

// The sentence in place of the list when it is empty.
export function historicoVazio(total) {
  return total === 0
    ? 'Nenhuma decisão ainda.'
    : 'Nenhuma decisão com esses filtros. Use "Limpar filtros" para ver todas.';
}

// The sentence under the list when the server left the oldest out; null
// when the list is whole.
// A state stored before the whole history was sent has no
// "historico_completo" but says "historico_ha_mais" when it was paged.
export function avisoDeHistoricoIncompleto(estado) {
  const completo = estado?.historico_completo ?? estado?.historico_ha_mais !== true;
  if (completo !== false) {
    return null;
  }
  return `Só as ${(estado.historico ?? []).length} decisões mais recentes estão aqui `
    + '(e as exclusões de fonte, passagens adicionadas e saídas marcadas ainda em vigor). '
    + 'Os filtros não alcançam as mais antigas.';
}

// ---- remembered filters --------------------------------------------------

const chaveGuardada = (prova) => `contagem-revisao-historico:${prova}`;

// The filters this browser last used on this prova. Storage may be missing
// or refuse (a private window): the filters then start empty.
export function lerFiltrosGuardados(armazenamento, prova) {
  try {
    const texto = armazenamento?.getItem(chaveGuardada(prova));
    return texto ? normalizarFiltros(JSON.parse(texto)) : { ...FILTROS_VAZIOS };
  } catch (erro) {
    return { ...FILTROS_VAZIOS };
  }
}

export function guardarFiltros(armazenamento, prova, filtros) {
  try {
    if (temFiltro(filtros)) {
      armazenamento?.setItem(chaveGuardada(prova), JSON.stringify(normalizarFiltros(filtros)));
    } else {
      armazenamento?.removeItem(chaveGuardada(prova));
    }
  } catch (erro) {
    // Not remembered: they still apply until the page is reloaded.
  }
}
