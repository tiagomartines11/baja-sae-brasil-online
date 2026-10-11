// The "últimos registros" list of the count page: the last 10 entries of THIS
// device, newest first, each with its own "desfazer".

import { formatTimeOfDay } from './race-clock.js';

function span(className, text) {
    const node = document.createElement('span');
    node.className = className;
    node.textContent = text;
    return node;
}

function uploadStateText(entry) {
    if (entry.voided) {
        return entry.void_sent ? 'desfeito' : 'desfeito (na fila)';
    }
    return entry.sent ? 'enviado' : 'na fila';
}

/**
 * @param {object}      options
 * @param {HTMLElement} options.listElement
 * @param {object}      options.session   CountSession
 * @param {function}    options.onUndo    (uuid) => void
 */
export function createRecentList({ listElement, session, onUndo }) {
    let renderedSignature = null;

    return {
        render() {
            const entries = session.recentEntries();
            // The list is asked to redraw after every sync (every 5 s). It is
            // rebuilt only when something visible changed, so a "desfazer"
            // button is never swapped out from under a finger.
            const signature = entries
                .map((entry) => [entry.uuid, uploadStateText(entry), entry.nomeEquipe].join('|'))
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
                item.appendChild(span('recente-hora', formatTimeOfDay(entry.ts_device_ms)));
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
