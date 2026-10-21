// Draws the legend under the timeline (what is in it: revisao-legenda-logica.js).
// It is drawn again only when what the prova has changes (a first "??", a
// sensor created), never on every poll.

import { el, substituir } from './revisao-dom.js';
import { guardarLegendaAberta, itensDaLegenda, legendaAberta, oQueAProvaTem } from './revisao-legenda-logica.js';

function amostra(item) {
  // A span, not a chip's button: the sample itself does nothing.
  return el('span', { class: `${item.classes.join(' ')} amostra`, 'aria-hidden': 'true' },
    item.texto, item.marcadores.map((marcador) => el('span', { class: marcador.classe }, marcador.texto)));
}

// `elementos`: {detalhes (the <details>), itens (the list inside it),
// explicacao (where a tapped item's explanation goes: a phone has no tooltip)}.
export function criarLegenda(elementos, armazenamento) {
  const { detalhes, itens, explicacao } = elementos;
  let desenhada = null;
  if (detalhes) {
    detalhes.open = legendaAberta(armazenamento);
    detalhes.addEventListener('toggle', () => guardarLegendaAberta(armazenamento, detalhes.open));
  }

  const explicar = (item) => {
    if (explicacao) {
      explicacao.textContent = item.dica;
      explicacao.hidden = false;
    }
  };

  return function desenharLegenda(estado) {
    if (!itens) {
      return;
    }
    const tem = oQueAProvaTem(estado);
    const chave = JSON.stringify(tem);
    if (chave === desenhada) {
      return;
    }
    const botao = (item) => el('button', {
      type: 'button', class: 'legenda-item', title: item.dica, onclick: () => explicar(item),
    }, amostra(item), item.rotulo);
    // The items of every group flow as one line that wraps; a group's title
    // stays on the line of its first item.
    substituir(itens, itensDaLegenda(tem).map((grupo) => el('span', { class: 'legenda-grupo', role: 'group', 'aria-label': grupo.titulo },
      el('span', { class: 'legenda-inicio' }, el('span', { class: 'legenda-titulo', 'aria-hidden': 'true' }, grupo.titulo), botao(grupo.itens[0])),
      grupo.itens.slice(1).map(botao))));
    desenhada = chave;
  };
}
