// Time between two records of this device, for the "same car again too
// soon" warning. (The time a record is STORED with is decided in
// server-clock.js; this file only compares two records.)

/**
 * Milliseconds between two records of this device (positive when `later` is
 * the later one), read from the best clock the two have in common. It is
 * used for the "same car again too soon" warning, nothing else.
 *
 *   - both have a final server time: that is the truth;
 *   - both made in the same stretch of the same page load: the monotonic clock;
 *   - same page load, but the clock guard had a doubt in between (one of the
 *     two clocks jumped, and nobody can tell which): the SHORTER of the two
 *     readings. A warning shown for nothing costs a glance; a double entry
 *     that gets no warning costs a reviewer's time later;
 *   - otherwise: the system clock.
 */
export function elapsedBetween(earlier, later) {
    if (Number.isFinite(earlier.ts_ms) && Number.isFinite(later.ts_ms)) {
        return later.ts_ms - earlier.ts_ms;
    }
    const deviceMs = later.ts_device_ms - earlier.ts_device_ms;
    const samePageLoad = earlier.page_load !== undefined && earlier.page_load === later.page_load
        && Number.isFinite(earlier.mono_ms) && Number.isFinite(later.mono_ms);
    if (!samePageLoad) {
        return deviceMs;
    }
    const monoMs = later.mono_ms - earlier.mono_ms;
    if (earlier.clock_segment === later.clock_segment) {
        return monoMs;
    }
    return Math.abs(monoMs) <= Math.abs(deviceMs) ? monoMs : deviceMs;
}
