// The detail panel: the selected flag (or car, or chip) and the decision
// buttons. The lane timeline is not in it: it is the permanent panel at
// the top of the page (revisao-linha.js), so these buttons stay in the same
// place for every flag.
//
// This file only draws and reports clicks through `acoes`; what a click
// means is decided in revisao.js.

import { el, substituir } from './revisao-dom.js';
import {
  AVISO_TEMPO_DE_VOLTA, NOMES_DE_ALERTA, NOMES_DE_STATUS, duracao, listaDeCarros, relogio, tempoDeProva,
} from './revisao-formato.js';
import {
  acaoDeAceitar, acaoDeConfirmar, acoesExtrasDoAlerta, efeitoDeUsarTempo, fraseDeDuvida, temposParaEscolher,
} from './revisao-alertas.js';
import { edicaoManualDoAlerta } from './revisao-edicao-manual.js';
import { acoesDaEntrada, descreverSugestao, repeticoesEmAberto, rotuloDeDesfazerConfirmacao } from './revisao-decisoes.js';
import { quandoDoAlerta, situacaoDoCarroFora } from './revisao-fila.js';
import { SEM_IMAGEM_NA_REDE, imagemDaEntrada } from './revisao-observacao.js';
import { descricaoDoChip, encontrarEntrada } from './revisao-raias-logica.js';
import {
  atribuicoesAutomaticas, candidatosDoAlerta, candidatosParaTrocar, ehIlegivel, ehMarcaDoSensor, fonteEhSensor, fraseDaAtribuicao,
  nomeDoAlertaIlegivel, rotuloDaEntrada, situacaoDoIlegivel,
} from './revisao-ilegivel.js';

function campoDeCarro(valorInicial) {
  return el('input', {
    type: 'number', min: 1, max: 9999, required: true, class: 'campo-carro',
    list: 'carros-validos', value: valorInicial ?? '', 'aria-label': 'número do carro',
  });
}

// The optional reason for the next decision made in this panel. It is kept
// in the screen's state on every keystroke, because the panel is redrawn
// from scratch and would otherwise lose it.
function campoDeMotivo(contexto) {
  return el('label', { class: 'campo-motivo' }, 'Motivo (opcional): ',
    el('input', {
      type: 'text', maxlength: 500, size: 40, value: contexto.motivo ?? '',
      placeholder: 'fica registrado no histórico junto com a decisão',
      oninput: (evento) => contexto.acoes.anotarMotivo(evento.target.value),
    }));
}

const temLargada = (estado) => estado.corrida.largada_ms !== null && estado.corrida.largada_ms !== undefined;

// "Adicionar passagem": a pass nobody recorded. The time is typed as time
// of race (h:mm:ss) because that is what every other part of the screen
// shows. Without a start there is no time of race, so there is no form.
function formularioAdicionar(contexto, carroInicial, tsInicialMs) {
  if (!temLargada(contexto.estado)) {
    return el('p', { class: 'contagem-dica' }, 'A corrida ainda não tem largada: não é possível adicionar passagens.');
  }
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
  el('button', { type: 'submit', disabled: contexto.ocupado }, 'Adicionar'),
  el('small', {}, ' tempo desde a largada, não a hora do relógio'));
}

// "Mover o registro": on a "troca provável", the manual edit that settles
// the flag is saying which car the stray entry belongs to. It stores the
// same decision as "Aceitar sugestão" (reatribuir), so the flag closes the
// same way and the history can undo it.
function formularioMover(contexto, mover) {
  const carro = campoDeCarro(mover.carroSugerido);
  return el('form', {
    class: 'acao-manual acao-mover',
    onsubmit: (evento) => {
      evento.preventDefault();
      contexto.acoes.moverRegistro(mover, carro.value === '' ? null : Number(carro.value));
    },
  },
  el('strong', {}, 'Mover o registro'), ` do contador ${mover.fonteNome ?? '(fonte desconhecida)'} (digitado: carro ${mover.carroAtual}) para o carro `,
  carro, ' ',
  el('button', { type: 'submit', disabled: contexto.ocupado || !contexto.podeDecidir }, 'Mover'),
  el('small', {}, ' é isto que resolve este alerta: diz de qual carro era o registro solto'));
}

// What "Editar manualmente" opens on a flag: on a "troca provável" the
// form that moves the stray entry, and then, clearly apart, "Adicionar
// passagem" (with no car filled in where that would be a trap).
function edicaoManual(contexto, alerta) {
  const edicao = edicaoManualDoAlerta(alerta, contexto.estado);
  const adicionar = formularioAdicionar(contexto, edicao.adicionar.carro, edicao.adicionar.tsMs);
  if (!edicao.mover) {
    return adicionar;
  }
  return el('div', { class: 'edicao-manual' },
    formularioMover(contexto, edicao.mover),
    el('p', { class: 'contagem-dica separador-manual' },
      'Outra coisa: adicionar uma passagem que NINGUÉM registrou. Não resolve este alerta, e dá uma volta ao carro informado.'),
    adicionar);
}

const nomeDaFonte = (estado, id) => (estado.fontes ?? []).find((fonte) => fonte.id === id)?.nome ?? null;

// What the tooltips and the panel say about a "??" or a mark.
function contextoDaDica(contexto, entrada) {
  return {
    atribuicoes: atribuicoesAutomaticas(contexto.estado),
    nomeDaFonte: (id) => nomeDaFonte(contexto.estado, id),
    naRaiaDoSensor: fonteEhSensor(contexto.estado, entrada.fonte_id),
  };
}

// The cars a "??" (or a sensor's mark) may have been: one big button each,
// most likely first, with the key that presses it (1 to 6; the suggested
// one is A as well), a field for any other car and, where the flag's own
// V is not at hand, the void. Each stores a "reatribuir" of the entry;
// what it does to the count is under it, and it asks first when it adds
// a pass (see efeitoDeAtribuir).
//   opcoes: {entradaId, candidatos, sensor, comAnular, teclaA}
function blocoDeCandidatos(contexto, opcoes) {
  const semDecisao = contexto.ocupado || !contexto.podeDecidir;
  const carro = campoDeCarro('');
  const vazio = opcoes.sensor
    ? 'Nenhum carro provável: nenhum carro era esperado na linha neste momento.'
    : 'Nenhum carro provável: nenhuma outra contagem registrou carro neste momento, nem havia carro esperado na linha.';
  return el('div', { class: 'candidatos' },
    opcoes.candidatos.length === 0
      ? el('p', { class: 'contagem-dica' }, vazio)
      : el('ol', { class: 'lista-candidatos' }, opcoes.candidatos.map((candidato) => el('li', {},
        el('button', {
          type: 'button',
          class: candidato.efeito.voltas > 0 ? 'candidato soma-volta' : 'candidato',
          disabled: semDecisao,
          'data-carro': candidato.carro,
          onclick: () => contexto.acoes.atribuir(opcoes.entradaId, candidato.carro),
        },
        el('kbd', {}, candidato.tecla), opcoes.teclaA === candidato.carro ? [' ', el('kbd', {}, 'A')] : null, ' ',
        el('span', { class: 'rotulo' }, candidato.rotulo),
        el('small', {}, candidato.efeito.nota))))),
    el('form', {
      class: 'em-linha atribuir-outro',
      onsubmit: (evento) => {
        evento.preventDefault();
        contexto.acoes.atribuir(opcoes.entradaId, carro.value === '' ? null : Number(carro.value));
      },
    }, 'Outro carro: ', carro, ' ', el('button', { type: 'submit', disabled: semDecisao }, 'Atribuir')),
    opcoes.comAnular
      ? el('button', { type: 'button', class: 'anular-ilegivel', disabled: semDecisao, onclick: () => contexto.acoes.anularEntrada(opcoes.entradaId) },
        opcoes.sensor ? 'Anular a marca (não foi passagem)' : 'Anular (não passou carro)')
      : null);
}

// The open "numero_ilegivel" flag of an entry, if any.
const alertaDoIlegivel = (estado, entradaId) => (estado.alertas_abertos ?? [])
  .find((alerta) => alerta.tipo === 'numero_ilegivel' && (alerta.passagem_ids ?? [])[0] === entradaId) ?? null;

// A "??" or a mark clicked on the timeline: who gave it its car, and the
// cars it may have been. Null for an entry typed with a number. On the
// flag about this very entry the flag's own buttons are above already.
function partesDoIlegivel(contexto, entrada) {
  const sensor = fonteEhSensor(contexto.estado, entrada.fonte_id);
  const doAlerta = alertaDoIlegivel(contexto.estado, entrada.id);
  const doProprioAlerta = (contexto.alerta?.passagem_ids ?? [])[0] === entrada.id
    && ['numero_ilegivel', 'numero_atribuido'].includes(contexto.alerta?.tipo);
  if (doProprioAlerta || entrada.anulada) {
    return null;
  }
  const situacao = situacaoDoIlegivel(entrada);
  if (situacao === 'automatico') {
    const trocando = contexto.trocandoId === entrada.id;
    return [
      el('div', { class: 'botoes' }, el('button', { type: 'button', class: trocando ? 'ativo' : '', onclick: () => contexto.acoes.alternarTroca(entrada.id) },
        `Não é o carro ${entrada.carro}…`)),
      trocando ? blocoDeCandidatos(contexto, {
        entradaId: entrada.id, candidatos: candidatosParaTrocar(entrada, contexto.janela, contexto.estado), sensor: false, comAnular: true,
      }) : null,
    ];
  }
  // A mark that times a pass ('marca') is not asked about: the counters
  // said which car it was.
  if (situacao === 'pendente') {
    const candidatos = doAlerta
      ? candidatosDoAlerta(doAlerta, contexto.janela, contexto.estado)
      : candidatosParaTrocar(entrada, contexto.janela, contexto.estado);
    return [el('p', { class: 'contagem-dica' }, sensor ? 'Que carro passou nesta marca?' : 'Que carro era?'),
      blocoDeCandidatos(contexto, { entradaId: entrada.id, candidatos, sensor, comAnular: true })];
  }
  return null;
}

// What can be done with the chip the reviewer clicked.
function caixaDaEntrada(contexto, entrada) {
  const doIlegivel = ehIlegivel(entrada) || ehMarcaDoSensor(entrada) ? partesDoIlegivel(contexto, entrada) : null;
  // The block of candidates has its own field and void.
  const opcoesDaEntrada = acoesDaEntrada(entrada, repeticoesEmAberto(contexto.estado), contexto.janela)
    .filter((opcao) => doIlegivel === null || !['anular', 'reatribuir'].includes(opcao.acao));
  const botoes = opcoesDaEntrada.map((opcao) => {
    const agir = (extra) => contexto.acoes.acaoNaEntrada(opcao, entrada, extra);
    switch (opcao.acao) {
      case 'desfazer_confirmacao':
        // The quick way out of a hurried confirmation; it asks first.
        return el('button', { type: 'button', class: 'tira-volta', disabled: contexto.ocupado, onclick: () => agir() },
          rotuloDeDesfazerConfirmacao(opcao));
      case 'anular':
        return el('button', { type: 'button', disabled: contexto.ocupado, onclick: () => agir() }, 'Anular');
      case 'confirmar':
        // Said in full: on a pass that does not count today this button adds a lap.
        return el('button', { type: 'button', class: opcao.maisUmaPassagem ? 'soma-volta' : '', disabled: contexto.ocupado, onclick: () => agir() },
          opcao.maisUmaPassagem ? 'Contar esta passagem (+1 volta)' : 'Está correto, parar de avisar');
      case 'descartar_passagem':
        return el('button', { type: 'button', disabled: contexto.ocupado, onclick: () => agir() },
          `Descartar esta passagem (anula os ${opcao.registros} registros)`);
      case 'restaurar':
        return el('button', { type: 'button', disabled: contexto.ocupado, onclick: () => agir() }, 'Restaurar (desfazer a anulação)');
      case 'remover':
        return el('button', { type: 'button', disabled: contexto.ocupado, onclick: () => agir() }, 'Remover a passagem adicionada');
      case 'desfazer_troca':
        return el('button', { type: 'button', disabled: contexto.ocupado, onclick: () => agir() },
          entrada.carro_digitado === null || entrada.carro_digitado === undefined
            ? 'Voltar a ficar sem número (??)'
            : `Voltar para o carro digitado (${entrada.carro_digitado})`);
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
      el('strong', {}, rotuloDaEntrada(entrada, fonteEhSensor(contexto.estado, entrada.fonte_id))), ' · ',
      descricaoDoChip(entrada, contextoDaDica(contexto, entrada)), ' · ',
      entrada.qualidade_tempo === 'desconhecido' ? 'sem horário' : tempoDeProva(entrada.ts_ms, contexto.estado.corrida.largada_ms)),
    figuraDaEntrada(entrada),
    el('div', { class: 'botoes' }, botoes),
    doIlegivel);
}

// The picture an automatic source linked to the entry, if any. It comes
// from the system itself, usually over plain http on the track's network:
// from anywhere else, or from a page on https, it may not load. Then the
// placeholder says so, and the link still opens it in a tab of its own.
function figuraDaEntrada(entrada) {
  const imagem = imagemDaEntrada(entrada);
  if (!imagem) {
    return null;
  }
  const link = el('a', { href: imagem.url, target: '_blank', rel: 'noopener noreferrer' }, 'abrir em outra aba');
  const legenda = el('figcaption', {}, imagem.confianca ? `${imagem.confianca} · ` : '', link);
  const figura = el('figure', { class: 'imagem-entrada' });
  const foto = el('img', {
    src: imagem.url,
    alt: `imagem do registro #${entrada.id}`,
    loading: 'lazy',
    onerror: () => foto.replaceWith(el('span', { class: 'imagem-indisponivel' }, SEM_IMAGEM_NA_REDE)),
  });
  figura.append(foto, legenda);
  return figura;
}

// Said where the buttons are, since they wait for it: the timeline above
// says the same, with its "Tentar de novo".
function esperaPelaLinha(contexto) {
  if (contexto.erroDaJanela) {
    return el('p', { class: 'contagem-dica' }, 'A linha do tempo não carregou (veja acima): as decisões esperam por ela.');
  }
  if (!contexto.janela) {
    return el('p', { class: 'contagem-dica' }, 'Carregando a linha do tempo…');
  }
  return null;
}

function entradaSelecionada(contexto) {
  if (contexto.entradaSelecionadaId === null || contexto.entradaSelecionadaId === undefined) {
    return null;
  }
  const entrada = encontrarEntrada(contexto.janela, contexto.entradaSelecionadaId);
  return entrada ? caixaDaEntrada(contexto, entrada) : null;
}

// A "??" flag: the moment and the source, then the cars it may have been.
// An assignment the engine made by itself: why, and "Não é o carro 7…",
// which opens the other cars read near it.
function partesDoAlertaIlegivel(contexto, alerta) {
  const { estado } = contexto;
  const entradaId = (alerta.passagem_ids ?? [])[0];
  const sensor = fonteEhSensor(estado, alerta.fonte_id);
  const fonte = nomeDaFonte(estado, alerta.fonte_id) ?? 'fonte desconhecida';
  const onde = el('p', { class: 'ilegivel-onde' },
    el('strong', {}, sensor ? 'Marca do sensor' : 'Passagem sem número ("??")'),
    ` · ${fonte} · ${tempoDeProva(alerta.ts_ms, estado.corrida.largada_ms)}`);
  if (alerta.tipo === 'numero_ilegivel') {
    return [onde, blocoDeCandidatos(contexto, {
      entradaId,
      candidatos: candidatosDoAlerta(alerta, contexto.janela, estado),
      sensor,
      comAnular: false,
      teclaA: alerta.sugestao?.tipo === 'reatribuir' ? alerta.sugestao.equipe_numero : null,
    })];
  }
  const carro = alerta.atribuicao?.carro ?? (alerta.carros ?? [])[0];
  const entrada = encontrarEntrada(contexto.janela, entradaId);
  const trocando = contexto.trocandoId === entradaId;
  return [
    onde,
    el('p', { class: 'vazio' }, `Resolvido automaticamente (${alerta.atribuicao
      ? fraseDaAtribuicao(alerta.atribuicao, (id) => nomeDaFonte(estado, id))
      : `atribuído automaticamente ao carro ${carro}`}). Uma decisão de revisor sobre este registro vale mais que a do sistema.`),
    el('div', { class: 'botoes' },
      el('button', { type: 'button', class: trocando ? 'ativo' : '', onclick: () => contexto.acoes.alternarTroca(entradaId) },
        `Não é o carro ${carro}…`)),
    trocando && entrada
      ? blocoDeCandidatos(contexto, {
        entradaId, candidatos: candidatosParaTrocar(entrada, contexto.janela, estado), sensor: false, comAnular: true,
      })
      : null,
    trocando && !entrada ? esperaPelaLinha(contexto) : null,
  ];
}

// The title of a flag: its kind and its cars; for a "??" the cars it may have been.
function tituloDoAlerta(alerta, estado) {
  const ilegivel = nomeDoAlertaIlegivel(alerta, estado);
  if (ilegivel !== null) {
    const carros = alerta.tipo === 'numero_atribuido' ? ` · carro ${listaDeCarros(alerta.carros)}` : '';
    return `${ilegivel}${carros}`;
  }
  return `${NOMES_DE_ALERTA[alerta.tipo] ?? alerta.tipo} · carro ${listaDeCarros(alerta.carros)}`;
}

export function desenharDetalheDeAlerta(container, contexto) {
  const { alerta, estado, acoes, ocupado } = contexto;
  const ilegivel = ['numero_ilegivel', 'numero_atribuido'].includes(alerta.tipo);
  const sensor = ilegivel && fonteEhSensor(estado, alerta.fonte_id);
  const olhando = alerta.olhando ?? [];
  // A / K / V act on the timeline below. While it is loading, or behind the
  // flag list, there is nothing to act on.
  const semDecisao = ocupado || !contexto.podeDecidir;
  const semEntradas = (alerta.passagem_ids ?? []).length === 0;
  const aceitar = acaoDeAceitar(alerta, estado);
  const confirmar = acaoDeConfirmar(alerta);
  const sugereAnular = alerta.sugestao?.tipo === 'anular';
  const duvida = fraseDeDuvida(alerta);
  const semHorario = alerta.tipo === 'tempo_desconhecido';
  // The other buttons of this kind of flag: mark the exit, discard a pass, add the pass by hand.
  const extras = acoesExtrasDoAlerta(alerta, estado.corrida.largada_ms, estado).map((extra) => {
    switch (extra.acao) {
      case 'confirmar_saida':
        return el('button', { type: 'button', class: 'primario', disabled: semDecisao, onclick: acoes.confirmarSaida }, extra.rotulo);
      case 'marcar_saida':
        return el('button', {
          type: 'button', class: /\(\+\d+ voltas?\)/.test(extra.rotulo) ? 'soma-volta' : '', disabled: semDecisao, onclick: acoes.marcarSaida,
        }, extra.rotulo);
      case 'descartar_passagem':
        return el('button', { type: 'button', disabled: semDecisao, onclick: () => acoes.descartarPassagem(extra.passagem) }, extra.rotulo);
      default:
        return el('button', { type: 'button', class: contexto.modoManual ? 'ativo' : '', onclick: acoes.alternarManual }, extra.rotulo);
    }
  });

  // "Usar o tempo de …": whose time is right, on a crossing typed late or a
  // held pass whose entries are too far apart. Each says what it does to
  // the count in force (+1, −1, or nothing); it asks.
  const tempos = temposParaEscolher(alerta, estado).map((tempo, indice) => el('button', {
    type: 'button', class: tempo.voltas > 0 ? 'soma-volta usar-tempo' : 'usar-tempo', disabled: semDecisao, onclick: () => acoes.usarTempo(indice),
  }, `${[tempo.rotulo, efeitoDeUsarTempo(tempo)].filter(Boolean).join(' ')} `, el('kbd', {}, tempo.tecla)));

  substituir(container,
    el('header', { class: 'detalhe-titulo' },
      el('h2', {}, tituloDoAlerta(alerta, estado)),
      el('span', { class: 'quando' }, quandoDoAlerta(alerta, estado.corrida.largada_ms)),
      el('button', { type: 'button', class: 'fechar', onclick: acoes.fechar }, 'Fechar')),
    olhando.length > 0 ? el('p', { class: 'olhando' }, `${olhando.join(', ')} também está olhando este alerta.`) : null,
    // Said first and loud: a standings number may be wrong while this is open.
    duvida ? el('p', { class: 'contagem-em-duvida', role: 'alert' }, el('strong', {}, 'Contagem em dúvida: '), duvida) : null,
    el('p', { class: 'motivo' }, alerta.motivo),
    ilegivel ? null : el('p', { class: 'sugestao' }, descreverSugestao(alerta)),
    ilegivel ? partesDoAlertaIlegivel(contexto, alerta) : null,
    semHorario
      ? el('p', { class: 'contagem-dica' }, 'Este registro não tem horário confiável e não conta. Se a passagem aconteceu, use "Adicionar passagem…" '
        + 'e digite o tempo de prova; depois anule este registro (V) para fechar o alerta.')
      : null,
    alerta.auto_resolvido && alerta.tipo === 'numero_atribuido' ? null : alerta.auto_resolvido
      ? el('p', { class: 'vazio' }, 'Resolvido automaticamente. Use os registros abaixo se quiser mudar o resultado.')
      : el('div', { class: 'botoes botoes-decisao' },
        // On a "??" the candidates above are the choices, the suggested
        // one marked A; a second button saying the same would only confuse.
        ilegivel ? null : el('button', {
          type: 'button', class: aceitar?.pergunta ? 'primario soma-volta' : 'primario', disabled: semDecisao || !aceitar, onclick: acoes.aceitar,
        }, `${aceitar?.rotulo ?? 'Aceitar sugestão'} `, el('kbd', {}, 'A')),
        // No K on a repeat: "keep both" would count the car twice.
        confirmar
          ? el('button', {
            type: 'button', class: confirmar.maisUmaPassagem ? 'soma-volta' : '', disabled: semDecisao, onclick: acoes.confirmarAlerta,
          }, `${confirmar.rotulo} `, el('kbd', {}, 'K'))
          : null,
        alerta.tipo === 'repetida'
          ? el('button', { type: 'button', disabled: semDecisao || !sugereAnular, onclick: () => acoes.eraOutroCarro(alerta.sugestao.passagem_id) },
            'Era outro carro…')
          : null,
        tempos,
        extras,
        el('button', { type: 'button', disabled: semDecisao || semEntradas, onclick: acoes.anular },
          `${ilegivel ? (sensor ? 'Anular a marca (não foi passagem)' : 'Anular (não passou carro)') : 'Anular registro'} `, el('kbd', {}, 'V')),
        // On an entry of unknown time the form is opened by "Adicionar passagem…" above.
        semHorario ? null : el('button', { type: 'button', class: contexto.modoManual ? 'ativo' : '', onclick: acoes.alternarManual },
          'Editar manualmente')),
    campoDeMotivo(contexto),
    esperaPelaLinha(contexto),
    el('p', { class: 'contagem-dica' }, alerta.tipo === 'numero_ilegivel'
      ? 'O registro sem número está contornado na linha do tempo acima; as passagens dos carros possíveis têm o número do botão.'
      : 'Os registros deste alerta estão contornados na linha do tempo acima; clique em um para agir sobre ele.'),
    entradaSelecionada(contexto),
    // What is filled in there, and whether a "move the stray entry" form
    // comes first, depends on the kind of flag: see edicaoManualDoAlerta().
    contexto.modoManual ? edicaoManual(contexto, alerta) : null);
}

// A chip clicked on the live timeline, with no flag or car open.
export function desenharDetalheDeEntrada(container, contexto) {
  const entrada = encontrarEntrada(contexto.janela, contexto.entradaSelecionadaId);
  const rotulo = rotuloDaEntrada(entrada, fonteEhSensor(contexto.estado, entrada.fonte_id));
  substituir(container,
    el('header', { class: 'detalhe-titulo' },
      el('h2', {}, ehIlegivel(entrada) || ehMarcaDoSensor(entrada) ? rotulo : `Registro · carro ${entrada.carro}`),
      el('button', { type: 'button', class: 'fechar', onclick: () => contexto.acoes.escolherEntrada(entrada.id) }, 'Fechar')),
    caixaDaEntrada(contexto, entrada),
    campoDeMotivo(contexto));
}

export function desenharDetalheDeCarro(container, contexto) {
  const { carro, acoes } = contexto;
  const passagemZero = (carro.passagens ?? 0) > carro.voltas ? ' + passagem zero' : '';

  substituir(container,
    el('header', { class: 'detalhe-titulo' },
      el('h2', {}, `Carro ${carro.carro}${carro.equipe ? ` · ${carro.equipe}` : ''}`),
      el('span', { class: 'quando' }, carro.escola ?? ''),
      el('button', { type: 'button', class: 'fechar', onclick: acoes.fechar }, 'Fechar')),
    el('p', { class: 'motivo' },
      `${carro.voltas} voltas${passagemZero} · ${NOMES_DE_STATUS[carro.status] ?? carro.status}`,
      ' · ',
      el('span', { class: 'com-dica', title: AVISO_TEMPO_DE_VOLTA },
        `última volta ${duracao(carro.ultima_volta_ms)} · melhor volta ${duracao(carro.melhor_volta_ms)}`)),
    carro.classificado === false
      ? el('p', { class: 'revisao-aviso' }, situacaoDoCarroFora(carro).frase)
      : null,
    el('p', { class: 'contagem-dica' }, 'A linha do tempo acima mostra a corrida inteira deste carro ("Só o carro" desligado: '
      + 'todos os carros, este em destaque). Clique em um registro para agir sobre ele.'),
    entradaSelecionada(contexto),
    campoDeMotivo(contexto),
    formularioAdicionar(contexto, carro.carro, null));
}
