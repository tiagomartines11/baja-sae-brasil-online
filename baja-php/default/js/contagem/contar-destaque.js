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

/** Colour and animation of the box (style sheet: .destaque-*). */
export const NIVEL = {
    OK: '',
    AVISO: 'aviso',
    ERRO: 'erro',
    DESFEITO: 'desfeito',
    // Nothing recorded yet on this page load: the instruction is shown.
    INSTRUCAO: 'instrucao',
};

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
 * @param {number}  pass.car
 * @param {Array}   pass.avisos      CountSession.recordPass().avisos
 * @param {?string} pass.nomeEquipe
 * @param {boolean} pass.anyListKnown with no car list at all, "unknown
 *        number" would be shown for every car; the box then shows no name
 */
export function passFlash({ car, avisos, nomeEquipe, anyListKnown }) {
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
 * The pass was NOT stored. It must be impossible to miss.
 *
 * @param {number}  car
 * @param {?string} blockedText TEXTO_BLOQUEIO[...] when recording is not
 *        allowed on this page; null when the device failed to store it
 */
export function notRecordedFlash(car, blockedText = null) {
    const aviso = blockedText === null
        ? { curta: 'Digite de novo', completa: ['O aparelho não conseguiu guardar. Digite de novo.'] }
        : { curta: 'Bloqueado: veja o aviso acima', completa: [blockedText] };
    return { numero: String(car), equipe: 'NÃO REGISTRADO', aviso, nivel: NIVEL.ERRO };
}

/**
 * After "desfazer". The box would otherwise still show the warning (or the
 * green pass) of the entry that no longer counts.
 */
export function undoFlash(car) {
    return { numero: String(car), equipe: 'Desfeito: carro ' + car, aviso: null, nivel: NIVEL.DESFEITO };
}

const NIVEL_CLASSES = {
    [NIVEL.AVISO]: 'destaque-problema',
    [NIVEL.ERRO]: 'destaque-erro',
    [NIVEL.DESFEITO]: 'destaque-desfeito',
    [NIVEL.INSTRUCAO]: 'destaque-instrucao',
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
            if (flash.nivel !== NIVEL.INSTRUCAO && flash.nivel !== NIVEL.DESFEITO) {
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
