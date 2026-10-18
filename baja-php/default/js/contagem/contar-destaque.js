// The big flash of the count page: what it says after a pass, a failure and
// an undo, and the drawing of it.
//
// THE BOX NEVER CHANGES SIZE. It sits right above the keypad and the tiles.
// A box that grew by one line of warning pushed every key and tile down by
// about one keypad row, and the next tap landed on the key or the car below
// the one aimed at; with "um toque" that is a wrong car recorded at once. So
// the box has three fixed rows (style sheet: .destaque): the number, one
// line of team name, one line of warning. Whatever does not fit is cut with
// "…". The whole sentence of every warning is on the entry's row in the
// recent list, in the warning line's title, and read out by screen readers.

import { MODO } from './entry-modes.js';
import { mostSeriousWarning } from './warnings.js';
import { FIM_CORRECAO } from './correction-mode.js';

/** Colour and animation of the box (style sheet: .destaque-*). */
export const NIVEL = {
    OK: '',
    AVISO: 'aviso',
    ERRO: 'erro',
    DESFEITO: 'desfeito',
    // Nothing recorded yet on this page load: the instruction is shown.
    INSTRUCAO: 'instrucao',
    // A "??" was given its number (or the correction ended): no pass was
    // recorded, so not the green of one.
    CORRECAO: 'correcao',
};

/** What the number row shows for a pass whose number could not be read. */
export const NUMERO_ILEGIVEL = '??';

const SEM_NUMERO = ' ';

/** What the box says before the first pass, in the mode on screen. */
export function instructionFlash(mode) {
    return {
        numero: SEM_NUMERO,
        equipe: mode === MODO.BLOCOS ? 'Toque no carro' : 'Digite o número',
        aviso: null,
        nivel: NIVEL.INSTRUCAO,
    };
}

/**
 * After a pass was stored.
 *
 * @param {object}  pass
 * @param {?number} pass.car         null for "??" (see unreadFlash)
 * @param {Array}   pass.avisos      CountSession.recordPass().avisos
 * @param {?string} pass.nomeEquipe
 * @param {boolean} pass.anyListKnown with no car list at all, "unknown
 *        number" would be shown for every car; the box then shows no name
 */
export function passFlash({ car, avisos, nomeEquipe, anyListKnown }) {
    if (car === null) {
        return unreadFlash();
    }
    const shown = mostSeriousWarning(avisos);
    const serious = avisos.some((aviso) => aviso.grave);
    return {
        numero: String(car),
        equipe: nomeEquipe || (anyListKnown ? 'Número desconhecido' : ''),
        aviso: shown === null ? null : { curta: shown.curta, completa: avisos.map((aviso) => aviso.mensagem) },
        nivel: serious ? NIVEL.ERRO : (shown !== null ? NIVEL.AVISO : NIVEL.OK),
    };
}

/**
 * After "??": the pass is stored, with its time, and waits for a number.
 * The warning colour: it is fine, but there is something left to do.
 */
export function unreadFlash() {
    return {
        numero: NUMERO_ILEGIVEL,
        equipe: '?? registrado',
        aviso: {
            curta: 'Informe o número depois',
            completa: ['?? registrado — informe o número quando puder, em "Informar número" na lista.'],
        },
        nivel: NIVEL.AVISO,
    };
}

/** A "??" was given its number: no new pass, the correction goes up. */
export function fixFlash({ car, nomeEquipe }) {
    return { numero: String(car), equipe: 'Corrigido: ' + (nomeEquipe || 'carro ' + car), aviso: null, nivel: NIVEL.CORRECAO };
}

/** The correction ended with nothing corrected (see CorrectionMode). */
export function correctionEndedFlash(motivo) {
    const aviso = motivo === FIM_CORRECAO.TEMPO_ESGOTADO
        ? { curta: 'Tempo esgotado: nada mudou', completa: ['Correção cancelada: tempo esgotado. Nada foi mudado.'] }
        : null;
    return { numero: SEM_NUMERO, equipe: 'Correção cancelada', aviso, nivel: NIVEL.CORRECAO };
}

/**
 * The banner over the big flash while a "??" is being corrected. Two lines,
 * each cut on its own: what to do comes first, so a long time text
 * ("(relógio do aparelho)") can only cut the time, never the instruction.
 * @param {object}  entry      the "??" record
 * @param {string}  mode       MODO.*: "toque no carro" or "digite o número"
 * @param {string}  timeText   the time of the "??" as the recent list shows it
 * @returns {{acao: string, registro: string}}
 */
export function correctionBannerText(entry, mode, timeText) {
    return {
        acao: 'Corrigindo: ' + (mode === MODO.BLOCOS ? 'toque no carro' : 'digite o número'),
        registro: Number.isFinite(entry.ts_ms) ? 'Registro das ' + timeText : 'Registro sem horário',
    };
}

/** A correction could not be stored: say so, and how to try again (not "digite de novo", which records a pass). */
export function fixNotStoredFlash(car, blockedText = null) {
    const aviso = blockedText === null
        ? { curta: 'Toque em Informar número', completa: ['O aparelho não conseguiu guardar a correção. Toque em Informar número de novo.'] }
        : { curta: 'Bloqueado: veja o aviso acima', completa: [blockedText] };
    return { numero: String(car), equipe: 'Correção NÃO guardada', aviso, nivel: NIVEL.ERRO };
}

/**
 * The pass was NOT stored. It must be impossible to miss.
 *
 * @param {?number} car         null for "??"
 * @param {?string} blockedText TEXTO_BLOQUEIO[...] when recording is not
 *        allowed on this page; null when the device failed to store it
 */
export function notRecordedFlash(car, blockedText = null) {
    const aviso = blockedText === null
        ? { curta: 'Digite de novo', completa: ['O aparelho não conseguiu guardar. Digite de novo.'] }
        : { curta: 'Bloqueado: veja o aviso acima', completa: [blockedText] };
    return { numero: car === null ? NUMERO_ILEGIVEL : String(car), equipe: 'NÃO REGISTRADO', aviso, nivel: NIVEL.ERRO };
}

/**
 * After "desfazer". The box would otherwise still show the warning (or the
 * green pass) of the entry that no longer counts.
 */
export function undoFlash(car) {
    if (car === null) {
        return { numero: NUMERO_ILEGIVEL, equipe: 'Desfeito: ??', aviso: null, nivel: NIVEL.DESFEITO };
    }
    return { numero: String(car), equipe: 'Desfeito: carro ' + car, aviso: null, nivel: NIVEL.DESFEITO };
}

const NIVEL_CLASSES = {
    [NIVEL.AVISO]: 'destaque-problema',
    [NIVEL.ERRO]: 'destaque-erro',
    [NIVEL.DESFEITO]: 'destaque-desfeito',
    [NIVEL.INSTRUCAO]: 'destaque-instrucao',
    [NIVEL.CORRECAO]: 'destaque-correcao',
};
const FLASH_CLASS = 'destaque-ativo';

/**
 * @param {object}      elements
 * @param {HTMLElement} elements.box       #destaque
 * @param {HTMLElement} elements.number    #destaque-numero
 * @param {HTMLElement} elements.team      #destaque-equipe
 * @param {HTMLElement} elements.warning   #destaque-aviso
 * @param {HTMLElement} elements.warningShort #destaque-aviso-curto: what is seen
 * @param {HTMLElement} elements.warningFull  #destaque-aviso-completo: what is read out
 */
export function createFlash({ box, number, team, warning, warningShort, warningFull }) {
    let current = null;

    return {
        /** @param {object} flash one of the *Flash() descriptions above */
        show(flash) {
            current = flash;
            number.textContent = flash.numero;
            team.textContent = flash.equipe;
            team.title = flash.equipe;
            const full = flash.aviso === null ? '' : flash.aviso.completa.join(' ');
            warningShort.textContent = flash.aviso === null ? '' : flash.aviso.curta;
            warningFull.textContent = full;
            warning.title = full;
            warning.classList.toggle('destaque-aviso-vazio', flash.aviso === null);
            for (const [nivel, className] of Object.entries(NIVEL_CLASSES)) {
                box.classList.toggle(className, flash.nivel === nivel);
            }
            box.classList.remove(FLASH_CLASS);
            if (flash.nivel !== NIVEL.INSTRUCAO && flash.nivel !== NIVEL.DESFEITO && flash.nivel !== NIVEL.CORRECAO) {
                // Removing and re-adding the class restarts the CSS animation,
                // so two identical entries in a row still flash twice.
                void box.offsetWidth;
                box.classList.add(FLASH_CLASS);
            }
        },

        /** Whether the box still shows the instruction (nothing recorded yet). */
        showsInstruction() {
            return current === null || current.nivel === NIVEL.INSTRUCAO;
        },
    };
}
