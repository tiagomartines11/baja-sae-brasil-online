// The bottom zone: the car table, the statistics of each source (with
// "excluir esta fonte de … até …") and the decision history.

import { el, substituir } from './revisao-dom.js';
import { NOMES_DE_STATUS, SEM_VALOR, duracao, idade, relogio, tempoDeProva } from './revisao-formato.js';
import { fontesDivergentes } from './revisao-fila.js';
import { descreverDecisao } from './revisao-decisoes.js';

export function desenharTabelaDeCarros(tabela, estado, carroSelecionado, aoEscolherCarro) {
  const fontes = (estado.fontes ?? []).filter((fonte) => fonte.ativa);
  const largada = estado.corrida.largada_ms;

  const cabecalho = el('thead', {}, el('tr', {},
    el('th', {}, 'Pos'), el('th', {}, 'Carro'), el('th', {}, 'Equipe'), el('th', {}, 'Voltas'),
    fontes.map((fonte) => el('th', { title: fonte.tipo === 'totais' ? 'total importado' : 'registros desta fonte' }, fonte.nome)),
    el('th', {}, 'Última passagem'), el('th', {}, 'Última volta'), el('th', {}, 'Melhor volta'), el('th', {}, 'Situação')));

  const linhas = (estado.carros ?? []).map((carro) => {
    const divergentes = fontesDivergentes(carro, fontes);
    const celulasDasFontes = fontes.map((fonte) => {
      const valor = fonte.tipo === 'totais'
        ? (carro.totais_importados ?? {})[fonte.id]
        : (carro.contagem_por_fonte ?? {})[fonte.id] ?? 0;
      return el('td', { class: divergentes.includes(fonte.id) ? 'num divergente' : 'num' }, valor ?? SEM_VALOR);
    });

    return el('tr', {
      class: carro.carro === carroSelecionado ? `selecionado status-${carro.status}` : `status-${carro.status}`,
      tabindex: 0,
      onclick: () => aoEscolherCarro(carro.carro),
      onkeydown: (evento) => {
        if (evento.key === 'Enter') {
          aoEscolherCarro(carro.carro);
        }
      },
    },
    el('td', { class: 'num' }, carro.posicao ?? SEM_VALOR),
    el('td', { class: 'num carro' }, carro.carro),
    el('td', { title: carro.escola ?? '' }, carro.equipe ?? ''),
    el('td', { class: 'num voltas' }, carro.voltas),
    celulasDasFontes,
    el('td', { class: 'num' }, tempoDeProva(carro.ultima_passagem_ms, largada)),
    el('td', { class: 'num' }, duracao(carro.ultima_volta_ms)),
    el('td', { class: 'num' }, duracao(carro.melhor_volta_ms)),
    el('td', { class: 'situacao' }, NOMES_DE_STATUS[carro.status] ?? carro.status,
      carro.status_desde_ms ? ` desde ${tempoDeProva(carro.status_desde_ms, largada)}` : ''));
  });

  substituir(tabela, cabecalho, el('tbody', {}, linhas));
}

function numero(valor) {
  return valor === null || valor === undefined ? SEM_VALOR : String(valor);
}

function blocoDaFonte(fonte, estado, ocupado, aoExcluirFonte) {
  const stats = fonte.stats;
  const largada = estado.corrida.largada_ms;

  const de = el('input', { type: 'text', size: 8, required: true, placeholder: 'h:mm:ss', 'aria-label': 'início, em tempo de prova' });
  const ate = el('input', { type: 'text', size: 8, required: true, placeholder: 'h:mm:ss', 'aria-label': 'fim, em tempo de prova' });
  const podeExcluir = fonte.tipo !== 'totais' && largada !== null && largada !== undefined;

  return el('div', { class: fonte.ativa ? 'bloco-fonte' : 'bloco-fonte inativa' },
    el('h3', {}, fonte.nome, fonte.principal ? ' ★' : '', fonte.ativa ? '' : ' (inativa)'),
    stats
      ? el('dl', {},
        el('dt', {}, 'registros em uso'), el('dd', {}, numero(stats.entradas)),
        el('dt', {}, 'faltas'), el('dd', {}, numero(stats.faltas)),
        el('dt', {}, 'trocas corrigidas'), el('dd', {}, numero(stats.trocas)),
        el('dt', {}, 'repetidas anuladas'), el('dd', {}, numero(stats.repetidas_anuladas)),
        el('dt', {}, 'atraso mediano'), el('dd', {}, `${Math.round((stats.desvio_mediano_ms ?? 0) / 1000)} s`),
        el('dt', {}, 'sincronizado até'), el('dd', {}, tempoDeProva(stats.sincronizado_ate_ms, largada)))
      : el('p', { class: 'vazio' }, 'Sem estatísticas.'),
    podeExcluir
      ? el('form', {
        class: 'acao-manual',
        onsubmit: (evento) => {
          evento.preventDefault();
          aoExcluirFonte(fonte, de.value, ate.value);
        },
      }, 'Excluir esta fonte de ', de, ' até ', ate, ' ',
      el('button', { type: 'submit', disabled: ocupado }, 'Excluir período'))
      : null);
}

export function desenharFontes(container, estado, ocupado, aoExcluirFonte) {
  substituir(container, (estado.fontes ?? []).map((fonte) => blocoDaFonte(fonte, estado, ocupado, aoExcluirFonte)));
}

export function desenharHistorico(tabela, estado, ocupado, aoDesfazer) {
  const nomeDaFonte = (fonteId) => (estado.fontes ?? []).find((fonte) => fonte.id === fonteId)?.nome ?? null;
  const largada = estado.corrida.largada_ms;
  const agora = estado.servidor_agora_ms;

  const linhas = (estado.historico ?? []).map((decisao) => {
    let detalhe = '';
    if (decisao.tipo === 'adicionar') {
      detalhe = ` em ${tempoDeProva(decisao.ts_ms, largada)}`;
    } else if (decisao.tipo === 'excluir_fonte') {
      detalhe = `: ${relogio(decisao.ts_ms - largada)} até ${relogio(decisao.ts_fim_ms - largada)}`;
    }
    return el('tr', { class: decisao.ativa ? '' : 'desfeita' },
      el('td', { class: 'num' }, `#${decisao.id}`),
      el('td', {}, `há ${idade(agora - decisao.criado_em_ms)}`),
      el('td', {}, decisao.usuario ?? SEM_VALOR, decisao.origem === 'contador' ? ' (contador)' : ''),
      el('td', {}, descreverDecisao(decisao, nomeDaFonte) + detalhe, decisao.motivo ? ` — ${decisao.motivo}` : ''),
      el('td', {},
        decisao.pode_desfazer
          ? el('button', { type: 'button', disabled: ocupado, onclick: () => aoDesfazer(decisao) }, 'Desfazer')
          : (decisao.ativa ? '' : 'desfeita')));
  });

  substituir(tabela,
    el('thead', {}, el('tr', {}, el('th', {}, '#'), el('th', {}, 'Quando'), el('th', {}, 'Quem'), el('th', {}, 'O quê'), el('th', {}, ''))),
    el('tbody', {}, linhas.length > 0 ? linhas : el('tr', {}, el('td', { colspan: 5, class: 'vazio' }, 'Nenhuma decisão ainda.'))));
}
