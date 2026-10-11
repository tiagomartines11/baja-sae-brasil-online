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
        URL, Request, Response, AbortController, setTimeout, clearTimeout,
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

test('a login page arriving instead of the count page never replaces the stored copy', async () => {
    // Situation: the login session expired. The server now answers the page
    // address with the login form (no shell header). The counter's unsent
    // entries are reachable only through the real count page.
    const worker = loadWorker();
    worker.network = () => shellPageResponse('<p>count page</p>');
    await worker.request(PAGE_URL);

    worker.network = () => new Response('<p>please log in</p>', { status: 200 });
    const answer = await worker.request(PAGE_URL);

    // Expected: the stored count page, not the login form.
    assert.equal(await answer.text(), '<p>count page</p>');
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
