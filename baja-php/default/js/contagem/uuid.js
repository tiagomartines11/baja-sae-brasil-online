// Generates the identifier every entry carries from the moment it is created.
//
// The identifier is what makes uploads safe to repeat: the server stores an
// entry only once per uuid, so the browser may send the same entry as many
// times as it takes to get an answer back.

const HEX_DIGITS = '0123456789abcdef';

/**
 * @param {Crypto|undefined} cryptoSource normally the browser's `crypto`;
 *        passed in so tests can force each fallback path.
 * @returns {string} a version 4 UUID such as "3f2c1e9a-7b1d-4c55-9e0f-8a2b6c4d1e7f"
 */
export function generateUuid(cryptoSource = globalThis.crypto) {
    // randomUUID only exists on secure pages (https or localhost). The dev
    // setup is served over plain http, so the fallbacks below are not dead code.
    if (cryptoSource && typeof cryptoSource.randomUUID === 'function') {
        return cryptoSource.randomUUID();
    }

    const bytes = new Uint8Array(16);
    if (cryptoSource && typeof cryptoSource.getRandomValues === 'function') {
        cryptoSource.getRandomValues(bytes);
    } else {
        for (let index = 0; index < bytes.length; index++) {
            bytes[index] = Math.floor(Math.random() * 256);
        }
    }

    // Version and variant bits, as RFC 4122 requires for a random UUID.
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    let text = '';
    for (let index = 0; index < bytes.length; index++) {
        if (index === 4 || index === 6 || index === 8 || index === 10) {
            text += '-';
        }
        text += HEX_DIGITS[bytes[index] >> 4] + HEX_DIGITS[bytes[index] & 0x0f];
    }
    return text;
}
