// The status bar at the top and the flag queue on the left.

import { el, substituir } from './revisao-dom.js';
import {
  NOMES_DE_ALERTA, NOMES_DE_ESTADO, idade, listaDeCarros, relogio, relogioDaCorrida,
} from './revisao-formato.js';
import { exclusoesAbertas } from './revisao-decisoes.js';
import {
  contagemEmDuvida, dispositivosDaFonte, estadoDaFonte, ordenarFila, quandoDoAlerta, resumoDeAlertas, resumoDosDispositivos,
} from './revisao-fila.js';
import { fraseDeDuvida } from './revisao-alertas.js';
import { horarioOficial, idadeDoCalculo } from './revisao-relogio.js';

// The chip of one source: its name, whether it is in contact, and anything
// about its devices or an open-ended exclusion a reviewer must not miss.
function chipDaFonte(fonte, estado, agoraServidorMs, excluidas) {
  const situacao = estadoDaFonte(fonte, agoraServidorMs);
  const dispositivos = resumoDosDispositivos(dispositivosDaFonte(estado, fonte.id));
  const excluida = excluidas.has(fonte.id);

  return el('span', {
    class: `chip-fonte ${situacao.classe}${excluida ? ' excluida' : ''}`,
    title: [situacao.texto, dispositivos ? `dispositivos: ${dispositivos}` : null].filter(Boolean).join(' · '),
  },
  el('strong', {}, fonte.nome), fonte.principal ? ' ★ ' : ' ', situacao.texto,
  dispositivos ? el('span', { class: 'chip-dispositivos' }, ` · ${dispositivos}`) : null,
  excluida ? el('strong', { class: 'chip-excluida' }, ' · EXCLUÍDA até segunda ordem') : null);
}

// The bar before the first answer: there is no state yet, and the official
// time is not known, so it says so instead of showing this computer's clock.
export function desenharBarraSemEstado(container) {
  substituir(container, el('span', { class: 'horario-oficial' }, 'Horário oficial ', el('strong', {}, horarioOficial(null))));
}

// Redrawn every second so the clock and the "há 12 s" ages keep moving
// between two answers from the server.
//
// `agoraServidorMs` is the best the screen knows for "now" and is what the
// ages are worked out from. `oficialMs` is the server-anchored clock itself:
// null while it is (re)synchronising, and then the bar says so.
export function desenharBarra(container, estado, agoraServidorMs, oficialMs = agoraServidorMs) {
  const corrida = estado.corrida;
  const calculo = idadeDoCalculo(estado, agoraServidorMs);
  const decorrido = relogioDaCorrida(corrida, agoraServidorMs);
  const abertos = (estado.alertas_abertos ?? []).length;
  const excluidas = new Set(exclusoesAbertas(estado).map((decisao) => decisao.fonte_id));

  const fontes = (estado.fontes ?? []).map((fonte) => chipDaFonte(fonte, estado, agoraServidorMs, excluidas));

  // With publishing switched off nothing reaches the official results, and
  // "publicado há 3 h" would read as a fault. Say what is going on instead.
  let publicacao = null;
  if (estado.publicacao_desligada) {
    publicacao = el('strong', { class: 'publicacao desligada', role: 'alert' },
      'Publicação desligada: os resultados oficiais não estão sendo atualizados');
  } else if (estado.publicado_em_ms === null || estado.publicado_em_ms === undefined) {
    publicacao = el('span', { class: 'publicacao' }, 'ainda não publicado');
  } else {
    publicacao = el('span', { class: 'publicacao' }, `publicado há ${idade(agoraServidorMs - estado.publicado_em_ms)}`);
  }

  const outros = (estado.revisores ?? []).filter((nome) => nome !== document.body.dataset.usuario);

  substituir(container,
    el('span', { class: 'horario-oficial', title: 'Horário do servidor, igual em todas as telas; não depende do relógio deste computador' },
      'Horário oficial ', el('strong', {}, horarioOficial(oficialMs))),
    el('span', { class: `relogio estado-${corrida.estado}`, title: 'Tempo de prova, contado a partir da largada pelo horário oficial' },
      el('strong', {}, oficialMs === null ? 'sincronizando…' : relogio(decorrido)), ' ', NOMES_DE_ESTADO[corrida.estado] ?? corrida.estado),
    el('span', { class: 'fontes' }, fontes),
    el('span', { class: abertos > 0 ? 'pendencias com-alertas' : 'pendencias' }, resumoDeAlertas(abertos)),
    publicacao,
    calculo === null ? null : el('span', {
      class: calculo.atrasado ? 'calculo atrasado' : 'calculo',
      title: 'Há quanto tempo alertas, tempos de volta e melhores voltas foram recalculados. A contagem de voltas em si é imediata.',
    }, `calculado há ${calculo.segundos} s`),
    outros.length > 0 ? el('span', { class: 'revisores' }, `também revisando: ${outros.join(', ')}`) : null);
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
      el('span', { class: 'tipo' }, NOMES_DE_ALERTA[alerta.tipo] ?? alerta.tipo),
      el('span', { class: 'carros' }, `carro ${listaDeCarros(alerta.carros)}`),
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
