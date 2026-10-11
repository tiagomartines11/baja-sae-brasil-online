// The status bar at the top and the flag queue on the left.

import { el, substituir } from './revisao-dom.js';
import {
  NOMES_DE_ALERTA, NOMES_DE_ESTADO, idade, listaDeCarros, relogio, relogioDaCorrida, tempoDeProva,
} from './revisao-formato.js';
import { exclusoesAbertas } from './revisao-decisoes.js';
import { dispositivosDaFonte, estadoDaFonte, ordenarFila, resumoDeAlertas, resumoDosDispositivos } from './revisao-fila.js';

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

// Redrawn every second so the clock and the "há 12 s" ages keep moving
// between two answers from the server.
export function desenharBarra(container, estado, agoraServidorMs) {
  const corrida = estado.corrida;
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
    el('span', { class: `relogio estado-${corrida.estado}` },
      el('strong', {}, relogio(decorrido)), ' ', NOMES_DE_ESTADO[corrida.estado] ?? corrida.estado),
    el('span', { class: 'fontes' }, fontes),
    el('span', { class: abertos > 0 ? 'pendencias com-alertas' : 'pendencias' }, resumoDeAlertas(abertos)),
    publicacao,
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
  const olhando = alerta.olhando ?? [];

  return el('li', {},
    el('button', { type: 'button', class: classes.join(' '), onclick: () => aoEscolher(alerta.id) },
      el('span', { class: 'tipo' }, NOMES_DE_ALERTA[alerta.tipo] ?? alerta.tipo),
      el('span', { class: 'carros' }, `carro ${listaDeCarros(alerta.carros)}`),
      el('span', { class: 'quando' }, tempoDeProva(alerta.ts_ms, estado.corrida.largada_ms)),
      alerta.prioridade !== null && alerta.prioridade !== undefined
        ? el('span', { class: 'posicao', title: 'melhor posição entre os carros envolvidos' }, `P${alerta.prioridade}`)
        : null,
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
