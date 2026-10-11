// Draws the always-visible status bar and the fixed warning banner shared by
// the count page and the pit page. Reads nothing, decides nothing: it turns a
// state object into text.

import { describeRaceClock, formatTimeOfDay } from './race-clock.js';
import { describeBanner, describeConnection } from './status-messages.js';

export function createStatusBar(documentRef, config) {
    const element = (id) => documentRef.getElementById(id);
    const fonte = element('st-fonte');
    const relogio = element('st-relogio');
    const estadoCorrida = element('st-estado-corrida');
    const conexao = element('st-conexao');
    const pendentes = element('st-pendentes');
    const ultimoSync = element('st-ultimo-sync');
    const outros = element('st-outros');
    const avisoFixo = element('aviso-fixo');

    const avisoFixoTextos = element('aviso-fixo-textos');
    const botaoTentarDeNovo = element('btn-reconectar');
    let renderedBannerSignature = null;

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
        avisoFixo.hidden = banner.avisos.length === 0;
        botaoTentarDeNovo.hidden = !banner.oferecerNovaTentativa;
    }

    return {
        /**
         * @param {object} state    SyncQueue.getState()
         * @param {object} problems {tokenProblem: ?string, armazenamento: string}
         */
        render(state, problems) {
            const serverFonte = state.servidor && state.servidor.fonte;
            fonte.textContent = serverFonte && serverFonte.nome ? serverFonte.nome : config.fonte.nome;

            const connectionClass = state.turnoEncerrado ? 'encerrado' : state.conexao;
            conexao.textContent = describeConnection(state, problems);
            conexao.className = 'conexao conexao-' + connectionClass;

            pendentes.textContent = String(state.pendentes);
            pendentes.parentElement.classList.toggle('tem-pendentes', state.pendentes > 0);
            ultimoSync.textContent = formatTimeOfDay(state.ultimoSyncMs);

            // Names of other people counting on THIS source (a handover, or
            // two people sharing a counter). Never anything about what they
            // typed, and never anything about the other sources.
            outros.textContent = state.outrosUsuarios.length > 0 ? state.outrosUsuarios.join(', ') : 'ninguém';

            renderBanner(state, problems);
        },

        /** Called a few times per second; cheap on purpose. */
        renderClock(state, deviceNowMs, clockOffsetMs) {
            const race = state.servidor ? state.servidor.race : null;
            const description = describeRaceClock(race, deviceNowMs, clockOffsetMs);
            relogio.textContent = description.texto;
            estadoCorrida.textContent = description.rotulo;
            estadoCorrida.className = 'estado-corrida estado-' + description.estado;
        },
    };
}
