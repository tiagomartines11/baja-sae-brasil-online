// Gives held records their final time.
//
// A record is HELD when it was typed while this page had no anchor it could
// trust (none yet, or one in doubt; see server-clock.js): its `ts_ms` is null
// and it is not uploaded. There are two
// ways out, and each record takes exactly one of them, once:
//
//   - the page gets an anchor in the SAME page load: the time is worked out
//     from the monotonic clock and is exact (brief 6.3);
//   - the page was reloaded first: the monotonic clock of that page load no
//     longer exists, so the time is estimated from the system clock, and the
//     record says so, for the reviewer (brief 6.4).

/**
 * Called once, when the page starts, before anything is uploaded.
 * @returns {Promise<number>} how many records were given an estimated or unknown time
 */
export async function resolveRecordsOfEarlierLoads(store, clock) {
    let resolved = 0;
    for (const { collection, record } of store.heldRecords()) {
        if (record.page_load === clock.loadId) {
            continue;
        }
        await store.setFinalTime(collection, record.uuid, clock.resolveEarlierLoad(record));
        resolved++;
    }
    return resolved;
}

/**
 * Called before every request and after every answer. Does nothing while the
 * page has no anchor, or has one that is in doubt (see ServerClock.checkMonotonic).
 * @returns {Promise<number>} how many records got their time just now
 */
export async function resolveHeldRecords(store, clock) {
    if (!clock.canStamp()) {
        return 0;
    }
    let resolved = 0;
    for (const { collection, record } of store.heldRecords()) {
        const time = clock.isHeldHere(record) ? clock.resolveHeld(record) : clock.resolveEarlierLoad(record);
        if (time === null) {
            continue;
        }
        await store.setFinalTime(collection, record.uuid, time);
        resolved++;
    }
    return resolved;
}
