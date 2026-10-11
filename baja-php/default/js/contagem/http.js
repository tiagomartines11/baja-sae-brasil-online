// The one place that talks HTTP for the count and pit pages.

const DEFAULT_TIMEOUT_MS = 10000;

/**
 * POSTs JSON and returns `{status, body}`; `body` is null when the answer is
 * not JSON (for example the login page arriving after a session expired).
 * Rejects when the network fails or the answer takes longer than the timeout:
 * on a weak signal a request that hangs for minutes would block every later
 * attempt, so it is better to give up and let the next heartbeat retry.
 *
 * @param {function} fetchFn normally `window.fetch`
 * @param {string}   url
 * @param {object}   [options]
 * @param {?string}  [options.token]  device token for the Authorization header
 * @param {object}   [options.body]
 * @param {number}   [options.timeoutMs]
 */
export async function postJson(fetchFn, url, { token = null, body = {}, timeoutMs = DEFAULT_TIMEOUT_MS } = {}) {
    const abortController = new AbortController();
    const timeoutTimer = setTimeout(() => abortController.abort(), timeoutMs);
    try {
        const headers = { 'Content-Type': 'application/json' };
        if (token !== null) {
            headers.Authorization = 'Bearer ' + token;
        }
        const response = await fetchFn(url, {
            method: 'POST',
            headers,
            body: JSON.stringify(body),
            credentials: 'same-origin',
            cache: 'no-store',
            signal: abortController.signal,
        });
        const text = await response.text();
        let parsedBody = null;
        try {
            parsedBody = JSON.parse(text);
        } catch (notJson) {
            parsedBody = null;
        }
        return { status: response.status, body: parsedBody };
    } finally {
        clearTimeout(timeoutTimer);
    }
}
