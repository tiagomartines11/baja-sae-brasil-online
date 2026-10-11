// Draws the always-visible status bar and the fixed warning banner shared by
// the count page and the pit page. Reads nothing, decides nothing: it turns a
// state object into text.

import { CONEXAO } from './sync-queue.js';
import { describeRaceClock, formatTimeOfDay } from './race-clock.js';

const ROTULO_CONEXAO = {
    [CONEXAO.CONECTANDO]: 'Conectando…',
    [CONEXAO.ONLINE]: 'Conectado',
    [CONEXAO.OFFLINE]: 'Sem conexão',
    [CONEXAO.REVOGADO]: 'Envio bloqueado',
};

const TEXTO_REVOGADO = 'Este aparelho foi desconectado desta contagem e não envia mais nada. '
    + 'Tudo o que você registrou continua guardado aqui. Toque em “Exportar meus dados” '
    + 'e procure o responsável pela prova.';

const TEXTO_SEM_DISCO = 'Este navegador não deixa guardar os registros no aparelho. '
    + 'NÃO recarregue nem feche esta página enquanto houver registros na fila.';

const TEXTO_PROBLEMA_TOKEN = {
    sem_permissao: 'Você não tem mais permissão para esta contagem. Os registros ficam guardados '
        + 'neste aparelho; procure o responsável pela prova.',
    sem_sessao: 'Sua sessão expirou e este aparelho ainda não foi autorizado a enviar. '
        + 'Os registros ficam guardados aqui. Entre novamente e reabra esta página.',
};

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
    const avisoFixoTexto = element('aviso-fixo-texto');

    function bannerText(state, problems) {
        if (state.conexao === CONEXAO.REVOGADO) {
            return TEXTO_REVOGADO;
        }
        if (!problems.storagePersistent) {
            return TEXTO_SEM_DISCO;
        }
        return TEXTO_PROBLEMA_TOKEN[problems.tokenProblem] || null;
    }

    return {
        /**
         * @param {object} state    SyncQueue.getState()
         * @param {object} problems {tokenProblem: ?string, storagePersistent: boolean}
         */
        render(state, problems) {
            const serverFonte = state.servidor && state.servidor.fonte;
            fonte.textContent = serverFonte && serverFonte.nome ? serverFonte.nome : config.fonte.nome;

            conexao.textContent = ROTULO_CONEXAO[state.conexao];
            conexao.className = 'conexao conexao-' + state.conexao;

            pendentes.textContent = String(state.pendentes);
            pendentes.parentElement.classList.toggle('tem-pendentes', state.pendentes > 0);
            ultimoSync.textContent = formatTimeOfDay(state.ultimoSyncMs);

            // Names of other people counting on THIS source (a handover, or
            // two people sharing a counter). Never anything about what they
            // typed, and never anything about the other sources.
            outros.textContent = state.outrosUsuarios.length > 0 ? state.outrosUsuarios.join(', ') : 'ninguém';

            const text = bannerText(state, problems);
            avisoFixo.hidden = text === null;
            avisoFixoTexto.textContent = text === null ? '' : text;
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
