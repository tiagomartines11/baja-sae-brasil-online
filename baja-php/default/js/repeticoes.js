// The repeated groups on the judge's entry page (juiz/entry.php,
// BAJAAPP-103): keeps each repetition's block state and lock up to date
// while the judge types. The page draws the blocks as they are when it
// opens (Baja\Juiz\EntryBlocks); the rules here are the same.
//
//   preenchida  every field has a value (an xor pair needs only one side)
//   incompleta  some fields have a value, not all
//   vazia       no value, and it may be filled
//   bloqueada   no value, and the repetition before it is not complete:
//               its fields are disabled until it is
//
// A block that has values is never locked, even if the one before it was
// emptied afterwards: a disabled field is not sent with the form, and the
// save would erase it. The server refuses that save and says why.
//
// It also shows ONE block of each group at a time, and makes the blocks'
// navigation switch between them (see "One block at a time" below).
//
// The functions on top are pure, for the tests (tests/js/repeticoes.test.js);
// ligar() at the bottom connects them to the page.

/** The xor letters the page handles: "A", "B", and the same scoped to a repetition ("A_3"). */
export function xorAtivo(letra) {
  return /^[AB](_[1-9][0-9]*)?$/.test(String(letra ?? ''));
}

/**
 * The state of every block.
 *
 * @param {Array<{ancora: string, grupo: number, numero: number, campos: Array<{codigo: string, xor?: string}>}>} blocos
 *        in page order
 * @param {Record<string, string|null>} valores code => what the field holds ("" or null: empty)
 * @returns {Record<string, {estado: string, bloqueado: boolean}>} anchor => state
 */
export function estadosDosBlocos(blocos, valores) {
  const preenchido = (codigo) => valores[codigo] !== undefined && valores[codigo] !== null && valores[codigo] !== '';
  const letrasEmUso = new Set();
  for (const bloco of blocos) {
    for (const campo of bloco.campos) {
      if (preenchido(campo.codigo) && xorAtivo(campo.xor)) letrasEmUso.add(campo.xor);
    }
  }

  const completo = new Map();
  const estados = {};
  for (const bloco of blocos) {
    let temValores = false;
    let estaCompleto = true;
    for (const campo of bloco.campos) {
      if (preenchido(campo.codigo)) temValores = true;
      else if (!(xorAtivo(campo.xor) && letrasEmUso.has(campo.xor))) estaCompleto = false;
    }
    completo.set(`${bloco.grupo}:${bloco.numero}`, estaCompleto);
    const anteriorCompleto = completo.get(`${bloco.grupo}:${bloco.numero - 1}`) ?? true;

    let estado = 'vazia';
    if (temValores && estaCompleto) estado = 'preenchida';
    else if (temValores) estado = 'incompleta';
    else if (!anteriorCompleto) estado = 'bloqueada';
    estados[bloco.ancora] = { estado, bloqueado: estado === 'bloqueada' };
  }
  return estados;
}

/** The text of a state, as the page writes it (EntryBlocks::STATUS_TEXT). */
export const TEXTO_DO_ESTADO = {
  preenchida: '✔ Preenchida',
  incompleta: '… Incompleta',
  vazia: '○ Vazia',
  bloqueada: '🔒 Bloqueada',
};

/** The blocks of the page, read from the DOM the page drew. */
export function blocosDaPagina(documento) {
  return [...documento.querySelectorAll('tbody.repeticao')].map((corpo) => ({
    ancora: corpo.id,
    grupo: Number(corpo.dataset.grupo),
    numero: Number(corpo.dataset.numero),
    campos: [...corpo.querySelectorAll('[data-group]')].map((campo) => ({
      codigo: campo.id,
      xor: campo.dataset.xor,
      elemento: campo,
    })),
  }));
}

/**
 * The value of a field as the page's other script reads it, $(field).val():
 * a multiple select gives its chosen values joined.
 */
function valorDoCampo(campo) {
  if (campo.multiple) return [...campo.selectedOptions].map((opcao) => opcao.value).filter((v) => v !== '').join(',');
  return campo.value;
}

/**
 * Disabled when any of the page's locks says so: the same props the page's
 * jQuery computeDisable() reads (jQuery's .prop() stores them on the element).
 */
function recalcularDesabilitado(campo) {
  campo.disabled = Boolean(campo['xor-disabled'] || campo['group-disabled'] || campo['filter-disabled'] || campo['rep-disabled']);
}

/** Applies the states to the page: block colour and text, jump buttons, locks. */
export function aplicar(documento, blocos) {
  const valores = {};
  for (const bloco of blocos) {
    for (const campo of bloco.campos) valores[campo.codigo] = valorDoCampo(campo.elemento);
  }
  const estados = estadosDosBlocos(blocos, valores);
  for (const bloco of blocos) {
    const { estado, bloqueado } = estados[bloco.ancora];
    const corpo = documento.getElementById(bloco.ancora);
    corpo.dataset.estado = estado;
    const texto = corpo.querySelector('.repeticao-estado');
    if (texto) texto.textContent = TEXTO_DO_ESTADO[estado];
    for (const campo of bloco.campos) {
      campo.elemento['rep-disabled'] = bloqueado;
      recalcularDesabilitado(campo.elemento);
    }
    for (const salto of documento.querySelectorAll(`.repeticao-salto[data-alvo="${bloco.ancora}"]`)) {
      salto.dataset.estado = estado;
    }
    for (const opcao of documento.querySelectorAll(`.repeticao-select option[data-alvo="${bloco.ancora}"]`)) {
      opcao.textContent = opcao.textContent.replace(/ — .*$/, ` — ${TEXTO_DO_ESTADO[estado]}`);
    }
  }
  return estados;
}

// --- One block at a time ---------------------------------------------
//
// With the script, each repeated group shows only one of its blocks; the
// navigation of the block (Anterior, Próxima, the numbered buttons or the
// select) switches which one. Without the script every block is shown, as
// the server drew them, and those links simply scroll.
//
// The other blocks are HIDDEN, never removed: they stay in the same form, so
// their fields are sent with every save exactly as before (a hidden field is
// sent; only a disabled one is not), and what the judge typed in them stays.

/** States that still need the judge: the first such block opens first. */
const ESTADOS_A_PREENCHER = ['incompleta', 'vazia'];

/**
 * Which block of ONE group opens first:
 *   1. the block named in the address (#repeticao-1-3), e.g. after a Salvar
 *      in that block, which brings the judge back to it;
 *   2. otherwise the first block still to fill (incompleta or vazia; a
 *      bloqueada one cannot be filled yet);
 *   3. otherwise the last block.
 *
 * @param {Array<{ancora: string}>} blocosDoGrupo the group's blocks, in order
 * @param {Record<string, {estado: string}>} estados anchor => state (estadosDosBlocos)
 * @param {string} hash the address's "#..." part ("" when none)
 * @returns {string} the anchor of the block to show
 */
export function blocoInicial(blocosDoGrupo, estados, hash) {
  const pedido = String(hash ?? '').replace(/^#/, '');
  if (blocosDoGrupo.some((bloco) => bloco.ancora === pedido)) return pedido;
  const aPreencher = blocosDoGrupo.find((bloco) => ESTADOS_A_PREENCHER.includes(estados[bloco.ancora]?.estado));
  if (aPreencher) return aPreencher.ancora;
  return blocosDoGrupo[blocosDoGrupo.length - 1].ancora;
}

/**
 * The blocks split by repeated group, each group in page order. A prova
 * may have more than one group (e.g. one for "Inclinação" and one for
 * "Velocidade"); each shows one of its own blocks.
 *
 * @returns {Map<number, Array>} group number => its blocks
 */
export function blocosPorGrupo(blocos) {
  const grupos = new Map();
  for (const bloco of blocos) {
    if (!grupos.has(bloco.grupo)) grupos.set(bloco.grupo, []);
    grupos.get(bloco.grupo).push(bloco);
  }
  return grupos;
}

/**
 * Shows the block with this anchor and hides the other blocks of ITS group.
 * Blocks of other groups are left as they are. Nothing is removed from the
 * page and no field is touched.
 *
 * @returns {boolean} false when no block has this anchor (nothing changes)
 */
export function mostrarBloco(documento, blocos, ancora) {
  const alvo = blocos.find((bloco) => bloco.ancora === ancora);
  if (!alvo) return false;
  for (const bloco of blocos) {
    if (bloco.grupo !== alvo.grupo) continue;
    documento.getElementById(bloco.ancora).hidden = bloco.ancora !== ancora;
  }
  // The jump select of the block now shown points at that block.
  for (const seletor of documento.getElementById(ancora).querySelectorAll('.repeticao-select')) {
    seletor.value = ancora;
  }
  return true;
}

/**
 * The anchor a click leads to, when it is on a block's navigation link
 * (Anterior, Próxima, a numbered button); null for any other click.
 */
function alvoDoClique(evento) {
  const link = evento.target?.closest?.('a.repeticao-seta, a.repeticao-salto');
  if (!link) return null;
  const href = link.getAttribute('href') ?? '';
  return href.startsWith('#') ? href.slice(1) : null;
}

/**
 * Switches to a block the way the navigation does: shows it, writes its
 * anchor in the address (replacing, so the browser's Back still leaves the
 * page instead of walking the blocks), and brings its title into view when
 * it is above the screen.
 */
function irPara(documento, blocos, ancora) {
  if (!mostrarBloco(documento, blocos, ancora)) return;
  const janela = documento.defaultView;
  janela?.history?.replaceState(null, '', `#${ancora}`);
  const corpo = documento.getElementById(ancora);
  if (corpo.getBoundingClientRect && corpo.getBoundingClientRect().top < 0) corpo.scrollIntoView({ block: 'start' });
}

/** Connects the page: states now, again after every change, and the navigation. */
export function ligar(documento) {
  const blocos = blocosDaPagina(documento);
  if (blocos.length === 0) return;
  const atualizar = () => aplicar(documento, blocos);
  // After the page's own handlers (they run on the field, this on the document).
  documento.addEventListener('input', atualizar);
  documento.addEventListener('change', atualizar);
  const estados = atualizar();

  // One block per group: the one in the address, or the first still to fill.
  // After a refused save there is no anchor in the address; the page names
  // the block whose Salvar was pressed instead (juiz/entry.php).
  const aberta = documento.querySelector?.('[data-repeticao-aberta]')?.dataset.repeticaoAberta;
  const hash = documento.location?.hash || (aberta ? `#${aberta}` : '');
  for (const blocosDoGrupo of blocosPorGrupo(blocos).values()) {
    mostrarBloco(documento, blocos, blocoInicial(blocosDoGrupo, estados, hash));
  }
  // The browser scrolls to an anchor before this runs; with the other blocks
  // hidden the block moved up, so scroll to it again.
  const pedido = hash.replace(/^#/, '');
  if (blocos.some((bloco) => bloco.ancora === pedido)) documento.getElementById(pedido).scrollIntoView?.({ block: 'start' });

  // The navigation switches the block instead of scrolling to it.
  documento.addEventListener('click', (evento) => {
    const ancora = alvoDoClique(evento);
    if (ancora === null || !blocos.some((bloco) => bloco.ancora === ancora)) return;
    evento.preventDefault();
    irPara(documento, blocos, ancora);
  });
  for (const seletor of documento.querySelectorAll('.repeticao-select')) {
    seletor.addEventListener('change', () => irPara(documento, blocos, seletor.value));
  }
  // An anchor typed or followed some other way (e.g. a link on the page).
  documento.defaultView?.addEventListener('hashchange', () => {
    mostrarBloco(documento, blocos, documento.location.hash.replace(/^#/, ''));
  });
  // A field the browser refuses (e.g. a negative number where min="0") in a
  // hidden block: show that block, so the browser can point at the field
  // instead of silently not sending the form.
  documento.addEventListener('invalid', (evento) => {
    const corpo = evento.target?.closest?.('tbody.repeticao');
    if (corpo && corpo.hidden) mostrarBloco(documento, blocos, corpo.id);
  }, true);
}

if (typeof document !== 'undefined') {
  ligar(document);
}
