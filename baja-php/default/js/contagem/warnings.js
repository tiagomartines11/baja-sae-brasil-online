// Warnings shown to a counter right after an entry.
//
// IMPORTANT: these are computed ONLY from what this device itself recorded and
// from the list of car numbers. The three counters must stay independent, so
// nothing a counter sees may depend on what another counter typed. Do not add
// a warning here that needs any other source's data.
//
// A warning never blocks anything: the entry is already stored when it shows.

import { elapsedBetween } from './record-elapsed.js';

export const AVISO = {
    NUMERO_DESCONHECIDO: 'numero_desconhecido',
    // The car is at the event but not classified for this prova. The pass
    // is stored like any other (the phone's list may be out of date, and a
    // stored pass is what protects a protest), but it will not count unless
    // a reviewer says so. The counter must notice: `grave` asks the page for
    // the error colour and the error sound.
    NAO_CLASSIFICADO: 'nao_classificado',
    REPETIDO: 'repetido',
};

export const DEFAULT_JANELA_MS = 20000;

/**
 * @param {object}  input
 * @param {?number} input.car           number just typed; null for "??" (no warning at all)
 * @param {object}  [input.time]        the new entry's time fields (ServerClock.stamp())
 * @param {number}  [input.tsDeviceMs]  instead of `time`: only the system clock reading
 * @param {Array}   input.ownEntries    this device's earlier entries (store records)
 * @param {?Array}  input.validCars     [{numero, nome}] classified cars, or null when not known yet
 * @param {?Array}  [input.unclassifiedCars] [{numero, nome}] at the event but not classified
 * @param {?number} input.janelaMs      "same car again too soon" window
 * @returns {Array<{tipo: string, mensagem: string, curta: string, grave: boolean}>}
 *          `mensagem` is the whole sentence (recent list, screen readers);
 *          `curta` fits the single warning line of the big flash, which
 *          must never grow (see contar-destaque.js).
 */
export function computeWarnings({ car, time, tsDeviceMs, ownEntries, validCars, unclassifiedCars = null, janelaMs }) {
    const warnings = [];
    if (car === null) {
        // "??": no number, so nothing to check it against. Above all no
        // REPETIDO: two unread cars in a row are two different crossings.
        return warnings;
    }
    const windowMs = Number.isFinite(janelaMs) && janelaMs > 0 ? janelaMs : DEFAULT_JANELA_MS;
    const newEntry = time || { ts_device_ms: tsDeviceMs };
    const hasNumber = (cars) => Array.isArray(cars) && cars.some((listed) => listed.numero === car);

    if (hasNumber(unclassifiedCars)) {
        warnings.push({
            tipo: AVISO.NAO_CLASSIFICADO,
            mensagem: 'CARRO NÃO CLASSIFICADO: o carro ' + car + ' não está classificado para esta prova. '
                + 'Registrado mesmo assim; avise o responsável.',
            curta: 'NÃO CLASSIF.: avise o resp.',
            grave: true,
        });
    } else if ((carListIsKnown(validCars) || carListIsKnown(unclassifiedCars)) && !hasNumber(validCars)) {
        // Without any car list we cannot call a number unknown (see carListIsKnown).
        warnings.push({
            tipo: AVISO.NUMERO_DESCONHECIDO,
            mensagem: 'Número ' + car + ' não está na lista de carros. Registrado mesmo assim.',
            curta: 'Número fora da lista',
            grave: false,
        });
    }

    const previous = latestActiveEntryOfCar(ownEntries, car, newEntry);
    if (previous !== null) {
        const elapsedMs = Math.abs(elapsedBetween(previous, newEntry));
        if (elapsedMs < windowMs) {
            const seconds = Math.round(elapsedMs / 1000);
            warnings.push({
                tipo: AVISO.REPETIDO,
                mensagem: 'Carro ' + car + ' já registrado há ' + seconds + ' s. Se foi engano, toque em desfazer.',
                curta: 'REPETIDO há ' + seconds + ' s: desfazer?',
                grave: false,
            });
        }
    }

    return warnings;
}

// Which warning the big flash shows when there are two (it has room for
// one line): an unclassified car before a number off the list, and either
// before a repeat. The colour already says how serious it is; the recent
// list shows every warning in full.
const ORDEM_GRAVIDADE = [AVISO.NAO_CLASSIFICADO, AVISO.NUMERO_DESCONHECIDO, AVISO.REPETIDO];

/**
 * @param {Array<{tipo: string}>} warnings computeWarnings()
 * @returns {?object} the most serious one, or null when there is none
 */
export function mostSeriousWarning(warnings) {
    const rank = (warning) => {
        const position = ORDEM_GRAVIDADE.indexOf(warning.tipo);
        return position === -1 ? ORDEM_GRAVIDADE.length : position;
    };
    let chosen = null;
    for (const warning of warnings) {
        if (chosen === null || rank(warning) < rank(chosen)) {
            chosen = warning;
        }
    }
    return chosen;
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

/**
 * The entry of this car closest in time to the new one. An entry the
 * counter undid no longer counts as "already typed".
 */
function latestActiveEntryOfCar(ownEntries, car, newEntry) {
    let closest = null;
    let closestGapMs = Infinity;
    for (const entry of ownEntries) {
        if (effectiveCar(entry) !== car || entry.voided) {
            continue;
        }
        const gapMs = Math.abs(elapsedBetween(entry, newEntry));
        if (gapMs < closestGapMs) {
            closest = entry;
            closestGapMs = gapMs;
        }
    }
    return closest;
}

/**
 * The car an entry stands for on this phone: the number typed, or, for a
 * "??" the counter corrected afterwards, the number given then (unless the
 * server refused that correction). Null for a "??" still without a number.
 */
export function effectiveCar(entry) {
    if (entry.car === null && Number.isInteger(entry.fix_car) && !entry.fix_refused) {
        return entry.fix_car;
    }
    return entry.car;
}

/** @returns {?string} team name for the big flash, or null for an unknown number */
export function teamNameOf(car, validCars, unclassifiedCars = null) {
    for (const cars of [validCars, unclassifiedCars]) {
        const found = Array.isArray(cars) ? cars.find((listed) => listed.numero === car) : null;
        if (found) {
            return found.nome;
        }
    }
    return null;
}
