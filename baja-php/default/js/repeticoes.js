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

/** Connects the page: states now, again after every change, and the jump selects. */
export function ligar(documento) {
  const blocos = blocosDaPagina(documento);
  if (blocos.length === 0) return;
  const atualizar = () => aplicar(documento, blocos);
  // After the page's own handlers (they run on the field, this on the document).
  documento.addEventListener('input', atualizar);
  documento.addEventListener('change', atualizar);
  for (const seletor of documento.querySelectorAll('.repeticao-select')) {
    seletor.addEventListener('change', () => { documento.location.hash = seletor.value; });
  }
  atualizar();
}

if (typeof document !== 'undefined') {
  ligar(document);
}
