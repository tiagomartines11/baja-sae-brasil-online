// Text for the race clock and the other times in the status bar.

export const ESTADO_CORRIDA = {
    AGUARDANDO: 'aguardando',
    CORRENDO: 'correndo',
    BANDEIRA_VERMELHA: 'bandeira_vermelha',
    ENCERRADA: 'encerrada',
};

const ROTULO_ESTADO = {
    [ESTADO_CORRIDA.AGUARDANDO]: 'Aguardando largada',
    [ESTADO_CORRIDA.CORRENDO]: 'Correndo',
    [ESTADO_CORRIDA.BANDEIRA_VERMELHA]: 'Bandeira vermelha',
    [ESTADO_CORRIDA.ENCERRADA]: 'Encerrada',
};

const NO_CLOCK_TEXT = '--:--:--';

function twoDigits(value) {
    return String(value).padStart(2, '0');
}

/** 3725000 -> "1:02:05" */
export function formatElapsed(elapsedMs) {
    const totalSeconds = Math.max(0, Math.floor(elapsedMs / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return hours + ':' + twoDigits(minutes) + ':' + twoDigits(seconds);
}

/**
 * @param {?{largada_ms: ?number, encerramento_ms: ?number, estado: string}} race
 *        the `race` object of the last sync answer (null before the first one)
 * @param {number} deviceNowMs
 * @param {?number} clockOffsetMs null when unknown; the device clock is then
 *        used as it is, which is better than showing no clock at all
 * @returns {{texto: string, rotulo: string, estado: string}}
 */
export function describeRaceClock(race, deviceNowMs, clockOffsetMs) {
    if (!race || !Number.isFinite(race.largada_ms)) {
        return {
            texto: NO_CLOCK_TEXT,
            rotulo: ROTULO_ESTADO[ESTADO_CORRIDA.AGUARDANDO],
            estado: ESTADO_CORRIDA.AGUARDANDO,
        };
    }
    const serverNowMs = deviceNowMs + (Number.isFinite(clockOffsetMs) ? clockOffsetMs : 0);
    // After the finish the clock stays frozen at the race duration.
    const untilMs = Number.isFinite(race.encerramento_ms) ? Math.min(serverNowMs, race.encerramento_ms) : serverNowMs;
    return {
        texto: formatElapsed(untilMs - race.largada_ms),
        rotulo: ROTULO_ESTADO[race.estado] || '',
        estado: race.estado,
    };
}

/** Local wall-clock time, "14:03:22", for "último envio". */
export function formatTimeOfDay(timestampMs) {
    if (!Number.isFinite(timestampMs)) {
        return '—';
    }
    const date = new Date(timestampMs);
    return twoDigits(date.getHours()) + ':' + twoDigits(date.getMinutes()) + ':' + twoDigits(date.getSeconds());
}
