// The "últimos registros" list of the count page: the last 10 entries of THIS
// device, newest first, each with its own "desfazer".

import { describeRecordTime } from './race-clock.js';

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

/** Official time of the entry, saying so when it is not exact (see server-clock.js). */
function timeText(entry, timeZone) {
    const text = describeRecordTime(entry, timeZone);
    if (entry.time_quality === 'estimado') {
        return text + ' (estimado)';
    }
    return entry.time_quality === 'desconhecido' ? text + ' (relógio do aparelho)' : text;
}

/**
 * @param {object}      options
 * @param {HTMLElement} options.listElement
 * @param {object}      options.session   CountSession
 * @param {function}    options.onUndo    (uuid) => void
 * @param {?string}     [options.timeZone] the event's time zone, for the times shown
 */
export function createRecentList({ listElement, session, onUndo, timeZone = null }) {
    let renderedSignature = null;

    return {
        render() {
            const entries = session.recentEntries();
            // The list is asked to redraw after every sync (every 5 s). It is
            // rebuilt only when something visible changed, so a "desfazer"
            // button is never swapped out from under a finger.
            const signature = entries
                .map((entry) => [entry.uuid, uploadStateText(entry), entry.nomeEquipe, entry.ts_ms].join('|'))
                .join('\n');
            if (signature === renderedSignature) {
                return;
            }
            renderedSignature = signature;

            listElement.replaceChildren();
            for (const entry of entries) {
                const item = document.createElement('li');
                item.className = 'recente' + (entry.voided ? ' recente-desfeito' : '');
                item.appendChild(span('recente-numero', String(entry.car)));
                item.appendChild(span('recente-equipe', entry.nomeEquipe || 'Número desconhecido'));
                item.appendChild(span('recente-hora', timeText(entry, timeZone)));
                item.appendChild(span('recente-estado', uploadStateText(entry)));
                if (!entry.voided) {
                    const undoButton = document.createElement('button');
                    undoButton.type = 'button';
                    undoButton.className = 'botao-desfazer';
                    undoButton.textContent = 'desfazer';
                    undoButton.addEventListener('click', () => onUndo(entry.uuid));
                    item.appendChild(undoButton);
                }
                listElement.appendChild(item);
            }
        },
    };
}
