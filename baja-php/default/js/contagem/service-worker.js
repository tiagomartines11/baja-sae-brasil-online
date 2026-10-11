// Service worker for the count and pit pages: lets them reload with no network.
//
// Served by juiz/contagem_sw.php, which puts one line in front of this file:
//     const CONTAGEM_SHELL = {"versao": "...", "arquivos": ["js/contagem/...?v=...", ...]};
// A new deploy changes that line, the browser sees a different worker, and the
// new worker builds a fresh cache before replacing the old one.
//
// It deliberately touches only three kinds of request: the two page shells and
// this feature's own scripts and style sheet. Everything else on the site
// (other pages, uploads, the review screen) goes to the network untouched.

/* global CONTAGEM_SHELL */

const CACHE_PREFIX = 'contagem-';
const CACHE_NAME = CACHE_PREFIX + CONTAGEM_SHELL.versao;

// The page shells send this header. A login redirect or an "access denied"
// answer does not carry it, which is how the worker tells them apart.
const SHELL_HEADER = 'X-Contagem-Shell';
const SHELL_PAGES = ['contagem_contar.php', 'contagem_box.php'];

// How long a reload waits for the network before showing the cached page.
const PAGE_NETWORK_TIMEOUT_MS = 4000;

function isShellPage(url) {
    return SHELL_PAGES.some((pageName) => url.pathname.endsWith('/' + pageName));
}

function isShellAsset(url) {
    if (url.pathname.endsWith('/css/contagem.css')) {
        return true;
    }
    const marker = '/js/contagem/';
    const position = url.pathname.indexOf(marker);
    if (position === -1) {
        return false;
    }
    // The review screen's scripts live in the same folder but are online-only
    // and are not part of this cache's version.
    return !url.pathname.slice(position + marker.length).startsWith('revisao');
}

function isUsableShell(response) {
    return response.ok && response.headers.get(SHELL_HEADER) === '1';
}

async function precacheAssets() {
    const cache = await caches.open(CACHE_NAME);
    for (const assetUrl of CONTAGEM_SHELL.arquivos) {
        // "reload" skips the browser's 30-day HTTP cache for /js/ and /css/.
        const response = await fetch(new Request(assetUrl, { cache: 'reload' }));
        if (!response.ok) {
            // Failing the install keeps the previous worker and its complete
            // cache in charge, which is better than a half-filled new one.
            throw new Error('Could not cache ' + assetUrl);
        }
        await cache.put(assetUrl, response);
    }
}

/** Stores the shell of every count/pit page that is open right now. */
async function cacheOpenPages() {
    const cache = await caches.open(CACHE_NAME);
    const openWindows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of openWindows) {
        if (!isShellPage(new URL(client.url))) {
            continue;
        }
        try {
            const response = await fetch(client.url, { credentials: 'same-origin', cache: 'no-store' });
            if (isUsableShell(response)) {
                await cache.put(client.url, response);
            }
        } catch (networkError) {
            // The page is cached on its next successful load instead.
        }
    }
}

async function deleteOldCaches() {
    for (const cacheName of await caches.keys()) {
        if (cacheName.startsWith(CACHE_PREFIX) && cacheName !== CACHE_NAME) {
            await caches.delete(cacheName);
        }
    }
}

function fetchWithTimeout(request, timeoutMs) {
    const abortController = new AbortController();
    const timer = setTimeout(() => abortController.abort(), timeoutMs);
    return fetch(request, { signal: abortController.signal }).finally(() => clearTimeout(timer));
}

/**
 * Page shells: network first, so config and permissions are fresh whenever
 * there is a connection. The cached copy is used when the network fails, is
 * too slow, or answers with something that is not the shell (session expired,
 * access removed): the counter must always be able to get back to the page
 * that holds their unsent entries and the export button.
 */
async function pageFromNetworkThenCache(request) {
    const cache = await caches.open(CACHE_NAME);
    let networkResponse = null;
    try {
        networkResponse = await fetchWithTimeout(request, PAGE_NETWORK_TIMEOUT_MS);
    } catch (networkError) {
        networkResponse = null;
    }
    if (networkResponse !== null && isUsableShell(networkResponse)) {
        await cache.put(request.url, networkResponse.clone());
        return networkResponse;
    }
    const cachedResponse = await cache.match(request.url);
    if (cachedResponse) {
        return cachedResponse;
    }
    if (networkResponse !== null) {
        return networkResponse;
    }
    return new Response(
        '<!DOCTYPE html><meta charset="utf-8"><title>Sem conexão</title>'
        + '<p>Sem conexão, e esta página ainda não foi guardada neste aparelho. '
        + 'Tente de novo quando houver sinal.</p>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
    );
}

/** Scripts and styles: their URL carries the version, so the cache is final. */
async function assetFromCacheThenNetwork(request) {
    const cache = await caches.open(CACHE_NAME);
    const cachedResponse = await cache.match(request.url);
    if (cachedResponse) {
        return cachedResponse;
    }
    try {
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            await cache.put(request.url, networkResponse.clone());
        }
        return networkResponse;
    } catch (networkError) {
        // Offline and asked for a version string we do not hold (an old shell
        // after a deploy): the same file in another version beats a dead page.
        const otherVersion = await cache.match(request.url, { ignoreSearch: true });
        if (otherVersion) {
            return otherVersion;
        }
        throw networkError;
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(precacheAssets().then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    // Order matters: fill the new cache with the open pages BEFORE dropping
    // the old cache, so there is no moment without an offline copy.
    event.waitUntil(cacheOpenPages().then(deleteOldCaches).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }
    if (isShellPage(url)) {
        event.respondWith(pageFromNetworkThenCache(request));
    } else if (isShellAsset(url)) {
        event.respondWith(assetFromCacheThenNetwork(request));
    }
});
