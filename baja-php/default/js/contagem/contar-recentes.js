// The "últimos registros" list of the count page: the last 10 entries of THIS
// device, newest first, each with its own "desfazer" and, in full, the
// warnings it got when typed (the big flash only shows a short line).
// A "??" shows "??" and one big button, "Informar número"
// (correction-mode.js); the older "??" still without a number stay below
// the last 10 (CountSession.recentEntries).

import { describeRecordTime } from './race-clock.js';
import { waitsForNumber } from './count-session.js';
import { MOTIVO_SEM_CONFIRMACAO } from './refused-records.js';

function span(className, text) {
    const node = document.createElement('span');
    node.className = className;
    node.textContent = text;
    return node;
}

function uploadStateText(entry) {
    if (entry.refused) {
        return 'recusado pelo servidor';
    }
    if (entry.voided) {
        return entry.void_sent ? 'desfeito' : 'desfeito (na fila)';
    }
    return entry.sent ? 'enviado' : 'na fila';
}

/** The small mark of a corrected "??": empty for any other entry. */
function fixStateText(entry) {
    if (entry.car !== null || !Number.isInteger(entry.fix_car)) {
        return '';
    }
    if (entry.fix_refused) {
        return entry.fix_refused_reason === MOTIVO_SEM_CONFIRMACAO ? 'correção não confirmada' : 'correção recusada';
    }
    return entry.fix_sent ? 'corrigido' : 'corrigido (na fila)';
}

/** A "??" can be given a number (again) until the server refuses that. */
function mayBeCorrected(entry) {
    return entry.car === null && !entry.voided && !entry.refused && !entry.fix_refused;
}

/** Official time of the entry, saying so when it is not exact (see server-clock.js). */
export function timeText(entry, timeZone) {
    const text = describeRecordTime(entry, timeZone);
    if (entry.time_quality === 'estimado') {
        return text + ' (estimado)';
    }
    return entry.time_quality === 'desconhecido' ? text + ' (relógio do aparelho)' : text;
}

/** An undone entry's warnings no longer matter: they are not shown. */
function warningsShown(entry) {
    return entry.voided || !Array.isArray(entry.avisos) ? [] : entry.avisos;
}

/**
 * @param {object}      options
 * @param {HTMLElement} options.listElement
 * @param {object}      options.session   CountSession
 * @param {function}    options.onUndo    (uuid) => void
 * @param {function}    [options.onInform] (entry) => void: "Informar número" on a "??"
 * @param {function}    [options.correctingUuid] () => ?string: the "??" being corrected now
 * @param {?string}     [options.timeZone] the event's time zone, for the times shown
 */
export function createRecentList({
    listElement, session, onUndo, onInform = () => {}, correctingUuid = () => null, timeZone = null,
}) {
    let renderedSignature = null;

    return {
        render() {
            const entries = session.recentEntries();
            const correcting = correctingUuid();
            // The list is asked to redraw after every sync (every 5 s). It is
            // rebuilt only when something visible changed, so a "desfazer"
            // button is never swapped out from under a finger.
            const signature = entries
                .map((entry) => [entry.uuid, uploadStateText(entry), entry.nomeEquipe, entry.ts_ms, entry.carro,
                    fixStateText(entry), entry.uuid === correcting,
                    ...warningsShown(entry).map((aviso) => aviso.mensagem)].join('|'))
                .join('\n');
            if (signature === renderedSignature) {
                return;
            }
            renderedSignature = signature;

            listElement.replaceChildren();
            for (const entry of entries) {
                const item = document.createElement('li');
                const unread = entry.carro === null;
                item.className = 'recente' + (entry.voided ? ' recente-desfeito' : '')
                    + (unread ? ' recente-ilegivel' : '') + (entry.uuid === correcting ? ' recente-corrigindo' : '');
                item.appendChild(span('recente-numero', unread ? '??' : String(entry.carro)));
                item.appendChild(span('recente-equipe', unread ? 'Número não lido' : (entry.nomeEquipe || 'Número desconhecido')));
                item.appendChild(span('recente-hora', timeText(entry, timeZone)));
                item.appendChild(span('recente-estado', uploadStateText(entry)));
                const fixState = fixStateText(entry);
                if (fixState !== '') {
                    item.appendChild(span('recente-corrigido' + (entry.fix_refused ? ' recente-corrigido-recusado' : ''), fixState));
                }
                for (const aviso of warningsShown(entry)) {
                    item.appendChild(span('recente-aviso' + (aviso.grave ? ' recente-aviso-grave' : ''), aviso.mensagem));
                }
                if (!entry.voided) {
                    const undoButton = document.createElement('button');
                    undoButton.type = 'button';
                    undoButton.className = 'botao-desfazer';
                    undoButton.textContent = 'desfazer';
                    undoButton.addEventListener('click', () => onUndo(entry.uuid));
                    item.appendChild(undoButton);
                }
                if (mayBeCorrected(entry)) {
                    const informButton = document.createElement('button');
                    informButton.type = 'button';
                    // Big while the "??" has no number; once corrected, a
                    // smaller one to correct it again.
                    informButton.className = waitsForNumber(entry) ? 'botao-informar' : 'botao-informar botao-informar-de-novo';
                    informButton.textContent = waitsForNumber(entry) ? 'Informar número' : 'Corrigir número';
                    informButton.addEventListener('click', () => onInform(entry));
                    item.appendChild(informButton);
                }
                listElement.appendChild(item);
            }
        },
    };
}
