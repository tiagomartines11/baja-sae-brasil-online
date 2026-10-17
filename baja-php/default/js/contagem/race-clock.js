// Text for the race clock, the official time and the other times on the
// count and pit pages.
//
// Every time shown here is SERVER time (see server-clock.js). Until this page
// load has heard from the server there is no such time, and the page says
// "sincronizando…" instead of showing what the phone's own clock believes.

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

export const TEXTO_SINCRONIZANDO = 'sincronizando…';

/**
 * @param {?{largada_ms: ?number, encerramento_ms: ?number, estado: string}} race
 *        the `race` object of the last sync answer (null before the first one)
 * @param {?number} serverNowMs ServerClock.serverNowMs(); null until this
 *        page load has an anchor. The race clock then shows dashes: a clock
 *        built on the phone's own time could be wrong by hours.
 * @returns {{texto: string, rotulo: string, estado: string}}
 */
export function describeRaceClock(race, serverNowMs) {
    if (!race || !Number.isFinite(race.largada_ms)) {
        return {
            texto: NO_CLOCK_TEXT,
            rotulo: ROTULO_ESTADO[ESTADO_CORRIDA.AGUARDANDO],
            estado: ESTADO_CORRIDA.AGUARDANDO,
        };
    }
    const rotulo = ROTULO_ESTADO[race.estado] || '';
    // A finished race has a fixed duration; no clock is needed to show it.
    if (Number.isFinite(race.encerramento_ms)
        && (!Number.isFinite(serverNowMs) || serverNowMs >= race.encerramento_ms)) {
        return { texto: formatElapsed(race.encerramento_ms - race.largada_ms), rotulo, estado: race.estado };
    }
    if (!Number.isFinite(serverNowMs)) {
        return { texto: NO_CLOCK_TEXT, rotulo, estado: race.estado };
    }
    return { texto: formatElapsed(serverNowMs - race.largada_ms), rotulo, estado: race.estado };
}

const timeFormatters = new Map();

/** One formatter per time zone, built once: this runs several times a second. */
function timeFormatterFor(timeZone) {
    if (!timeFormatters.has(timeZone)) {
        const options = { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' };
        let formatter;
        try {
            formatter = new Intl.DateTimeFormat('pt-BR', timeZone ? { ...options, timeZone } : options);
        } catch (unknownTimeZone) {
            formatter = new Intl.DateTimeFormat('pt-BR', options);
        }
        timeFormatters.set(timeZone, formatter);
    }
    return timeFormatters.get(timeZone);
}

/**
 * Wall-clock time, "14:03:22".
 *
 * @param {?number} timestampMs
 * @param {?string} [timeZone] the EVENT's time zone ("America/Sao_Paulo"),
 *        so that a phone set to another zone still shows the hour everybody
 *        at the track reads. Without it the phone's own zone is used.
 */
export function formatTimeOfDay(timestampMs, timeZone = null) {
    if (!Number.isFinite(timestampMs)) {
        return '—';
    }
    return timeFormatterFor(timeZone).format(new Date(timestampMs));
}

export const TEXTO_VERIFICANDO = 'verificando';

/**
 * Text of "Horário oficial".
 *
 * @param {?number} serverNowMs ServerClock.serverNowMs()
 * @param {?string} [timeZone]
 * @param {?string} [estadoRelogio] ServerClock.estado(). While it is
 *        'verificando' the page doubts its anchor (see server-clock.js): the
 *        time is still shown, since it is the best there is and usually
 *        right, but it says so. "sincronizando…" is only for no time at all.
 */
export function describeOfficialTime(serverNowMs, timeZone = null, estadoRelogio = null) {
    if (!Number.isFinite(serverNowMs)) {
        return TEXTO_SINCRONIZANDO;
    }
    const time = formatTimeOfDay(serverNowMs, timeZone);
    return estadoRelogio === TEXTO_VERIFICANDO ? time + ' (' + TEXTO_VERIFICANDO + ')' : time;
}

/**
 * The time shown beside a record in the recent lists: its official time, or
 * a plain statement that it does not have one yet.
 */
export function describeRecordTime(record, timeZone = null) {
    return Number.isFinite(record.ts_ms) ? formatTimeOfDay(record.ts_ms, timeZone) : 'aguardando horário oficial';
}
