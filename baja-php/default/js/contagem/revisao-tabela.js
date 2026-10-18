// The bottom zone: the car table, the statistics and devices of each source
// (with "excluir esta fonte") and the decision history.

import { el, substituir } from './revisao-dom.js';
import {
  AVISO_TEMPO_DE_VOLTA, NOMES_DE_STATUS, SEM_VALOR, duracao, horaDoDia, tempoDeProva,
} from './revisao-formato.js';
import {
  NOMES_DE_SITUACAO, avisoDeVoltasCurtas, dispositivosDaFonte, fonteSilenciosa, situacaoDoCarroFora,
} from './revisao-fila.js';
import {
  DICA_DE_ACIMA, celulaDaFonte, colunaDaFonte, colunasDeFontes, desdeDoStatus, indicadorDaOrdem, ordemEmVigor, ordenarCarros, sinaisDoCarro,
  textoDeAcima, textoDeCurtas, textoDosAlertas,
} from './revisao-carros.js';
import { descreverParada } from './revisao-raias-logica.js';
import { exclusoesAbertas } from './revisao-decisoes.js';
import {
  FILTROS_VAZIOS, avisoDeHistoricoIncompleto, filtrarHistorico, historicoVazio, idadeDaLinha, linhasDoHistorico, opcoesDosFiltros,
  resumoDoHistorico, temFiltro,
} from './revisao-historico.js';
import {
  ehObservadora, linhasDaObservacao, tituloDaObservacao,
} from './revisao-observacao.js';
import { linhasDoSensor, textoDosIlegiveis } from './revisao-ilegivel.js';

// One column header: a button that sorts by it (see proximaOrdem), with
// aria-sort and an arrow on the column in use.
function cabecalhoOrdenavel(coluna, rotulo, ordem, aoOrdenar, atributos = {}) {
  const { ariaSort, seta } = indicadorDaOrdem(ordem, coluna);
  return el('th', { ...atributos, 'aria-sort': ariaSort },
    el('button', {
      type: 'button',
      class: 'ordenar',
      'data-coluna': coluna,
      onclick: aoOrdenar ? () => aoOrdenar(coluna) : null,
    }, rotulo, el('span', { class: 'seta', 'aria-hidden': 'true' }, seta)));
}

// `ordem`: {coluna, direcao} or null for the standings; `aoOrdenar(coluna)`
// is called by a click on a header (see revisao-carros.js).
export function desenharTabelaDeCarros(tabela, estado, carroSelecionado, aoEscolherCarro, ordemEscolhida = null, aoOrdenar = null) {
  const colunas = colunasDeFontes(estado);
  const largada = estado.corrida.largada_ms;
  const ordem = ordemEmVigor(ordemEscolhida, colunas);
  // The table is drawn anew on every change. A reviewer who sorted with the
  // keyboard keeps the focus on the same header.
  const colunaEmFoco = tabela.contains(document.activeElement) ? document.activeElement.dataset?.coluna ?? null : null;

  const cabecalho = el('thead', {}, el('tr', {},
    cabecalhoOrdenavel('posicao', 'Pos', ordem, aoOrdenar),
    cabecalhoOrdenavel('carro', 'Carro', ordem, aoOrdenar),
    cabecalhoOrdenavel('equipe', 'Equipe', ordem, aoOrdenar),
    cabecalhoOrdenavel('voltas', 'Voltas', ordem, aoOrdenar, { title: 'voltas oficiais (a passagem zero não é volta)' }),
    cabecalhoOrdenavel('avisos', 'Avisos', ordem, aoOrdenar, {
      title: 'alertas abertos do carro, contagem acima de todos os contadores e voltas curtas confirmadas',
    }),
    colunas.map((coluna) => cabecalhoOrdenavel(colunaDaFonte(coluna.fonte), coluna.fonte.nome, ordem, aoOrdenar, {
      class: coluna.comparada || coluna.fonte.tipo === 'totais' ? 'fonte' : 'fonte nao-comparada',
      title: [coluna.fonte.tipo === 'totais'
        ? 'total de voltas importado, como diferença para as voltas oficiais'
        : 'passagens registradas por esta fonte, como diferença para as passagens oficiais (com a passagem zero); o número está na dica de cada célula',
      coluna.motivo].filter(Boolean).join('; '),
    })),
    cabecalhoOrdenavel('ultima_passagem', 'Última passagem', ordem, aoOrdenar),
    cabecalhoOrdenavel('ultima_volta', 'Última volta', ordem, aoOrdenar, { class: 'com-dica', title: AVISO_TEMPO_DE_VOLTA }),
    cabecalhoOrdenavel('melhor_volta', 'Melhor volta', ordem, aoOrdenar, { class: 'com-dica', title: AVISO_TEMPO_DE_VOLTA }),
    cabecalhoOrdenavel('situacao', 'Situação', ordem, aoOrdenar)));

  // Standings order as the server sent them, or the column chosen. A car
  // that is not classified (or not at the event at all) is only in the list
  // because somebody typed its number; it stays at the end, marked.
  const emOrdem = ordenarCarros(estado.carros ?? [], ordem, { estado, colunas });

  const linhas = emOrdem.map((carro) => {
    const celulasDasFontes = colunas.map((coluna) => {
      const celula = celulaDaFonte(carro, coluna);
      return el('td', { class: coluna.comparada || coluna.fonte.tipo === 'totais' ? 'num fonte' : 'num fonte nao-comparada', title: celula.titulo },
        celula.texto);
    });
    const passagemZero = (carro.passagens ?? 0) > carro.voltas;

    const foraDaProva = carro.classificado === false;
    const voltasCurtas = avisoDeVoltasCurtas(carro, largada);
    const sinais = sinaisDoCarro(carro, estado, colunas);
    const alertas = textoDosAlertas(sinais);

    return el('tr', {
      class: [carro.carro === carroSelecionado ? 'selecionado' : '', `status-${carro.status}`, foraDaProva ? 'nao-classificado' : '']
        .filter(Boolean).join(' '),
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
    el('td', { title: carro.escola ?? '' }, carro.equipe ?? '',
      // With nobody marked present the warning above the table says it once.
      foraDaProva && !(estado.sem_equipes_presentes && carro.inscrito)
        ? el('span', { class: 'etiqueta-fora' }, ` ${situacaoDoCarroFora(carro).etiqueta}`) : null),
    el('td', {
      class: voltasCurtas ? 'num voltas volta-curta-confirmada' : 'num voltas',
      title: [passagemZero ? `${carro.voltas} voltas + a passagem zero = ${carro.passagens} passagens oficiais` : '', voltasCurtas ?? '']
        .filter(Boolean).join('\n'),
    }, carro.voltas),
    el('td', { class: 'avisos' },
      sinais.acima === null ? null
        : el('span', { class: 'sinal acima', title: DICA_DE_ACIMA }, textoDeAcima(sinais.acima)),
      alertas === null ? null
        : el('span', { class: sinais.emDuvida > 0 ? 'sinal alertas em-duvida' : 'sinal alertas', title: alertas.titulo }, alertas.texto),
      sinais.curtas === 0 ? null
        : el('span', { class: 'sinal volta-curta-confirmada', title: voltasCurtas }, textoDeCurtas(sinais.curtas))),
    celulasDasFontes,
    el('td', { class: 'num' }, tempoDeProva(carro.ultima_passagem_ms, largada)),
    el('td', { class: 'num', title: AVISO_TEMPO_DE_VOLTA }, duracao(carro.ultima_volta_ms)),
    el('td', { class: 'num', title: AVISO_TEMPO_DE_VOLTA }, duracao(carro.melhor_volta_ms)),
    el('td', { class: 'situacao' }, NOMES_DE_STATUS[carro.status] ?? carro.status ?? '', desdeDoStatus(carro, largada)));
  });

  // No team at all is marked present: then every number typed is "not a
  // present team". That is ONE problem, with the attendance list, and it is
  // said once, here, instead of as a label on every car.
  const semPresentes = estado.sem_equipes_presentes
    ? el('tr', { class: 'aviso-tabela' }, el('td', { colspan: 9 + colunas.length, role: 'alert' },
      el('strong', {}, 'Nenhuma equipe está marcada como presente neste evento. '),
      'Por isso nenhuma passagem conta e todo número digitado gera alerta. Marque a presença das equipes no cadastro do evento; a contagem se ajusta sozinha.'))
    : null;

  substituir(tabela, cabecalho, el('tbody', {}, semPresentes, linhas));
  if (colunaEmFoco !== null) {
    tabela.querySelector(`button.ordenar[data-coluna="${CSS.escape(colunaEmFoco)}"]`)?.focus({ preventScroll: true });
  }
}

function numero(valor) {
  return valor === null || valor === undefined ? SEM_VALOR : String(valor);
}

// One line per phone of the source: who, and whether it is still expected.
function listaDeDispositivos(dispositivos) {
  if (dispositivos.length === 0) {
    return el('p', { class: 'vazio' }, 'Nenhum dispositivo abriu esta fonte.');
  }
  return el('ul', { class: 'dispositivos' }, dispositivos.map((dispositivo) => {
    const quando = {
      sem_contato: dispositivo.visto_em_ms,
      silencioso: dispositivo.visto_em_ms,
      fechado: dispositivo.fechado_em_ms,
      revogado: dispositivo.revogado_em_ms,
    }[dispositivo.situacao];
    return el('li', { class: `dispositivo ${dispositivo.situacao}` },
      el('strong', {}, dispositivo.usuario ?? 'API'), ` (#${dispositivo.id}) `,
      el('span', { class: 'situacao' }, NOMES_DE_SITUACAO[dispositivo.situacao] ?? dispositivo.situacao,
        quando ? ` desde ${horaDoDia(quando)}` : ''),
      dispositivo.situacao === 'silencioso' ? ' · o sistema não espera mais por ele' : '',
      dispositivo.situacao === 'sem_contato' ? ' · o sistema ainda espera o envio dele' : '');
  }));
}

// An exclusion with no end, shown first and loud: while it stands, nothing
// this source records counts. Two ways out, and they are not the same:
//   "Encerrar exclusão em"  the counter is back: what it recorded while
//                           excluded stays out, what it records from that
//                           moment counts again;
//   "Desfazer a exclusão"   it was a mistake: everything counts, as if the
//                           source had never been excluded (and every pass
//                           it did not record in the gap becomes a miss).
function exclusaoAberta(decisao, largada, ocupado, acoes) {
  const fim = el('input', { type: 'text', size: 8, required: true, placeholder: 'h:mm:ss', 'aria-label': 'fim da exclusão, em tempo de prova' });
  return el('div', { class: 'exclusao-aberta', role: 'alert' },
    el('strong', {}, `EXCLUÍDA desde ${tempoDeProva(decisao.ts_ms, largada)} até segunda ordem`),
    ` por ${decisao.usuario ?? SEM_VALOR}${decisao.motivo ? ` — ${decisao.motivo}` : ''}. `,
    'Os registros desta fonte não estão contando. ',
    el('form', {
      class: 'em-linha',
      onsubmit: (evento) => {
        evento.preventDefault();
        acoes.encerrarExclusao(decisao, fim.value);
      },
    }, 'Encerrar exclusão em ', fim, el('button', { type: 'submit', disabled: ocupado }, 'Encerrar')),
    ' ',
    el('button', {
      type: 'button', disabled: ocupado, onclick: () => acoes.desfazer(decisao),
      title: 'Como se a fonte nunca tivesse sido excluída: o período inteiro volta a contar',
    }, 'Desfazer a exclusão'));
}

function blocoDaFonte(fonte, estado, ocupado, acoes) {
  const stats = fonte.stats;
  const largada = estado.corrida.largada_ms;
  const abertas = exclusoesAbertas(estado).filter((decisao) => decisao.fonte_id === fonte.id);

  const de = el('input', { type: 'text', size: 8, required: true, placeholder: 'h:mm:ss', 'aria-label': 'início, em tempo de prova' });
  const ate = el('input', { type: 'text', size: 8, placeholder: 'vazio = até segunda ordem', 'aria-label': 'fim, em tempo de prova (opcional)' });
  const motivo = el('input', { type: 'text', size: 24, maxlength: 500, placeholder: 'motivo (opcional)', 'aria-label': 'motivo' });
  const podeExcluir = fonte.tipo !== 'totais' && largada !== null && largada !== undefined;

  // An observer counts nothing, so the counter's numbers (entries in use,
  // misses) say nothing about it: its own comparison takes their place. A
  // promoted one shows both, the comparison of the time it observed below.
  const observacao = linhasDaObservacao(stats?.observacao, fonte);
  const ilegiveis = textoDosIlegiveis(stats);
  const doSensor = linhasDoSensor(stats?.sensor);
  const sincronizado = stats
    ? [el('dt', {}, 'sincronizado até'), el('dd', {},
      stats.nada_a_esperar ? 'nenhum dispositivo aberto' : tempoDeProva(stats.sincronizado_ate_ms, largada))]
    : null;
  const comparacao = (comSincronizado) => el('div', { class: 'observacao' },
    el('p', {}, tituloDaObservacao(fonte)),
    el('dl', {}, observacao.map(([rotulo, valor]) => [el('dt', {}, rotulo), el('dd', {}, valor)]), comSincronizado ? sincronizado : null));

  let numeros = el('p', { class: 'vazio' }, 'Sem estatísticas.');
  if (stats && observacao && ehObservadora(fonte)) {
    numeros = comparacao(true);
  } else if (stats && doSensor) {
    // A sensor that counts reads no car: what it did with its marks, not a
    // counter's misses and swaps. Its comparison as an observer, if it was one, below.
    numeros = [el('p', {}, 'Sensor de tempo: marca o horário das passagens; os contadores dizem o carro.'),
      el('dl', {}, doSensor.map(([rotulo, valor, dica]) => [el('dt', { class: 'com-dica', title: dica }, rotulo), el('dd', {}, valor)]), sincronizado),
      observacao ? comparacao(false) : null];
  } else if (stats) {
    numeros = [el('dl', {},
      el('dt', {}, 'registros em uso'), el('dd', {}, numero(stats.entradas)),
      el('dt', {}, 'faltas'), el('dd', {}, numero(stats.faltas)),
      el('dt', {}, 'trocas corrigidas'), el('dd', {}, numero(stats.trocas)),
      el('dt', {}, 'repetidas anuladas'), el('dd', {}, numero(stats.repetidas_anuladas)),
      el('dt', {}, 'atraso mediano'), el('dd', {}, `${Math.round((stats.desvio_mediano_ms ?? 0) / 1000)} s`),
      // Only a source that recorded "??" has the line at all.
      ilegiveis === null ? null : [el('dt', {}, 'ilegíveis'), el('dd', {}, ilegiveis.replace(/^ilegíveis: /, ''))],
      sincronizado), observacao ? comparacao(false) : null];
  }

  // Switched on, yet nothing recorded since the start and no device open
  // (26SE: a source whose only phone was revoked the night before).
  const silenciosa = fonteSilenciosa(fonte, largada, estado.servidor_agora_ms ?? null);

  return el('div', { class: ['bloco-fonte', fonte.ativa ? '' : 'inativa', silenciosa ? 'silenciosa' : ''].filter(Boolean).join(' ') },
    el('h3', {}, fonte.nome, fonte.principal ? ' ★' : '', fonte.ativa ? '' : ' (inativa)', ehObservadora(fonte) ? ' (observadora)' : ''),
    // Shown first and loud: while this stands, nothing this source records counts.
    abertas.map((decisao) => exclusaoAberta(decisao, largada, ocupado, acoes)),
    silenciosa
      ? el('p', { class: 'aviso-silenciosa' },
        el('strong', {}, 'Sem registros desde a largada. '),
        'A fonte está ativa, mas nenhum dispositivo está aberto nela: não está contando, e a tabela de carros não a compara com a contagem. '
        + 'Abra a fonte num aparelho, ou desative-a em Fontes e configurações.')
      : null,
    numeros,
    fonte.tipo === 'totais' ? null : listaDeDispositivos(dispositivosDaFonte(estado, fonte.id)),
    podeExcluir
      ? el('form', {
        class: 'acao-manual',
        onsubmit: (evento) => {
          evento.preventDefault();
          acoes.excluirFonte(fonte, de.value, ate.value, motivo.value);
        },
      }, 'Excluir esta fonte de ', de, ' até ', ate, ' ', motivo, ' ',
      el('button', { type: 'submit', disabled: ocupado }, 'Excluir'),
      el('small', {}, ' sem fim = até segunda ordem (para um dispositivo que não volta)'))
      : null);
}

const NOMES_DE_PARADA = {
  box_entrada: 'entrada no box sem saída',
  box_saida: 'saída do box sem entrada',
  abastecimento_entrada: 'entrada no abastecimento sem saída',
  abastecimento_saida: 'saída do abastecimento sem entrada',
};

// Pit and fueling events the engine could not pair up, for the whole race.
// They block nothing; the list is here so a hole in the log is noticed.
function blocoDeParadasSemPar(estado) {
  const semPar = estado.paradas_sem_par ?? [];
  if (semPar.length === 0) {
    return null;
  }
  return el('div', { class: 'bloco-fonte paradas-sem-par' },
    el('h3', {}, `Box e abastecimento: ${semPar.length} registro(s) sem par`),
    el('ul', { class: 'dispositivos' }, semPar.map((parada) => el('li', {},
      el('strong', {}, `carro ${parada.carro}`), ` · ${NOMES_DE_PARADA[parada.tipo] ?? parada.tipo}`,
      ` · ${tempoDeProva(parada.ts_ms, estado.corrida.largada_ms)}`))),
    el('p', { class: 'vazio' }, 'Não entram como parada nas estatísticas até terem as duas pontas.'));
}

// Pit and fueling events of unknown time, for the whole race. The engine
// keeps them out of the pairing; somebody has to know they exist.
function blocoDeParadasSemHorario(estado) {
  const semHorario = estado.paradas_sem_horario ?? [];
  if (semHorario.length === 0) {
    return null;
  }
  return el('div', { class: 'bloco-fonte paradas-sem-par' },
    el('h3', {}, `Box e abastecimento: ${semHorario.length} registro(s) sem horário`),
    el('ul', { class: 'dispositivos' }, semHorario.map((parada) => el('li', {}, descreverParada(parada)))),
    el('p', { class: 'vazio' }, 'O aparelho não tinha o horário oficial. Não entram em nenhuma parada; confira com quem registrou.'));
}

// acoes: {excluirFonte(fonte, de, ate, motivo), encerrarExclusao(decisao, ate), desfazer(decisao)}
export function desenharFontes(container, estado, ocupado, acoes) {
  substituir(container,
    (estado.fontes ?? []).map((fonte) => blocoDaFonte(fonte, estado, ocupado, acoes)),
    blocoDeParadasSemPar(estado),
    blocoDeParadasSemHorario(estado));
}

// ---- decision history ---------------------------------------------------------

// The filter bar is in the page's markup (contagem_revisao.php) and is never
// redrawn, so a car number half typed survives every answer from the
// server. This wires it, once. `aoFiltrar(filtros)` gets the whole set.
export function ligarFiltrosDoHistorico(formulario, aoFiltrar) {
  const campos = formulario.elements;
  const ler = () => ({
    carro: campos.carro.value,
    fonte: campos.fonte.value,
    quem: campos.quem.value,
    tipo: campos.tipo.value,
    soEmVigor: campos.soEmVigor.checked,
  });
  // Enter in the car field must not reload the page.
  formulario.addEventListener('submit', (evento) => evento.preventDefault());
  formulario.addEventListener('input', () => aoFiltrar(ler()));
  formulario.addEventListener('change', () => aoFiltrar(ler()));
  campos.limpar.addEventListener('click', () => aoFiltrar({ ...FILTROS_VAZIOS }));
}

// The choices of one list, rebuilt only when they changed and never while
// the list is open (that would close it under the reviewer's finger).
function preencherLista(lista, opcoes, valor) {
  const chave = JSON.stringify(opcoes);
  if (lista.dataset.opcoes !== chave && document.activeElement !== lista) {
    substituir(lista, el('option', { value: '' }, 'todos'), opcoes.map((opcao) => el('option', { value: opcao.valor }, opcao.rotulo)));
    lista.dataset.opcoes = chave;
  }
  if (lista.value !== valor) {
    lista.value = valor;
  }
}

// The bar shows the filters in force: those remembered from the last visit,
// and none after "Limpar filtros". A field being typed in is left alone.
function mostrarFiltros(formulario, estado, linhas, filtros) {
  const campos = formulario.elements;
  const opcoes = opcoesDosFiltros(estado, linhas, filtros);
  preencherLista(campos.fonte, opcoes.fontes, filtros.fonte);
  preencherLista(campos.quem, opcoes.quem, filtros.quem);
  preencherLista(campos.tipo, opcoes.tipos, filtros.tipo);
  if (document.activeElement !== campos.carro && campos.carro.value !== filtros.carro) {
    campos.carro.value = filtros.carro;
  }
  campos.soEmVigor.checked = filtros.soEmVigor;
  campos.limpar.disabled = !temFiltro(filtros);
}

function linhaDoHistorico(linha, estado, ocupado, acoes) {
  const { decisao, alvo } = linha;
  const abrir = alvo ? () => acoes.abrir(alvo) : null;
  const classes = [linha.desfeita ? 'desfeita' : '', linha.automatica ? 'automatica' : '', abrir ? 'abre' : ''].filter(Boolean).join(' ');
  const dicaDoVer = alvo?.tipo === 'alerta'
    ? 'Abrir este alerta no painel de detalhe'
    : `Abrir a linha do tempo do carro ${alvo?.numero} neste momento`;

  return el('tr', {
    class: classes,
    // The whole line opens it with the mouse; "Ver" is the same for the keyboard.
    onclick: abrir ? (evento) => { if (!evento.target.closest('button')) abrir(); } : null,
  },
  el('td', { class: 'num' }, decisao ? `#${decisao.id}` : SEM_VALOR),
  el('td', {}, idadeDaLinha(estado, linha)),
  el('td', {}, linha.automatica
    ? 'automático'
    : [decisao.usuario ?? SEM_VALOR, decisao.origem === 'contador' ? ' (contador, no aparelho)' : '']),
  el('td', {}, linha.emVigor ? [el('span', { class: 'etiqueta-em-vigor' }, 'em vigor'), ' '] : null, linha.texto),
  el('td', { class: 'acoes' },
    abrir ? el('button', { type: 'button', class: 'ver', title: dicaDoVer, onclick: abrir }, 'Ver') : null,
    decisao?.pode_desfazer
      ? el('button', { type: 'button', disabled: ocupado, onclick: () => acoes.desfazer(decisao) }, 'Desfazer')
      : (linha.desfeita ? ' desfeita' : null)));
}

// The history panel: one list, newest first, the system's own resolutions
// among the decisions (see linhasDoHistorico), cut down by the filters.
// What still stands carries "em vigor"; the open-ended source exclusions
// are also loud in the Fontes block and the status bar.
//   elementos: {formulario, resumo, tabela, aviso}
//   filtros:   see revisao-historico.js
//   acoes:     {desfazer(decisao), abrir(alvo)}
export function desenharHistorico(elementos, estado, filtros, ocupado, acoes) {
  const linhas = linhasDoHistorico(estado);
  const mostradas = filtrarHistorico(linhas, filtros);
  const colunas = 5;

  if (elementos.formulario) {
    mostrarFiltros(elementos.formulario, estado, linhas, filtros);
  }
  if (elementos.resumo) {
    elementos.resumo.textContent = resumoDoHistorico(mostradas, linhas, filtros);
  }

  substituir(elementos.tabela,
    el('thead', {}, el('tr', {}, el('th', {}, '#'), el('th', {}, 'Quando'), el('th', {}, 'Quem'), el('th', {}, 'O quê'), el('th', {}, ''))),
    el('tbody', {}, mostradas.length > 0
      ? mostradas.map((linha) => linhaDoHistorico(linha, estado, ocupado, acoes))
      : el('tr', {}, el('td', { colspan: colunas, class: 'vazio' }, historicoVazio(linhas.length)))));

  if (elementos.aviso) {
    const aviso = avisoDeHistoricoIncompleto(estado);
    substituir(elementos.aviso, aviso ? el('p', { class: 'contagem-dica' }, aviso) : null);
  }
}
