// Passes recorded without a number ("??": a counter or a camera saw a car
// and could not read it) and a timing sensor's marks, as the review screen
// shows them: the look and the tooltip of their chips, the cars a "??" may
// have been and what giving it one does, the system's own assignments in
// the history, and the sources' numbers. Pure functions, tested with
// `node --test`; drawn by revisao-raias.js, revisao-detalhe.js and
// revisao-tabela.js.
//
// Nothing here shows in a race with no "??" and no sensor: the server then
// sends no `ilegivel` entry, no `candidatos`, no `ilegiveis` and no
// `sensor` statistics.

import { duracao } from './revisao-formato.js';
import { voltaImpossivelmenteCurta } from './revisao-alertas.js';

export const ehIlegivel = (entrada) => entrada?.ilegivel === true;

export const ehRaiaDeSensor = (raia) => raia?.tipo === 'sensor';

export function fonteEhSensor(estado, fonteId) {
  return (estado?.fontes ?? []).some((fonte) => fonte.id === fonteId && fonte.tipo === 'sensor');
}

// A timing sensor's mark. Matched, it carries `marca_do_sensor` and the
// pass it times in `grupo`; unmatched, it is out of use ('marca_do_sensor').
// `naRaiaDoSensor` covers the one a reviewer gave a car: it is then an
// ordinary entry of that car's pass, still in the sensor's lane.
export function ehMarcaDoSensor(entrada, naRaiaDoSensor = false) {
  return naRaiaDoSensor || entrada?.marca_do_sensor === true || entrada?.motivo_fora_de_uso === 'marca_do_sensor';
}

// The newest "reatribuir" in force on an entry: the car somebody gave it.
// A counter may fix a "??" several times on the phone; the newest counts.
function trocaEmVigor(entrada) {
  const trocas = (entrada?.decisoes ?? []).filter((decisao) => decisao.tipo === 'reatribuir');
  return trocas.length === 0 ? null : trocas.reduce((a, b) => (b.id > a.id ? b : a));
}

const temCarro = (entrada) => entrada?.carro !== null && entrada?.carro !== undefined;

// Where a "??" stands:
//   'pendente'    nobody knows which car yet (flagged, or waiting for the
//                 other counters to sync past it)
//   'automatico'  the engine gave it a car by itself (`atribuida_automaticamente`)
//   'contador'    its own counter said which car, on the phone
//   'revisor'     a reviewer said which car
//   'anulado'     voided: not a crossing
// Null for an entry typed with a number.
export function situacaoDoIlegivel(entrada) {
  if (!ehIlegivel(entrada)) {
    return null;
  }
  if (entrada.anulada) {
    return 'anulado';
  }
  if (entrada.atribuida_automaticamente) {
    return 'automatico';
  }
  if (!temCarro(entrada)) {
    return 'pendente';
  }
  return trocaEmVigor(entrada)?.origem === 'contador' ? 'contador' : 'revisor';
}

// What a chip says: the car, or "??" while it has none. A sensor's mark is
// a tick with no text, unless a reviewer gave it a car.
export function textoDoChip(entrada, naRaiaDoSensor = false) {
  if (ehMarcaDoSensor(entrada, naRaiaDoSensor)) {
    return temCarro(entrada) && entrada.reatribuida ? String(entrada.carro) : '';
  }
  return temCarro(entrada) ? String(entrada.carro) : '??';
}

// The small marker in a chip's corner: who gave the "??" its car.
//   {texto, classe, titulo} or null
export function marcadorDoChip(entrada) {
  switch (situacaoDoIlegivel(entrada)) {
    case 'automatico':
      return { texto: 'auto', classe: 'marcador marcador-auto', titulo: 'atribuído automaticamente' };
    case 'contador':
      return { texto: 'C', classe: 'marcador marcador-contador', titulo: 'o contador informou o número no aparelho' };
    case 'revisor':
      return { texto: 'R', classe: 'marcador marcador-revisor', titulo: 'a revisão informou o número' };
    default:
      return null;
  }
}

// The classes a "??" or a mark adds to its chip (see classesDoChip).
export function classesDoIlegivel(entrada, naRaiaDoSensor = false) {
  if (ehMarcaDoSensor(entrada, naRaiaDoSensor)) {
    const classes = ['marca-sensor'];
    if (entrada.anulada) {
      classes.push('anulada');
    } else if (entrada.grupo && entrada.marca_do_sensor) {
      classes.push('com-passagem');
    } else if (temCarro(entrada) && entrada.reatribuida) {
      classes.push('atribuida');
    } else {
      classes.push('sem-passagem');
    }
    return classes;
  }
  const situacao = situacaoDoIlegivel(entrada);
  return situacao === null || situacao === 'anulado' ? [] : ['ilegivel', `ilegivel-${situacao}`];
}

// ---- words ------------------------------------------------------------------

// "0,4 s": tenths of a second, written the Brazilian way.
export function segundosComVirgula(ms) {
  return `${(Math.abs(ms) / 1000).toFixed(1).replace('.', ',')} s`;
}

// "Contagem 1", "Contagem 1 e 2", "Contagem 1, 2 e 3", "Ana e Contagem 2":
// names that differ only in their last word are said once.
export function listaDeNomes(nomes) {
  const unicos = [...new Set(nomes.filter(Boolean))];
  if (unicos.length <= 1) {
    return unicos[0] ?? 'outra contagem';
  }
  const partes = unicos.map((nome) => /^(.*\s)(\S+)$/.exec(nome));
  const prefixo = partes[0]?.[1];
  const juntos = partes.every((parte) => parte && parte[1] === prefixo)
    ? [`${prefixo}${partes[0][2]}`, ...partes.slice(1).map((parte) => parte[2])]
    : unicos;
  return `${juntos.slice(0, -1).join(', ')} e ${juntos[juntos.length - 1]}`;
}

// "Contagem 1 e 2 leram o carro 7 a 0,4 s"
function fraseDaLeitura(nomes, carro, desvioMs) {
  const quem = listaDeNomes(nomes);
  const verbo = new Set(nomes.filter(Boolean)).size > 1 ? 'leram' : 'leu';
  return `${quem} ${verbo} o carro ${carro}${desvioMs === null || desvioMs === undefined ? '' : ` a ${segundosComVirgula(desvioMs)}`}`;
}

// The engine's assignments, from the flags it resolved by itself
// ("numero_atribuido"), by the id of the "??": what a chip's tooltip and
// the panel say about why it was given that car.
export function atribuicoesAutomaticas(estado) {
  const porEntrada = new Map();
  for (const alerta of [...(estado?.alertas_resolvidos ?? []), ...(estado?.alertas_abertos ?? [])]) {
    if (alerta.tipo === 'numero_atribuido' && alerta.atribuicao && (alerta.passagem_ids ?? []).length > 0) {
      porEntrada.set(alerta.passagem_ids[0], alerta.atribuicao);
    }
  }
  return porEntrada;
}

// "atribuído automaticamente ao carro 7: Contagem 1 e 2 leram o carro 7 a 0,4 s"
export function fraseDaAtribuicao(atribuicao, nomeDaFonte = () => null) {
  const nomes = (atribuicao.fonte_ids ?? []).map((id) => nomeDaFonte(id) ?? `fonte #${id}`);
  return `atribuído automaticamente ao carro ${atribuicao.carro}: ${fraseDaLeitura(nomes, atribuicao.carro, atribuicao.desvio_ms ?? null)}`;
}

// The parts of a chip's tooltip about its missing number, for
// descricaoDoChip. `contexto`: {atribuicoes (Map), nomeDaFonte(id), naRaiaDoSensor}.
// Null for an entry that is neither a "??" nor a mark.
export function descricaoDoIlegivel(entrada, contexto = {}) {
  const naRaiaDoSensor = Boolean(contexto.naRaiaDoSensor);
  if (ehMarcaDoSensor(entrada, naRaiaDoSensor)) {
    const partes = [`marca do sensor #${entrada.id}`];
    if (entrada.anulada) {
      return partes;
    }
    if (entrada.grupo && entrada.marca_do_sensor) {
      partes.push('marca do sensor: tempo desta passagem');
    } else if (temCarro(entrada) && entrada.reatribuida) {
      partes.push(`atribuída ao carro ${entrada.carro} pela revisão: conta na palavra do revisor, no horário do sensor`);
    } else {
      partes.push('marca sem passagem: nenhuma passagem dos contadores ligada a ela; não conta');
    }
    return partes;
  }
  const situacao = situacaoDoIlegivel(entrada);
  if (situacao === null) {
    return null;
  }
  const partes = [`passagem sem número ("??") #${entrada.id}`];
  if (situacao === 'automatico') {
    const atribuicao = contexto.atribuicoes?.get(entrada.id) ?? null;
    partes.push(atribuicao ? fraseDaAtribuicao(atribuicao, contexto.nomeDaFonte) : `atribuído automaticamente ao carro ${entrada.carro}`);
  } else if (situacao === 'contador') {
    const troca = trocaEmVigor(entrada);
    partes.push(`o contador informou no aparelho: carro ${entrada.carro}${troca?.usuario ? ` (${troca.usuario})` : ''}`);
  } else if (situacao === 'revisor') {
    const troca = trocaEmVigor(entrada);
    partes.push(`atribuído ao carro ${entrada.carro} pela revisão${troca?.usuario ? ` (${troca.usuario})` : ''}`);
  } else if (situacao === 'pendente') {
    partes.push('ainda sem carro: não conta até alguém dizer qual foi');
  }
  return partes;
}

// The tooltip part of a counter's chip whose pass is timed by a sensor's mark.
export const DICA_TEMPO_DO_SENSOR = 'horário desta passagem: a marca do sensor (preciso)';

// What the panel calls an entry: "Carro 7", "Sem número (??)", "Marca do sensor".
export function rotuloDaEntrada(entrada, naRaiaDoSensor = false) {
  if (ehMarcaDoSensor(entrada, naRaiaDoSensor)) {
    return temCarro(entrada) && entrada.reatribuida ? `Marca do sensor · carro ${entrada.carro}` : 'Marca do sensor';
  }
  if (situacaoDoIlegivel(entrada) === 'pendente' || (ehIlegivel(entrada) && !temCarro(entrada))) {
    return 'Sem número (??)';
  }
  return ehIlegivel(entrada) ? `Carro ${entrada.carro} (registrado como ??)` : `Carro ${entrada.carro}`;
}

// ---- which car it may have been -------------------------------------------------

// How far apart a "??" and another counter's reading of a car may be, as
// the engine reads it (`dispersao_alerta_ms`).
export const toleranciaDoIlegivel = (estado) => Number(estado?.configuracoes?.dispersao_alerta_ms) || 4000;

// What the OTHER sources read near an entry, on the timeline on screen,
// per car, closest first: [{carro, desvioMs, fonteIds, entradas}].
// `desvioMs` is the closest reading of that car. Entries out of use, voided
// or of unknown time, observers, the sensor and the reviewer's lane are no
// readings.
export function leiturasPerto(janela, entrada, toleranciaMs) {
  if (!janela || !entrada || typeof entrada.ts_ms !== 'number') {
    return [];
  }
  const porCarro = new Map();
  for (const raia of janela.raias ?? []) {
    if (raia.fonte_id === entrada.fonte_id || ehRaiaDeSensor(raia) || raia.tipo === 'revisor' || raia.papel === 'observadora') {
      continue;
    }
    for (const outra of raia.entradas ?? []) {
      if (!temCarro(outra) || outra.anulada || outra.anulada_auto || outra.fora_de_uso || outra.qualidade_tempo === 'desconhecido') {
        continue;
      }
      const desvioMs = Math.abs(outra.ts_ms - entrada.ts_ms);
      if (desvioMs > toleranciaMs) {
        continue;
      }
      const leitura = porCarro.get(outra.carro) ?? { carro: outra.carro, desvioMs, fonteIds: [], entradas: [] };
      leitura.desvioMs = Math.min(leitura.desvioMs, desvioMs);
      if (!leitura.fonteIds.includes(raia.fonte_id)) {
        leitura.fonteIds.push(raia.fonte_id);
      }
      leitura.entradas.push(outra);
      porCarro.set(outra.carro, leitura);
    }
  }
  for (const leitura of porCarro.values()) {
    leitura.entradas.sort((a, b) => Math.abs(a.ts_ms - entrada.ts_ms) - Math.abs(b.ts_ms - entrada.ts_ms));
  }
  return [...porCarro.values()].sort((a, b) => a.desvioMs - b.desvioMs || a.carro - b.carro);
}

// The entry the "??" flag is about, from the timeline (null while it is loading).
function entradaDoAlerta(alerta, janela) {
  const id = (alerta?.passagem_ids ?? [])[0];
  for (const raia of janela?.raias ?? []) {
    for (const entrada of raia.entradas ?? []) {
      if (entrada.id === id) {
        return entrada;
      }
    }
  }
  return null;
}

const NOME_DA_FONTE = (estado) => (id) => (estado?.fontes ?? []).find((fonte) => fonte.id === id)?.nome ?? null;

export const MAXIMO_DE_CANDIDATOS = 6;

// The buttons of a "numero_ilegivel" flag, most likely first, keys 1 to 6:
//   [{carro, motivo, tecla, rotulo, efeito}]
// `rotulo`: "Carro 7 — Contagem 1 e 2 leram o carro 7 a 0,4 s",
// "Carro 12 — esperado por volta deste horário", "Carro 3 — ficaria sem
// esta volta", with "(+1 volta)" when giving it that car adds a pass.
// `efeito` is efeitoDeAtribuir(). Who read the car is taken from the
// timeline on screen; while it loads, "outra contagem".
export function candidatosDoAlerta(alerta, janela, estado) {
  if (alerta?.tipo !== 'numero_ilegivel') {
    return [];
  }
  const nomeDaFonte = NOME_DA_FONTE(estado);
  const entrada = entradaDoAlerta(alerta, janela) ?? { id: alerta.passagem_ids?.[0], fonte_id: alerta.fonte_id, ts_ms: alerta.ts_ms };
  const sensor = fonteEhSensor(estado, alerta.fonte_id);
  const leituras = leiturasPerto(janela, entrada, toleranciaDoIlegivel(estado));
  return (alerta.candidatos ?? []).slice(0, MAXIMO_DE_CANDIDATOS).map((candidato, indice) => {
    let detalhe;
    if (candidato.motivo === 'outros_contadores') {
      const leitura = leituras.find((umaLeitura) => umaLeitura.carro === candidato.carro);
      const desvioMs = typeof candidato.ts_ms === 'number' && typeof alerta.ts_ms === 'number'
        ? alerta.ts_ms - candidato.ts_ms
        : leitura?.desvioMs ?? null;
      detalhe = fraseDaLeitura((leitura?.fonteIds ?? []).map(nomeDaFonte), candidato.carro, desvioMs);
    } else if (candidato.motivo === 'carro_esperado') {
      detalhe = 'esperado por volta deste horário';
    } else {
      detalhe = 'ficaria sem esta volta';
    }
    const efeito = efeitoDeAtribuir(entrada, candidato.carro, { janela, estado, sensor });
    return {
      carro: candidato.carro,
      motivo: candidato.motivo,
      tecla: String(indice + 1),
      rotulo: `Carro ${candidato.carro} — ${detalhe}${efeito.voltas > 0 ? ' (+1 volta)' : ''}`,
      efeito,
    };
  });
}

// "Não é o carro 7": the other cars a "??" the engine assigned may have
// been, from the timeline on screen (the other sources' readings near it),
// keys 1 to 6. Same shape as candidatosDoAlerta().
export function candidatosParaTrocar(entrada, janela, estado) {
  const nomeDaFonte = NOME_DA_FONTE(estado);
  return leiturasPerto(janela, entrada, toleranciaDoIlegivel(estado))
    .filter((leitura) => leitura.carro !== entrada.carro)
    .slice(0, MAXIMO_DE_CANDIDATOS)
    .map((leitura, indice) => {
      const efeito = efeitoDeAtribuir(entrada, leitura.carro, { janela, estado, sensor: false });
      return {
        carro: leitura.carro,
        motivo: 'outros_contadores',
        tecla: String(indice + 1),
        rotulo: `Carro ${leitura.carro} — ${fraseDaLeitura(leitura.fonteIds.map(nomeDaFonte), leitura.carro, leitura.desvioMs)}`
          + `${efeito.voltas > 0 ? ' (+1 volta)' : ''}`,
        efeito,
      };
    });
}

function perguntaDeVoltaCurtaAoAtribuir(voltaMs, estado, carro) {
  const linha = (estado?.carros ?? []).find((outra) => outra.carro === carro);
  return voltaImpossivelmenteCurta(voltaMs ?? null, estado?.configuracoes, linha?.volta_esperada_ms ?? null)
    ? ` Além disso, ela criaria uma volta de ${duracao(voltaMs)}, curta demais para ser real.`
    : '';
}

// What giving the "??" (or the mark) `entrada` the car `carro` does to the
// count, and what is asked first. It stores a "reatribuir", as "Trocar
// para o carro" on a chip does:
//   {voltas: 0 | 1, pergunta: string | null, nota: string}
// - another source's reading of that car near it is in a pass that counts:
//   the "??" joins it, nothing changes, nothing is asked;
// - a sensor's mark: a reviewer's word makes it a pass of its own, timed by
//   the sensor (+1 volta), asked, with the short-lap question when the
//   car's last pass is that close before it;
// - the only reading of that car near it is one counter's, waiting alone:
//   with the "??" two sources agree and the pass may start to count
//   (+1 volta), asked, with the short lap that pass would make;
// - nobody read that car near it: the "??" becomes a lone entry of the car,
//   which does not count by itself.
// `contexto`: {janela, estado, sensor}.
export function efeitoDeAtribuir(entrada, carro, { janela = null, estado = null, sensor = false } = {}) {
  const leitura = leiturasPerto(janela, entrada, toleranciaDoIlegivel(estado)).find((umaLeitura) => umaLeitura.carro === carro);
  const grupo = leitura?.entradas.map((outra) => outra.grupo).find(Boolean) ?? null;
  const nomeDaFonte = NOME_DA_FONTE(estado);
  if (grupo?.contado) {
    return { voltas: 0, pergunta: null, nota: `junta-se à passagem do carro ${carro}, que já conta` };
  }
  if (sensor) {
    const linha = (estado?.carros ?? []).find((outra) => outra.carro === carro);
    const anteriorMs = linha?.ultima_passagem_ms ?? null;
    const voltaMs = anteriorMs !== null && typeof entrada?.ts_ms === 'number' && anteriorMs < entrada.ts_ms ? entrada.ts_ms - anteriorMs : null;
    return {
      voltas: 1,
      pergunta: `Atribuir esta marca do sensor ao carro ${carro}? Nenhum contador registrou esta passagem: ela passa a CONTAR `
        + `na palavra do revisor, no horário do sensor (+1 volta).${perguntaDeVoltaCurtaAoAtribuir(voltaMs, estado, carro)} `
        + 'Pode ser desfeito pelo histórico. Atribuir?',
      nota: 'passa a contar na palavra do revisor',
    };
  }
  if (grupo && grupo.soma_ao_confirmar && (grupo.passagem_ids ?? []).length === 1) {
    const quem = listaDeNomes(leitura.fonteIds.map((id) => nomeDaFonte(id) ?? `fonte #${id}`));
    return {
      voltas: 1,
      pergunta: `Atribuir este "??" ao carro ${carro}? Só ${quem} registrou o carro ${carro} aqui; com este registro são dois, `
        + `e a passagem pode passar a CONTAR (+1 volta).${perguntaDeVoltaCurtaAoAtribuir(grupo.volta_resultante_ms, estado, carro)} `
        + 'Pode ser desfeito pelo histórico. Atribuir?',
      nota: 'com este registro, dois contadores concordam',
    };
  }
  return {
    voltas: 0,
    pergunta: null,
    nota: grupo ? `junta-se aos registros do carro ${carro}` : `fica como registro solitário do carro ${carro}: sozinho, ainda não conta`,
  };
}

// The key 1 to 6 on a "??": the index of the candidate it presses, or null.
export function indiceDeCandidato(tecla, candidatos) {
  const indice = Number(tecla) - 1;
  return /^[1-6]$/.test(String(tecla)) && candidatos[indice] ? indice : null;
}

// ---- the flags and the history ----------------------------------------------

// The name of a flag about an unread pass, for the queue and the panel.
// Null for any other flag.
export function nomeDoAlertaIlegivel(alerta, estado) {
  if (alerta?.tipo === 'numero_ilegivel') {
    return fonteEhSensor(estado, alerta.fonte_id) ? 'Marca do sensor sem passagem' : 'Passagem sem número (??)';
  }
  return alerta?.tipo === 'numero_atribuido' ? 'Número atribuído automaticamente' : null;
}

// What the queue says about the cars of such a flag: "carro ?? (7, 12 ou 3)",
// "carro 7 (era ??)". Null for any other flag.
export function carrosDoAlertaIlegivel(alerta) {
  const carros = alerta?.carros ?? [];
  if (alerta?.tipo === 'numero_ilegivel') {
    if (carros.length === 0) {
      return 'carro ??';
    }
    return `carro ?? (${carros.length === 1 ? carros[0] : `${carros.slice(0, -1).join(', ')} ou ${carros[carros.length - 1]}`})`;
  }
  return alerta?.tipo === 'numero_atribuido' ? `carro ${carros[0]} (era ??)` : null;
}

// The history's line for an assignment the engine made by itself:
// "Sistema: atribuiu o ?? #123 de Contagem 2 ao carro 7".
export function linhaDaAtribuicao(alerta, nomeDaFonte = () => null) {
  const id = (alerta.passagem_ids ?? [])[0];
  const carro = alerta.atribuicao?.carro ?? (alerta.carros ?? [])[0];
  const fonte = nomeDaFonte(alerta.fonte_id);
  return `Sistema: atribuiu o ?? #${id}${fonte ? ` de ${fonte}` : ''} ao carro ${carro}`;
}

// ---- the sources' numbers ------------------------------------------------------

// "ilegíveis: 12 (9 atribuídos)"; null for a source with none.
export function textoDosIlegiveis(stats) {
  const ilegiveis = stats?.ilegiveis ?? 0;
  if (!ilegiveis) {
    return null;
  }
  const atribuidos = stats.ilegiveis_atribuidas ?? 0;
  return `ilegíveis: ${ilegiveis} (${atribuidos} ${atribuidos === 1 ? 'atribuído' : 'atribuídos'})`;
}

// A timing sensor's own block, as [label, value, tooltip] lines. Null with
// none. The labels are short (a source's block is narrow); what each means
// is in its tooltip.
export function linhasDoSensor(sensor) {
  if (!sensor) {
    return null;
  }
  const desvio = sensor.desvio_mediano_ms === null || sensor.desvio_mediano_ms === undefined
    ? '—'
    : `${sensor.desvio_mediano_ms > 0 ? '+' : sensor.desvio_mediano_ms < 0 ? '−' : ''}${segundosComVirgula(sensor.desvio_mediano_ms)}`
      + ` (90% até ${segundosComVirgula(sensor.desvio_p90_ms ?? 0)})`;
  return [
    ['marcas', String(sensor.marcas ?? 0), 'marcas do sensor desde a largada'],
    ['atribuídas', String(sensor.atribuidas ?? 0), 'marcas que dão o horário de uma passagem dos contadores'],
    ['ambíguas', String(sensor.ambiguas ?? 0), 'perto de passagens dos contadores, mas sem par seguro: não dão horário a nenhuma (não é alerta)'],
    ['sem passagem', String(sensor.sem_passagem ?? 0), 'nenhuma passagem dos contadores por perto: em geral ruído'],
    ['passagens sem marca', String(sensor.passagens_sem_marca ?? 0), 'passagens oficiais que o sensor não marcou enquanto estava sincronizado'],
    ['desvio', desvio, 'diferença mediana entre a marca e a passagem dos contadores que ela marca, e o tamanho de 90% das diferenças'],
  ];
}

// The words of the status bar chip's tooltip: "ilegíveis: 3 (2 atribuídos)",
// "sensor: 412 marcas, 398 atribuídas". Null when there is nothing to say.
export function dicaDosIlegiveisDaFonte(fonte) {
  const sensor = fonte?.stats?.sensor;
  if (sensor) {
    return `sensor: ${sensor.marcas ?? 0} marcas, ${sensor.atribuidas ?? 0} atribuídas`;
  }
  return textoDosIlegiveis(fonte?.stats);
}

// May the "??" (or mark) be given this car? The sentence to show, or null
// when it is fine. The server checks again. Giving a "??" the car the
// engine chose is fine: it makes it a person's word.
export function problemaAoAtribuir(carro, entrada, estado) {
  if (!Number.isInteger(carro) || carro <= 0) {
    return 'Informe o número do carro que passou.';
  }
  if (entrada && temCarro(entrada) && entrada.carro === carro && !entrada.atribuida_automaticamente) {
    return `Este registro já é do carro ${carro}.`;
  }
  if ((estado?.carros_validos ?? []).some((linha) => linha.numero === carro)) {
    return null;
  }
  const naTabela = (estado?.carros ?? []).find((linha) => linha.carro === carro);
  return naTabela?.presente
    ? `O carro ${carro} não está classificado para esta prova: uma passagem dele não contaria.`
    : `O carro ${carro} não é de uma equipe presente neste evento.`;
}
