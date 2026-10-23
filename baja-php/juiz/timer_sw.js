// Service worker for timer.php: lets an already-opened timer be reloaded with
// no network. Only the timer page itself and its sounds are handled; every
// other request goes to the network untouched.

const CACHE = "timer-v1";

self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (e) => e.waitUntil(self.clients.claim()));

self.addEventListener("fetch", (e) => {
    const url = new URL(e.request.url);
    if (e.request.method !== "GET" || url.origin !== self.location.origin) return;

    if (url.pathname.indexOf("/sons/") >= 0) {
        e.respondWith(caches.open(CACHE).then((cache) =>
            cache.match(e.request).then((hit) => hit || fetch(e.request).then((res) => {
                if (res.ok) cache.put(e.request, res.clone());
                return res;
            }))
        ));
    } else if (/\/timer\.php$/.test(url.pathname) && (url.searchParams.has("k") || url.searchParams.has("id"))) {
        e.respondWith(caches.open(CACHE).then((cache) =>
            fetch(e.request).then((res) => {
                if (res.ok) cache.put(e.request, res.clone());
                return res;
            }).catch(() => cache.match(e.request).then((hit) => hit || Response.error()))
        ));
    }
});
