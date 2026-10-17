// Whether this page may RECORD anything new, as opposed to only keeping and
// uploading what it already holds.
//
// The page can be shown to somebody who is not allowed to count: the offline
// copy (service-worker.js) is the page as the LAST person who opened it on
// this browser received it, with their configuration and their device token.
// It is served when the network does not answer, which is its whole purpose,
// but also when the network answers with something other than the page. When
// that answer is "Você não tem acesso a essa página" (not a redirect to the
// login: an expired forum login does not stop counting), the person in front
// of the phone has no access. The page must
// then record nothing new; but it must still upload what is queued and offer
// the export, since unsent entries are never given up.
//
// Two things put a page in that state (BLOQUEIO):
//   - ACESSO_RECUSADO: the worker served the offline copy because the server
//     refused the page. It marks that copy with a <meta> tag (see below). It
//     lasts for this page load: a reload that gets the real page ends it.
//   - SEM_PERMISSAO: the server answered an upload with 403
//     {"erro": "sem_permissao"}: this device's user has lost access. It
//     lasts until an upload is accepted again (see SyncQueue).

export const BLOQUEIO = {
    ACESSO_RECUSADO: 'acesso_recusado',
    SEM_PERMISSAO: 'sem_permissao',
};

/**
 * The mark the worker puts into the offline copy it serves in place of a
 * refused page: `<meta name="contagem-acesso" content="recusado">`.
 *
 * Why a tag in the HTML: a page cannot read the HTTP headers of its own
 * document, and a message from the worker could arrive after the first key
 * press. A tag is there before any script of the page runs, and survives
 * nothing else: the copy in the cache itself is never marked.
 */
export const ACCESS_META_NAME = 'contagem-acesso';
export const ACCESS_META_REFUSED = 'recusado';

/** Whether this page load is an offline copy served because the server refused the page. */
export function shellSaysAccessRefused(documentRef) {
    const meta = documentRef.querySelector('meta[name="' + ACCESS_META_NAME + '"]');
    return meta !== null && meta.getAttribute('content') === ACCESS_META_REFUSED;
}

/**
 * @param {object}  situation
 * @param {boolean} situation.shellRefused  shellSaysAccessRefused()
 * @param {object}  situation.queueState    SyncQueue.getState()
 * @returns {?string} one of BLOQUEIO.*, or null when recording is allowed
 */
export function recordingBlockReason({ shellRefused, queueState }) {
    if (shellRefused) {
        return BLOQUEIO.ACESSO_RECUSADO;
    }
    if (queueState && queueState.semPermissao) {
        return BLOQUEIO.SEM_PERMISSAO;
    }
    return null;
}

/** What a count or pit session rejects with while recording is not allowed. */
export class RecordingBlockedError extends Error {
    /** @param {string} motivo one of BLOQUEIO.* */
    constructor(motivo) {
        super('Recording is not allowed on this page: ' + motivo);
        this.motivo = motivo;
    }
}

/** The sentence shown where the entry controls were, and in the warning banner. */
export const TEXTO_BLOQUEIO = {
    [BLOQUEIO.ACESSO_RECUSADO]: 'Esta página não está liberada para o usuário que está conectado agora. '
        + 'Nada novo pode ser registrado aqui. Os registros já feitos neste aparelho '
        + 'continuam guardados e serão enviados; “Exportar meus dados” continua funcionando. '
        + 'Para registrar, entre com um usuário que tenha acesso e abra a página de novo.',
    [BLOQUEIO.SEM_PERMISSAO]: 'O usuário deste aparelho não tem mais permissão para registrar aqui. '
        + 'Nada novo pode ser registrado. Os registros já feitos continuam guardados neste aparelho e em '
        + '“Exportar meus dados”; o envio volta sozinho se o acesso for liberado de novo. '
        + 'Procure o responsável pela prova.',
};
