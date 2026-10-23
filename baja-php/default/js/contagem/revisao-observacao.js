// Machine vision on trial, as the review screen shows it: an observer
// source (compared with the official count, never counted) and the pictures
// an automatic source may link to its entries. Pure functions; the drawing
// is in revisao-tabela.js and revisao-detalhe.js.
//
// Everything here is invisible in a race with no observer and no image base
// URL: the server then sends no 'observacao' and no 'imagem_url' at all.

export const SEM_IMAGEM_NA_REDE = 'imagem só na rede local';

export function ehObservadora(fonte) {
  return fonte?.papel === 'observadora';
}

// The sources that get a column of the car table: switched on and counting.
// An observer's passes are no part of any car's count, so a column of them
// beside the official count would only be read as a disagreement. Nor are
// a timing sensor's: it never reads a car, and its column would say "−N"
// on every row.
export function fontesDaTabela(fontes) {
  return (fontes ?? []).filter((fonte) => fonte.ativa && !ehObservadora(fonte) && fonte.tipo !== 'sensor');
}

function segundos(ms, comSinal) {
  const valor = (ms / 1000).toFixed(1).replace('.', ',');
  return comSinal && ms > 0 ? `+${valor} s` : `${valor} s`;
}

// The observer's comparison as [label, value] lines for the source block.
// Null when the source is no observer (or the server sent no comparison).
// A timing sensor reads no number: its hits are marks that fall on an
// official pass, and it never reads one wrong. A camera's "??" (it saw a
// car and could not read it) are counted apart, with how many fell on an
// official pass it had nothing else for.
export function linhasDaObservacao(observacao, fonte = null) {
  if (!observacao) {
    return null;
  }
  const sensor = fonte?.tipo === 'sensor';
  const linhas = sensor
    ? [
      ['marcas numa passagem oficial', `${observacao.acertos} de ${observacao.entradas}`],
      ['passagens oficiais sem marca', String(observacao.faltas)],
      ['marcas sem passagem', String(observacao.falsos_positivos)],
    ]
    : [
      ['acertos', `${observacao.acertos} de ${observacao.entradas}`],
      ['passagens oficiais não vistas', String(observacao.faltas)],
      ['falsos positivos', String(observacao.falsos_positivos)],
      ['números lidos errado', String(observacao.leituras_trocadas)],
    ];
  if (!sensor && observacao.ilegiveis) {
    linhas.push(['ilegíveis', `${observacao.ilegiveis} (${observacao.ilegiveis_com_passagem ?? 0} numa passagem oficial)`]);
  }
  const trocas = observacao.trocas_frequentes ?? [];
  if (trocas.length > 0) {
    linhas.push(['trocas mais comuns', trocas.map((troca) => `${troca.carro_real} lido como ${troca.carro_lido} (${troca.vezes}×)`).join(', ')]);
  }
  if (observacao.desvio_mediano_ms !== null && observacao.desvio_mediano_ms !== undefined) {
    linhas.push(['diferença para o horário oficial',
      `mediana ${segundos(observacao.desvio_mediano_ms, true)}; 90% dentro de ${segundos(observacao.desvio_p90_ms ?? 0, false)}`]);
  }
  return linhas;
}

// The sentence above the comparison. A source promoted to votante keeps the
// comparison of the time it observed, below its counter numbers.
// A sensor on trial is said in a sensor's words: as an observer its marks
// time nothing either.
export function tituloDaObservacao(fonte) {
  if (fonte?.tipo === 'sensor') {
    return ehObservadora(fonte)
      ? 'Observador: só medido. Não muda a contagem nem o horário das passagens; as marcas são só comparadas com as passagens oficiais.'
      : 'Enquanto era observador (antes da promoção), comparado com as passagens oficiais:';
  }
  return ehObservadora(fonte)
    ? 'Observadora: só medida. Não muda a contagem nem o horário; o que registra é só comparado com a contagem oficial.'
    : 'Enquanto era observadora (antes da promoção), comparada com a contagem oficial:';
}

// The picture behind an entry, when its source links pictures: where it is
// and how sure the system was. Null when there is none.
export function imagemDaEntrada(entrada) {
  if (!entrada?.imagem_url) {
    return null;
  }
  const confianca = typeof entrada.confianca === 'number'
    ? `confiança ${Math.round(entrada.confianca * 100)}%`
    : null;
  // The picture is served by the system itself, usually a box on the
  // track's network over plain http. A page on https may not load it, so the
  // link always opens it in a tab of its own as well.
  return { url: entrada.imagem_url, confianca };
}
