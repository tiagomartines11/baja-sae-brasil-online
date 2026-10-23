// Small helpers to build the page. Everything is created with
// createElement and text nodes, never by assembling HTML strings: team
// names, usernames and reasons come from people and must not be able to
// inject markup.

// el('button', {class: 'x', onclick: fn, disabled: true}, 'texto', outroElemento)
export function el(etiqueta, atributos = {}, ...filhos) {
  const elemento = document.createElement(etiqueta);
  for (const [nome, valor] of Object.entries(atributos ?? {})) {
    if (valor === null || valor === undefined || valor === false) {
      continue;
    }
    if (nome.startsWith('on') && typeof valor === 'function') {
      elemento.addEventListener(nome.slice(2), valor);
    } else if (nome === 'class') {
      elemento.className = valor;
    } else if (nome === 'style' && typeof valor === 'object') {
      for (const [propriedade, valorDaPropriedade] of Object.entries(valor)) {
        // setProperty also accepts custom properties ("--largura"), which
        // plain assignment to elemento.style silently ignores.
        elemento.style.setProperty(propriedade, valorDaPropriedade);
      }
    } else if (valor === true) {
      elemento.setAttribute(nome, '');
    } else {
      elemento.setAttribute(nome, String(valor));
    }
  }
  acrescentar(elemento, filhos);
  return elemento;
}

function acrescentar(elemento, filhos) {
  for (const filho of filhos) {
    if (filho === null || filho === undefined || filho === false) {
      continue;
    }
    if (Array.isArray(filho)) {
      acrescentar(elemento, filho);
    } else if (filho instanceof Node) {
      elemento.appendChild(filho);
    } else {
      elemento.appendChild(document.createTextNode(String(filho)));
    }
  }
}

// Replaces everything inside a container.
export function substituir(container, ...filhos) {
  container.replaceChildren();
  acrescentar(container, filhos);
}

// True while the reviewer is typing in a field inside `container`. The
// screen then avoids redrawing that part, which would throw the typing away.
export function estaDigitandoEm(container) {
  const ativo = document.activeElement;
  if (!ativo || !container.contains(ativo)) {
    return false;
  }
  return ['INPUT', 'SELECT', 'TEXTAREA'].includes(ativo.tagName);
}
