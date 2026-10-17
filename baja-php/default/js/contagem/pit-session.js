// Pit lane and fueling station log, with no DOM in sight: the event types,
// which cars are inside right now, how the car list is ordered, the choices
// the page opens on, and recording an event.
//
// Two AREAS are logged by the same page: the pit lane ("box") and the fueling
// station ("abastecimento"). In each, a car ENTERS and LEAVES. Usually two
// people do it, one at each end, each on a phone: one in "Entrada" mode, the
// other in "Saída" mode. After the mode is chosen, one tap on a car is one
// event. (Which taps count and when the tiles move: box-toques.js.)

import { RecordingBlockedError } from './access.js';

export const AREA = { BOX: 'box', ABASTECIMENTO: 'abastecimento' };
export const AREAS = [AREA.BOX, AREA.ABASTECIMENTO];
export const SENTIDO = { ENTRADA: 'entrada', SAIDA: 'saida' };
export const SENTIDOS = [SENTIDO.ENTRADA, SENTIDO.SAIDA];

/** The four values of `pit_events[].tipo` in the upload contract. */
export const TIPO_PARADA = {
    BOX_ENTRADA: 'box_entrada',
    BOX_SAIDA: 'box_saida',
    ABASTECIMENTO_ENTRADA: 'abastecimento_entrada',
    ABASTECIMENTO_SAIDA: 'abastecimento_saida',
};
const TIPOS = Object.values(TIPO_PARADA);

export function tipoFor(area, sentido) {
    return area + '_' + sentido;
}

/** 'abastecimento_saida' -> {area: 'abastecimento', sentido: 'saida'}; null for anything else */
export function partsOfTipo(tipo) {
    if (!TIPOS.includes(tipo)) {
        return null;
    }
    const cut = tipo.lastIndexOf('_');
    return { area: tipo.slice(0, cut), sentido: tipo.slice(cut + 1) };
}

/**
 * The server's list only reflects events it has already received. Events
 * still waiting on this device are applied on top, oldest first, so the list
 * on screen changes the instant a car is tapped, with or without network.
 *
 * @param {number[]} serverCarsInside `cars_in_pit` or `cars_in_fuel` of the last sync answer
 * @param {Array}    unsentPitEvents  local events the server has not confirmed (any area)
 * @param {string}   [area]           which area's events to apply
 * @returns {number[]} car numbers, ascending
 */
export function mergeCarsInside(serverCarsInside, unsentPitEvents, area = AREA.BOX) {
    const carsInside = new Set(Array.isArray(serverCarsInside) ? serverCarsInside : []);
    const oldestFirst = unsentPitEvents.slice().sort((first, second) => first.seq - second.seq);
    for (const pitEvent of oldestFirst) {
        const parts = partsOfTipo(pitEvent.tipo);
        if (parts === null || parts.area !== area) {
            continue;
        }
        if (parts.sentido === SENTIDO.ENTRADA) {
            carsInside.add(pitEvent.car);
        } else {
            carsInside.delete(pitEvent.car);
        }
    }
    return Array.from(carsInside).sort((first, second) => first - second);
}

/**
 * The two groups of car tiles, in the order they are shown (brief 8.2).
 * In "Saída" mode the cars inside come first, since one of them is about to
 * leave; in "Entrada" mode the cars outside come first. NO CAR IS EVER LEFT
 * OUT: the person at the other end may have missed one, so every car can be
 * tapped in either mode, and a car that is inside is shown even when it is
 * not in the car list at all.
 *
 * @param {object}   input
 * @param {number[]} input.listedCars  numbers of the (classified) cars
 * @param {number[]} input.carsInside  from mergeCarsInside()
 * @param {string}   input.sentido     SENTIDO.*
 * @returns {{primeiros: number[], outros: number[]}}
 */
export function orderCarsForMode({ listedCars, carsInside, sentido }) {
    const inside = new Set(carsInside);
    const everyCar = Array.from(new Set([...listedCars, ...carsInside])).sort((first, second) => first - second);
    const carsIn = everyCar.filter((car) => inside.has(car));
    const carsOut = everyCar.filter((car) => !inside.has(car));
    return sentido === SENTIDO.SAIDA
        ? { primeiros: carsIn, outros: carsOut }
        : { primeiros: carsOut, outros: carsIn };
}

export const PASSO = { AREA: 'area', SENTIDO: 'sentido', CARROS: 'carros' };

/**
 * The choices in front of the car tiles: the area (when the prova has a
 * fueling station), then entry or exit. A device that has never chosen is
 * asked. A device that has opens DIRECTLY in the mode it used last: the
 * person at the pit exit who reloads the page, or whose phone restarts,
 * must be recording exits again at the first tap, not answering questions
 * while a car leaves. The page says which mode it opened in (see box.js),
 * and both choices stay visible as switches, one tap to change.
 */
export class PitFlow {
    /**
     * @param {object}  options
     * @param {object}  options.preferences     from createPreferences()
     * @param {boolean} options.fuelingEnabled  whether this prova logs the fueling station
     */
    constructor({ preferences, fuelingEnabled }) {
        this.preferences = preferences;
        this.fuelingEnabled = fuelingEnabled === true;
        const remembered = this.lembrado();
        // With one area only there is nothing to choose.
        this.area = this.fuelingEnabled ? remembered.area : AREA.BOX;
        this.sentido = remembered.sentido;
        /** True when no question was asked: the page went straight to the cars. */
        this.abriuNoModoLembrado = this.passo() === PASSO.CARROS;
    }

    passo() {
        if (this.area === null) {
            return PASSO.AREA;
        }
        return this.sentido === null ? PASSO.SENTIDO : PASSO.CARROS;
    }

    /** What this device chose last time (null for what it never chose). */
    lembrado() {
        const area = this.preferences.get('box_area', null);
        const sentido = this.preferences.get('box_sentido', null);
        return {
            area: AREAS.includes(area) ? area : null,
            sentido: SENTIDOS.includes(sentido) ? sentido : null,
        };
    }

    chooseArea(area) {
        if (!AREAS.includes(area) || (area === AREA.ABASTECIMENTO && !this.fuelingEnabled)) {
            return;
        }
        this.area = area;
        this.preferences.set('box_area', area);
    }

    chooseSentido(sentido) {
        if (!SENTIDOS.includes(sentido)) {
            return;
        }
        this.sentido = sentido;
        this.preferences.set('box_sentido', sentido);
    }

    /**
     * The server's latest word on whether the fueling station is logged.
     * @returns {boolean} true when the page was ON the fueling area and has
     *          just been moved to the pit lane because the area was switched off
     */
    setFuelingEnabled(enabled) {
        const wasEnabled = this.fuelingEnabled;
        this.fuelingEnabled = enabled === true;
        if (this.fuelingEnabled && !wasEnabled && this.sentido === null) {
            // Still on the opening choice (a page printed before the area
            // was switched on, or an old offline copy): ask for the area
            // after all. Once the person is recording, nothing is asked;
            // the area switch simply appears.
            this.area = null;
        }
        if (!this.fuelingEnabled && this.area !== AREA.BOX) {
            const wasOnFueling = this.area === AREA.ABASTECIMENTO;
            this.area = AREA.BOX;
            return wasOnFueling;
        }
        return false;
    }

    /** @returns {?string} the `tipo` a tap records now; null until both choices are made */
    tipo() {
        return this.passo() === PASSO.CARROS ? tipoFor(this.area, this.sentido) : null;
    }
}

export class PitSession {
    /**
     * @param {object}   options
     * @param {object}   options.store        LocalStore
     * @param {object}   options.clock        ServerClock
     * @param {function} options.generateUuid () => string
     * @param {function} [options.recordingBlocked] () => ?string: a reason
     *        (access.js BLOQUEIO.*) while this page may record nothing new
     */
    constructor({ store, clock, generateUuid, recordingBlocked = () => null }) {
        this.store = store;
        this.clock = clock;
        this.generateUuid = generateUuid;
        this.recordingBlocked = recordingBlocked;
    }

    /**
     * THE way a pit or fueling event gets recorded: a tap on a car and the
     * race simulation both end here. Same local-first rule as a count entry:
     * resolves after the disk write.
     *
     * @param {object}  pitEvent
     * @param {number}  pitEvent.car
     * @param {string}  pitEvent.tipo   one of TIPO_PARADA.*
     * @param {?number} [pitEvent.tsMs] the time of the event, in SERVER time,
     *        when it is not "right now" (the simulation)
     * @param {?string} [pitEvent.mode] 'simulacao' for a simulated event; left out otherwise
     * @param {?string} [pitEvent.uuid] the simulation names its records, so
     *        that a script run twice records each event once: an event
     *        whose uuid this device already holds is NOT recorded again, and
     *        the record it already has is returned
     */
    recordEvent({ car, tipo, tsMs = null, mode = null, uuid = null }) {
        if (partsOfTipo(tipo) === null) {
            return Promise.reject(new Error('Unknown pit event type: ' + tipo));
        }
        // The one gate every way of recording goes through (taps, the number
        // box, the simulation), box and fueling alike.
        const blockedBecause = this.recordingBlocked();
        if (blockedBecause !== null) {
            return Promise.reject(new RecordingBlockedError(blockedBecause));
        }
        const time = Number.isFinite(tsMs) ? this.clock.stampAt(tsMs) : this.clock.stamp();
        const record = { uuid: uuid === null ? this.generateUuid() : uuid, car, tipo, ...time };
        if (mode !== null) {
            record.mode = mode;
        }
        return this.store.addPitEvent(record);
    }

    /**
     * @param {string}   area             AREA.*
     * @param {number[]} serverCarsInside that area's list in the last sync answer
     */
    carsInside(area, serverCarsInside) {
        return mergeCarsInside(serverCarsInside, this.store.unsentPitEvents(), area);
    }

    /** Newest first, for the "últimos registros" list. */
    recentEvents(limit = 10) {
        return this.store.allPitEvents().slice(-limit).reverse();
    }
}
