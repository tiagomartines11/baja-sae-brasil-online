// WARNINGS AND STATUS LINES: what the page tells the counter about itself.
//
// Background: a volunteer at the side of a track does what the screen says.
// So what the screen says in a bad moment decides whether their entries
// survive. Three things are checked here:
//
//   - the red banner at the top (`describeBanner`): which warnings it shows
//     and whether it offers the "Tentar de novo" button;
//   - the connection chip in the status bar (`describeConnection`);
//   - the two status lines in the footer (`describeDeviceStatus`), which say
//     whether this device is safe to count on.
//
// "Memory mode" below means: the page could not use the device's storage and
// keeps the entries only in its own memory. A reload then ERASES every entry
// that has not been uploaded.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { CONEXAO } from '../../default/js/contagem/sync-queue.js';
import { ARMAZENAMENTO } from '../../default/js/contagem/storage-open.js';
import { describeBanner, describeConnection } from '../../default/js/contagem/status-messages.js';
import {
    COPIA_OFFLINE, describeDeviceStatus, readOfflineCopy, readStorageProtection,
} from '../../default/js/contagem/device-status.js';

/** The state of the upload queue, as the status bar receives it. */
function queueState(overrides = {}) {
    return { conexao: CONEXAO.ONLINE, pendentes: 0, turnoEncerrado: false, ...overrides };
}

const ON_DISK = { tokenProblem: null, armazenamento: ARMAZENAMENTO.DISCO };

// ---------------------------------------------------------------------------
// The banner
// ---------------------------------------------------------------------------

test('with nothing wrong there is no banner and no button', () => {
    const banner = describeBanner(queueState(), ON_DISK);

    assert.deepEqual(banner, { avisos: [], oferecerNovaTentativa: false });
});

test('in memory mode the banner says "NÃO recarregue" and offers no button', () => {
    // Situation: a browser in private mode that refuses storage. The counter
    // has typed 12 entries; the network is down, so all 12 are only in the
    // page's memory.
    //
    // What used to go wrong: the banner carried a button, "Tentar
    // reconectar", that RELOADED the page. Under a text that says "do not
    // reload", a button that reloads: one tap and the 12 entries were gone.
    const banner = describeBanner(
        queueState({ conexao: CONEXAO.OFFLINE, pendentes: 12 }),
        { tokenProblem: null, armazenamento: ARMAZENAMENTO.SEM_SUPORTE },
    );

    assert.equal(banner.avisos.length, 1);
    assert.match(banner.avisos[0], /NÃO recarregue/);
    assert.equal(banner.oferecerNovaTentativa, false);
});

test('when the stored records could not be opened, the banner says exactly that', () => {
    // Situation: the device HAS storage, with entries in it from before the
    // reload, but the page could not open it (see startup.test.js).
    const banner = describeBanner(queueState(), { tokenProblem: null, armazenamento: ARMAZENAMENTO.NAO_ABRIU });

    // Expected: the counter learns that earlier records exist but cannot be
    // seen or sent from this page, that they were not erased, and that what
    // is typed now must not be lost to a reload either.
    assert.equal(banner.avisos.length, 1);
    assert.match(banner.avisos[0], /Não foi possível abrir os registros guardados/);
    assert.match(banner.avisos[0], /não foram apagados/);
    assert.match(banner.avisos[0], /NÃO recarregue/);
    assert.equal(banner.oferecerNovaTentativa, false);
});

test('two problems at once give two warnings, and still no button', () => {
    // Situation: memory mode AND the device was shut out by the controller
    // (see sync-heartbeat.test.js).
    //
    // What used to go wrong: the banner had room for one text. "Envio
    // bloqueado" won, and the far more urgent "do not reload" was never
    // shown.
    const banner = describeBanner(
        queueState({ conexao: CONEXAO.REVOGADO, pendentes: 3 }),
        { tokenProblem: null, armazenamento: ARMAZENAMENTO.SEM_SUPORTE },
    );

    // Expected: both, the storage warning first. No button: this page is
    // under "NÃO recarregue", and it asks for a new token by itself anyway.
    assert.equal(banner.avisos.length, 2);
    assert.match(banner.avisos[0], /NÃO recarregue/);
    assert.match(banner.avisos[1], /desconectado desta contagem/);
    assert.equal(banner.oferecerNovaTentativa, false);
});

test('an expired session in memory mode also shows both warnings', () => {
    const banner = describeBanner(
        queueState({ conexao: CONEXAO.OFFLINE }),
        { tokenProblem: 'sem_sessao', armazenamento: ARMAZENAMENTO.NAO_ABRIU },
    );

    assert.equal(banner.avisos.length, 2);
    assert.match(banner.avisos[1], /sessão expirou/);
    assert.equal(banner.oferecerNovaTentativa, false);
});

test('with the records safely on disk, a blocked upload offers "Tentar de novo"', () => {
    // Situation: normal storage; the device was shut out, or the session
    // expired before the device was authorised. Here a retry button is
    // useful and harmless: it retries in place, it never reloads.
    const shutOut = describeBanner(queueState({ conexao: CONEXAO.REVOGADO }), ON_DISK);
    const expired = describeBanner(queueState({ conexao: CONEXAO.OFFLINE }), { ...ON_DISK, tokenProblem: 'sem_sessao' });
    const refused = describeBanner(queueState({ conexao: CONEXAO.OFFLINE }), { ...ON_DISK, tokenProblem: 'sem_permissao' });

    for (const banner of [shutOut, expired, refused]) {
        assert.equal(banner.avisos.length, 1);
        assert.equal(banner.oferecerNovaTentativa, true);
    }
});

test('no warning anywhere tells the counter to reload or reopen the page', () => {
    // Situation: every combination of problems the page knows.
    //
    // Expected: wherever the word "recarregue" appears, it is in "NÃO
    // recarregue". (The expired-session text used to end with "reabra esta
    // página", which in memory mode is the same trap as the old button.)
    const everyText = [];
    for (const conexao of Object.values(CONEXAO)) {
        for (const armazenamento of Object.values(ARMAZENAMENTO)) {
            for (const tokenProblem of [null, 'sem_permissao', 'sem_sessao', 'sem_rede']) {
                everyText.push(...describeBanner(queueState({ conexao }), { tokenProblem, armazenamento }).avisos);
            }
        }
    }

    assert.ok(everyText.length > 0);
    for (const text of everyText) {
        assert.doesNotMatch(text, /reabra|atualize a página/i);
        const withoutTheProhibition = text.replaceAll('NÃO recarregue', '');
        assert.doesNotMatch(withoutTheProhibition, /recarregue/i);
    }
});

test('a plain network failure shows no banner: the status bar already says "Sem conexão"', () => {
    const banner = describeBanner(queueState({ conexao: CONEXAO.OFFLINE }), { ...ON_DISK, tokenProblem: 'sem_rede' });

    assert.deepEqual(banner.avisos, []);
});

// ---------------------------------------------------------------------------
// The connection chip
// ---------------------------------------------------------------------------

test('the connection chip names the state in plain words', () => {
    assert.equal(describeConnection(queueState({ conexao: CONEXAO.CONECTANDO }), ON_DISK), 'Conectando…');
    assert.equal(describeConnection(queueState({ conexao: CONEXAO.ONLINE }), ON_DISK), 'Conectado');
    assert.equal(describeConnection(queueState({ conexao: CONEXAO.OFFLINE }), ON_DISK), 'Sem conexão');
    assert.equal(describeConnection(queueState({ conexao: CONEXAO.REVOGADO }), ON_DISK), 'Envio bloqueado');
    // After "Encerrar meu turno" the page is silent on purpose, whatever the network does.
    assert.equal(describeConnection(queueState({ turnoEncerrado: true }), ON_DISK), 'Turno encerrado');
});

test('a memory-mode page that has nothing to send does not pretend to be connecting', () => {
    // Situation: memory mode, nothing typed yet. The page sends nothing until
    // there is an entry (see startup.test.js), so "Conectando…" would stay on
    // the screen for ever and look like a fault.
    const chip = describeConnection(
        queueState({ conexao: CONEXAO.CONECTANDO }),
        { tokenProblem: null, armazenamento: ARMAZENAMENTO.SEM_SUPORTE },
    );

    assert.equal(chip, 'Envia ao registrar');
});

// ---------------------------------------------------------------------------
// The two status lines in the footer
// ---------------------------------------------------------------------------
//
// "Armazenamento protegido": the browser has promised not to delete this
// site's records when the phone runs short of space. Without that promise a
// phone that fills up with photos during the race may silently erase unsent
// entries.
//
// "Cópia offline": the page itself is stored on the device (by a "service
// worker"), so it can be reopened with no network. Browsers only allow that
// on https.

test('a well prepared device shows two reassuring lines', () => {
    const lines = describeDeviceStatus({
        protegido: true, armazenamento: ARMAZENAMENTO.DISCO, copiaOffline: COPIA_OFFLINE.INSTALADA,
    });

    assert.equal(lines.armazenamento, 'Armazenamento protegido: sim');
    assert.equal(lines.copiaOffline, 'Cópia offline: instalada');
    assert.equal(lines.tudoCerto, true);
});

test('a device that is not prepared says what is missing', () => {
    // Situation: the rehearsal. The site is still served over plain http,
    // and the browser did not grant protected storage.
    const lines = describeDeviceStatus({
        protegido: false, armazenamento: ARMAZENAMENTO.DISCO, copiaOffline: COPIA_OFFLINE.PRECISA_HTTPS,
    });

    // Expected: both facts in words a volunteer can read out to the
    // organiser, who can then fix them before race day.
    assert.equal(lines.armazenamento, 'Armazenamento protegido: não');
    assert.equal(lines.copiaOffline, 'Cópia offline: não instalada (precisa de https)');
    assert.equal(lines.tudoCerto, false);
});

test('in memory mode the storage line never says "sim"', () => {
    // Situation: the browser granted protected storage, but this page could
    // not open the storage and keeps its entries in memory.
    const lines = describeDeviceStatus({
        protegido: true, armazenamento: ARMAZENAMENTO.NAO_ABRIU, copiaOffline: COPIA_OFFLINE.INSTALADA,
    });

    // Expected: "sim" would be read as "my entries are safe". They are not.
    assert.match(lines.armazenamento, /^Armazenamento protegido: não/);
    assert.equal(lines.tudoCerto, false);
});

test('the storage protection is read from the browser, and "cannot tell" is not "yes"', async () => {
    assert.equal(await readStorageProtection({ storage: { persisted: async () => true } }), true);
    assert.equal(await readStorageProtection({ storage: { persisted: async () => false } }), false);
    // An older browser without the question, or one that refuses to answer.
    assert.equal(await readStorageProtection({}), null);
    assert.equal(await readStorageProtection({ storage: { persisted: async () => { throw new Error('refused'); } } }), null);

    const lines = describeDeviceStatus({
        protegido: null, armazenamento: ARMAZENAMENTO.DISCO, copiaOffline: COPIA_OFFLINE.INSTALADA,
    });
    assert.match(lines.armazenamento, /^Armazenamento protegido: não/);
});

/** A pretend browser cache storage: cache name -> list of stored URLs. */
function fakeCaches(contents) {
    return {
        keys: async () => Object.keys(contents),
        open: async (name) => ({ match: async (url) => (contents[name].includes(url) ? {} : undefined) }),
    };
}

const PAGE_URL = 'https://juiz.example/26BR/contagem_contar.php?p=END&f=1';

test('the offline copy counts as installed only with a worker in charge AND the page in its cache', async () => {
    const workerInCharge = { serviceWorker: { controller: {} } };
    const noWorkerYet = { serviceWorker: { controller: null } };
    const pageIsCached = fakeCaches({ 'contagem-abc123': [PAGE_URL] });
    const onlyScriptsCached = fakeCaches({ 'contagem-abc123': ['https://juiz.example/26BR/js/contagem/contar.js?v=abc123'] });
    const someoneElsesCache = fakeCaches({ 'outra-coisa': [PAGE_URL] });
    const read = (navigatorRef, cachesRef, isSecureContext = true) => readOfflineCopy({
        navigatorRef, cachesRef, pageUrl: PAGE_URL, isSecureContext,
    });

    // Both halves present.
    assert.equal(await read(workerInCharge, pageIsCached), COPIA_OFFLINE.INSTALADA);
    // The worker has not taken charge of this page yet: a reload without
    // network would get no answer.
    assert.equal(await read(noWorkerYet, pageIsCached), COPIA_OFFLINE.NAO_INSTALADA);
    // The worker is there but does not hold this page: it would have nothing
    // to answer with.
    assert.equal(await read(workerInCharge, onlyScriptsCached), COPIA_OFFLINE.NAO_INSTALADA);
    assert.equal(await read(workerInCharge, someoneElsesCache), COPIA_OFFLINE.NAO_INSTALADA);
    // Plain http: browsers offer no service worker and no cache at all.
    assert.equal(await read({}, undefined, false), COPIA_OFFLINE.PRECISA_HTTPS);
    assert.equal(await read(workerInCharge, pageIsCached, false), COPIA_OFFLINE.PRECISA_HTTPS);
});

// ---------------------------------------------------------------------------
// Brief 2.4 and 2.5: a blocked device, and a browser without Web Locks
// ---------------------------------------------------------------------------

test('a device blocked by the controller says who can unblock it, and keeps the data message', () => {
    // SITUATION: a controller revoked this counter's phone. The token
    // endpoint answers 403 "dispositivo_revogado" (see device-token tests).
    const banner = describeBanner(
        queueState({ conexao: CONEXAO.REVOGADO }),
        { tokenProblem: 'dispositivo_revogado', armazenamento: ARMAZENAMENTO.DISCO },
    );

    // EXPECTED: plain words: blocked, by whom, nothing lost, what has to
    // happen ("vincular de novo" or "liberar"), and that the page recovers
    // by itself. "Tentar de novo" is offered for the impatient.
    assert.equal(banner.avisos.length, 1);
    assert.match(banner.avisos[0], /bloqueado nesta contagem pelo responsável pela prova/);
    assert.match(banner.avisos[0], /continua guardado aqui/);
    assert.match(banner.avisos[0], /liberar/);
    assert.match(banner.avisos[0], /volta sozinho/);
    assert.equal(banner.oferecerNovaTentativa, true);
});

test('the same text is shown when the page OPENS blocked, before any upload was refused', () => {
    // Situation: the blocked counter reloads the page (or opens it on a
    // phone with no stored token). The queue is not in the "revogado" state:
    // it never got as far as an upload.
    const banner = describeBanner(
        queueState({ conexao: CONEXAO.OFFLINE }),
        { tokenProblem: 'dispositivo_revogado', armazenamento: ARMAZENAMENTO.DISCO },
    );

    assert.match(banner.avisos[0], /bloqueado nesta contagem/);
});

test('where the browser has no Web Locks, the footer says the one-tab protection is off', () => {
    // SITUATION: an old browser, or the page served over plain http: there
    // is no `navigator.locks`, so nothing stops this count from being open
    // in two tabs at once (each uploading and reporting on its own).
    //
    // WHAT WENT WRONG: the page carried on without the protection and said
    // nothing, while the documentation promised "já aberto em outra aba".
    const lines = describeDeviceStatus({
        protegido: true, armazenamento: ARMAZENAMENTO.DISCO, copiaOffline: COPIA_OFFLINE.INSTALADA,
        semTravaDeAba: true,
    });

    // EXPECTED: a third line that says so and what to do, and the device no
    // longer counts as "all fine".
    assert.equal(lines.abaUnica, 'Proteção contra duas abas: desligada neste navegador. Abra esta contagem em UMA aba só.');
    assert.equal(lines.tudoCerto, false);
});

test('where the protection works, there is no third line', () => {
    const lines = describeDeviceStatus({
        protegido: true, armazenamento: ARMAZENAMENTO.DISCO, copiaOffline: COPIA_OFFLINE.INSTALADA,
    });

    assert.equal(lines.abaUnica, null);
    assert.equal(lines.tudoCerto, true);
});
