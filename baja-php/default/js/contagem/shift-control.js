// "Encerrar meu turno": a counter tells the server "I am done".
//
// Why it exists: the server holds back alerts and waits with unconfirmed
// passes for as long as a device that was counting might still upload
// something. A counter who simply pockets the phone looks exactly like a
// counter who lost the signal, and the server waits for nothing. Saying
// goodbye lets it stop waiting at once.
//
// The goodbye is the field `"encerrando": true` on one last, empty sync
// request. It is only ever sent when everything this device recorded is on
// the server: a device with entries still waiting is NOT done, and the server
// must keep waiting for it.

import { CONEXAO, RESULTADO } from './sync-queue.js';
import { buildClosingBeaconBody } from './sync-request.js';

export const TURNO = {
    ENCERRADO: 'encerrado',       // the server answered the goodbye
    PENDENTE: 'pendente',         // entries are still waiting; nothing was said
    SEM_CONEXAO: 'sem_conexao',   // the goodbye could not be delivered
    BLOQUEADO: 'bloqueado',       // this device's token was revoked
};

// The heartbeat may be in the middle of a request when the button is tapped.
const MAX_GOODBYE_ATTEMPTS = 3;

/**
 * Uploads what is waiting, then says goodbye, then goes quiet.
 * @param {object} queue SyncQueue
 * @returns {Promise<string>} one of TURNO.*; anything but ENCERRADO means the
 *          shift is still open and the page keeps working as before
 */
export async function endShift(queue) {
    for (let attempt = 0; attempt < MAX_GOODBYE_ATTEMPTS; attempt++) {
        try {
            await queue.flush();
        } catch (storageError) {
            // Could not mark something as sent; it then still counts as waiting.
        }
        if (queue.conexao === CONEXAO.REVOGADO) {
            return TURNO.BLOQUEADO;
        }
        if (queue.store.pendingCount() > 0) {
            return TURNO.PENDENTE;
        }
        const outcome = await queue.syncOnce({ encerrando: true });
        if (outcome.resultado === RESULTADO.OCUPADO) {
            continue;
        }
        if (outcome.resultado === RESULTADO.REVOGADO) {
            return TURNO.BLOQUEADO;
        }
        if (outcome.resultado !== RESULTADO.OK) {
            return TURNO.SEM_CONEXAO;
        }
        if (outcome.encerrado) {
            await queue.setShiftEnded(true);
            return TURNO.ENCERRADO;
        }
        // The request went out as an ordinary one: something was recorded
        // in the last instant. Go round again and upload it first.
    }
    return queue.store.pendingCount() > 0 ? TURNO.PENDENTE : TURNO.SEM_CONEXAO;
}

/**
 * "Retomar": the next request is an ordinary one again, without the goodbye
 * field, and that is how the server learns this device is back.
 */
export async function resumeShift(queue) {
    await queue.setShiftEnded(false);
    queue.requestSync();
}

/**
 * The same goodbye, best effort, for a page that is being closed without the
 * button: body for `navigator.sendBeacon`, or null when nothing may be sent
 * (entries still waiting, token revoked, goodbye already said, no token).
 */
export function closingBeaconBody(queue, token) {
    if (queue.conexao === CONEXAO.REVOGADO || queue.turnoEncerrado) {
        return null;
    }
    return buildClosingBeaconBody({
        store: queue.store,
        clock: queue.clock,
        now: queue.now,
        vouchesForHistory: queue.vouchesForHistory,
        token,
    });
}
