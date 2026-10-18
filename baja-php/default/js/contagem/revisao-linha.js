// The timeline panel of the review screen: always on screen, at the top.
// It loads what it shows (pieces of time, or one car's whole race), keeps
// it, scrolls, and tells revisao.js what is loaded through tela.janela.
// Which stretch, which pieces and what stands out are decided by the pure
// functions of revisao-linha-logica.js; the lanes are drawn by
// revisao-raias.js.
//
// With nothing selected it follows the race: the last few minutes, "now"
// near the right edge, refreshed as the state changes. Scrolling back loads
// earlier pieces; "Agora" comes back. A flag scrolls and zooms the same
// timeline to its moment, and the decision buttons wait until the flag's
// pieces were loaded again after it was opened (tela.janela stays null
// until then), as they waited for the flag's own window before.

import { buscarCarro, buscarJanela } from './revisao-api.js';
import { alertaSelecionado } from './revisao-desenho.js';
import { el, substituir } from './revisao-dom.js';
import { MEIA_JANELA_MS, janelaDoAlerta, momentosDoAlerta, trechoEssencialDoAlerta, voltaEsperadaDoAlerta } from './revisao-fila.js';
import {
  ALEM_DO_AGORA_MS, ANTES_DA_LARGADA_MS, FRACAO_DO_AGORA, PEDACO_MS, aindaSeguindo, alvoDaCorridaDoCarro, centroAoVerTodos, destaqueDaLinha, escalaDoAlerta, estenderFaixa,
  faixaAoVivo, faltaPorErro, faixaEntre, intervaloDoPedaco, juntarPedacos, lacunaDaPassagemAnterior, limitesDaFaixa, modoDaLinha, momentoEmDestaque,
  pedacosAPedir, pedacosDaFaixa, pedacosVisiveis, rolagemPara, rolagemParaAlerta, situacaoDaLinha, trechoVisivel,
} from './revisao-linha-logica.js';
import { desenharRaias } from './revisao-raias.js';
import { encontrarEntrada, entradasLigadas } from './revisao-raias-logica.js';
import { atribuicoesAutomaticas } from './revisao-ilegivel.js';
import { agoraServidorMs, pagina, tela } from './revisao-tela.js';

// Pieces kept in memory at most; the oldest outside the stretch on screen go first.
const PEDACOS_GUARDADOS = 60;

// `ganchos`:
//   desenharTudo()          redraw the screen (after something was loaded)
//   irParaAgora()           "Agora": close the selection and follow the race
//   escolherEntrada(id)     a chip was clicked
// Returns {atualizar, recarregar, aCadaSegundo, novaSelecao}.
export function criarLinhaDoTempo(ganchos) {
  const elementos = {
    situacao: document.getElementById('linha-situacao'),
    aviso: document.getElementById('linha-aviso'),
    agora: document.getElementById('linha-agora'),
    soCarro: document.getElementById('linha-so-carro'),
    rolagem: document.getElementById('linha-raias'),
    extras: document.getElementById('linha-extras'),
  };

  const linha = {
    modo: null,              // modoDaLinha() of what is on screen
    faixa: null,             // {primeiro, ultimo}: the pieces of time on screen
    soCarro: true,           // "só o carro N" for a selected car
    seguindo: true,          // the live view keeps "now" in sight
    pedacos: new Map(),      // inicio -> {janela, versao, buscadoEmMs, numero, erro}
    emVoo: new Map(),        // inicio -> requests on their way
    carro: null,             // one car's whole race: {carro, janela, versao, assinatura, numero, erro}
    carroEmVoo: false,
    rodada: null,            // a flag's own load: {assinatura, desde, inicios}
    numero: 0,               // numbers every request, to keep only the newest answer
    geracao: 0,              // grows whenever something loaded changes
    juntada: null,           // {geracao, faixa, janela}: the pieces of the stretch, joined
    alvo: null,              // where to scroll at the next drawing
    desenhado: null,         // what the lanes on screen were drawn from
    desenhadoSemDados: null, // the same, leaving out what was loaded
    carregandoDesenhado: 0,  // pieces drawn as still loading
    limites: null,           // {de, ate} of the lanes on screen
    escalaDesenhada: null,
    aviso: null,             // what the warning line says now
    sobOPonteiro: false,
    rolagemPorCodigo: null,  // the scroll position this module set last
  };

  const agoraMs = () => agoraServidorMs() ?? tela.estado?.servidor_agora_ms ?? 0;
  const limiteAntesMs = () => (tela.estado?.corrida?.largada_ms ?? agoraMs()) - ANTES_DA_LARGADA_MS;

  // ---- loading -------------------------------------------------------------------

  async function buscarPedaco(inicio) {
    linha.numero += 1;
    const numero = linha.numero;
    const versao = tela.estado.versao;
    linha.emVoo.set(inicio, (linha.emVoo.get(inicio) ?? 0) + 1);
    const { de, ate } = intervaloDoPedaco(inicio);
    let janela = null;
    try {
      janela = await buscarJanela(pagina.prova, de, ate);
    } catch (erro) {
      janela = null;
    }
    linha.emVoo.set(inicio, linha.emVoo.get(inicio) - 1);
    if (linha.emVoo.get(inicio) === 0) {
      linha.emVoo.delete(inicio);
    }
    const guardado = linha.pedacos.get(inicio);
    if (janela !== null && (!guardado || guardado.numero < numero)) {
      linha.pedacos.set(inicio, { janela, versao, buscadoEmMs: performance.now(), numero, erro: false });
      linha.geracao += 1;
    } else if (janela === null && (!guardado || guardado.numero < numero)) {
      // What was there stays on screen; it is asked again a little later.
      linha.pedacos.set(inicio, { ...(guardado ?? { janela: null, versao: null, numero: 0 }), buscadoEmMs: performance.now(), erro: true });
      linha.geracao += 1;
    }
    esquecerPedacosVelhos();
    ganchos.desenharTudo();
  }

  function esquecerPedacosVelhos() {
    if (linha.pedacos.size <= PEDACOS_GUARDADOS) {
      return;
    }
    const naTela = new Set(linha.faixa ? pedacosDaFaixa(linha.faixa) : []);
    const fora = [...linha.pedacos.entries()].filter(([inicio]) => !naTela.has(inicio))
      .sort((a, b) => a[1].buscadoEmMs - b[1].buscadoEmMs);
    for (const [inicio] of fora.slice(0, linha.pedacos.size - PEDACOS_GUARDADOS)) {
      linha.pedacos.delete(inicio);
    }
  }

  async function buscarCorridaDoCarro(carro) {
    linha.numero += 1;
    const numero = linha.numero;
    const versao = tela.estado.versao;
    // A flag shown over a car's whole race is decided against what it was when this left.
    const assinatura = alertaSelecionado()?.assinatura ?? null;
    linha.carroEmVoo = true;
    try {
      const janela = await buscarCarro(pagina.prova, carro);
      if (!linha.carro || linha.carro.numero < numero) {
        linha.carro = { carro, janela, versao, assinatura, numero, erro: false };
      }
    } catch (erro) {
      if (!linha.carro || linha.carro.numero < numero) {
        linha.carro = { carro, janela: linha.carro?.carro === carro ? linha.carro.janela : null, versao, assinatura, numero, erro: true };
      }
    }
    linha.carroEmVoo = false;
    linha.geracao += 1;
    ganchos.desenharTudo();
  }

  // The pieces in view that are missing or behind the state. A car's whole
  // race is loaded once; when the state moves on, the panel offers to load
  // it again (reloading hours of race every few seconds would be wasteful).
  function pedirOQueFalta() {
    const modo = linha.modo;
    if (modo.fonte === 'carro') {
      if ((!linha.carro || linha.carro.carro !== modo.carro) && !linha.carroEmVoo) {
        buscarCorridaDoCarro(modo.carro);
      }
      return;
    }
    const visiveis = pedacosVisiveis(linha.faixa, trechoNaTela());
    for (const inicio of pedacosAPedir(visiveis, linha.pedacos, new Set(linha.emVoo.keys()), tela.estado.versao, performance.now(), agoraMs())) {
      buscarPedaco(inicio);
    }
  }

  // The moments in view; the whole stretch before the lanes were first drawn.
  function trechoNaTela() {
    if (!linha.limites || linha.escalaDesenhada !== linha.modo.escala) {
      return limitesDaFaixa(linha.faixa);
    }
    return trechoVisivel(elementos.rolagem.scrollLeft, elementos.rolagem.clientWidth, linha.limites.de, linha.modo.escala);
  }

  // A flag's own pieces are asked for again when it is opened (or its
  // entries changed), whatever is kept: its buttons act on what they show.
  function iniciarRodada(alerta, limitesDoAlerta) {
    const inicios = pedacosDaFaixa(faixaEntre(limitesDoAlerta.de, limitesDoAlerta.ate));
    linha.rodada = { assinatura: alerta.assinatura ?? null, desde: linha.numero, inicios };
    for (const inicio of inicios) {
      buscarPedaco(inicio);
    }
  }

  const rodadaCompleta = () => linha.rodada.inicios.every((inicio) => (linha.pedacos.get(inicio)?.numero ?? 0) > linha.rodada.desde);

  function janelaJuntada() {
    if (!linha.juntada || linha.juntada.geracao !== linha.geracao || linha.juntada.faixa !== linha.faixa) {
      const janelas = pedacosDaFaixa(linha.faixa).map((inicio) => linha.pedacos.get(inicio)?.janela).filter(Boolean);
      linha.juntada = { geracao: linha.geracao, faixa: linha.faixa, janela: juntarPedacos(janelas, linha.faixa), vazia: janelas.length === 0 };
    }
    return linha.juntada;
  }

  // What the rest of the screen sees: tela.janela is what the timeline
  // shows, once it can be acted on; null while that is still loading.
  function publicarJanela() {
    const modo = linha.modo;
    tela.janelaVersao = null;
    tela.janelaAssinatura = null;
    if (modo.fonte === 'carro') {
      const doCarro = linha.carro?.carro === modo.carro ? linha.carro : null;
      tela.janela = doCarro?.janela ?? null;
      tela.janelaVersao = doCarro?.versao ?? null;
      tela.janelaAssinatura = doCarro?.assinatura ?? null;
      tela.erroDaJanela = Boolean(doCarro?.erro);
      return;
    }
    const juntada = janelaJuntada();
    if (linha.rodada) {
      tela.janela = rodadaCompleta() ? juntada.janela : null;
      tela.janelaAssinatura = linha.rodada.assinatura;
      tela.erroDaJanela = linha.rodada.inicios.some((inicio) => linha.pedacos.get(inicio)?.erro);
      return;
    }
    tela.janela = juntada.vazia ? null : juntada.janela;
    // A flag with no moment has nothing of its own to wait for.
    tela.janelaAssinatura = alertaSelecionado()?.assinatura ?? null;
    tela.erroDaJanela = faltaPorErro(pedacosVisiveis(linha.faixa, trechoNaTela()), linha.pedacos);
  }

  // ---- what is selected ------------------------------------------------------------

  function mudarDeModo(modo, alerta) {
    linha.modo = modo;
    linha.rodada = null;
    linha.alvo = null;
    if (modo.fonte === 'carro') {
      linha.alvo = alvoDaCorridaDoCarro(modo);
      if (linha.carro?.carro !== modo.carro || alerta) {
        // A flag over the car's race is loaded afresh, for its buttons.
        linha.carro = null;
      }
      return;
    }
    const limitesDoAlerta = alerta && !modo.aoVivo
      ? janelaDoAlerta(alerta, MEIA_JANELA_MS, voltaEsperadaDoAlerta(alerta, tela.estado))
      : null;
    if (limitesDoAlerta) {
      // Zoomed out, if need be, so that the previous pass and a late entry
      // are in view with the flag's own entry; on a phone at least the
      // flag's own entries, first to last.
      const essencial = trechoEssencialDoAlerta(alerta, limitesDoAlerta);
      const { primeiroMs, ultimoMs } = momentosDoAlerta(alerta);
      const proprio = { de: primeiroMs, ate: ultimoMs };
      linha.modo = { ...modo, escala: escalaDoAlerta(essencial, elementos.rolagem.clientWidth, proprio) };
      linha.faixa = faixaEntre(limitesDoAlerta.de, limitesDoAlerta.ate);
      linha.alvo = { tipo: 'alerta', janela: limitesDoAlerta, essencial, proprio };
      iniciarRodada(alerta, limitesDoAlerta);
    } else if (modo.aoVivo) {
      linha.faixa = faixaAoVivo(agoraMs());
      linha.seguindo = true;
      linha.alvo = { tipo: 'agora' };
    } else {
      // A car among all the others: around the chip picked in its race, the
      // moment of the history line it was opened from, or its last pass.
      const numero = tela.selecao?.numero;
      const ultimaPassagemMs = (tela.estado.carros ?? []).find((linhaDoCarro) => linhaDoCarro.carro === numero)?.ultima_passagem_ms ?? null;
      const centro = centroAoVerTodos(encontrarEntrada(linha.carro?.janela, tela.entradaSelecionadaId), ultimaPassagemMs, agoraMs(), modo.momentoMs);
      linha.faixa = faixaEntre(centro - 2 * PEDACO_MS, Math.min(centro + 2 * PEDACO_MS, agoraMs() + ALEM_DO_AGORA_MS));
      linha.alvo = { tipo: 'centro', ts: centro };
    }
  }

  // Called after every redraw of the screen.
  function atualizar() {
    if (!tela.estado) {
      return;
    }
    const alerta = alertaSelecionado();
    const modo = modoDaLinha(tela.selecao, alerta, linha.soCarro);
    if (!linha.modo || modo.chave !== linha.modo.chave) {
      mudarDeModo(modo, alerta);
    }
    pedirOQueFalta();
    publicarJanela();
    desenhar(alerta);
  }

  // After a decision, on "Tentar de novo" / "Atualizar", and when the open
  // flag's entries changed: load again what is on screen, now.
  function recarregar() {
    if (!tela.estado || !linha.modo) {
      return;
    }
    const modo = linha.modo;
    if (modo.fonte === 'carro') {
      buscarCorridaDoCarro(modo.carro);
      return;
    }
    const alerta = alertaSelecionado();
    const limitesDoAlerta = alerta && !modo.aoVivo
      ? janelaDoAlerta(alerta, MEIA_JANELA_MS, voltaEsperadaDoAlerta(alerta, tela.estado))
      : null;
    if (limitesDoAlerta) {
      iniciarRodada(alerta, limitesDoAlerta);
    }
    const daRodada = new Set(linha.rodada?.inicios ?? []);
    for (const inicio of pedacosVisiveis(linha.faixa, trechoNaTela())) {
      if (!daRodada.has(inicio)) {
        buscarPedaco(inicio);
      }
    }
    // At once: the poll must see the flag's load under way, or it would start another.
    publicarJanela();
  }

  // A new flag or car was picked: a car opens on its own race again.
  function novaSelecao() {
    linha.soCarro = true;
  }

  // ---- drawing -----------------------------------------------------------------------

  // Why the engine gave each "??" its car, and the sources' names, for the tooltips.
  function contextoDosIlegiveis() {
    const fontes = new Map((tela.estado.fontes ?? []).map((fonte) => [fonte.id, fonte.nome]));
    return { atribuicoes: atribuicoesAutomaticas(tela.estado), nomeDaFonte: (id) => fontes.get(id) ?? null };
  }

  function desenhar(alerta) {
    const modo = linha.modo;
    const juntada = modo.fonte === 'pedacos' ? janelaJuntada() : null;
    const janela = modo.fonte === 'carro' ? (linha.carro?.carro === modo.carro ? linha.carro.janela : null) : juntada.janela;
    const destaque = destaqueDaLinha(tela.selecao, alerta, modo);
    const lacuna = modo.fonte === 'pedacos' && !modo.aoVivo && alerta
      ? lacunaDaPassagemAnterior(alerta, voltaEsperadaDoAlerta(alerta, tela.estado))
      : null;
    const carregando = modo.fonte === 'pedacos'
      ? pedacosDaFaixa(linha.faixa).filter((inicio) => !linha.pedacos.get(inicio)?.janela).map(intervaloDoPedaco)
      : [];
    desenharCabecalho(alerta, carregando.length > 0 || (modo.fonte === 'carro' && !janela));

    const largadaMs = tela.estado.corrida?.largada_ms ?? null;
    const momento = momentoEmDestaque(modo, alerta);
    const candidatos = destaque.candidatos ? [...destaque.candidatos] : null;
    const doQueSeVe = [modo.chave, modo.escala, linha.faixa, { ...destaque, candidatos }, tela.entradaSelecionadaId, largadaMs, lacuna, momento,
      // What the tooltip of a "??" the engine gave a car says comes from the flags.
      (tela.estado.alertas_resolvidos ?? []).length];
    const chave = JSON.stringify([linha.geracao, carregando.length, ...doQueSeVe]);
    if (chave === linha.desenhado && linha.alvo === null) {
      return;
    }
    // In the live view with the pointer on the lanes, a refresh of what is
    // already drawn waits for the pointer to leave: rebuilt lanes would drop
    // the tooltip being read and could swallow a click. A piece that fills a
    // hole, and what the reviewer does (a click, a scroll that grows the
    // stretch), are drawn at once. Under the keyboard focus the lanes are
    // redrawn, the focus is put back and the view does not move.
    const parado = linha.sobOPonteiro || elementos.rolagem.contains(document.activeElement);
    if (linha.sobOPonteiro && modo.aoVivo && !tela.selecao && linha.limites && linha.alvo === null
        && linha.desenhadoSemDados === JSON.stringify(doQueSeVe) && carregando.length === linha.carregandoDesenhado) {
      return;
    }

    if (!janela) {
      substituir(elementos.rolagem, el('p', { class: 'vazio' }, 'Carregando a linha do tempo…'));
      substituir(elementos.extras);
      linha.limites = null;
      linha.desenhado = chave;
      linha.desenhadoSemDados = null;
      return;
    }

    // The moment at the left edge before the redraw stays at the left edge
    // after it, whatever was added on either side.
    const esquerdaAntes = linha.limites && linha.escalaDesenhada === modo.escala ? trechoNaTela().de : null;
    const focoAntes = elementos.rolagem.contains(document.activeElement) ? document.activeElement.dataset?.entrada : undefined;

    const { conteudo, extras, limites } = desenharRaias(janela, {
      escala: modo.escala,
      largadaMs,
      idsEmDestaque: destaque.ids,
      carrosEmFoco: destaque.carros,
      entradaSelecionadaId: tela.entradaSelecionadaId,
      momento,
      agoraMs: modo.fonte === 'pedacos' ? agoraMs() : null,
      lacuna,
      trechosCarregando: carregando,
      aoEscolherEntrada: ganchos.escolherEntrada,
      contextoDosIlegiveis: contextoDosIlegiveis(),
      candidatos: destaque.candidatos ?? null,
      ligadas: entradasLigadas(janela, tela.entradaSelecionadaId),
    });
    substituir(elementos.rolagem, conteudo);
    // Entries and stops of unknown time belong to a car's whole race; in a
    // stretch of time they have no place, and listing them there would
    // make the page below jump at every refresh.
    substituir(elementos.extras, modo.fonte === 'carro' ? extras : []);
    linha.limites = limites;
    linha.escalaDesenhada = modo.escala;
    linha.desenhado = chave;
    linha.desenhadoSemDados = JSON.stringify(doQueSeVe);
    linha.carregandoDesenhado = carregando.length;
    if (limites) {
      posicionar(esquerdaAntes, parado);
    }
    if (focoAntes !== undefined) {
      elementos.rolagem.querySelector(`[data-entrada="${CSS.escape(String(focoAntes))}"]`)?.focus({ preventScroll: true });
    }
  }

  // `parado`: the pointer or the focus is on the lanes, so the live view
  // does not run after "now"; what is under them stays where it was.
  function posicionar(esquerdaAntes, parado) {
    const { escala } = linha.modo;
    const largura = elementos.rolagem.clientWidth;
    const de = linha.limites.de;
    let alvoPx = null;
    if (linha.alvo?.tipo === 'alerta') {
      alvoPx = rolagemParaAlerta(linha.alvo.janela, linha.alvo.essencial, de, escala, largura, linha.alvo.proprio);
    } else if (linha.alvo?.tipo === 'centro') {
      alvoPx = rolagemPara(linha.alvo.ts, 0.5, de, escala, largura);
    } else if (linha.alvo?.tipo === 'fim') {
      alvoPx = rolagemPara(linha.limites.ate, 1, de, escala, largura);
    } else if (linha.modo.aoVivo && linha.seguindo && !(parado && esquerdaAntes !== null)) {
      alvoPx = rolagemPara(agoraMs(), FRACAO_DO_AGORA, de, escala, largura);
    } else if (esquerdaAntes !== null) {
      alvoPx = rolagemPara(esquerdaAntes, 0, de, escala, largura);
    }
    linha.alvo = null;
    if (alvoPx !== null) {
      rolarPara(alvoPx);
    }
  }

  function rolarPara(px) {
    const rolagem = elementos.rolagem;
    const possivel = Math.max(0, Math.min(px, rolagem.scrollWidth - rolagem.clientWidth));
    if (Math.abs(rolagem.scrollLeft - possivel) < 1) {
      return;
    }
    linha.rolagemPorCodigo = possivel;
    rolagem.scrollLeft = possivel;
  }

  // The line above the lanes. Its parts are rebuilt only when what they say
  // changes, so a button in it is never replaced under a click.
  function desenharCabecalho(alerta, carregando) {
    const modo = linha.modo;
    const situacao = situacaoDaLinha(modo, tela.selecao, alerta)
      + (modo.aoVivo && !linha.seguindo && !tela.selecao ? ' (parada no passado)' : '');
    if (elementos.situacao.textContent !== situacao) {
      elementos.situacao.textContent = situacao;
    }

    const seguindo = modo.aoVivo && linha.seguindo && !tela.selecao;
    elementos.agora.classList.toggle('ativo', seguindo);
    elementos.agora.setAttribute('aria-pressed', String(seguindo));
    elementos.agora.title = tela.selecao
      ? 'Fecha o que está aberto e volta a acompanhar a corrida agora'
      : 'Volta a acompanhar a corrida agora';

    const carro = tela.selecao?.tipo === 'carro' ? tela.selecao.numero : null;
    elementos.soCarro.hidden = carro === null;
    if (carro !== null) {
      elementos.soCarro.textContent = `Só o carro ${carro}`;
      elementos.soCarro.setAttribute('aria-pressed', String(linha.soCarro));
      elementos.soCarro.classList.toggle('ativo', linha.soCarro);
    }

    const desatualizada = modo.fonte === 'carro' && tela.selecao?.tipo === 'carro' && tela.janela !== null
      && tela.janelaVersao !== tela.estado.versao;
    const aviso = tela.erroDaJanela ? 'erro' : desatualizada ? 'desatualizada' : carregando ? 'carregando' : null;
    if (aviso === linha.aviso) {
      return;
    }
    linha.aviso = aviso;
    // Short: the line never wraps, and the button must stay in sight.
    if (aviso === 'erro') {
      substituir(elementos.aviso, el('span', { class: 'contagem-erro', title: 'Não foi possível carregar a linha do tempo.' }, 'Não carregou. ',
        el('button', { type: 'button', onclick: recarregar }, 'Tentar de novo')));
    } else if (aviso === 'desatualizada') {
      substituir(elementos.aviso, el('span', { title: 'Chegaram dados novos desde que a corrida deste carro foi carregada.' }, 'Dados novos. '),
        el('button', { type: 'button', onclick: recarregar }, 'Atualizar'));
    } else if (aviso === 'carregando') {
      substituir(elementos.aviso, 'carregando…');
    } else {
      substituir(elementos.aviso);
    }
  }

  // ---- scrolling and the clock -----------------------------------------------------

  let rolagemAgendada = false;

  // Grows the stretch when the view reached one of its edges, and loads
  // what came into view.
  function aoRolar() {
    rolagemAgendada = false;
    if (!tela.estado || !linha.modo || linha.modo.fonte !== 'pedacos' || !linha.limites) {
      return;
    }
    const nova = estenderFaixa(linha.faixa, trechoNaTela(), limiteAntesMs(), agoraMs() + ALEM_DO_AGORA_MS);
    if (nova !== linha.faixa) {
      linha.faixa = nova;
      atualizar();
      return;
    }
    pedirOQueFalta();
    desenharCabecalho(alertaSelecionado(), false);
  }

  elementos.rolagem.addEventListener('scroll', () => {
    const px = elementos.rolagem.scrollLeft;
    if (linha.rolagemPorCodigo !== null && Math.abs(px - linha.rolagemPorCodigo) <= 2) {
      linha.rolagemPorCodigo = null;
    } else if (linha.modo?.aoVivo && linha.limites) {
      // The reviewer scrolled: back in time stops following, back to the right edge follows again.
      linha.rolagemPorCodigo = null;
      const doAgora = rolagemPara(agoraMs(), FRACAO_DO_AGORA, linha.limites.de, linha.modo.escala, elementos.rolagem.clientWidth);
      linha.seguindo = aindaSeguindo(px, Math.min(doAgora, elementos.rolagem.scrollWidth - elementos.rolagem.clientWidth));
    }
    if (!rolagemAgendada) {
      rolagemAgendada = true;
      requestAnimationFrame(aoRolar);
    }
  }, { passive: true });

  // Nothing moves under the pointer or under the keyboard focus.
  elementos.rolagem.addEventListener('pointerenter', () => { linha.sobOPonteiro = true; });
  elementos.rolagem.addEventListener('pointerleave', () => {
    linha.sobOPonteiro = false;
    ganchos.desenharTudo(); // what arrived meanwhile
  });

  elementos.agora.addEventListener('click', () => {
    linha.seguindo = true;
    linha.modo = null; // drawn afresh in the live stretch
    ganchos.irParaAgora();
  });
  elementos.soCarro.addEventListener('click', () => {
    linha.soCarro = !linha.soCarro;
    ganchos.desenharTudo();
  });

  // Every second: the line of "now" moves, and the live view follows it.
  function aCadaSegundo() {
    if (!tela.estado || !linha.modo || linha.modo.fonte !== 'pedacos' || !linha.limites) {
      return;
    }
    const agora = agoraMs();
    const marca = elementos.rolagem.querySelector('.linha-agora');
    if (marca) {
      marca.hidden = agora > linha.limites.ate;
      marca.style.left = `${Math.round(((Math.min(agora, linha.limites.ate) - linha.limites.de) / 1000) * linha.modo.escala)}px`;
    }
    if (!linha.modo.aoVivo || !linha.seguindo || tela.selecao) {
      return;
    }
    const nova = estenderFaixa(linha.faixa, trechoNaTela(), limiteAntesMs(), agora + ALEM_DO_AGORA_MS);
    if (nova !== linha.faixa) {
      linha.faixa = nova;
      atualizar();
      return;
    }
    if (!linha.sobOPonteiro && !elementos.rolagem.contains(document.activeElement)) {
      rolarPara(rolagemPara(agora, FRACAO_DO_AGORA, linha.limites.de, linha.modo.escala, elementos.rolagem.clientWidth));
    }
  }

  return { atualizar, recarregar, aCadaSegundo, novaSelecao };
}
