// The bottom zone: the car table, the statistics and devices of each source
// (with "excluir esta fonte") and the decision history.

import { el, substituir } from './revisao-dom.js';
import {
  AVISO_TEMPO_DE_VOLTA, NOMES_DE_STATUS, SEM_VALOR, duracao, horaDoDia, idade, relogio, tempoDeProva,
} from './revisao-formato.js';
import {
  NOMES_DE_SITUACAO, avisoDeVoltasCurtas, dispositivosDaFonte, fontesDivergentes, situacaoDoCarroFora,
} from './revisao-fila.js';
import { descreverParada } from './revisao-raias-logica.js';
import { descreverDecisao, exclusoesAbertas } from './revisao-decisoes.js';

export function desenharTabelaDeCarros(tabela, estado, carroSelecionado, aoEscolherCarro) {
  const fontes = (estado.fontes ?? []).filter((fonte) => fonte.ativa);
  const largada = estado.corrida.largada_ms;

  const cabecalho = el('thead', {}, el('tr', {},
    el('th', {}, 'Pos'), el('th', {}, 'Carro'), el('th', {}, 'Equipe'),
    el('th', { title: 'voltas oficiais (a passagem zero não é volta)' }, 'Voltas'),
    fontes.map((fonte) => el('th', {
      title: fonte.tipo === 'totais'
        ? 'total de voltas importado'
        : 'passagens registradas por esta fonte (inclui a passagem zero); destacado quando difere das passagens oficiais',
    }, fonte.nome)),
    el('th', {}, 'Última passagem'),
    el('th', { class: 'com-dica', title: AVISO_TEMPO_DE_VOLTA }, 'Última volta'),
    el('th', { class: 'com-dica', title: AVISO_TEMPO_DE_VOLTA }, 'Melhor volta'),
    el('th', {}, 'Situação')));

  // Classified cars first, in standings order as the server sent them. A
  // car that is not classified (or not at the event at all) is only in the
  // list because somebody typed its number; it goes to the end, marked.
  const carros = estado.carros ?? [];
  const emOrdem = [...carros.filter((carro) => carro.classificado !== false), ...carros.filter((carro) => carro.classificado === false)];

  const linhas = emOrdem.map((carro) => {
    const divergentes = fontesDivergentes(carro, fontes);
    const celulasDasFontes = fontes.map((fonte) => {
      const valor = fonte.tipo === 'totais'
        ? (carro.totais_importados ?? {})[fonte.id]
        : (carro.contagem_por_fonte ?? {})[fonte.id] ?? 0;
      return el('td', { class: divergentes.includes(fonte.id) ? 'num divergente' : 'num' }, valor ?? SEM_VALOR);
    });
    const passagemZero = (carro.passagens ?? 0) > carro.voltas;

    const foraDaProva = carro.classificado === false;
    const voltasCurtas = avisoDeVoltasCurtas(carro, largada);

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
    celulasDasFontes,
    el('td', { class: 'num' }, tempoDeProva(carro.ultima_passagem_ms, largada)),
    el('td', { class: 'num', title: AVISO_TEMPO_DE_VOLTA }, duracao(carro.ultima_volta_ms)),
    el('td', { class: 'num', title: AVISO_TEMPO_DE_VOLTA }, duracao(carro.melhor_volta_ms)),
    el('td', { class: 'situacao' }, NOMES_DE_STATUS[carro.status] ?? carro.status ?? '',
      carro.status_desde_ms ? ` desde ${tempoDeProva(carro.status_desde_ms, largada)}` : ''));
  });

  // No team at all is marked present: then every number typed is "not a
  // present team". That is ONE problem, with the attendance list, and it is
  // said once, here, instead of as a label on every car.
  const semPresentes = estado.sem_equipes_presentes
    ? el('tr', { class: 'aviso-tabela' }, el('td', { colspan: 8 + fontes.length, role: 'alert' },
      el('strong', {}, 'Nenhuma equipe está marcada como presente neste evento. '),
      'Por isso nenhuma passagem conta e todo número digitado gera alerta. Marque a presença das equipes no cadastro do evento; a contagem se ajusta sozinha.'))
    : null;

  substituir(tabela, cabecalho, el('tbody', {}, semPresentes, linhas));
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

  return el('div', { class: fonte.ativa ? 'bloco-fonte' : 'bloco-fonte inativa' },
    el('h3', {}, fonte.nome, fonte.principal ? ' ★' : '', fonte.ativa ? '' : ' (inativa)'),
    // Shown first and loud: while this stands, nothing this source records counts.
    abertas.map((decisao) => exclusaoAberta(decisao, largada, ocupado, acoes)),
    stats
      ? el('dl', {},
        el('dt', {}, 'registros em uso'), el('dd', {}, numero(stats.entradas)),
        el('dt', {}, 'faltas'), el('dd', {}, numero(stats.faltas)),
        el('dt', {}, 'trocas corrigidas'), el('dd', {}, numero(stats.trocas)),
        el('dt', {}, 'repetidas anuladas'), el('dd', {}, numero(stats.repetidas_anuladas)),
        el('dt', {}, 'atraso mediano'), el('dd', {}, `${Math.round((stats.desvio_mediano_ms ?? 0) / 1000)} s`),
        el('dt', {}, 'sincronizado até'), el('dd', {},
          stats.nada_a_esperar ? 'nenhum dispositivo aberto' : tempoDeProva(stats.sincronizado_ate_ms, largada)))
      : el('p', { class: 'vazio' }, 'Sem estatísticas.'),
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

function linhaDoHistorico(decisao, estado, ocupado, aoDesfazer, classeExtra = '') {
  const nomeDaFonte = (fonteId) => (estado.fontes ?? []).find((fonte) => fonte.id === fonteId)?.nome ?? null;
  const largada = estado.corrida.largada_ms;
  const temLargada = largada !== null && largada !== undefined;

  let detalhe = '';
  if (decisao.tipo === 'adicionar' || decisao.tipo === 'marcar_saida') {
    detalhe = ` em ${tempoDeProva(decisao.ts_ms, largada)}`;
  } else if (decisao.tipo === 'excluir_fonte' && temLargada) {
    const fim = decisao.ts_fim_ms === null || decisao.ts_fim_ms === undefined ? null : relogio(decisao.ts_fim_ms - largada);
    detalhe = fim === null ? `: desde ${relogio(decisao.ts_ms - largada)}` : `: ${relogio(decisao.ts_ms - largada)} até ${fim}`;
  }
  const classes = [decisao.ativa ? '' : 'desfeita', classeExtra].filter(Boolean).join(' ');

  return el('tr', { class: classes },
    el('td', { class: 'num' }, `#${decisao.id}`),
    el('td', {}, `há ${idade(estado.servidor_agora_ms - decisao.criado_em_ms)}`),
    el('td', {}, decisao.usuario ?? SEM_VALOR, decisao.origem === 'contador' ? ' (contador)' : ''),
    // The "motivo" of a marked exit is its area, already said in the sentence.
    el('td', {}, descreverDecisao(decisao, nomeDaFonte) + detalhe, decisao.motivo && decisao.tipo !== 'marcar_saida' ? ` — ${decisao.motivo}` : ''),
    el('td', {},
      decisao.pode_desfazer
        ? el('button', { type: 'button', disabled: ocupado, onclick: () => aoDesfazer(decisao) }, 'Desfazer')
        : (decisao.ativa ? '' : 'desfeita')));
}

// The history panel.
//   elementos: {tabela, mais}
//   paginas:   {antigas, haMais, carregando}  older pages loaded on request
//   acoes:     {desfazer(decisao), carregarMais()}
//
// On top, always, the exclusions and added passes still in force: they
// change the count for as long as they stand and belong to no entry on a
// timeline, so this is the only place to find and undo them. Below, the
// history newest first, a page at a time.
export function desenharHistorico(elementos, estado, paginas, ocupado, acoes) {
  const emVigor = estado.historico_em_vigor ?? [];
  const recentes = [...(estado.historico ?? []), ...(paginas.antigas ?? [])];
  const colunas = 5;

  const corpo = [];
  if (emVigor.length > 0) {
    corpo.push(el('tr', { class: 'titulo-secao' },
      el('th', { colspan: colunas }, `Em vigor: exclusões de fonte, passagens adicionadas e saídas marcadas (${emVigor.length})`)));
    corpo.push(emVigor.map((decisao) => linhaDoHistorico(decisao, estado, ocupado, acoes.desfazer, 'em-vigor')));
    corpo.push(el('tr', { class: 'titulo-secao' }, el('th', { colspan: colunas }, 'Todas as decisões, da mais recente para a mais antiga')));
  }
  corpo.push(recentes.length > 0
    ? recentes.map((decisao) => linhaDoHistorico(decisao, estado, ocupado, acoes.desfazer))
    : el('tr', {}, el('td', { colspan: colunas, class: 'vazio' }, 'Nenhuma decisão ainda.')));

  substituir(elementos.tabela,
    el('thead', {}, el('tr', {}, el('th', {}, '#'), el('th', {}, 'Quando'), el('th', {}, 'Quem'), el('th', {}, 'O quê'), el('th', {}, ''))),
    el('tbody', {}, corpo));

  if (elementos.mais) {
    substituir(elementos.mais, paginas.haMais
      ? el('button', { type: 'button', disabled: paginas.carregando, onclick: acoes.carregarMais },
        paginas.carregando ? 'Carregando…' : 'Carregar decisões mais antigas')
      : (recentes.length > 0 ? el('span', { class: 'vazio' }, 'Fim do histórico.') : null));
  }
}
