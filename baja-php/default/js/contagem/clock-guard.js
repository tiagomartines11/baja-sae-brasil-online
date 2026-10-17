// The page's clock guard as a timer sees it. (What the guard looks for, and
// what a doubt means, is in server-clock.js.)

/**
 * What the page's once-a-second timer does (and what it does when the page
 * comes back to the front): lets the clock compare its two clocks, and when
 * a doubt about the anchor arose just now, asks the server at once, since
 * only its answer can settle it.
 *
 * @param {object} clock ServerClock
 * @param {object} queue SyncQueue
 * @returns {boolean} whether a doubt arose just now
 */
export function runClockGuard(clock, queue) {
    const doubtArose = clock.checkMonotonic();
    if (doubtArose) {
        queue.requestSync();
    }
    return doubtArose;
}
