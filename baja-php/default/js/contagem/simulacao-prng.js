// The random numbers of the race simulation.
//
// Three browsers and a Node script must all make up THE SAME race from the
// same seed, so the generator cannot be Math.random (different in every
// run) and must not use fractions (a rounding difference between two
// machines would slowly turn one race into another). This one works on
// 32-bit whole numbers only: the same seed gives the same sequence
// everywhere, for ever.
//
// It is "xorshift32", a well-known generator that is three shifts and three
// exclusive-ors. It is nowhere near good enough for secrets, and more than
// good enough for deciding which counter mistypes which car.

/** Keeps a number inside 32 bits, unsigned. */
function toUint32(value) {
    return value >>> 0;
}

export class Prng {
    /** @param {number} seed any whole number; 0 is replaced, because xorshift would stay at 0 for ever */
    constructor(seed) {
        this.state = toUint32(seed) || 0x9e3779b9;
    }

    /** @returns {number} the next whole number, from 1 to 4294967295 */
    next() {
        let x = this.state;
        x = toUint32(x ^ (x << 13));
        x = toUint32(x ^ (x >>> 17));
        x = toUint32(x ^ (x << 5));
        this.state = x;
        return x;
    }

    /** @returns {number} a whole number from 0 to limit - 1 */
    below(limit) {
        return this.next() % limit;
    }

    /** @returns {number} a whole number from min to max, both included */
    between(min, max) {
        return min + this.below(max - min + 1);
    }

    /**
     * True about `perThousand` times in a thousand. Rates are given per
     * thousand, not as fractions, to keep everything in whole numbers.
     */
    chance(perThousand) {
        return this.below(1000) < perThousand;
    }

    /** @returns {*} one item of a non-empty list */
    pick(list) {
        return list[this.below(list.length)];
    }

    /** @returns {Array} a shuffled copy (Fisher-Yates) */
    shuffled(list) {
        const copy = [...list];
        for (let index = copy.length - 1; index > 0; index--) {
            const other = this.below(index + 1);
            [copy[index], copy[other]] = [copy[other], copy[index]];
        }
        return copy;
    }
}

/**
 * A second seed made from a first one and a number that tells its users
 * apart (a source id, say). Each counter gets its own stream of mistakes
 * this way, while all of them still share the race made from the first
 * seed. The mixing is the finishing step of the MurmurHash3 hash, which
 * spreads nearby inputs (source 1, 2, 3) far apart.
 */
export function deriveSeed(seed, salt) {
    let h = toUint32(toUint32(seed) ^ Math.imul(toUint32(salt) + 1, 0x9e3779b1));
    h = Math.imul(h ^ (h >>> 16), 0x85ebca6b);
    h = Math.imul(h ^ (h >>> 13), 0xc2b2ae35);
    return toUint32(h ^ (h >>> 16));
}

/**
 * `value * perThousand / 1000` in whole numbers, rounded down. Every
 * duration of the simulation is scaled with this instead of a
 * multiplication by 1.07 or the like.
 */
export function scale(value, perThousand) {
    return Math.trunc((value * perThousand) / 1000);
}
