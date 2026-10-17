// The race simulation, as typed into the browser console of a count page
// or of the pit page:
//
//     contagemSimular({ semente: 42, voltaMediaS: 60 })                   live, compressed
//     contagemSimular({ semente: 42, voltaMediaS: 230, velocidade: 30 })  replay at real scale
//     contagemGabarito()                                                  the answer key
//     contagemGabarito({ baixar: true })                                  ... as two CSV files
//
// Open one tab per counter (each logged in on its own source) and the pit
// page, start the race in race control, and run the SAME command in every
// tab: all of them build the same race from the seed, and each plays its
// own part, with its own mistakes.
//
// The commands exist only while the prova allows simulation
// (`contagem.permitir_simulacao`): page-setup.js loads this file and calls
// instalarSimulacao() then, and removerSimulacao() when the setting goes
// off. Everything typed by the simulation goes through the page's own
// recording function (`window.contagemPagina`), so it is stored, queued
// and uploaded exactly like a key press, marked with the mode "simulacao".
//
// THREE REFUSALS, each with a sentence saying what to do:
//   - the prova publishes to the official results (set "modo de publicação"
//     to "desligada", or pass `confirmarPublicacao: '<id da prova>'`);
//   - the time settings do not fit the lap (apply the test preset);
//   - a replay whose race would not lie wholly in the past.
// The simulation never changes a setting itself.

import { generateRaceScript, raceDurationFor, AREA_BOX, AREA_ABASTECIMENTO } from './simulacao-corrida.js';
import { planCounter, planStopLog, withStableIds } from './simulacao-contador.js';
import { playPlan, windowFitsLap, replayOverrunMs, mayStartSimulation } from './simulacao-execucao.js';
import { trueLapCounts, injectedErrors, lapCountsCsv, errorsCsv, raceTime } from './simulacao-gabarito.js';

const PAGE_API = 'contagemPagina';
const TIPO_CONTAR = 'contar';

/** The simulation running in this tab, if any: {roteiro, planos, execucoes, opcoes, api}. */
let running = null;

class SimulationRefused extends Error {}

function pageApi(windowRef) {
    const api = windowRef[PAGE_API];
    if (!api) {
        throw new SimulationRefused('A simulação não está permitida nesta prova. Ligue "Permitir simulação" nas configurações da contagem (só para ensaios).');
    }
    return api;
}

function numbersOf(cars) {
    return (cars || []).map((car) => car.numero);
}

/** What the last answer of the server said about the prova (kept by the page). */
function serverInfoOf(api) {
    return api.armazenamento.getMeta('servidor', null) || {};
}

/**
 * Everything the script is built from. The start is the REAL start the
 * server knows ("Largar agora" in race control), so every tab uses the
 * same one.
 */
function scriptFor(api, { semente, voltaMediaS }) {
    if (!Number.isInteger(semente) || !Number.isInteger(voltaMediaS) || voltaMediaS <= 0) {
        throw new SimulationRefused('Informe a semente e a volta média em números inteiros: contagemSimular({semente: 42, voltaMediaS: 60}).');
    }
    const race = serverInfoOf(api).race;
    const largadaMs = race ? race.largada_ms : null;
    if (largadaMs === null || largadaMs === undefined) {
        throw new SimulationRefused('A corrida ainda não tem largada. Dê a largada no controle de prova e aguarde esta página sincronizar.');
    }
    const carros = numbersOf(api.carros());
    if (carros.length === 0) {
        throw new SimulationRefused('Esta página ainda não recebeu a lista de carros. Aguarde a sincronização.');
    }
    return generateRaceScript({
        semente,
        carros,
        voltaMediaMs: voltaMediaS * 1000,
        largadaMs,
        abastecimento: api.abastecimento(),
    });
}

/** The plans this page plays: one counter, or the people at the two stop areas. */
function plansFor(api, roteiro, { semente, taxas, area }) {
    const race = { semente, largadaMs: roteiro.largadaMs, voltaMediaMs: roteiro.voltaMediaMs };
    if (api.tipo === TIPO_CONTAR) {
        const fonteId = api.config.fonte.id;
        const plano = planCounter({
            roteiro,
            semente,
            fonteId,
            naoClassificados: numbersOf(api.naoClassificados()),
            janelaMs: api.janelaMs(),
            taxas,
        });
        return [{
            plano: withStableIds(plano, race, fonteId),
            registrar: (action) => api.registrarPassagem({ car: action.carro, tsMs: action.tsMs, uuid: action.uuid }),
        }];
    }
    const areas = [AREA_BOX, AREA_ABASTECIMENTO].filter((candidate) => {
        const wanted = area === undefined || area === 'ambas' || area === candidate;
        return wanted && (candidate === AREA_BOX || api.abastecimento());
    });
    return areas.map((oneArea) => ({
        plano: withStableIds(planStopLog({ roteiro, semente, area: oneArea, taxas }), race, oneArea),
        registrar: (action) => api.registrarParada({ car: action.carro, tipo: action.tipo, tsMs: action.tsMs, uuid: action.uuid }),
    }));
}

/** Throws, with the sentence for the person, when this simulation must not start. */
function refuseIfUnsafe(api, roteiro, opcoes, isReplay, officialNowMs) {
    const provaId = api.config.prova_id;
    if (!mayStartSimulation({ publica: serverInfoOf(api).publica, provaId, confirmacao: opcoes.confirmarPublicacao })) {
        throw new SimulationRefused(
            'Esta prova está publicando a contagem nos resultados oficiais. Mude o "modo de publicação" para "desligada" '
            + 'antes de simular. Se a intenção é mesmo publicar uma corrida simulada, repita o comando com '
            + 'confirmarPublicacao: \'' + provaId + '\'.'
        );
    }
    if (!windowFitsLap(api.janelaMs(), roteiro.voltaMediaMs)) {
        // The simulation never changes settings by itself: a person must.
        throw new SimulationRefused(
            'As configurações de tempo não servem para uma volta média de ' + opcoes.voltaMediaS + ' s '
            + '(janela de ' + api.janelaMs() + ' ms). Em Configurações da contagem, aplique a "Predefinição de teste" '
            + 'para volta média de ' + opcoes.voltaMediaS + ' s e aguarde esta página sincronizar.'
        );
    }
    if (isReplay) {
        const overrunMs = replayOverrunMs(roteiro.largadaMs, raceDurationFor(roteiro.voltaMediaMs), officialNowMs);
        if (overrunMs > 0) {
            throw new SimulationRefused(
                'Um replay precisa estar inteiro no passado: esta corrida dura ' + raceTime(roteiro.fimMs, roteiro.largadaMs)
                + ' e a largada registrada é recente demais. No controle de prova, corrija a largada para pelo menos '
                + raceTime(roteiro.fimMs, roteiro.largadaMs) + ' antes de agora (faltam ' + raceTime(overrunMs, 0) + ').'
            );
        }
    }
}

/**
 * @param {Window} windowRef
 * @param {object} opcoes
 * @param {number} opcoes.semente      the shared seed: the same in every tab
 * @param {number} opcoes.voltaMediaS  average lap, in seconds
 * @param {number} [opcoes.velocidade] given = replay: the race is played this many times faster than real time
 * @param {object} [opcoes.taxas]      error rates per thousand (see simulacao-contador.js)
 * @param {string} [opcoes.area]       pit page only: 'box', 'abastecimento' or 'ambas' (default)
 * @param {string} [opcoes.confirmarPublicacao] the prova's id, to simulate on a prova that publishes
 * @returns {{parar: function, terminou: Promise}}
 */
function simular(windowRef, opcoes = {}) {
    const api = pageApi(windowRef);
    if (running !== null) {
        throw new SimulationRefused('Já há uma simulação rodando nesta aba. Use contagemSimular.parar() ou recarregue a página.');
    }
    const roteiro = scriptFor(api, opcoes);
    const officialNowMs = api.relogio.serverNowMs();
    if (officialNowMs === null) {
        throw new SimulationRefused('Esta página ainda não sincronizou o horário com o servidor. Aguarde e tente de novo.');
    }
    const isReplay = Number.isFinite(opcoes.velocidade) && opcoes.velocidade > 0;
    refuseIfUnsafe(api, roteiro, opcoes, isReplay, officialNowMs);

    const plans = plansFor(api, roteiro, opcoes);
    if (isReplay) {
        // A replay types passes of hours ago. "My queue is empty, I have
        // sent everything up to now" would then be said between two of
        // them, and the server would conclude this counter missed every
        // pass the replay has not reached yet.
        api.suprimirMarcaDeFila(true);
    }
    const execucoes = plans.map(({ plano, registrar }) => playPlan({
        acoes: plano.acoes,
        registrar,
        // A live race follows the OFFICIAL clock, the one every tab and
        // the server share. A replay only needs "how much real time has passed".
        agora: isReplay ? () => windowRef.performance.now() : () => api.relogio.serverNowMs(),
        esperar: (ms) => new Promise((resolve) => windowRef.setTimeout(resolve, ms)),
        velocidade: isReplay ? opcoes.velocidade : 1,
        // Live: a tab that joins late first catches up with what it "missed".
        inicioCorridaMs: isReplay ? roteiro.largadaMs : officialNowMs,
        aoProgredir: (played, total) => {
            if (played === total || played % 200 === 0) {
                windowRef.console.log('Simulação: ' + played + ' de ' + total + ' registros.');
            }
        },
    }));

    running = { roteiro, planos: plans.map((plan) => plan.plano), execucoes, opcoes, api };
    const terminou = Promise.all(execucoes.map((execucao) => execucao.terminou)).then((results) => {
        api.suprimirMarcaDeFila(false);
        windowRef.console.log('Simulação terminada nesta aba.', results);
        return results;
    });
    windowRef.console.log(
        'Simulação iniciada (' + (isReplay ? 'replay ' + opcoes.velocidade + 'x' : 'ao vivo') + '): '
        + roteiro.travessias.length + ' passagens de ' + roteiro.carros.length + ' carros, até '
        + raceTime(roteiro.fimMs, roteiro.largadaMs) + ' de prova. Para interromper: contagemSimular.parar().'
    );
    return { parar: () => parar(windowRef), terminou };
}

function parar(windowRef) {
    if (running === null) {
        return;
    }
    running.execucoes.forEach((execucao) => execucao.parar());
    running.api.suprimirMarcaDeFila(false);
    running = null;
    windowRef.console.log('Simulação interrompida nesta aba. O que já foi registrado continua registrado.');
}

/**
 * The answer key: the true lap count per car and the mistakes injected by
 * this tab. Without a simulation running, pass the same `semente` and
 * `voltaMediaS` that were used.
 *
 * @param {Window} windowRef
 * @param {object} [opcoes]
 * @param {boolean}  [opcoes.baixar]  also download both lists as CSV files
 * @param {number}   [opcoes.ateMs]   live mode: the lap counts as of this moment (server time) instead of now
 * @param {number[]} [opcoes.fontes]  count pages: include the mistakes of these source ids too
 */
function gabarito(windowRef, opcoes = {}) {
    const api = pageApi(windowRef);
    const used = running !== null ? { ...running.opcoes, ...opcoes } : opcoes;
    const roteiro = running !== null ? running.roteiro : scriptFor(api, used);
    let plans = running !== null ? running.planos : plansFor(api, roteiro, used).map((plan) => plan.plano);
    if (Array.isArray(opcoes.fontes)) {
        plans = opcoes.fontes.map((fonteId) => planCounter({
            roteiro,
            semente: used.semente,
            fonteId,
            naoClassificados: numbersOf(api.naoClassificados()),
            janelaMs: api.janelaMs(),
            taxas: used.taxas,
        }));
    }

    // In a live race the key is "as of now" (or as of `ateMs`, to compare
    // with a moment already fully uploaded); a replay lies wholly in the past.
    const isReplay = Number.isFinite(used.velocidade) && used.velocidade > 0;
    const asOfMs = Number.isFinite(opcoes.ateMs) ? opcoes.ateMs : api.relogio.serverNowMs();
    const laps = trueLapCounts(roteiro, {
        // Which crossing completes lap 1 depends on the prova's counting rule.
        passagemInicial: serverInfoOf(api).passagem_inicial || undefined,
        ateMs: isReplay ? null : asOfMs,
    });
    const errors = injectedErrors(plans);

    windowRef.console.log('Voltas verdadeiras por carro' + (isReplay ? '' : ' (até agora)') + ':');
    windowRef.console.table([...laps].map(([carro, voltas]) => ({ carro, voltas })));
    windowRef.console.log('Erros injetados (' + errors.length + '):');
    windowRef.console.table(errors.map((error) => ({ ...error, tempo: raceTime(error.tsMs, roteiro.largadaMs) })));

    if (opcoes.baixar === true) {
        download(windowRef, 'gabarito-voltas.csv', lapCountsCsv(laps));
        download(windowRef, 'gabarito-erros.csv', errorsCsv(errors, roteiro.largadaMs));
    }
    return { voltas: Object.fromEntries(laps), erros: errors };
}

function download(windowRef, fileName, text) {
    const documentRef = windowRef.document;
    // The BOM makes Excel read the accents right.
    const url = windowRef.URL.createObjectURL(new windowRef.Blob(['﻿' + text], { type: 'text/csv;charset=utf-8' }));
    const link = documentRef.createElement('a');
    link.href = url;
    link.download = fileName;
    documentRef.body.appendChild(link);
    link.click();
    link.remove();
    windowRef.URL.revokeObjectURL(url);
}

/** Wraps a command so that a refusal is one readable line in the console, not a stack trace. */
function command(windowRef, action) {
    return (opcoes) => {
        try {
            return action(windowRef, opcoes);
        } catch (error) {
            if (error instanceof SimulationRefused) {
                windowRef.console.error(error.message);
                return null;
            }
            throw error;
        }
    };
}

/** Puts the two commands on the window. Safe to call more than once. */
export function instalarSimulacao(windowRef) {
    if (typeof windowRef.contagemSimular === 'function') {
        return;
    }
    windowRef.contagemSimular = command(windowRef, simular);
    windowRef.contagemSimular.parar = () => parar(windowRef);
    windowRef.contagemGabarito = command(windowRef, gabarito);
}

/** Takes them away again, stopping a simulation that is running. */
export function removerSimulacao(windowRef) {
    parar(windowRef);
    delete windowRef.contagemSimular;
    delete windowRef.contagemGabarito;
}
