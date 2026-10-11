// Warnings shown to a counter right after an entry.
//
// IMPORTANT: these are computed ONLY from what this device itself recorded and
// from the list of car numbers. The three counters must stay independent, so
// nothing a counter sees may depend on what another counter typed. Do not add
// a warning here that needs any other source's data.
//
// A warning never blocks anything: the entry is already stored when it shows.

export const AVISO = {
    NUMERO_DESCONHECIDO: 'numero_desconhecido',
    REPETIDO: 'repetido',
};

export const DEFAULT_JANELA_MS = 20000;

/**
 * @param {object}  input
 * @param {number}  input.car           number just typed
 * @param {number}  input.tsDeviceMs    when it was typed (device clock)
 * @param {Array}   input.ownEntries    this device's earlier entries (store records)
 * @param {?Array}  input.validCars     [{numero, nome}] or null when not known yet
 * @param {?number} input.janelaMs      "same car again too soon" window
 * @returns {Array<{tipo: string, mensagem: string}>}
 */
export function computeWarnings({ car, tsDeviceMs, ownEntries, validCars, janelaMs }) {
    const warnings = [];
    const windowMs = Number.isFinite(janelaMs) && janelaMs > 0 ? janelaMs : DEFAULT_JANELA_MS;

    // Without a car list we cannot call a number unknown (see carListIsKnown).
    if (carListIsKnown(validCars) && !validCars.some((validCar) => validCar.numero === car)) {
        warnings.push({
            tipo: AVISO.NUMERO_DESCONHECIDO,
            mensagem: 'Número ' + car + ' não está na lista de carros. Registrado mesmo assim.',
        });
    }

    const previous = latestActiveEntryOfCar(ownEntries, car);
    if (previous !== null) {
        const elapsedMs = Math.abs(tsDeviceMs - previous.ts_device_ms);
        if (elapsedMs < windowMs) {
            warnings.push({
                tipo: AVISO.REPETIDO,
                mensagem: 'Carro ' + car + ' já registrado há ' + Math.round(elapsedMs / 1000)
                    + ' s. Se foi engano, toque em desfazer.',
            });
        }
    }

    return warnings;
}

/**
 * Whether we really know which cars are at the event. A missing list (never
 * been online) and an EMPTY list both mean "unknown": an event with no car at
 * all does not exist, so an empty list is a list that has not been filled in
 * yet (teams not marked as present, for example). Treating it as "every
 * number is invalid" would put a warning on every single entry.
 */
export function carListIsKnown(validCars) {
    return Array.isArray(validCars) && validCars.length > 0;
}

/** An entry the counter undid no longer counts as "already typed". */
function latestActiveEntryOfCar(ownEntries, car) {
    let latest = null;
    for (const entry of ownEntries) {
        if (entry.car !== car || entry.voided) {
            continue;
        }
        if (latest === null || entry.ts_device_ms > latest.ts_device_ms) {
            latest = entry;
        }
    }
    return latest;
}

/** @returns {?string} team name for the big flash, or null for an unknown number */
export function teamNameOf(car, validCars) {
    if (!carListIsKnown(validCars)) {
        return null;
    }
    const found = validCars.find((validCar) => validCar.numero === car);
    return found ? found.nome : null;
}
