// Draws the pit page's car tiles (two groups), its heading and its recent
// list. Reads nothing and decides nothing: pit-session.js says which car is
// in which group.

import { AREA, SENTIDO, partsOfTipo } from './pit-session.js';
import { describeRecordTime } from './race-clock.js';

const NOME_AREA = { [AREA.BOX]: 'box', [AREA.ABASTECIMENTO]: 'abastecimento' };

/** 'abastecimento_saida' -> "saiu do abastecimento" */
export function describeTipo(tipo) {
    const parts = partsOfTipo(tipo);
    if (parts === null) {
        return tipo;
    }
    return (parts.sentido === SENTIDO.ENTRADA ? 'entrou no ' : 'saiu do ') + NOME_AREA[parts.area];
}

/**
 * The words around the two groups of tiles, for one area and one mode.
 * The first group is the one a tap is expected on; the second is "outros".
 */
export function describeGroups(area, sentido) {
    const nome = NOME_AREA[area];
    if (sentido === SENTIDO.SAIDA) {
        return {
            instrucao: 'SAÍDA do ' + nome + ': toque no carro que SAIU',
            primeiros: 'No ' + nome + ' agora',
            primeirosVazio: 'Nenhum carro no ' + nome + '.',
            outros: 'Outros carros',
        };
    }
    return {
        instrucao: 'ENTRADA no ' + nome + ': toque no carro que ENTROU',
        primeiros: 'Fora do ' + nome,
        primeirosVazio: 'Todos os carros estão no ' + nome + '.',
        outros: 'Outros (já estão no ' + nome + ')',
    };
}

function textNode(tagName, className, text) {
    const node = document.createElement(tagName);
    node.className = className;
    node.textContent = text;
    return node;
}

/**
 * @param {object}   options
 * @param {function} options.element     (id) => HTMLElement
 * @param {function} options.getTeamName (car) => string
 * @param {function} options.onTapCar    (car) => void
 * @param {?string}  options.timeZone    the event's time zone, for the times shown
 */
export function createPitLists({ element, getTeamName, onTapCar, timeZone }) {
    let renderedSignature = null;
    let renderedRecentSignature = null;

    function fillTiles(container, cars, className) {
        container.replaceChildren();
        for (const carNumber of cars) {
            const tile = textNode('button', className, String(carNumber));
            tile.type = 'button';
            tile.title = getTeamName(carNumber);
            tile.addEventListener('click', () => {
                tile.blur();
                onTapCar(carNumber);
            });
            container.appendChild(tile);
        }
    }

    function renderRecent(recentEvents) {
        const signature = JSON.stringify(recentEvents.map((pitEvent) => [
            pitEvent.uuid, pitEvent.sent, pitEvent.refused, pitEvent.ts_ms,
        ]));
        if (signature === renderedRecentSignature) {
            return;
        }
        renderedRecentSignature = signature;
        const recentList = element('lista-recentes');
        recentList.replaceChildren();
        for (const pitEvent of recentEvents) {
            const state = pitEvent.refused ? 'recusado pelo servidor' : (pitEvent.sent ? 'enviado' : 'na fila');
            const item = document.createElement('li');
            item.className = 'recente';
            item.appendChild(textNode('span', 'recente-numero', String(pitEvent.car)));
            item.appendChild(textNode('span', 'recente-equipe', describeTipo(pitEvent.tipo)));
            item.appendChild(textNode('span', 'recente-hora', describeRecordTime(pitEvent, timeZone)));
            item.appendChild(textNode('span', 'recente-estado', state));
            recentList.appendChild(item);
        }
    }

    return {
        renderRecent,

        /**
         * @param {object} view
         * @param {string} view.area
         * @param {string} view.sentido
         * @param {{primeiros: number[], outros: number[]}} view.groups from orderCarsForMode()
         * @param {Array}  view.recentEvents
         */
        render({ area, sentido, groups, recentEvents }) {
            renderRecent(recentEvents);
            // Redrawn only when something visible changed: this runs after
            // every sync, and a tile must not be swapped out from under a finger.
            const signature = JSON.stringify([area, sentido, groups]);
            if (signature === renderedSignature) {
                return;
            }
            renderedSignature = signature;

            const texts = describeGroups(area, sentido);
            const instruction = element('box-instrucao');
            instruction.textContent = texts.instrucao;
            instruction.className = 'box-instrucao box-instrucao-' + sentido;
            element('titulo-primeiros').textContent = texts.primeiros;
            element('titulo-outros').textContent = texts.outros;
            element('primeiros-vazio').textContent = texts.primeirosVazio;
            element('primeiros-vazio').hidden = groups.primeiros.length > 0;
            element('grupo-outros').hidden = groups.outros.length === 0;
            // Cars inside the area keep the same look in both groups, so
            // "inside" is recognisable whichever mode is on.
            const insideClass = 'bloco bloco-no-box';
            const insideFirst = sentido === SENTIDO.SAIDA;
            fillTiles(element('blocos-primeiros'), groups.primeiros, insideFirst ? insideClass : 'bloco');
            fillTiles(element('blocos-outros'), groups.outros, insideFirst ? 'bloco' : insideClass);
        },
    };
}
