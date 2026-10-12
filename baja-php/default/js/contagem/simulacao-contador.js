// What one simulated person does with the shared race script.
//
// A COUNTER types the number of each car that crosses the line, a moment
// before or after it really crossed, and makes the mistakes people make:
// misses a car, hits a wrong digit, enters a car twice. A PIT-LOG person
// taps cars entering and leaving an area, sometimes late, sometimes not at
// all.
//
// Each person has their own stream of random numbers, derived from the
// shared seed and from who they are (their source id), so three counters
// watching the same race make DIFFERENT mistakes, and the same counter
// makes the same mistakes every time the simulation is run with that seed.
//
// Nothing here touches a page or the network. The result is a plan: a list
// of "type this car at this time", plus the list of mistakes that were put
// in on purpose, which is the answer key for the review screen.

import { Prng, deriveSeed } from './simulacao-prng.js';
import { AREA_BOX, carsInsideAt } from './simulacao-corrida.js';

/** The mistakes a counter can make, and how often, per thousand crossings. */
export const TAXAS_PADRAO = {
    perdido: 4,           // did not see the car: nothing typed
    numero_errado: 8,     // typed another number (see wrongNumberFor)
    toque_duplo: 4,       // the same car again within a second
    repetida: 4,          // the same car again a few seconds later
    desconhecido: 2,      // an extra entry for a number no car has
    nao_classificado: 2,  // an extra entry for a car that may not race
    no_box: 2,            // an extra entry for a car that is in the pit lane
};

/** Mistakes of the person logging an area, per thousand events. */
export const TAXAS_PARADA_PADRAO = {
    perdido: 20,          // the event is not logged at all
    atrasado: 50,         // logged 5 to 20 seconds late
};

export const ERRO = {
    PERDIDO: 'perdido',
    NUMERO_ERRADO: 'numero_errado',
    TOQUE_DUPLO: 'toque_duplo',
    REPETIDA: 'repetida',
    DESCONHECIDO: 'desconhecido',
    NAO_CLASSIFICADO: 'nao_classificado',
    NO_BOX: 'no_box',
    PARADA_PERDIDA: 'parada_perdida',
    PARADA_ATRASADA: 'parada_atrasada',
};

/**
 * How far from the true crossing a simulated counter types, in whole
 * milliseconds.
 *
 * In a real race (matching window of 20 s) people press Enter from half a
 * second before the car reaches the line to a second and a half after; a
 * double tap comes within a second, a forgetful repeat some seconds later.
 * A compressed rehearsal has a matching window of a second or two (see the
 * "Predefinição de teste"), and those human times would no longer fit
 * inside it: three counters seeing the same crossing would not be matched.
 * So every time is capped by a share of the window. With the real window
 * the caps do nothing and the times are the human ones.
 */
export function timingFor(janelaMs) {
    return {
        earlyMs: Math.min(500, Math.trunc(janelaMs / 8)),
        lateMs: Math.min(1500, Math.trunc(janelaMs / 2)),
        doubleTapMinMs: Math.min(300, Math.trunc(janelaMs / 10)),
        doubleTapMaxMs: Math.min(900, Math.trunc(janelaMs / 5)),
        repeatMinMs: Math.trunc((janelaMs * 3) / 10),
        repeatMaxMs: Math.trunc((janelaMs * 6) / 10),
        strayMinMs: Math.trunc(janelaMs / 10),
        strayMaxMs: Math.trunc(janelaMs / 5),
    };
}

/** The keys next to each key of a numeric keypad (1 2 3 / 4 5 6 / 7 8 9 / 0). */
const NEIGHBOUR_KEYS = {
    1: [2, 4], 2: [1, 3, 5], 3: [2, 6],
    4: [1, 5, 7], 5: [2, 4, 6, 8], 6: [3, 5, 9],
    7: [4, 8], 8: [5, 7, 9, 0], 9: [6, 8],
    0: [8],
};

/** How often each kind of slip happens, relative to the others. */
const TYPO_WEIGHTS = { teclaVizinha: 6, digitosTrocados: 3, digitoRepetido: 1, digitoPerdido: 1 };

/**
 * A wrong number for a car, the way fingers produce them on a keypad:
 *
 *   - a neighbouring key instead of one digit (41 -> 42, 41 -> 71): by far
 *     the most common slip;
 *   - two digits swapped (41 -> 14);
 *   - one digit typed twice instead of the two (41 -> 44);
 *   - a digit lost (41 -> 4), which is rare, and kept here only when the
 *     shortened number is NOT a car of the race.
 *
 * That last condition matters for what the simulation measures. Every car
 * from 40 to 49 loses its second digit to the same number, 4. Left
 * unchecked, ten cars keep sending their typos to one car, and "two
 * counters typed the same wrong number" becomes several times more
 * frequent on single-digit cars than anything a real race shows. A lost
 * digit that lands on no car is still simulated: it is an unknown number,
 * which the review screen flags.
 *
 * @param {number} carro
 * @param {Prng} prng
 * @param {Set<number>} [carsInTheRace] numbers a lost digit must not land on
 * @returns {?number} null when no variant gives a different, usable number
 */
export function wrongNumberFor(carro, prng, carsInTheRace = new Set()) {
    const digits = String(carro).split('').map(Number);
    const candidates = [];
    const offer = (resultDigits, weight) => {
        const number = Number(resultDigits.join(''));
        // No leading zero: nobody types "04" for a car and means 4.
        if (resultDigits[0] !== 0 && number > 0 && number !== carro) {
            candidates.push({ number, weight });
        }
    };

    digits.forEach((digit, position) => {
        for (const neighbour of NEIGHBOUR_KEYS[digit]) {
            const changed = [...digits];
            changed[position] = neighbour;
            offer(changed, TYPO_WEIGHTS.teclaVizinha);
        }
    });
    if (digits.length >= 2) {
        offer([digits[1], digits[0], ...digits.slice(2)], TYPO_WEIGHTS.digitosTrocados);
        offer([digits[0], digits[0], ...digits.slice(2)], TYPO_WEIGHTS.digitoRepetido);
        const shortened = digits.slice(0, -1);
        if (!carsInTheRace.has(Number(shortened.join('')))) {
            offer(shortened, TYPO_WEIGHTS.digitoPerdido);
        }
    }
    if (candidates.length === 0) {
        return null;
    }

    // One draw, spread over the candidates in proportion to their weights.
    let draw = prng.below(candidates.reduce((sum, candidate) => sum + candidate.weight, 0));
    for (const candidate of candidates) {
        draw -= candidate.weight;
        if (draw < 0) {
            return candidate.number;
        }
    }
    return candidates[candidates.length - 1].number;
}

/**
 * @param {object}   options
 * @param {object}   options.roteiro  the shared race script
 * @param {number}   options.semente  the shared seed
 * @param {number}   options.fonteId  who this counter is
 * @param {number[]} [options.naoClassificados] cars that are present but may not race
 * @param {number}   [options.janelaMs] the prova's matching window; see timingFor()
 * @param {object}   [options.taxas]  overrides of TAXAS_PADRAO
 * @returns {{acoes: Array<{tsMs: number, carro: number}>, erros: Array<object>}}
 *          `acoes` sorted by time; each error says what was done and when
 */
export function planCounter({ roteiro, semente, fonteId, naoClassificados = [], janelaMs = 20000, taxas = {} }) {
    const rates = { ...TAXAS_PADRAO, ...taxas };
    const timing = timingFor(janelaMs);
    const prng = new Prng(deriveSeed(semente, fonteId));
    const racing = new Set(roteiro.carros);
    const acoes = [];
    const erros = [];
    const noteError = (tipo, tsMs, carro, digitado = null) => erros.push({ tipo, fonteId, tsMs, carro, digitado });

    for (const { carro, tsMs: crossingMs } of roteiro.travessias) {
        const typedAtMs = crossingMs + prng.between(-timing.earlyMs, timing.lateMs);

        if (prng.chance(rates.perdido)) {
            noteError(ERRO.PERDIDO, crossingMs, carro);
            continue;
        }
        let typed = carro;
        if (prng.chance(rates.numero_errado)) {
            const wrong = wrongNumberFor(carro, prng, racing);
            if (wrong !== null) {
                typed = wrong;
                noteError(ERRO.NUMERO_ERRADO, typedAtMs, carro, wrong);
            }
        }
        acoes.push({ tsMs: typedAtMs, carro: typed });

        if (prng.chance(rates.toque_duplo)) {
            const againAtMs = typedAtMs + prng.between(timing.doubleTapMinMs, timing.doubleTapMaxMs);
            acoes.push({ tsMs: againAtMs, carro: typed });
            noteError(ERRO.TOQUE_DUPLO, againAtMs, carro, typed);
        } else if (prng.chance(rates.repetida)) {
            const againAtMs = typedAtMs + prng.between(timing.repeatMinMs, timing.repeatMaxMs);
            acoes.push({ tsMs: againAtMs, carro: typed });
            noteError(ERRO.REPETIDA, againAtMs, carro, typed);
        }

        // Entries for cars that did NOT cross: a number nobody has, a car
        // that may not race, a car that is standing in the pit lane.
        const strayAtMs = typedAtMs + prng.between(timing.strayMinMs, timing.strayMaxMs);
        if (prng.chance(rates.desconhecido)) {
            const unknown = unknownNumber(racing, naoClassificados, prng);
            acoes.push({ tsMs: strayAtMs, carro: unknown });
            noteError(ERRO.DESCONHECIDO, strayAtMs, null, unknown);
        }
        if (naoClassificados.length > 0 && prng.chance(rates.nao_classificado)) {
            const unclassified = prng.pick(naoClassificados);
            acoes.push({ tsMs: strayAtMs, carro: unclassified });
            noteError(ERRO.NAO_CLASSIFICADO, strayAtMs, null, unclassified);
        }
        if (prng.chance(rates.no_box)) {
            const inPitLane = carsInsideAt(roteiro, AREA_BOX, strayAtMs);
            if (inPitLane.length > 0) {
                const standing = prng.pick(inPitLane);
                acoes.push({ tsMs: strayAtMs, carro: standing });
                noteError(ERRO.NO_BOX, strayAtMs, null, standing);
            }
        }
    }

    acoes.sort((a, b) => a.tsMs - b.tsMs);
    return { acoes, erros };
}

/** A number that belongs to no car of the event. */
function unknownNumber(racing, naoClassificados, prng) {
    const taken = new Set([...racing, ...naoClassificados]);
    for (let attempt = 0; attempt < 50; attempt++) {
        const candidate = prng.between(1, 999);
        if (!taken.has(candidate)) {
            return candidate;
        }
    }
    return 9999;
}

/**
 * Gives every action of a plan an id that is the same every time the same
 * plan is made: for the same race, the same person and the same position
 * in the plan.
 *
 * The page stores each record under this id and the server keeps one
 * record per id. So a tab that is reloaded in the middle of a rehearsal, or
 * somebody running the command a second time, injects nothing twice: the
 * second copy of every record is recognised and dropped.
 *
 * @param {{acoes: Array<object>}} plan   a plan from planCounter() or planStopLog()
 * @param {object} race                   what makes this race this race
 * @param {number} race.semente
 * @param {number} race.largadaMs
 * @param {number} race.voltaMediaMs
 * @param {string|number} who             the source id of a counter, or the area of a stop logger
 * @returns {object} the same plan, each action with a `uuid`
 */
export function withStableIds(plan, { semente, largadaMs, voltaMediaMs }, who) {
    // Three numbers folded into one, then written as eight hex digits. The
    // start time is split in two because it does not fit 32 bits.
    const startHigh = Math.trunc(largadaMs / 0x100000000);
    const startLow = largadaMs % 0x100000000;
    const raceKey = deriveSeed(deriveSeed(deriveSeed(semente, startHigh), startLow), voltaMediaMs);
    const prefix = 'sim-' + raceKey.toString(16).padStart(8, '0') + '-' + String(who).slice(0, 13) + '-';
    plan.acoes.forEach((action, index) => {
        action.uuid = prefix + String(index).padStart(6, '0');
    });
    return plan;
}

/**
 * The plan of the person logging one area (pit lane or fueling station).
 *
 * @param {object} options
 * @param {object} options.roteiro
 * @param {number} options.semente
 * @param {string} options.area     AREA_BOX or AREA_ABASTECIMENTO
 * @param {object} [options.taxas]  overrides of TAXAS_PARADA_PADRAO
 * @returns {{acoes: Array<{tsMs: number, carro: number, tipo: string}>, erros: Array<object>}}
 */
export function planStopLog({ roteiro, semente, area, taxas = {} }) {
    const rates = { ...TAXAS_PARADA_PADRAO, ...taxas };
    // The two areas are logged by different people: different streams.
    const prng = new Prng(deriveSeed(semente, area === AREA_BOX ? 100001 : 100002));
    const acoes = [];
    const erros = [];

    for (const stop of roteiro.paradas.filter((candidate) => candidate.area === area)) {
        for (const [sentido, happenedAtMs] of [['entrada', stop.entradaMs], ['saida', stop.saidaMs]]) {
            const tipo = area + '_' + sentido;
            if (prng.chance(rates.perdido)) {
                erros.push({ tipo: ERRO.PARADA_PERDIDA, area, evento: tipo, tsMs: happenedAtMs, carro: stop.carro });
                continue;
            }
            let loggedAtMs = happenedAtMs + prng.between(0, 2000);
            if (prng.chance(rates.atrasado)) {
                loggedAtMs = happenedAtMs + prng.between(5000, 20000);
                erros.push({ tipo: ERRO.PARADA_ATRASADA, area, evento: tipo, tsMs: loggedAtMs, carro: stop.carro });
            }
            acoes.push({ tsMs: loggedAtMs, carro: stop.carro, tipo });
        }
    }

    acoes.sort((a, b) => a.tsMs - b.tsMs);
    return { acoes, erros };
}
