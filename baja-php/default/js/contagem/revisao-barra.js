// The status bar at the top and the flag queue on the left.

import { el, substituir } from './revisao-dom.js';
import {
  NOMES_DE_ALERTA, NOMES_DE_ESTADO, idade, listaDeCarros, relogio, relogioDaCorrida, tempoDeProva,
} from './revisao-formato.js';
import { estadoDaFonte, ordenarFila, resumoDeAlertas } from './revisao-fila.js';

// Redrawn every second so the clock and the "há 12 s" ages keep moving
// between two answers from the server.
export function desenharBarra(container, estado, agoraServidorMs) {
  const corrida = estado.corrida;
  const decorrido = relogioDaCorrida(corrida, agoraServidorMs);
  const abertos = (estado.alertas_abertos ?? []).length;

  const fontes = (estado.fontes ?? []).map((fonte) => {
    const situacao = estadoDaFonte(fonte, agoraServidorMs);
    return el('span', { class: `chip-fonte ${situacao.classe}`, title: situacao.texto },
      el('strong', {}, fonte.nome), fonte.principal ? ' ★ ' : ' ', situacao.texto);
  });

  const publicado = estado.publicado_em_ms === null || estado.publicado_em_ms === undefined
    ? 'ainda não publicado'
    : `publicado há ${idade(agoraServidorMs - estado.publicado_em_ms)}`;

  const outros = (estado.revisores ?? []).filter((nome) => nome !== document.body.dataset.usuario);

  substituir(container,
    el('span', { class: `relogio estado-${corrida.estado}` },
      el('strong', {}, relogio(decorrido)), ' ', NOMES_DE_ESTADO[corrida.estado] ?? corrida.estado),
    el('span', { class: 'fontes' }, fontes),
    el('span', { class: abertos > 0 ? 'pendencias com-alertas' : 'pendencias' }, resumoDeAlertas(abertos)),
    el('span', { class: 'publicacao' }, publicado),
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
