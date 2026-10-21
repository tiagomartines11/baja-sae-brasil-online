// The status bar at the top and the flag queue on the left.

import { el, substituir } from './revisao-dom.js';
import {
  NOMES_DE_ALERTA, NOMES_DE_ESTADO, haExato, haQuanto, listaDeCarros, relogio, relogioDaCorrida,
} from './revisao-formato.js';
import { exclusoesAbertas } from './revisao-decisoes.js';
import {
  contagemEmDuvida, dispositivosDaFonte, estadoDaFonte, ordenarFila, quandoDoAlerta, resumoDeAlertas, resumoDosDispositivos,
} from './revisao-fila.js';
import { fraseDeDuvida } from './revisao-alertas.js';
import { avisosDaPontuacao, recalculoDosPontos } from './revisao-frases.js';
import { horarioOficial, idadeDoCalculo } from './revisao-relogio.js';
import { ehObservadora } from './revisao-observacao.js';
import { carrosDoAlertaIlegivel, dicaDosIlegiveisDaFonte, nomeDoAlertaIlegivel } from './revisao-ilegivel.js';

// ---- what the bar says (pure, tested) --------------------------------------
//
// The bar is redrawn every second. Every pill has a text of fixed shape: a
// label, and where there is an age, a slot of fixed width ("agora",
// "há 30 s") that moves in steps of 15 s (idadeEmPassos). The exact numbers
// are in the titles. So no pill grows or shrinks from one second to the
// next, and on a wide screen the bar keeps its height (its two lines never
// wrap; see the style sheet).

// The chip of one source: its name, whether it is in contact, and anything
// about its devices or an open-ended exclusion a reviewer must not miss.
//   {classe, nome, principal, texto, idade, titulo, dispositivos}
// `dispositivos`: "· 2 disp." when some of its phones are not simply
// working, or null; which ones is in the title.
export function descreverChip(fonte, estado, agoraServidorMs, excluidas = new Set()) {
  const situacao = estadoDaFonte(fonte, agoraServidorMs, estado?.corrida?.largada_ms ?? null);
  const dosDispositivos = dispositivosDaFonte(estado, fonte.id);
  const resumo = resumoDosDispositivos(dosDispositivos);
  const comProblema = dosDispositivos.filter((dispositivo) => dispositivo.situacao !== 'ativo').length;
  const excluida = excluidas.has(fonte.id);
  // An observer is compared with the count and never counts: drawn apart,
  // and never a "missing" counter.
  const classes = ['chip-fonte', situacao.classe, ehObservadora(fonte) ? 'observadora' : '', excluida ? 'excluida' : ''];
  return {
    classe: classes.filter(Boolean).join(' '),
    nome: fonte.nome,
    principal: Boolean(fonte.principal),
    texto: excluida ? 'EXCLUÍDA' : situacao.texto,
    idade: excluida ? null : situacao.idade,
    titulo: [
      excluida ? 'EXCLUÍDA até segunda ordem: o que ela registra não conta' : null,
      ehObservadora(fonte) ? 'observadora: comparada com a contagem, nunca conta' : null,
      situacao.titulo,
      resumo ? `dispositivos: ${resumo}` : null,
      // In the tooltip only: the chip's text keeps its fixed shape.
      dicaDosIlegiveisDaFonte(fonte),
    ].filter(Boolean).join(' · '),
    dispositivos: comProblema > 0 ? `· ${comProblema} disp.` : null,
  };
}

// Whether, and how long ago, the official results were last written.
//   {classe, rotulo, idade, titulo}
// With publishing switched off nothing reaches the official results, and
// "publicado há 3 h" would read as a fault: the pill says so, in red, and
// the whole sentence is in the warning under the bar (avisosDosResultados).
export function descreverPublicacao(estado, agoraServidorMs) {
  if (estado.publicacao_desligada) {
    return {
      classe: 'publicacao desligada', rotulo: 'publicação desligada', idade: null,
      titulo: 'Publicação desligada: os resultados oficiais não estão sendo atualizados',
    };
  }
  if (estado.publicado_em_ms === null || estado.publicado_em_ms === undefined) {
    return { classe: 'publicacao', rotulo: 'ainda não publicado', idade: null, titulo: null };
  }
  const desdeMs = agoraServidorMs - estado.publicado_em_ms;
  return { classe: 'publicacao', rotulo: 'publicado', idade: haQuanto(desdeMs), titulo: `publicado ${haExato(desdeMs)}` };
}

// "calculado agora" / "calculado há 30 s"; null when unknown. Late (red),
// the slot counts every second: then the reviewer wants to watch it.
export function descreverCalculo(estado, agoraServidorMs) {
  const calculo = idadeDoCalculo(estado, agoraServidorMs);
  if (calculo === null) {
    return null;
  }
  const desdeMs = calculo.segundos * 1000;
  return {
    classe: calculo.atrasado ? 'calculo atrasado' : 'calculo',
    rotulo: 'calculado',
    idade: calculo.atrasado ? haExato(desdeMs) : haQuanto(desdeMs),
    titulo: `Calculado ${haExato(desdeMs)}. Há quanto tempo alertas, tempos de volta e melhores voltas foram recalculados. `
      + 'A contagem de voltas em si é imediata.',
  };
}

// The short red pill about the points; null when all is well. The
// sentences are in the warning under the bar.
export function descreverPontos(pontuacao) {
  const avisos = avisosDaPontuacao(pontuacao);
  return avisos.length === 0 ? null : { texto: 'pontos suspeitos', titulo: avisos.join('\n') };
}

// The sentences of the warning under the bar: publishing off and what is
// wrong with the points (the 26SE case, every car with 0 points). Empty
// when there is nothing to say. Unlike the bar, this may take lines: it
// changes when the settings change, not every second.
export function avisosDosResultados(estado) {
  const avisos = [];
  if (estado?.publicacao_desligada) {
    avisos.push('Publicação desligada: os resultados oficiais não estão sendo atualizados.');
  }
  return [...avisos, ...avisosDaPontuacao(estado?.pontuacao).map((aviso) => `Pontuação: ${aviso}.`)];
}

// ---- drawing ------------------------------------------------------------------

// A label and its age slot (fixed width in the style sheet).
function comIdade(rotulo, quanto) {
  return quanto === null ? [rotulo] : [rotulo, ' ', el('span', { class: 'idade' }, quanto)];
}

function chipDaFonte(fonte, estado, agoraServidorMs, excluidas) {
  const chip = descreverChip(fonte, estado, agoraServidorMs, excluidas);
  return el('span', { class: chip.classe, title: chip.titulo },
    el('strong', { class: 'chip-nome' }, chip.nome), chip.principal ? ' ★' : '', ' ',
    el('span', { class: 'chip-estado' }, chip.texto),
    chip.idade === null ? null : el('span', { class: 'idade' }, chip.idade),
    chip.dispositivos === null ? null : el('span', { class: 'chip-dispositivos' }, ` ${chip.dispositivos}`));
}

// While the official clock is (re)synchronising, the bar shows dashes of
// the clocks' own shape, not the word "sincronizando…": that word is wider
// than a time and pushed the rest of the line about 140 px sideways for a
// second (the anchor is dropped on load and after the computer sleeps).
// The word is in the tooltip.
export const HORARIO_SINCRONIZANDO = '--:--:--';
export const RELOGIO_SINCRONIZANDO = '-:--:--';
const DICA_SINCRONIZANDO = 'Sincronizando com o horário do servidor…';

export const textoDoHorario = (oficialMs) => (oficialMs === null || oficialMs === undefined ? HORARIO_SINCRONIZANDO : horarioOficial(oficialMs));

function horario(oficialMs) {
  const sincronizando = oficialMs === null || oficialMs === undefined;
  return el('span', {
    class: sincronizando ? 'horario-oficial sincronizando' : 'horario-oficial',
    title: sincronizando ? DICA_SINCRONIZANDO : 'Horário do servidor, igual em todas as telas; não depende do relógio deste computador',
  }, 'Horário oficial ', el('strong', {}, textoDoHorario(oficialMs)));
}

// The bar before the first answer: there is no state yet, and the official
// time is not known, so it says so instead of showing this computer's clock.
// Same two lines as with a state, so nothing below moves at the first answer.
export function desenharBarraSemEstado(container) {
  substituir(container,
    el('div', { class: 'barra-linha barra-tempo' }, horario(null)),
    el('div', { class: 'barra-linha barra-fontes' }));
}

// The warning under the bar (see avisosDosResultados).
export function desenharAvisoDosResultados(container, estado) {
  if (!container) {
    return;
  }
  const avisos = avisosDosResultados(estado);
  // Only when it changed: it is an alert, and a screen reader would read
  // the same sentences again after every poll.
  const assinatura = JSON.stringify(avisos);
  if (container.dataset.avisos === assinatura) {
    return;
  }
  container.dataset.avisos = assinatura;
  substituir(container, avisos.map((aviso) => el('p', {}, aviso)));
  container.className = avisos.length > 0 ? 'revisao-aviso erro aviso-resultados' : 'revisao-aviso oculto';
}

// Redrawn every second so the clock and the "há 30 s" ages keep moving
// between two answers from the server.
//
// `agoraServidorMs` is the best the screen knows for "now" and is what the
// ages are worked out from. `oficialMs` is the server-anchored clock itself:
// null while it is (re)synchronising, and then the bar says so.
//
// Two lines: the clocks, the flags and the age of what is on screen; then
// the sources, and what is wrong with the points.
export function desenharBarra(container, estado, agoraServidorMs, oficialMs = agoraServidorMs) {
  const corrida = estado.corrida;
  const decorrido = relogioDaCorrida(corrida, agoraServidorMs);
  const abertos = (estado.alertas_abertos ?? []).length;
  const excluidas = new Set(exclusoesAbertas(estado).map((decisao) => decisao.fonte_id));

  const fontes = (estado.fontes ?? []).map((fonte) => chipDaFonte(fonte, estado, agoraServidorMs, excluidas));
  const publicacao = descreverPublicacao(estado, agoraServidorMs);
  const calculo = descreverCalculo(estado, agoraServidorMs);
  // The 26SE check: nothing else on the bar would show that every car scored 0.
  const recalculo = recalculoDosPontos(estado.pontuacao);
  const pontos = descreverPontos(estado.pontuacao);

  const outros = (estado.revisores ?? []).filter((nome) => nome !== document.body.dataset.usuario);

  substituir(container,
    el('div', { class: 'barra-linha barra-tempo' },
      horario(oficialMs),
      el('span', {
        class: `relogio estado-${corrida.estado}`,
        title: oficialMs === null ? DICA_SINCRONIZANDO : 'Tempo de prova, contado a partir da largada pelo horário oficial',
      }, el('strong', {}, oficialMs === null ? RELOGIO_SINCRONIZANDO : relogio(decorrido)), ' ', NOMES_DE_ESTADO[corrida.estado] ?? corrida.estado),
      el('span', { class: abertos > 0 ? 'pendencias com-alertas' : 'pendencias' }, resumoDeAlertas(abertos)),
      el(publicacao.classe.includes('desligada') ? 'strong' : 'span', { class: publicacao.classe, title: publicacao.titulo },
        comIdade(publicacao.rotulo, publicacao.idade)),
      calculo === null ? null : el('span', { class: calculo.classe, title: calculo.titulo }, comIdade(calculo.rotulo, calculo.idade)),
      outros.length > 0
        ? el('span', { class: 'revisores', title: `também revisando: ${outros.join(', ')}` }, `também revisando: ${outros.join(', ')}`)
        : null),
    el('div', { class: 'barra-linha barra-fontes' },
      el('span', { class: 'fontes' }, fontes),
      pontos === null ? null : el('strong', { class: 'publicacao pontos-suspeitos', title: pontos.titulo }, pontos.texto),
      recalculo === null ? null : el('span', {
        class: 'publicacao recalculo',
        title: `${recalculo}. Última vez que a pontuação da prova foi recalculada (ou que a prova foi alterada).`,
      }, recalculo)));
}

function itemDaFila(alerta, estado, selecionadoId, aoEscolher) {
  const classes = ['item-fila'];
  if (alerta.id === selecionadoId) {
    classes.push('selecionado');
  }
  if (alerta.auto_resolvido) {
    classes.push('resolvido');
  }
  if (contagemEmDuvida(alerta)) {
    classes.push('em-duvida');
  }
  const olhando = alerta.olhando ?? [];

  return el('li', {},
    el('button', { type: 'button', class: classes.join(' '), onclick: () => aoEscolher(alerta.id) },
      el('span', { class: 'tipo' }, nomeDoAlertaIlegivel(alerta, estado) ?? NOMES_DE_ALERTA[alerta.tipo] ?? alerta.tipo),
      el('span', { class: 'carros' }, carrosDoAlertaIlegivel(alerta) ?? `carro ${listaDeCarros(alerta.carros)}`),
      el('span', { class: 'quando' }, quandoDoAlerta(alerta, estado.corrida.largada_ms)),
      alerta.prioridade !== null && alerta.prioridade !== undefined
        ? el('span', { class: 'posicao', title: 'melhor posição entre os carros envolvidos' }, `P${alerta.prioridade}`)
        : null,
      contagemEmDuvida(alerta) ? el('span', { class: 'duvida' }, fraseDeDuvida(alerta)) : null,
      el('span', { class: 'motivo' }, alerta.motivo),
      olhando.length > 0 ? el('span', { class: 'olhando' }, `${olhando.join(', ')} está olhando`) : null));
}

export function desenharFila(elementos, estado, selecionadoId, aoEscolher) {
  const abertos = ordenarFila(estado.alertas_abertos ?? []);
  const resolvidos = estado.alertas_resolvidos ?? [];

  substituir(elementos.abertos,
    abertos.length === 0
      ? el('li', { class: 'vazio' }, 'Nenhum alerta aberto.')
      : abertos.map((alerta) => itemDaFila(alerta, estado, selecionadoId, aoEscolher)));
  substituir(elementos.resolvidos,
    resolvidos.map((alerta) => itemDaFila(alerta, estado, selecionadoId, aoEscolher)));

  elementos.contagemAbertos.textContent = `(${abertos.length})`;
  elementos.contagemResolvidos.textContent = `(${resolvidos.length})`;
}
