// Draws the always-visible status bar and the fixed warning banner shared by
// the count page and the pit page. Reads nothing, decides nothing: it turns a
// state object into text.
//
// Neither may move the page below them: the keypad and the tiles are right
// there, and a tap aimed at one key would land on the one below. The bar's
// lines never wrap (long texts are cut, the whole text is in the title), and
// the banner lies OVER the bottom of the bar, two lines high, until somebody
// taps it to read it all (style sheet: .aviso-fixo).

import { describeOfficialTime, describeRaceClock, formatTimeOfDay, TEXTO_VERIFICANDO } from './race-clock.js';
import {
    describeBackLink, describeBanner, describeConnection, describeOthers, describeQueue,
} from './status-messages.js';

export function createStatusBar(documentRef, config) {
    const element = (id) => documentRef.getElementById(id);
    const fonte = element('st-fonte');
    const relogio = element('st-relogio');
    const horarioOficial = element('st-horario-oficial');
    const faixaSimulacao = element('faixa-simulacao');
    const estadoCorrida = element('st-estado-corrida');
    const conexao = element('st-conexao');
    const pendentes = element('st-pendentes');
    const ultimoSync = element('st-ultimo-sync');
    const outros = element('st-outros');
    const avisoFixo = element('aviso-fixo');
    // In the footer, below the keys: showing one or the other moves nothing above them.
    const linkVoltar = element('link-voltar');
    const voltarSemConexao = element('voltar-sem-conexao');

    const avisoFixoTextos = element('aviso-fixo-textos');
    const botaoTentarDeNovo = element('btn-reconectar');
    const botaoAlternar = element('btn-aviso-fixo');
    let renderedBannerSignature = null;
    let renderedBannerTipos = [];
    let bannerCount = 0;
    let bannerOpen = false;

    function setBannerOpen(open) {
        bannerOpen = open;
        avisoFixo.classList.toggle('aviso-fixo-aberto', open);
        botaoAlternar.setAttribute('aria-expanded', open ? 'true' : 'false');
        botaoAlternar.textContent = open ? 'recolher' : 'ver tudo' + (bannerCount > 1 ? ' (' + bannerCount + ')' : '');
    }

    // A tap anywhere on the closed banner opens it; "recolher" closes it.
    avisoFixo.addEventListener('click', (event) => {
        if (event.target === botaoTentarDeNovo) {
            return;
        }
        setBannerOpen(event.target === botaoAlternar ? !bannerOpen : true);
    });

    function renderBanner(state, problems) {
        const banner = describeBanner(state, problems);
        // Redrawn only when it changed: this runs after every sync, and a
        // screen reader would otherwise read the same warning again each time.
        const signature = JSON.stringify(banner);
        if (signature === renderedBannerSignature) {
            return;
        }
        renderedBannerSignature = signature;
        avisoFixoTextos.replaceChildren();
        for (const aviso of banner.avisos) {
            const paragraph = documentRef.createElement('p');
            paragraph.textContent = aviso;
            avisoFixoTextos.appendChild(paragraph);
        }
        const wasShown = !avisoFixo.hidden;
        avisoFixo.hidden = banner.avisos.length === 0;
        botaoTentarDeNovo.hidden = !banner.oferecerNovaTentativa;
        bannerCount = banner.avisos.length;
        // A new problem (or the banner just appearing): closed again, so
        // that the start of the newest news is what shows. Only the text of a
        // problem already there changed (one more refused record): somebody
        // who opened it to read it keeps reading.
        const newProblem = banner.tipos.some((tipo) => !renderedBannerTipos.includes(tipo));
        renderedBannerTipos = banner.tipos;
        setBannerOpen(bannerOpen && wasShown && !newProblem);
        // Something new to say: it flashes once more.
        avisoFixo.classList.remove('aviso-fixo-novo');
        void avisoFixo.offsetWidth;
        avisoFixo.classList.add('aviso-fixo-novo');
    }

    return {
        /**
         * @param {object}  state    SyncQueue.getState()
         * @param {object}  problems {tokenProblem: ?string, armazenamento: string}
         * @param {boolean} simulationAllowed the prova is in simulation mode
         * @param {boolean} browserOnline navigator.onLine
         */
        render(state, problems, simulationAllowed = false, browserOnline = true) {
            // Permanent while the setting is on: nobody may mistake a
            // rehearsal with invented cars for a real count, or the reverse.
            faixaSimulacao.hidden = !simulationAllowed;

            const serverFonte = state.servidor && state.servidor.fonte;
            fonte.textContent = serverFonte && serverFonte.nome ? serverFonte.nome : config.fonte.nome;
            fonte.title = fonte.textContent;

            const connectionClass = state.turnoEncerrado ? 'encerrado' : state.conexao;
            conexao.textContent = describeConnection(state, problems);
            conexao.className = 'conexao conexao-' + connectionClass;

            // Records typed before this page knew the server's time are in
            // the queue too; saying why they wait avoids "why is it stuck?".
            const queueText = describeQueue(state);
            pendentes.textContent = queueText.texto;
            pendentes.parentElement.title = queueText.titulo;
            pendentes.parentElement.classList.toggle('tem-pendentes', state.pendentes > 0);
            ultimoSync.textContent = formatTimeOfDay(state.ultimoSyncMs, config.fuso || null);

            // Names of other people counting on THIS source (a handover, or
            // two people sharing a counter). Never anything about what they
            // typed, and never anything about the other sources.
            const others = describeOthers(state.outrosUsuarios);
            outros.textContent = others.texto;
            outros.parentElement.title = others.titulo;

            // "Voltar" leads to a page that needs the network.
            const backLink = describeBackLink(state, browserOnline);
            if (linkVoltar && voltarSemConexao) {
                linkVoltar.hidden = !backLink.disponivel;
                voltarSemConexao.hidden = backLink.disponivel;
                voltarSemConexao.title = backLink.titulo;
            }

            renderBanner(state, problems);
        },

        /**
         * Called a few times per second; cheap on purpose.
         * @param {?number} serverNowMs ServerClock.serverNowMs(): null until
         *        this page load has heard from the server
         * @param {?string} [estadoRelogio] ServerClock.estado()
         */
        renderClock(state, serverNowMs, estadoRelogio = null) {
            const race = state.servidor ? state.servidor.race : null;
            const description = describeRaceClock(race, serverNowMs);
            horarioOficial.textContent = describeOfficialTime(serverNowMs, config.fuso || null, estadoRelogio);
            horarioOficial.classList.toggle('sincronizando', serverNowMs === null);
            horarioOficial.classList.toggle('verificando', serverNowMs !== null && estadoRelogio === TEXTO_VERIFICANDO);
            relogio.textContent = description.texto;
            estadoCorrida.textContent = description.rotulo;
            estadoCorrida.className = 'estado-corrida estado-' + description.estado;
        },
    };
}
