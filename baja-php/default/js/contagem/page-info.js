// What the page knows about the prova: car lists, the warning window, whether
// the fueling station is logged, whether simulation is allowed.
//
// Each fact has two sources. The page shell prints a first version into the
// HTML (`config`), so the very first paint is right and a first load that
// never reaches the server still works. Every sync answer then brings the
// current version, which is kept on disk (`servidor` in the local store) and
// wins from then on, also after a reload with no network.

import { AREA } from './pit-session.js';

const META_SERVER_INFO = 'servidor';

function nonEmptyArray(value) {
    return Array.isArray(value) && value.length > 0;
}

/**
 * @param {object} store  LocalStore
 * @param {object} config the JSON the page shell printed
 */
export function createPageInfo(store, config) {
    const serverInfo = () => store.getMeta(META_SERVER_INFO, null);

    /**
     * The server's two car lists are used together or not at all: a
     * classified list from one moment beside an unclassified list from
     * another could show a car in both, or in neither. An answer with BOTH
     * lists empty is taken as "not filled in yet" (an event with no car does
     * not exist) and the lists printed in the page are used.
     */
    function carLists() {
        const info = serverInfo();
        if (info && (nonEmptyArray(info.valid_cars) || nonEmptyArray(info.unclassified_cars))) {
            return { classified: info.valid_cars || [], unclassified: info.unclassified_cars || [] };
        }
        return { classified: config.carros || [], unclassified: config.carros_nao_classificados || [] };
    }

    return {
        /** Cars that take part: present AND classified. Tiles and lists show only these. */
        validCars() {
            return carLists().classified;
        },
        /** Present but not classified: a pass for one of these is stored, with a loud warning. */
        unclassifiedCars() {
            return carLists().unclassified;
        },
        janelaMs() {
            const info = serverInfo();
            return info && Number.isFinite(info.janela_ms) ? info.janela_ms : config.janela_ms;
        },
        /** Whether the pit page offers the fueling station as a second area. */
        fuelingEnabled() {
            const info = serverInfo();
            return info ? info.abastecimento === true : config.abastecimento === true;
        },
        /** `contagem.permitir_simulacao`: the "SIMULAÇÃO" banner and the simulation hook depend on it. */
        simulationAllowed() {
            const info = serverInfo();
            return info ? info.simulacao === true : config.simulacao === true;
        },
        /** The server's list of cars inside an area (before local events are applied). */
        serverCarsInside(area) {
            const info = serverInfo();
            if (!info) {
                return [];
            }
            return (area === AREA.ABASTECIMENTO ? info.cars_in_fuel : info.cars_in_pit) || [];
        },
    };
}
