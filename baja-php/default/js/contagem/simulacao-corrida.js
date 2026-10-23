// The race script of a simulation: what REALLY happens on the made-up
// track. Which car crosses the line when, which car stops in the pit lane
// or at the fueling station, which car breaks down or retires.
//
// The script is SHARED: every count page, the pit page and the Node runner
// build it from the same four things (seed, car list, average lap, start
// time) and get exactly the same race. What each counter then types, with
// their own mistakes, is another module (simulacao-contador.js).
//
// Everything is whole milliseconds, and the car list is sorted first, so
// neither rounding nor the order in which a page happened to receive the
// cars can make two copies of the race differ.

import { Prng, scale } from './simulacao-prng.js';

export const AREA_BOX = 'box';
export const AREA_ABASTECIMENTO = 'abastecimento';

/** How often things happen, per thousand laps. */
export const CHANCES = {
    quebra: 15,          // a breakdown: one very long lap
    box: 30,             // a pit stop
    abastecimento: 25,   // a fueling stop (only when the prova logs that area)
    abandono: 3,         // the car stops for good
};

/** A real endurance race lasts four hours. */
export const REAL_RACE_MS = 4 * 60 * 60 * 1000;

/**
 * How long the simulated race lasts for a given average lap: as many laps
 * as a real race has at a real lap of four minutes, so a compressed race
 * has the same shape as a real one, only faster.
 */
export function raceDurationFor(voltaMediaMs) {
    return voltaMediaMs * 60;
}

/**
 * @param {object}   options
 * @param {number}   options.semente       the shared seed
 * @param {number[]} options.carros        car numbers taking part (any order)
 * @param {number}   options.voltaMediaMs  average lap
 * @param {number}   options.largadaMs     start of the race, server time
 * @param {number}   [options.duracaoMs]   length of the race; see raceDurationFor()
 * @param {boolean}  [options.abastecimento] whether cars also stop at the fueling station
 * @returns {{
 *   largadaMs: number, fimMs: number, voltaMediaMs: number, carros: number[],
 *   travessias: Array<{carro: number, tsMs: number}>,
 *   paradas: Array<{carro: number, area: string, entradaMs: number, saidaMs: number}>,
 *   quebras: Array<{carro: number, tsMs: number}>,
 *   abandonos: Array<{carro: number, tsMs: number}>
 * }} crossings sorted by time; everything else in the order it happened per car
 */
export function generateRaceScript({ semente, carros, voltaMediaMs, largadaMs, duracaoMs = null, abastecimento = false }) {
    const prng = new Prng(semente);
    const sortedCars = [...carros].sort((a, b) => a - b);
    const fimMs = largadaMs + (duracaoMs ?? raceDurationFor(voltaMediaMs));

    // The pace of the whole field drifts over the race (the track gets
    // rougher, drivers tire): by the end laps are this much slower or faster.
    const driftAtEndPerThousand = prng.between(-60, 120);

    const script = {
        largadaMs, fimMs, voltaMediaMs, carros: sortedCars,
        travessias: [], paradas: [], quebras: [], abandonos: [],
    };

    // The starting grid, front to back. The counting line is a little
    // after the start line, so every car's first crossing comes shortly
    // after the start: the pack passes within a few seconds of each other.
    const grid = prng.shuffled(sortedCars);
    grid.forEach((carro, gridPosition) => {
        const paceOfCarPerThousand = prng.between(850, 1300);
        const firstCrossingMs = largadaMs
            + scale(voltaMediaMs, 120)
            + gridPosition * scale(voltaMediaMs, 6)
            + prng.between(0, scale(voltaMediaMs, 4));
        runOneCar(script, prng, {
            carro, firstCrossingMs, paceOfCarPerThousand, driftAtEndPerThousand, abastecimento,
        });
    });

    script.travessias.sort((a, b) => a.tsMs - b.tsMs || a.carro - b.carro);
    return script;
}

/** Adds one car's whole race to the script. */
function runOneCar(script, prng, { carro, firstCrossingMs, paceOfCarPerThousand, driftAtEndPerThousand, abastecimento }) {
    const { largadaMs, fimMs, voltaMediaMs } = script;
    let crossingMs = firstCrossingMs;

    while (crossingMs <= fimMs) {
        script.travessias.push({ carro, tsMs: crossingMs });

        if (prng.chance(CHANCES.abandono)) {
            script.abandonos.push({ carro, tsMs: crossingMs + scale(voltaMediaMs, prng.between(100, 900)) });
            return;
        }

        // The lap that starts at this crossing.
        const raceDonePerThousand = Math.trunc(((crossingMs - largadaMs) * 1000) / (fimMs - largadaMs));
        const driftPerThousand = 1000 + scale(driftAtEndPerThousand, raceDonePerThousand);
        let lapMs = scale(scale(scale(voltaMediaMs, paceOfCarPerThousand), driftPerThousand), prng.between(960, 1040));

        if (prng.chance(CHANCES.quebra)) {
            script.quebras.push({ carro, tsMs: crossingMs + scale(lapMs, 500) });
            lapMs += voltaMediaMs * prng.between(2, 6);
        }
        // A stop happens part of the way round, and its length is added to
        // the lap. A car may do both in one lap: pit lane first.
        let stopStartsMs = crossingMs + scale(lapMs, 300);
        if (prng.chance(CHANCES.box)) {
            const stayMs = scale(voltaMediaMs, prng.between(200, 1500));
            script.paradas.push({ carro, area: AREA_BOX, entradaMs: stopStartsMs, saidaMs: stopStartsMs + stayMs });
            stopStartsMs += stayMs + scale(lapMs, 100);
            lapMs += stayMs;
        }
        if (abastecimento && prng.chance(CHANCES.abastecimento)) {
            const stayMs = scale(voltaMediaMs, prng.between(300, 800));
            script.paradas.push({ carro, area: AREA_ABASTECIMENTO, entradaMs: stopStartsMs, saidaMs: stopStartsMs + stayMs });
            lapMs += stayMs;
        }

        crossingMs += lapMs;
    }
}

/** @returns {number[]} cars inside an area at a moment, according to the script */
export function carsInsideAt(script, area, tsMs) {
    return script.paradas
        .filter((stop) => stop.area === area && stop.entradaMs <= tsMs && tsMs < stop.saidaMs)
        .map((stop) => stop.carro);
}
