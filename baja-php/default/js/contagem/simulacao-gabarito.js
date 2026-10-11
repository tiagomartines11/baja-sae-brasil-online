// The answer key of a simulation: what the right result is, worked out
// from the race script alone, without any counter.
//
// After a rehearsal, this is what the review screen and the results page
// are compared with: every car's true number of laps, and the list of
// mistakes that were put in on purpose, each with its time, so the flag it
// should have caused can be looked up.

export const INICIAL_PRIMEIRA_PASSAGEM = 'primeira_passagem';
export const INICIAL_LARGADA = 'largada';

/**
 * Each car's true lap count at a given moment.
 *
 * Which crossing completes the first lap depends on the prova's counting
 * rule (`contagem.passagem_inicial`):
 *   - 'primeira_passagem' (the default): the counting line is after the
 *     start line, so a car's first crossing completes nothing ("pass
 *     zero") and laps = crossings - 1;
 *   - 'largada': the start itself is pass zero, and laps = crossings.
 *
 * @param {object} roteiro          the race script
 * @param {object} [options]
 * @param {string} [options.passagemInicial]
 * @param {?number} [options.ateMs] count crossings up to this moment (default: all)
 * @returns {Map<number, number>} car number => laps, for every car of the script
 */
export function trueLapCounts(roteiro, { passagemInicial = INICIAL_PRIMEIRA_PASSAGEM, ateMs = null } = {}) {
    const crossings = new Map(roteiro.carros.map((carro) => [carro, 0]));
    for (const { carro, tsMs } of roteiro.travessias) {
        if (ateMs === null || tsMs <= ateMs) {
            crossings.set(carro, crossings.get(carro) + 1);
        }
    }
    const laps = new Map();
    for (const [carro, count] of crossings) {
        const firstCrossingIsPassZero = passagemInicial === INICIAL_PRIMEIRA_PASSAGEM;
        laps.set(carro, firstCrossingIsPassZero ? Math.max(0, count - 1) : count);
    }
    return laps;
}

/**
 * The injected mistakes of every simulated person, oldest first.
 *
 * @param {Array<{erros: Array<object>}>} plans the plans of the counters and of the stop loggers
 */
export function injectedErrors(plans) {
    return plans.flatMap((plan) => plan.erros).sort((a, b) => a.tsMs - b.tsMs);
}

function csvLine(values) {
    return values.map((value) => (value === null || value === undefined ? '' : String(value))).join(';');
}

/** "h:mm:ss" since the start of the race; negative before it. */
export function raceTime(tsMs, largadaMs) {
    const totalSeconds = Math.trunc(Math.abs(tsMs - largadaMs) / 1000);
    const hours = Math.trunc(totalSeconds / 3600);
    const minutes = String(Math.trunc((totalSeconds % 3600) / 60)).padStart(2, '0');
    const seconds = String(totalSeconds % 60).padStart(2, '0');
    return (tsMs < largadaMs ? '-' : '') + hours + ':' + minutes + ':' + seconds;
}

/** @param {Map<number, number>} lapsByCar */
export function lapCountsCsv(lapsByCar) {
    const lines = [csvLine(['carro', 'voltas'])];
    for (const [carro, laps] of [...lapsByCar].sort((a, b) => a[0] - b[0])) {
        lines.push(csvLine([carro, laps]));
    }
    return lines.join('\r\n') + '\r\n';
}

/** @param {Array<object>} errors as returned by injectedErrors() */
export function errorsCsv(errors, largadaMs) {
    const lines = [csvLine(['tipo', 'tempo_de_prova', 'ts_ms', 'fonte_id', 'area', 'carro_real', 'digitado'])];
    for (const error of errors) {
        lines.push(csvLine([
            error.tipo, raceTime(error.tsMs, largadaMs), error.tsMs,
            error.fonteId, error.area, error.carro, error.digitado,
        ]));
    }
    return lines.join('\r\n') + '\r\n';
}
