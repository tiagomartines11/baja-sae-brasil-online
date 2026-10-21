// SERVICE WORKER: the small program that lets the count page open with no
// network.
//
// Background: a "service worker" is a script the browser keeps for a site and
// runs between the page and the network. Ours keeps a copy (a "cache") of the
// count page and of its scripts. Every time the page or one of its scripts is
// requested, the browser asks the worker first, and the worker answers from
// the network or from its copy.
//
// The worker is not a module, so the test loads its source text into a small
// pretend browser (`loadWorker`): a pretend cache, a pretend network, and a
// way to hand the worker a request and get its answer.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const WORKER_SOURCE = readFileSync(
    new URL('../../default/js/contagem/service-worker.js', import.meta.url),
    'utf8',
);
const ORIGIN = 'https://juiz.example';
const PAGE_URL = ORIGIN + '/26BR/contagem_contar.php?p=END&f=1';
const VERSION = 'abc123';

function withoutQuery(url) {
    return url.split('?')[0];
}

/** One named cache: URL -> Response, with the two lookups the worker uses. */
function createFakeCache() {
    const cache = {
        stored: new Map(),
        failWrites: false,
        async match(url, options = {}) {
            for (const [storedUrl, response] of cache.stored) {
                const same = options.ignoreSearch
                    ? withoutQuery(storedUrl) === withoutQuery(url)
                    : storedUrl === url;
                if (same) {
                    return response.clone();
                }
            }
            return undefined;
        },
        async put(url, response) {
            if (cache.failWrites) {
                throw new Error('simulated: QuotaExceededError');
            }
            cache.stored.set(url, response);
        },
    };
    return cache;
}

/**
 * Runs the worker's source in a pretend browser.
 * `network` is a function (url) => Response that the test replaces; throwing
 * from it means "no connection".
 */
function loadWorker() {
    const handlers = new Map();
    const cache = createFakeCache();
    const world = {
        cache,
        requestedFromNetwork: [],
        network: () => {
            throw new Error('simulated: no network');
        },
    };
    const sandbox = {
        CONTAGEM_SHELL: { versao: VERSION, arquivos: [] },
        self: {
            location: { origin: ORIGIN },
            addEventListener: (eventName, handler) => handlers.set(eventName, handler),
            clients: { matchAll: async () => [], claim: async () => {} },
            skipWaiting: async () => {},
        },
        caches: { open: async () => cache, keys: async () => [], delete: async () => true },
        fetch: async (request) => {
            const url = typeof request === 'string' ? request : request.url;
            world.requestedFromNetwork.push(url);
            return world.network(url);
        },
        URL, Request, Response, Headers, AbortController, setTimeout, clearTimeout,
    };
    vm.runInNewContext(WORKER_SOURCE, sandbox);

    /**
     * The browser offering a request to the worker. Returns the worker's
     * answer (a promise), or null when the worker leaves the request alone
     * and the browser handles it as if there were no worker.
     */
    world.offer = (url, method = 'GET') => {
        let answer = null;
        handlers.get('fetch')({
            request: new Request(url, { method }),
            respondWith: (responsePromise) => { answer = responsePromise; },
        });
        return answer;
    };
    /** The same, for a request the worker is expected to answer. */
    world.request = (url) => {
        const answer = world.offer(url);
        assert.notEqual(answer, null, 'the worker did not take charge of ' + url);
        return answer;
    };
    return world;
}

function scriptResponse(text) {
    return new Response(text, { status: 200, headers: { 'Content-Type': 'application/javascript' } });
}

/** The count page as the server sends it: marked with the shell header. */
function shellPageResponse(text) {
    return new Response(text, { status: 200, headers: { 'X-Contagem-Shell': '1' } });
}

// ---------------------------------------------------------------------------
// Scripts requested without a version (browsers without import maps)
// ---------------------------------------------------------------------------

test('a script requested without a version is answered from the cache, with no network', async () => {
    // Situation: an older phone. Modern browsers are told, through an
    // "import map", to fetch every script as "name.js?v=abc123". An older
    // browser ignores that map and asks for plain "name.js". The worker's
    // cache holds the scripts under their versioned names. The phone has no
    // signal and the counter reloads the page.
    //
    // What used to go wrong: "local-store.js" is not literally the same
    // address as "local-store.js?v=abc123", so the worker found nothing in
    // its cache and went to the network. With no signal the script failed to
    // load and the count page stayed blank.
    const worker = loadWorker();
    const versionedUrl = ORIGIN + '/26BR/js/contagem/local-store.js?v=' + VERSION;
    worker.cache.stored.set(versionedUrl, scriptResponse('// local-store, current version'));

    const answer = await worker.request(ORIGIN + '/26BR/js/contagem/local-store.js');

    // Expected: the cached copy, and the network was not even tried (with
    // network it would have returned whatever the browser's 30-day cache
    // holds, possibly a different deploy than the other scripts).
    assert.equal(await answer.text(), '// local-store, current version');
    assert.deepEqual(worker.requestedFromNetwork, []);
});

test('a script requested with its exact version is still answered from the cache', async () => {
    // Situation: a modern browser, the normal case.
    const worker = loadWorker();
    const versionedUrl = ORIGIN + '/26BR/js/contagem/contar.js?v=' + VERSION;
    worker.cache.stored.set(versionedUrl, scriptResponse('// contar, current version'));

    const answer = await worker.request(versionedUrl);

    assert.equal(await answer.text(), '// contar, current version');
    assert.deepEqual(worker.requestedFromNetwork, []);
});

test('a script requested in a NEWER version goes to the network, not to the old copy', async () => {
    // Situation: a deploy has just happened. The page (fresh from the
    // server) asks for "contar.js?v=new", while the worker still holds the
    // previous version. With a connection, the new one must win: mixing an
    // old script into a new page is how subtle bugs appear.
    const worker = loadWorker();
    worker.cache.stored.set(ORIGIN + '/26BR/js/contagem/contar.js?v=' + VERSION, scriptResponse('// old'));
    worker.network = () => scriptResponse('// new');

    const answer = await worker.request(ORIGIN + '/26BR/js/contagem/contar.js?v=new999');

    assert.equal(await answer.text(), '// new');

    // Without a connection, the old copy is better than a dead page.
    worker.network = () => {
        throw new Error('simulated: no network');
    };
    const offlineAnswer = await worker.request(ORIGIN + '/26BR/js/contagem/contar.js?v=newer000');
    assert.equal(await offlineAnswer.text(), '// old');
});

// ---------------------------------------------------------------------------
// A cache that cannot be written
// ---------------------------------------------------------------------------

test('when the copy cannot be saved, the page from the network is still delivered', async () => {
    // Situation: the phone's storage is full (a race day of photos and
    // videos). The counter opens the count page WITH a good connection. The
    // server answers, and the worker tries to keep a copy for later.
    //
    // What used to go wrong: the failure to save the copy was treated as a
    // failure to load the page. The counter got an error page although the
    // server had answered perfectly well.
    const worker = loadWorker();
    worker.cache.failWrites = true;
    worker.network = () => shellPageResponse('<p>count page</p>');

    const answer = await worker.request(PAGE_URL);

    // Expected: the page. Only the offline copy is missing.
    assert.equal(answer.status, 200);
    assert.equal(await answer.text(), '<p>count page</p>');
    assert.equal(worker.cache.stored.size, 0);
});

test('the same holds for a script fetched from the network', async () => {
    const worker = loadWorker();
    worker.cache.failWrites = true;
    worker.network = () => scriptResponse('// fresh script');

    const answer = await worker.request(ORIGIN + '/26BR/js/contagem/contar.js?v=' + VERSION);

    assert.equal(await answer.text(), '// fresh script');
});

// ---------------------------------------------------------------------------
// The normal behaviour, for reference
// ---------------------------------------------------------------------------

test('a page loaded with a connection is kept, and served again when the connection is gone', async () => {
    // Situation: the counter opens the page at the paddock (signal), walks
    // to the far corner of the track (no signal) and reloads.
    const worker = loadWorker();
    worker.network = () => shellPageResponse('<p>count page</p>');
    await worker.request(PAGE_URL);

    worker.network = () => {
        throw new Error('simulated: no network');
    };
    const answer = await worker.request(PAGE_URL);

    assert.equal(await answer.text(), '<p>count page</p>');
});

// ---------------------------------------------------------------------------
// The server refuses the page: the stored copy, marked "record nothing new"
// ---------------------------------------------------------------------------

const SHELL_HTML = '<!DOCTYPE html><html><head><title>Box</title></head><body><p>pit page</p></body></html>';
const REFUSED_MARK = '<meta name="contagem-acesso" content="recusado">';

/** A worker holding the page, as after one load with a connection. */
async function workerWithStoredPage() {
    const worker = loadWorker();
    worker.network = () => shellPageResponse(SHELL_HTML);
    await worker.request(PAGE_URL);
    return worker;
}

/**
 * What the worker gets when the page redirects to login.php (no forum
 * session). A navigation is fetched with redirect mode "manual": an opaque
 * redirect, whose target cannot be read.
 */
function opaqueRedirectToLogin() {
    return { type: 'opaqueredirect', status: 0, ok: false, redirected: false, url: PAGE_URL, headers: new Headers() };
}

/** The same redirect, followed: the login form, saying it came through a redirect. */
function followedRedirectToLogin() {
    const loginForm = new Response('<form>entrar</form>', { status: 200, headers: { 'Content-Type': 'text/html' } });
    Object.defineProperty(loginForm, 'redirected', { value: true });
    Object.defineProperty(loginForm, 'url', { value: ORIGIN + '/26BR/login.php' });
    return loginForm;
}

test('a redirect to the login page leaves the stored copy unmarked, so recording goes on', async () => {
    // SITUATION: four hours into the race the counter's forum session
    // expires. They reload the page; the server answers its address with a
    // redirect to login.php.
    //
    // WHY IT MATTERS: counting survives an expired forum login: the device
    // token on the phone (the counter's own) is the credential. Marking the
    // page here would take the page away from a counter who has access.
    for (const [label, redirect] of [['opaque redirect', opaqueRedirectToLogin], ['followed redirect', followedRedirectToLogin]]) {
        const worker = await workerWithStoredPage();
        worker.network = redirect;

        const html = await (await worker.request(PAGE_URL)).text();

        // EXPECTED: the stored page exactly as it was: it records normally.
        assert.equal(html, SHELL_HTML, label);
    }
});

test('an "access denied" answer still marks the stored copy as refused', async () => {
    // SITUATION: the phone was used for counting by equipe1. Now somebody
    // logged in WITHOUT access opens the page. The server answers with
    // "Você não tem acesso a essa página" (status 200, no shell header).
    //
    // WHAT WENT WRONG: the worker served the stored copy as it was: equipe1's
    // configuration and, on the phone's disk, equipe1's device token. The
    // person in front of the phone could record box entries and exits, and
    // the server, seeing a valid token, stored them.
    const worker = await workerWithStoredPage();

    worker.network = () => new Response('Você não tem acesso a essa página', { status: 200 });
    const answer = await worker.request(PAGE_URL);

    // EXPECTED: still the stored page, never the refusal (the unsent entries
    // and the export are reachable only through it), but carrying the mark
    // that makes the page record nothing new.
    const html = await answer.text();
    assert.ok(html.includes('<p>pit page</p>'));
    assert.ok(html.includes(REFUSED_MARK + '\n</head>'), 'the mark is in the head, before any script runs');
    assert.equal(answer.headers.get('X-Contagem-Shell'), '1');
});

test('an "access denied" page, whatever its status, is a refusal too', async () => {
    // "Você não tem acesso a essa página" (200), a 403, a 404 for a page that
    // was removed, the page a forum user unknown to this system gets (200):
    // the server answered, and the answer is not the page.
    for (const refusal of [
        new Response('<p>Você não tem acesso a essa página</p>', { status: 200 }),
        new Response('forbidden', { status: 403 }),
        new Response('not found', { status: 404 }),
    ]) {
        const worker = await workerWithStoredPage();
        worker.network = () => refusal;

        const html = await (await worker.request(PAGE_URL)).text();

        assert.ok(html.includes(REFUSED_MARK), 'status ' + refusal.status);
    }
});

test('no network, a slow network or a server error: the stored copy, NOT marked', async () => {
    // SITUATION: the counter is at the far corner of the track. The point of
    // the offline copy is that counting goes on there, as normal.
    const noNetwork = await workerWithStoredPage();
    noNetwork.network = () => {
        throw new Error('simulated: no network');
    };
    // A request that took too long ends the same way: the worker aborts it.
    const timedOut = await workerWithStoredPage();
    timedOut.network = () => {
        throw new DOMException('simulated: the worker gave up waiting', 'AbortError');
    };
    // A server error is trouble on the way, not a decision about this person.
    const serverError = await workerWithStoredPage();
    serverError.network = () => new Response('oops', { status: 502 });

    for (const [label, worker] of [['no network', noNetwork], ['timeout', timedOut], ['server error', serverError]]) {
        const html = await (await worker.request(PAGE_URL)).text();
        // EXPECTED: the page exactly as stored: it records normally.
        assert.equal(html, SHELL_HTML, label);
    }
});

test('the mark is only on what is served: the stored copy itself stays clean', async () => {
    // SITUATION: refused once ("sem acesso"), then the network drops and the
    // page is reloaded again.
    const worker = await workerWithStoredPage();
    worker.network = () => new Response('Você não tem acesso a essa página', { status: 200 });
    await worker.request(PAGE_URL);
    worker.network = () => {
        throw new Error('simulated: no network');
    };

    const html = await (await worker.request(PAGE_URL)).text();

    // EXPECTED: no mark. (Had the mark been stored, a counter would be
    // locked out of counting offline for good.)
    assert.equal(html, SHELL_HTML);
});

test('the user who IS allowed gets the fresh page from the server, unmarked', async () => {
    // SITUATION: another counter, with access, logs in on the same phone
    // and opens the page. The server sends the page, made for them.
    const worker = await workerWithStoredPage();
    const pageForTheNewUser = SHELL_HTML.replace('pit page', 'pit page for capitao2');
    worker.network = () => shellPageResponse(pageForTheNewUser);

    const html = await (await worker.request(PAGE_URL)).text();

    assert.equal(html, pageForTheNewUser);
});

test('requests that are not part of the count pages are left alone', async () => {
    // Situation: the review screen's scripts live in the same folder but
    // must always come fresh from the network; so must every other page, and
    // uploads are never the worker's business.
    const worker = loadWorker();
    worker.network = () => scriptResponse('');

    assert.equal(worker.offer(ORIGIN + '/26BR/js/contagem/revisao.js'), null);
    assert.equal(worker.offer(ORIGIN + '/26BR/contagem_revisao.php?p=END'), null);
    assert.equal(worker.offer('https://outro.example/js/contagem/contar.js'), null);
    assert.equal(worker.offer(ORIGIN + '/26BR/contagem_sync.php', 'POST'), null);

    // Its own files, for comparison, it does answer.
    assert.notEqual(worker.offer(ORIGIN + '/26BR/js/contagem/contar.js'), null);
    assert.notEqual(worker.offer(ORIGIN + '/26BR/css/contagem.css'), null);
});
