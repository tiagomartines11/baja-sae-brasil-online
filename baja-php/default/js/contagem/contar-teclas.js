// Physical keyboard on the count page: one listener for the whole page.
//
// After a tap on "desfazer", on a switch or on the page background the number
// box no longer has the focus. The next car is typed without looking, so
// those keys must still count. `keyActionFor` (entry-modes.js) decides what a
// key means; this file is the wiring, kept apart from contar.js so that it
// can be tested with a pretend page: what matters here is not only WHICH
// action a key gets but that the browser's own reaction to the key is
// cancelled (`preventDefault`) in exactly the right cases.

import { keyActionFor, TECLA } from './entry-modes.js';

/**
 * @param {object}   options
 * @param {object}   options.documentRef  the page (anything with addEventListener)
 * @param {object}   options.numberField  the number box of keyboard mode
 * @param {object}   options.keypad       the element that contains the on-screen keys
 * @param {function} options.getMode      () => the entry mode in use (MODO.*)
 * @param {function} options.isBlocked    () => true while nothing may be recorded (shift ended)
 * @param {function} options.onKey        ({tecla, digito}) => void, for a digit, backspace or Enter
 */
export function wireCountKeys({ documentRef, numberField, keypad, getMode, isBlocked, onKey }) {
    function actionFor(event) {
        if (isBlocked()) {
            return null;
        }
        return keyActionFor(event, {
            mode: getMode(),
            typedInNumberBox: event.target === numberField,
            focusOnKeypadKey: Boolean(keypad) && keypad.contains(event.target),
        });
    }

    documentRef.addEventListener('keydown', (event) => {
        const action = actionFor(event);
        if (action === null) {
            return;
        }
        // Stops the key from also doing what it normally does where the
        // focus is: Enter would "click" the button that was tapped last, a
        // held digit would repeat inside the number box.
        event.preventDefault();
        if (action.tecla !== TECLA.NADA) {
            onKey(action);
        }
    });

    // A browser "clicks" a focused button when Space is RELEASED, so
    // cancelling the press alone would not be enough for that key.
    documentRef.addEventListener('keyup', (event) => {
        const action = actionFor(event);
        if (action !== null && action.tecla === TECLA.NADA) {
            event.preventDefault();
        }
    });
}
