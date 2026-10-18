// "Informar número": giving a "??" its number afterwards, with no DOM in
// sight. contar-correcao.js draws it; contar.js routes the keys and tiles.
//
// The danger is a counter who forgets they are correcting and types the
// next car that crosses: that car would become the correction and its pass
// would be lost. So the mode is short and loud:
//
//   - it starts from the "Informar número" button of one "??" row;
//   - the NEXT car typed or tapped is the correction: no new entry, no time
//     taken. The mode then ends by itself;
//   - "Cancelar" ends it with nothing done;
//   - "Passou um carro" ends it AND records a "??" at that very instant: a
//     car crossing in the middle of a correction is never lost, and its
//     time is the tap (its number can be given afterwards, like any "??");
//   - after TEMPO_CORRECAO_MS without a car it ends by itself and says so;
//   - changing the entry mode, or undoing the entry being corrected, ends it.
//
// Only one correction at a time: "Informar número" on another row moves the
// mode to that row.

export const TEMPO_CORRECAO_MS = 20000;

/** How a correction ended (what the big flash then says, contar-destaque.js). */
export const FIM_CORRECAO = {
    CORRIGIDO: 'corrigido',
    CANCELADO: 'cancelado',
    TEMPO_ESGOTADO: 'tempo_esgotado',
    // "Passou um carro": cancelled so that a crossing can be recorded.
    PASSAGEM: 'passagem',
};

export class CorrectionMode {
    /**
     * @param {object}   options
     * @param {function} options.onChange      (state) => void after every change; `state` is
     *        null when no correction is open, else {uuid, entry}
     * @param {function} options.onEnd         ({motivo, uuid, car}) => void once per correction;
     *        `car` only for FIM_CORRECAO.CORRIGIDO
     * @param {function} [options.setTimeoutFn]
     * @param {function} [options.clearTimeoutFn]
     * @param {number}   [options.timeoutMs]
     */
    constructor({
        onChange = () => {}, onEnd = () => {}, setTimeoutFn = setTimeout, clearTimeoutFn = clearTimeout,
        timeoutMs = TEMPO_CORRECAO_MS,
    } = {}) {
        this.onChange = onChange;
        this.onEnd = onEnd;
        this.setTimeoutFn = setTimeoutFn;
        this.clearTimeoutFn = clearTimeoutFn;
        this.timeoutMs = timeoutMs;
        this.current = null;
        this.timer = null;
    }

    /** True while the next car typed or tapped is a correction, not a pass. */
    active() {
        return this.current !== null;
    }

    /** @returns {?string} the uuid of the "??" being corrected */
    uuid() {
        return this.current === null ? null : this.current.uuid;
    }

    /** @returns {?object} the entry being corrected */
    entry() {
        return this.current === null ? null : this.current.entry;
    }

    /** @param {object} entry the "??" record (CountSession.recentEntries() row) */
    start(entry) {
        this.stopTimer();
        this.current = { uuid: entry.uuid, entry };
        this.timer = this.setTimeoutFn(() => this.end(FIM_CORRECAO.TEMPO_ESGOTADO), this.timeoutMs);
        this.onChange(this.current);
    }

    /**
     * A car was typed (Enter / OK) or tapped while correcting.
     * @returns {?{uuid: string, car: number}} the correction to store, or null
     *          when no correction is open (the car is then an ordinary pass)
     */
    pressCar(car) {
        if (this.current === null || !Number.isInteger(car)) {
            return null;
        }
        const fix = { uuid: this.current.uuid, car };
        this.end(FIM_CORRECAO.CORRIGIDO, car);
        return fix;
    }

    cancel() {
        this.end(FIM_CORRECAO.CANCELADO);
    }

    /** "Passou um carro": the caller records a "??" right after this. */
    escapeToPass() {
        this.end(FIM_CORRECAO.PASSAGEM);
    }

    /** The entry being corrected was undone: there is nothing left to correct. */
    forget(uuid) {
        if (this.current !== null && this.current.uuid === uuid) {
            this.end(FIM_CORRECAO.CANCELADO);
        }
    }

    end(motivo, car = null) {
        if (this.current === null) {
            return;
        }
        const { uuid } = this.current;
        this.stopTimer();
        this.current = null;
        this.onChange(null);
        this.onEnd({ motivo, uuid, car });
    }

    stopTimer() {
        if (this.timer !== null) {
            this.clearTimeoutFn(this.timer);
            this.timer = null;
        }
    }
}
