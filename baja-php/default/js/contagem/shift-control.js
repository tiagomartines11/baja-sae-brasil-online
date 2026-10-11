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
import { buildClosingBeaconBody, nothingIsWaiting } from './sync-request.js';

export const TURNO = {
    ENCERRADO: 'encerrado',       // the server answered the goodbye
    PENDENTE: 'pendente',         // entries are still waiting; nothing was said
    SEM_CONEXAO: 'sem_conexao',   // the goodbye could not be delivered
    SEM_CONFIRMACAO: 'sem_confirmacao', // delivered, but the server did not say it closed the device
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
    let answeredWithoutClosing = false;
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
        if (!nothingIsWaiting(queue.store)) {
            // Something is on its way to the disk this very instant; it
            // will be in the queue on the next round.
            continue;
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
        // `encerrado` is the SERVER saying "I closed this device". Nothing
        // else counts: a 200 to a request that asked for it proves nothing
        // (the request may have gone out as an ordinary one because a pass
        // was typed in the last instant, or the server may have declined).
        if (!outcome.encerrado) {
            answeredWithoutClosing = true;
            continue;
        }
        // Checked once more before the page says "Turno encerrado": a pass
        // typed while the goodbye was in the air is waiting now, the server
        // does not have it, and a page that went quiet would sit on it.
        if (nothingIsWaiting(queue.store)) {
            await queue.setShiftEnded(true);
            return TURNO.ENCERRADO;
        }
        // Uploading it (next round) also tells the server this device is
        // counting again; then the goodbye is said anew.
    }
    if (!nothingIsWaiting(queue.store)) {
        return TURNO.PENDENTE;
    }
    return answeredWithoutClosing ? TURNO.SEM_CONFIRMACAO : TURNO.SEM_CONEXAO;
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
        vouchesForHistory: queue.vouchesForHistory,
        token,
    });
}
