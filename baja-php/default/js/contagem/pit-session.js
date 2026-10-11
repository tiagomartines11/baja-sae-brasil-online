// Pit log logic: recording "Entrou"/"Saiu" and working out which cars are in
// the pit lane right now.

export const TIPO_BOX = {
    ENTRADA: 'box_entrada',
    SAIDA: 'box_saida',
};

/**
 * The server's list only reflects events it has already received. Events
 * still waiting on this device are applied on top, oldest first, so the list
 * on screen changes the instant a button is tapped, with or without network.
 *
 * @param {number[]} serverCarsInPit `cars_in_pit` of the last sync answer
 * @param {Array}    unsentPitEvents local events the server has not confirmed
 * @returns {number[]} car numbers, ascending
 */
export function mergeCarsInPit(serverCarsInPit, unsentPitEvents) {
    const carsInPit = new Set(Array.isArray(serverCarsInPit) ? serverCarsInPit : []);
    const oldestFirst = unsentPitEvents.slice().sort((first, second) => first.seq - second.seq);
    for (const pitEvent of oldestFirst) {
        if (pitEvent.tipo === TIPO_BOX.ENTRADA) {
            carsInPit.add(pitEvent.car);
        } else if (pitEvent.tipo === TIPO_BOX.SAIDA) {
            carsInPit.delete(pitEvent.car);
        }
    }
    return Array.from(carsInPit).sort((first, second) => first - second);
}

export class PitSession {
    /**
     * @param {object}   options
     * @param {object}   options.store        LocalStore
     * @param {object}   options.clock        ClockEstimator
     * @param {function} options.now          () => device time in ms
     * @param {function} options.generateUuid () => string
     */
    constructor({ store, clock, now, generateUuid }) {
        this.store = store;
        this.clock = clock;
        this.now = now;
        this.generateUuid = generateUuid;
    }

    /** Same local-first rule as a count entry: resolves after the disk write. */
    recordEvent({ car, tipo }) {
        if (tipo !== TIPO_BOX.ENTRADA && tipo !== TIPO_BOX.SAIDA) {
            return Promise.reject(new Error('Unknown pit event type: ' + tipo));
        }
        return this.store.addPitEvent({
            uuid: this.generateUuid(),
            car,
            tipo,
            ts_device_ms: this.now(),
            clock_offset_ms: this.clock.offsetMs(),
        });
    }

    carsInPit(serverCarsInPit) {
        return mergeCarsInPit(serverCarsInPit, this.store.unsentPitEvents());
    }

    /** Newest first, for the "últimos registros" list. */
    recentEvents(limit = 10) {
        return this.store.allPitEvents().slice(-limit).reverse();
    }
}
