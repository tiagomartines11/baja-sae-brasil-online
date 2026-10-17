// Decides what the fixed warning banner says. No DOM here: state in, text out,
// so every combination can be tested.
//
// Two rules a counter's data depends on:
//   - Two problems at once give two warnings. One must never hide the other.
//   - While records live only in this page's memory, nothing on the screen
//     may invite a reload. The "try again" button used to reload the page;
//     it now retries in place, and is not offered at all in that situation,
//     where the page retries by itself instead.

import { CONEXAO } from './sync-queue.js';
import { ARMAZENAMENTO } from './storage-open.js';
import { TEXTO_BLOQUEIO } from './access.js';

const TEXTO_REVOGADO = 'Este aparelho foi desconectado desta contagem e não está enviando. '
    + 'Tudo o que você registrou continua guardado aqui. Toque em “Exportar meus dados” '
    + 'e procure o responsável pela prova. Se você for liberado de novo, o envio volta sozinho.';

const TEXTO_DISPOSITIVO_BLOQUEADO = 'Este aparelho foi bloqueado nesta contagem pelo responsável pela prova '
    + 'e não está enviando. Tudo o que você registrou continua guardado aqui. Peça ao responsável para '
    + 'vincular você de novo ou tocar em “liberar” na página de fontes: o envio volta sozinho em até meio minuto. '
    + 'Se precisar, toque em “Exportar meus dados”.';

const MOTIVO_SEM_CONFIRMACAO = 'sem_confirmacao';
const TEXTO_MOTIVO_RECUSA = {
    simulacao_nao_permitida: 'a simulação não está permitida nesta prova',
    abastecimento_desligado: 'o abastecimento está desligado nesta prova',
    [MOTIVO_SEM_CONFIRMACAO]: 'o servidor não confirmou o recebimento',
};

/**
 * @param {string[]} motivos one reason per refused record
 * @returns {?string} null when nothing was refused
 */
function describeRefused(motivos) {
    if (motivos.length === 0) {
        return null;
    }
    const reasons = Array.from(new Set(motivos), (motivo) => TEXTO_MOTIVO_RECUSA[motivo] || motivo);
    const quantity = motivos.length === 1 ? '1 registro foi recusado' : motivos.length + ' registros foram recusados';
    return quantity + ' pelo servidor (' + reasons.join('; ') + '). Nada foi apagado: '
        + (motivos.length === 1 ? 'ele continua' : 'eles continuam') + ' neste aparelho e em “Exportar meus dados”. '
        + 'Avise o responsável pela prova.';
}

const TEXTO_SEM_DISCO = 'Este navegador não deixa guardar os registros no aparelho. '
    + 'NÃO recarregue nem feche esta página enquanto houver registros na fila.';

const TEXTO_DISCO_NAO_ABRIU = 'Não foi possível abrir os registros guardados neste aparelho. '
    + 'Eles não foram apagados, mas esta página não consegue ver nem enviar o que foi registrado antes. '
    + 'O que você digitar agora fica só nesta página: NÃO recarregue nem feche enquanto houver '
    + 'registros na fila. Avise o responsável pela prova.';

const TEXTO_PROBLEMA_TOKEN = {
    sem_permissao: 'Você não tem mais permissão para esta contagem. Os registros continuam aqui; '
        + 'procure o responsável pela prova.',
    sem_sessao: 'Sua sessão expirou e este aparelho ainda não foi autorizado a enviar. '
        + 'Os registros continuam aqui. Entre de novo em outra aba, sem fechar esta; '
        + 'o envio começa sozinho.',
};

const TEXTO_ARMAZENAMENTO = {
    [ARMAZENAMENTO.SEM_SUPORTE]: TEXTO_SEM_DISCO,
    [ARMAZENAMENTO.NAO_ABRIU]: TEXTO_DISCO_NAO_ABRIU,
};

/**
 * @param {object}  state    SyncQueue.getState()
 * @param {object}  problems
 * @param {?string} problems.tokenProblem  'sem_permissao' | 'dispositivo_revogado' | 'sem_sessao' | 'sem_rede' | null
 * @param {string}  problems.armazenamento one of ARMAZENAMENTO.*
 * @param {?string} [problems.bloqueio]   access.js BLOQUEIO.* while nothing new may be recorded
 * @returns {{avisos: string[], oferecerNovaTentativa: boolean}}
 *          `avisos`: every warning that applies, most dangerous first.
 *          `oferecerNovaTentativa`: whether to show the "Tentar de novo" button.
 */
export function describeBanner(state, problems) {
    const avisos = [];

    // First: it says why the entry controls are gone.
    const accessWarning = TEXTO_BLOQUEIO[problems.bloqueio] || null;
    if (accessWarning !== null) {
        avisos.push(accessWarning);
    }

    const storageWarning = TEXTO_ARMAZENAMENTO[problems.armazenamento] || null;
    if (storageWarning !== null) {
        avisos.push(storageWarning);
    }

    let uploadWarning = null;
    if (problems.tokenProblem === 'dispositivo_revogado') {
        // The server has said WHY the token stopped working; that is more
        // useful than the general "desconectado" text below.
        uploadWarning = TEXTO_DISPOSITIVO_BLOQUEADO;
    } else if (state.conexao === CONEXAO.REVOGADO) {
        uploadWarning = TEXTO_REVOGADO;
    } else if (TEXTO_PROBLEMA_TOKEN[problems.tokenProblem]) {
        uploadWarning = TEXTO_PROBLEMA_TOKEN[problems.tokenProblem];
    }
    if (uploadWarning !== null) {
        avisos.push(uploadWarning);
    }

    const refusedMotivos = Array.isArray(state.recusados) ? state.recusados : [];
    const refusedWarning = describeRefused(refusedMotivos);
    if (refusedWarning !== null) {
        avisos.push(refusedWarning);
    }
    // Trying again can help with an upload problem, and with records the
    // server never answered about. It cannot help with a record the server
    // refused with a reason.
    const retryCanHelp = uploadWarning !== null || refusedMotivos.includes(MOTIVO_SEM_CONFIRMACAO);

    return {
        avisos,
        oferecerNovaTentativa: retryCanHelp && storageWarning === null,
    };
}

/** Text of the connection chip in the status bar. */
export function describeConnection(state, problems) {
    if (state.turnoEncerrado) {
        return 'Turno encerrado';
    }
    if (state.conexao === CONEXAO.CONECTANDO && problems.armazenamento !== ARMAZENAMENTO.DISCO) {
        // A memory-only page does not call the server until there is
        // something to send; "Conectando…" would never go away.
        return 'Envia ao registrar';
    }
    return {
        [CONEXAO.CONECTANDO]: 'Conectando…',
        [CONEXAO.ONLINE]: 'Conectado',
        [CONEXAO.OFFLINE]: 'Sem conexão',
        [CONEXAO.REVOGADO]: 'Envio bloqueado',
        [CONEXAO.SEM_PERMISSAO]: 'Sem permissão',
    }[state.conexao];
}

// The status bar keeps the same height whatever it says (style sheet:
// .barra-linha): a line that wrapped moved the keypad and the tiles under the
// counter's finger. So its texts are short, and the long form goes in the
// title (a long press or hover shows it).

/**
 * "Na fila": records not uploaded yet, saying how many of them wait for the
 * official time (typed before this page knew the server's time).
 *
 * @returns {{texto: string, titulo: string}}
 */
export function describeQueue(state) {
    const held = state.aguardandoHorario > 0 ? state.aguardandoHorario : 0;
    return {
        texto: String(state.pendentes) + (held > 0 ? ' (' + held + ' sem horário)' : ''),
        titulo: state.pendentes + ' na fila' + (held > 0 ? ', ' + held + ' aguardando horário oficial' : ''),
    };
}

const OUTROS_VISIVEIS = 1;

/**
 * Who else is counting on this source: one name, then how many more (the
 * line is short; a handover is usually one other person).
 *
 * @param {string[]} names
 * @returns {{texto: string, titulo: string}}
 */
export function describeOthers(names) {
    if (names.length === 0) {
        return { texto: 'ninguém', titulo: 'ninguém' };
    }
    const shown = names.slice(0, OUTROS_VISIVEIS).join(', ');
    const more = names.length - OUTROS_VISIVEIS;
    return { texto: more > 0 ? shown + ' +' + more : shown, titulo: names.join(', ') };
}
