// Tiles mode of the count page: draws the car tiles, the selected car, the
// "Passou" button and the one-tap switch. The decisions are in
// tiles-control.js; this file only connects them to the DOM.

import { BLOCO_ILEGIVEL } from './tiles-control.js';

const TEXTO_SEM_SELECAO = 'Toque no carro que vem chegando';
const TEXTO_ILEGIVEL = 'Número não lido';

const PERGUNTA_UM_TOQUE = 'Ligar "Um toque marca a passagem"?\n\n'
    + 'Cada toque em um carro passa a registrar a passagem NA HORA, sem o botão "Passou". '
    + 'Um toque por engano vira uma passagem registrada (dá para desfazer na lista).';

/**
 * @param {object}   options
 * @param {function} options.element   (id) => HTMLElement
 * @param {object}   options.control   TilesControl
 * @param {function} options.getCars   () => [{numero, nome}] the classified cars
 * @param {function} options.confirm   (question) => boolean, normally window.confirm
 * @param {function} options.onPass    ({car, typingMs}) => void: record this pass now (car null for "??")
 * @param {function} [options.isCorrecting] () => true while a "??" is being corrected
 *        (correction-mode.js): a car tile then gives that number, it records nothing
 * @param {function} [options.onCorrect] (car) => void: the car tapped while correcting
 * @returns {{render: function}} `render()` after anything that may change the car list
 */
export function createTilesPanel({
    element, control, getCars, confirm, onPass, isCorrecting = () => false, onCorrect = () => {},
}) {
    const container = element('blocos');
    const passButton = element('btn-passou');
    const oneTapSwitch = element('opt-um-toque');
    let renderedTilesSignature = null;

    // Always first, whatever the car list: the same place every time.
    const unreadTile = document.createElement('button');
    unreadTile.type = 'button';
    unreadTile.className = 'bloco bloco-ilegivel';
    unreadTile.dataset.carro = BLOCO_ILEGIVEL;
    unreadTile.textContent = '??';
    unreadTile.title = 'Não consegui ler o número';
    unreadTile.setAttribute('aria-label', 'Não consegui ler o número');
    unreadTile.addEventListener('click', () => tap(unreadTile, null));

    /** A tap on a tile: `car` null for "??". */
    function tap(tile, car) {
        // The tile must not keep the keyboard focus: a stray
        // Enter or Space would "tap" it again.
        tile.blur();
        if (isCorrecting()) {
            if (car !== null) {
                onCorrect(car);
            }
            return;
        }
        const pass = control.tapTile(car);
        renderSelection();
        if (pass !== null) {
            onPass(pass);
        }
    }

    function teamNameOf(carNumber) {
        const car = getCars().find((candidate) => candidate.numero === carNumber);
        return car ? car.nome : '';
    }

    function renderSelection() {
        const selected = control.selected();
        const unread = selected === BLOCO_ILEGIVEL;
        // The panel belongs to two-tap mode; with one tap there is no
        // selection and no "Passou".
        element('blocos-dois-toques').hidden = control.oneTap();
        element('selecionado-numero').textContent = selected === null ? '–' : (unread ? '??' : String(selected));
        element('selecionado-equipe').textContent = selected === null ? TEXTO_SEM_SELECAO
            : (unread ? TEXTO_ILEGIVEL : teamNameOf(selected));
        // While correcting, nothing here records a pass: only the car tiles
        // work, and they give the number. (Disabled, not hidden: nothing moves.)
        passButton.disabled = selected === null || isCorrecting();
        unreadTile.disabled = isCorrecting();
        for (const tile of container.children) {
            const isSelected = selected !== null && tile.dataset.carro === String(selected);
            tile.classList.toggle('bloco-selecionado', isSelected);
            tile.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        }
    }

    function renderTiles() {
        const cars = getCars().slice().sort((first, second) => first.numero - second.numero);
        const signature = cars.map((car) => car.numero).join(',');
        // Rebuilt only when the list changed: this runs after every sync, and
        // a tile must never be swapped out from under a finger.
        if (signature !== renderedTilesSignature) {
            renderedTilesSignature = signature;
            container.replaceChildren(unreadTile);
            for (const car of cars) {
                const tile = document.createElement('button');
                tile.type = 'button';
                tile.className = 'bloco';
                tile.dataset.carro = String(car.numero);
                tile.textContent = String(car.numero);
                tile.title = car.nome;
                tile.addEventListener('click', () => tap(tile, car.numero));
                container.appendChild(tile);
            }
            element('blocos-vazio').hidden = cars.length > 0;
        }
        renderSelection();
    }

    passButton.addEventListener('click', () => {
        passButton.blur();
        if (isCorrecting()) {
            return;
        }
        // The time of the pass is taken inside onPass, first thing: this tap
        // is the car crossing the line.
        const pass = control.tapPassou();
        renderSelection();
        if (pass !== null) {
            onPass(pass);
        }
    });

    oneTapSwitch.checked = control.oneTap();
    oneTapSwitch.addEventListener('change', () => {
        // Switching it ON changes what a tap does, so the person confirms;
        // switching it off is always safe.
        if (oneTapSwitch.checked && !confirm(PERGUNTA_UM_TOQUE)) {
            oneTapSwitch.checked = false;
            return;
        }
        control.setOneTap(oneTapSwitch.checked);
        renderSelection();
    });

    return {
        render: renderTiles,
        /** After a correction starts or ends: what may be tapped changes. */
        renderSelection,
        /** Leaving tiles mode forgets the selection: it must not be waiting on return. */
        clearSelection() {
            control.clearSelection();
            renderSelection();
        },
    };
}
