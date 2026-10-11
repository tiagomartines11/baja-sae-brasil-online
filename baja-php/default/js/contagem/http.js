// The one place that talks HTTP for the count and pit pages.

const DEFAULT_TIMEOUT_MS = 10000;

/**
 * POSTs JSON and returns `{status, body, sentAtMs, receivedAtMs}`; `body` is null when the answer is
 * not JSON (for example the login page arriving after a session expired).
 * Rejects when the network fails or the answer takes longer than the timeout:
 * on a weak signal a request that hangs for minutes would block every later
 * attempt, so it is better to give up and let the next heartbeat retry.
 *
 * `sentAtMs` and `receivedAtMs` are device times taken as close to the wire
 * as a page can get: the last thing before the request leaves, and the first
 * thing after the answer's headers arrive. The clock estimate is built from
 * them (see clock-estimator.js), and every millisecond spent elsewhere in
 * between (preparing the body, reading it) would make it less precise.
 *
 * @param {function} fetchFn normally `window.fetch`
 * @param {string}   url
 * @param {object}   [options]
 * @param {?string}  [options.token]  device token for the Authorization header
 * @param {object}   [options.body]
 * @param {number}   [options.timeoutMs]
 * @param {function} [options.now]    () => device time in ms
 */
export async function postJson(fetchFn, url, {
    token = null, body = {}, timeoutMs = DEFAULT_TIMEOUT_MS, now = Date.now,
} = {}) {
    const abortController = new AbortController();
    const timeoutTimer = setTimeout(() => abortController.abort(), timeoutMs);
    try {
        const headers = { 'Content-Type': 'application/json' };
        if (token !== null) {
            headers.Authorization = 'Bearer ' + token;
        }
        const requestOptions = {
            method: 'POST',
            headers,
            body: JSON.stringify(body),
            credentials: 'same-origin',
            cache: 'no-store',
            signal: abortController.signal,
        };
        // Nothing may come between these three lines.
        const sentAtMs = now();
        const response = await fetchFn(url, requestOptions);
        const receivedAtMs = now();
        const text = await response.text();
        let parsedBody = null;
        try {
            parsedBody = JSON.parse(text);
        } catch (notJson) {
            parsedBody = null;
        }
        return { status: response.status, body: parsedBody, sentAtMs, receivedAtMs };
    } finally {
        clearTimeout(timeoutTimer);
    }
}
