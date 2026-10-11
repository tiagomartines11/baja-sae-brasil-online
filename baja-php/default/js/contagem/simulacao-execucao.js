// Plays a plan: hands each planned action to the page at the right moment.
//
// A plan is a list of "type car 41 at 14:03:07.250" (see
// simulacao-contador.js). This module waits until the race has reached
// each action's time and then calls `registrar` with it. The same code
// runs in a browser tab and in the Node runner; the clock and the way to
// wait are passed in.
//
// TWO THINGS TO KNOW
//
// 1. The time of an entry is the time in the PLAN, never the moment this
//    loop got round to it. Browsers slow the timers of a background tab
//    down to one tick a second or less; stamping entries with "now" would
//    bunch them together and the counters' entries would no longer match.
//    So a late tick simply plays everything that has come due, each action
//    with its own intended time.
//
// 2. "The race's time" can run faster than real time. In live mode it IS
//    the server's time. In replay mode the race lies in the past and is
//    played back `velocidade` times faster: race time = where the replay
//    started + real time elapsed x velocidade.

/** How long the loop sleeps at most, so that "parar" is obeyed promptly. */
const LONGEST_SLEEP_MS = 250;

/**
 * @param {object}   options
 * @param {Array<{tsMs: number}>} options.acoes   the plan, sorted by time
 * @param {function} options.registrar  async (action) => void; what "typing it" means on this page
 * @param {function} options.agora      () => real time now in ms (any clock that only moves forward)
 * @param {function} options.esperar    async (ms) => void
 * @param {number}   [options.velocidade]      1 = real time
 * @param {?number}  [options.inicioCorridaMs] race time at which playing starts;
 *                                             null = the time of the first action (replay from the beginning)
 * @param {function} [options.aoProgredir] (played, total) => void, after each batch
 * @returns {{terminou: Promise<{registradas: number, falhas: number}>, parar: function}}
 */
export function playPlan({ acoes, registrar, agora, esperar, velocidade = 1, inicioCorridaMs = null, aoProgredir = () => {} }) {
    let stopped = false;
    const realStartMs = agora();
    const raceStartMs = inicioCorridaMs ?? (acoes.length > 0 ? acoes[0].tsMs : 0);
    const raceNow = () => raceStartMs + (agora() - realStartMs) * velocidade;

    async function run() {
        let played = 0;
        let failed = 0;
        while (played < acoes.length && !stopped) {
            const next = acoes[played];
            const raceMsUntilDue = next.tsMs - raceNow();
            if (raceMsUntilDue > 0) {
                await esperar(Math.min(LONGEST_SLEEP_MS, Math.ceil(raceMsUntilDue / velocidade)));
                continue;
            }
            // Everything that has come due goes now, each with its own time.
            while (played < acoes.length && acoes[played].tsMs <= raceNow() && !stopped) {
                try {
                    await registrar(acoes[played]);
                } catch (error) {
                    // One refused action (the shift was ended, say) must
                    // not stop the rest of the race.
                    failed++;
                }
                played++;
            }
            aoProgredir(played, acoes.length);
        }
        return { registradas: played - failed, falhas: failed };
    }

    return {
        terminou: run(),
        parar() {
            stopped = true;
        },
    };
}

/**
 * Does the matching window fit the lap? With a window that is a large
 * share of a lap, two consecutive laps of a car fall into one group and
 * the count is wrong for reasons that have nothing to do with the code
 * under test. A quarter of the lap is the most that is accepted (the real
 * settings use a twelfth: 20 s for a lap of four minutes).
 */
export function windowFitsLap(janelaMs, voltaMediaMs) {
    return janelaMs * 4 <= voltaMediaMs;
}
