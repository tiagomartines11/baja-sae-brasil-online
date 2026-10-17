// The detail panel: the selected flag (or the selected car's whole race)
// with its lane timeline and the decision buttons.
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
import { acoesDaEntrada, descreverSugestao, repeticoesEmAberto } from './revisao-decisoes.js';
import { mostraCorridaInteira, quandoDoAlerta, situacaoDoCarroFora } from './revisao-fila.js';
import { desenharRaias } from './revisao-raias.js';
import { SEM_IMAGEM_NA_REDE, imagemDaEntrada } from './revisao-observacao.js';
import { ESCALA_ALERTA, ESCALA_CARRO, descricaoDoChip, encontrarEntrada } from './revisao-raias-logica.js';

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

// What can be done with the chip the reviewer clicked.
function caixaDaEntrada(contexto, entrada) {
  const botoes = acoesDaEntrada(entrada, repeticoesEmAberto(contexto.estado)).map((opcao) => {
    const agir = (extra) => contexto.acoes.acaoNaEntrada(opcao, entrada, extra);
    switch (opcao.acao) {
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
      entrada.qualidade_tempo === 'desconhecido' ? 'sem horário' : tempoDeProva(entrada.ts_ms, contexto.estado.corrida.largada_ms)),
    figuraDaEntrada(entrada),
    el('div', { class: 'botoes' }, botoes));
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
      el('h2', {}, `${NOMES_DE_ALERTA[alerta.tipo] ?? alerta.tipo} · carro ${listaDeCarros(alerta.carros)}`),
      el('span', { class: 'quando' }, quandoDoAlerta(alerta, estado.corrida.largada_ms)),
      el('button', { type: 'button', class: 'fechar', onclick: acoes.fechar }, 'Fechar')),
    olhando.length > 0 ? el('p', { class: 'olhando' }, `${olhando.join(', ')} também está olhando este alerta.`) : null,
    // Said first and loud: a standings number may be wrong while this is open.
    duvida ? el('p', { class: 'contagem-em-duvida', role: 'alert' }, el('strong', {}, 'Contagem em dúvida: '), duvida) : null,
    el('p', { class: 'motivo' }, alerta.motivo),
    el('p', { class: 'sugestao' }, descreverSugestao(alerta)),
    semHorario
      ? el('p', { class: 'contagem-dica' }, 'Este registro não tem horário confiável e não conta. Se a passagem aconteceu, use "Adicionar passagem…" '
        + 'e digite o tempo de prova; depois anule este registro (V) para fechar o alerta.')
      : null,
    alerta.auto_resolvido
      ? el('p', { class: 'vazio' }, 'Resolvido automaticamente. Use os registros abaixo se quiser mudar o resultado.')
      : el('div', { class: 'botoes botoes-decisao' },
        el('button', {
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
          'Anular registro ', el('kbd', {}, 'V')),
        // On an entry of unknown time the form is opened by "Adicionar passagem…" above.
        semHorario ? null : el('button', { type: 'button', class: contexto.modoManual ? 'ativo' : '', onclick: acoes.alternarManual },
          'Editar manualmente')),
    campoDeMotivo(contexto),
    avisoDesatualizado(contexto),
    raiasOuCarregando(contexto, mostraCorridaInteira(alerta)
      // The car's whole race: the entry of unknown time is listed under it.
      ? { escala: ESCALA_CARRO, idsEmDestaque: alerta.passagem_ids ?? [], centrarEmMs: null }
      : { escala: ESCALA_ALERTA, idsEmDestaque: alerta.passagem_ids ?? [], centrarEmMs: alerta.ts_ms }),
    el('p', { class: 'contagem-dica' }, LEGENDA),
    entradaSelecionada(contexto),
    // What is filled in there, and whether a "move the stray entry" form
    // comes first, depends on the kind of flag: see edicaoManualDoAlerta().
    contexto.modoManual ? edicaoManual(contexto, alerta) : null);
}

const LEGENDA = 'Clique em um registro para agir sobre ele. Riscado = anulado; contorno = envolvido neste alerta; '
  + '"0" no canto = passagem zero (não é volta); tracejado = não conta (antes da largada, janela de largada ou depois do encerramento); '
  + 'tracejado vermelho = passagem retida (não conta até um revisor decidir); '
  + '"~" = horário estimado; "?" = horário desconhecido (fora da linha do tempo).';

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
    avisoDesatualizado(contexto),
    raiasOuCarregando(contexto, { escala: ESCALA_CARRO, idsEmDestaque: [], centrarEmMs: null }),
    el('p', { class: 'contagem-dica' }, 'A corrida inteira deste carro. Role para os lados. ', LEGENDA),
    entradaSelecionada(contexto),
    campoDeMotivo(contexto),
    formularioAdicionar(contexto, carro.carro, null));
}
