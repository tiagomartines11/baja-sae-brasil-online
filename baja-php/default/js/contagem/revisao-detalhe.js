// The detail panel: the selected flag (or the selected car's whole race)
// with its lane timeline and the decision buttons.
//
// This file only draws and reports clicks through `acoes`; what a click
// means is decided in revisao.js.

import { el, substituir } from './revisao-dom.js';
import { NOMES_DE_ALERTA, NOMES_DE_STATUS, duracao, listaDeCarros, relogio, tempoDeProva } from './revisao-formato.js';
import { acoesDaEntrada, descreverSugestao } from './revisao-decisoes.js';
import { desenharRaias } from './revisao-raias.js';
import { ESCALA_ALERTA, ESCALA_CARRO, descricaoDoChip, encontrarEntrada } from './revisao-raias-logica.js';

function campoDeCarro(valorInicial) {
  return el('input', {
    type: 'number', min: 1, max: 9999, required: true, class: 'campo-carro',
    list: 'carros-validos', value: valorInicial ?? '', 'aria-label': 'número do carro',
  });
}

// "Adicionar passagem": a pass nobody recorded. The time is typed as time
// of race (h:mm:ss) because that is what every other part of the screen shows.
function formularioAdicionar(contexto, carroInicial, tsInicialMs) {
  const carro = campoDeCarro(carroInicial);
  const tempo = el('input', {
    type: 'text', required: true, size: 8, placeholder: 'h:mm:ss', 'aria-label': 'tempo de prova',
    value: tsInicialMs === null || tsInicialMs === undefined ? '' : relogio(tsInicialMs - contexto.estado.corrida.largada_ms),
  });
  return el('form', {
    class: 'acao-manual',
    onsubmit: (evento) => {
      evento.preventDefault();
      contexto.acoes.adicionar(Number(carro.value), tempo.value);
    },
  },
  el('strong', {}, 'Adicionar passagem'), ' carro ', carro, ' no tempo de prova ', tempo, ' ',
  el('button', { type: 'submit', disabled: contexto.ocupado }, 'Adicionar'));
}

// What can be done with the chip the reviewer clicked.
function caixaDaEntrada(contexto, entrada) {
  const botoes = acoesDaEntrada(entrada).map((opcao) => {
    const agir = (extra) => contexto.acoes.acaoNaEntrada(opcao, entrada, extra);
    switch (opcao.acao) {
      case 'anular':
        return el('button', { type: 'button', disabled: contexto.ocupado, onclick: () => agir() }, 'Anular');
      case 'confirmar':
        return el('button', { type: 'button', disabled: contexto.ocupado, onclick: () => agir() }, 'Confirmar (conta como está)');
      case 'restaurar':
        return el('button', { type: 'button', disabled: contexto.ocupado, onclick: () => agir() }, 'Restaurar (desfazer a anulação)');
      case 'remover':
        return el('button', { type: 'button', disabled: contexto.ocupado, onclick: () => agir() }, 'Remover a passagem adicionada');
      case 'desfazer_troca':
        return el('button', { type: 'button', disabled: contexto.ocupado, onclick: () => agir() }, `Voltar para o carro digitado (${entrada.carro_digitado})`);
      default: {
        const carro = campoDeCarro('');
        return el('form', {
          class: 'em-linha',
          onsubmit: (evento) => {
            evento.preventDefault();
            agir(Number(carro.value));
          },
        }, 'Trocar para o carro ', carro, ' ', el('button', { type: 'submit', disabled: contexto.ocupado }, 'Trocar'));
      }
    }
  });

  return el('div', { class: 'caixa-entrada' },
    el('p', {},
      el('strong', {}, `Carro ${entrada.carro}`), ' · ', descricaoDoChip(entrada), ' · ',
      tempoDeProva(entrada.ts_ms, contexto.estado.corrida.largada_ms)),
    el('div', { class: 'botoes' }, botoes));
}

function raiasOuCarregando(contexto, opcoes) {
  if (contexto.erroDaJanela) {
    return el('p', { class: 'contagem-erro' }, 'Não foi possível carregar a linha do tempo. ',
      el('button', { type: 'button', onclick: contexto.acoes.recarregar }, 'Tentar de novo'));
  }
  if (!contexto.janela) {
    return el('p', { class: 'vazio' }, 'Carregando a linha do tempo…');
  }
  return desenharRaias(contexto.janela, {
    largadaMs: contexto.estado.corrida.largada_ms,
    entradaSelecionadaId: contexto.entradaSelecionadaId,
    aoEscolherEntrada: contexto.acoes.escolherEntrada,
    ...opcoes,
  });
}

function avisoDesatualizado(contexto) {
  if (!contexto.desatualizado) {
    return null;
  }
  return el('p', { class: 'revisao-aviso' }, 'Chegaram dados novos. ',
    el('button', { type: 'button', onclick: contexto.acoes.recarregar }, 'Atualizar a linha do tempo'));
}

function entradaSelecionada(contexto) {
  if (contexto.entradaSelecionadaId === null || contexto.entradaSelecionadaId === undefined) {
    return null;
  }
  const entrada = encontrarEntrada(contexto.janela, contexto.entradaSelecionadaId);
  return entrada ? caixaDaEntrada(contexto, entrada) : null;
}

export function desenharDetalheDeAlerta(container, contexto) {
  const { alerta, estado, acoes, ocupado } = contexto;
  const olhando = alerta.olhando ?? [];

  substituir(container,
    el('header', { class: 'detalhe-titulo' },
      el('h2', {}, `${NOMES_DE_ALERTA[alerta.tipo] ?? alerta.tipo} · carro ${listaDeCarros(alerta.carros)}`),
      el('span', { class: 'quando' }, tempoDeProva(alerta.ts_ms, estado.corrida.largada_ms)),
      el('button', { type: 'button', class: 'fechar', onclick: acoes.fechar }, 'Fechar')),
    olhando.length > 0 ? el('p', { class: 'olhando' }, `${olhando.join(', ')} também está olhando este alerta.`) : null,
    el('p', { class: 'motivo' }, alerta.motivo),
    el('p', { class: 'sugestao' }, descreverSugestao(alerta)),
    alerta.auto_resolvido
      ? el('p', { class: 'vazio' }, 'Resolvido automaticamente. Use os registros abaixo se quiser mudar o resultado.')
      : el('div', { class: 'botoes botoes-decisao' },
        el('button', { type: 'button', class: 'primario', disabled: ocupado || !alerta.sugestao, onclick: acoes.aceitar },
          'Aceitar sugestão ', el('kbd', {}, 'A')),
        el('button', { type: 'button', disabled: ocupado || (alerta.passagem_ids ?? []).length === 0, onclick: acoes.manterAmbas },
          'Manter ambas ', el('kbd', {}, 'K')),
        el('button', { type: 'button', disabled: ocupado || (alerta.passagem_ids ?? []).length === 0, onclick: acoes.anular },
          'Anular registro ', el('kbd', {}, 'V')),
        el('button', { type: 'button', class: contexto.modoManual ? 'ativo' : '', onclick: acoes.alternarManual },
          'Editar manualmente')),
    avisoDesatualizado(contexto),
    raiasOuCarregando(contexto, {
      escala: ESCALA_ALERTA,
      idsEmDestaque: alerta.passagem_ids ?? [],
      centrarEmMs: alerta.ts_ms,
    }),
    el('p', { class: 'contagem-dica' }, 'Clique em um registro para agir sobre ele. Riscado = anulado; contorno = envolvido neste alerta.'),
    entradaSelecionada(contexto),
    contexto.modoManual && estado.corrida.largada_ms !== null && estado.corrida.largada_ms !== undefined
      ? formularioAdicionar(contexto, (alerta.carros ?? [])[0], alerta.ts_ms)
      : null);
}

export function desenharDetalheDeCarro(container, contexto) {
  const { carro, estado, acoes } = contexto;

  substituir(container,
    el('header', { class: 'detalhe-titulo' },
      el('h2', {}, `Carro ${carro.carro}${carro.equipe ? ` · ${carro.equipe}` : ''}`),
      el('span', { class: 'quando' }, carro.escola ?? ''),
      el('button', { type: 'button', class: 'fechar', onclick: acoes.fechar }, 'Fechar')),
    el('p', { class: 'motivo' },
      `${carro.voltas} voltas · ${NOMES_DE_STATUS[carro.status] ?? carro.status}`,
      ` · última volta ${duracao(carro.ultima_volta_ms)} · melhor volta ${duracao(carro.melhor_volta_ms)}`),
    avisoDesatualizado(contexto),
    raiasOuCarregando(contexto, { escala: ESCALA_CARRO, idsEmDestaque: [], centrarEmMs: null }),
    el('p', { class: 'contagem-dica' }, 'A corrida inteira deste carro. Role para os lados; clique em um registro para agir sobre ele.'),
    entradaSelecionada(contexto),
    estado.corrida.largada_ms === null || estado.corrida.largada_ms === undefined
      ? null
      : formularioAdicionar(contexto, carro.carro, null));
}
