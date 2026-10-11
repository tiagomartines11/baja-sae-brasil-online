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

const TEXTO_REVOGADO = 'Este aparelho foi desconectado desta contagem e não está enviando. '
    + 'Tudo o que você registrou continua guardado aqui. Toque em “Exportar meus dados” '
    + 'e procure o responsável pela prova. Se você for liberado de novo, o envio volta sozinho.';

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
 * @param {?string} problems.tokenProblem  'sem_permissao' | 'sem_sessao' | 'sem_rede' | null
 * @param {string}  problems.armazenamento one of ARMAZENAMENTO.*
 * @returns {{avisos: string[], oferecerNovaTentativa: boolean}}
 *          `avisos`: every warning that applies, most dangerous first.
 *          `oferecerNovaTentativa`: whether to show the "Tentar de novo" button.
 */
export function describeBanner(state, problems) {
    const avisos = [];

    const storageWarning = TEXTO_ARMAZENAMENTO[problems.armazenamento] || null;
    if (storageWarning !== null) {
        avisos.push(storageWarning);
    }

    let uploadWarning = null;
    if (state.conexao === CONEXAO.REVOGADO) {
        uploadWarning = TEXTO_REVOGADO;
    } else if (TEXTO_PROBLEMA_TOKEN[problems.tokenProblem]) {
        uploadWarning = TEXTO_PROBLEMA_TOKEN[problems.tokenProblem];
    }
    if (uploadWarning !== null) {
        avisos.push(uploadWarning);
    }

    return {
        avisos,
        oferecerNovaTentativa: uploadWarning !== null && storageWarning === null,
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
    }[state.conexao];
}
