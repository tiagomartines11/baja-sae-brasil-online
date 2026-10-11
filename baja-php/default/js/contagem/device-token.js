// Gets and remembers this browser's upload token.
//
// The token is issued once by contagem_dispositivo.php (which needs a logged-in
// user allowed on the source) and then lives in the local store. From then on
// uploads work with the token alone: an expired login session, or a page
// reloaded with no network, does not stop the counter.

const META_DEVICE = 'dispositivo';
const META_REVOKED = 'token_revogado';

export class TokenUnavailableError extends Error {
    /** @param {string} motivo 'sem_permissao' | 'sem_sessao' | 'sem_rede' */
    constructor(motivo) {
        super('Device token unavailable: ' + motivo);
        this.motivo = motivo;
    }
}

/**
 * Returns the stored token, asking the server for one when there is none, when
 * the stored one was revoked, or when it belongs to a different user.
 *
 * @param {object}   options
 * @param {object}   options.store        LocalStore
 * @param {function} options.requestToken async () => {status, body}; throws when the network fails
 * @param {?string}  options.usuario      who is logged in according to the page
 * @returns {Promise<{token: string, issuedNow: boolean}>}
 * @throws {TokenUnavailableError}
 */
export async function obtainDeviceToken({ store, requestToken, usuario }) {
    const stored = store.getMeta(META_DEVICE, null);
    const revoked = store.getMeta(META_REVOKED, false);
    const storedIsUsable = stored !== null && !revoked;

    if (storedIsUsable && (usuario === null || stored.usuario === usuario)) {
        return { token: stored.token, issuedNow: false };
    }

    let response;
    try {
        response = await requestToken();
    } catch (networkError) {
        return fallBackToStoredToken(stored, storedIsUsable, 'sem_rede');
    }

    if (response.status === 403) {
        // A clear "no" from the server. A token of another user must not be
        // used in this person's name, so there is no fallback here.
        throw new TokenUnavailableError('sem_permissao');
    }
    if (response.status !== 200 || !response.body || typeof response.body.token !== 'string') {
        // Typically the login page arriving instead of JSON: session expired.
        return fallBackToStoredToken(stored, storedIsUsable, 'sem_sessao');
    }

    await store.setMeta(META_DEVICE, { token: response.body.token, usuario });
    return { token: response.body.token, issuedNow: true };
}

/**
 * The token on disk, when it may still be used, without asking anybody.
 * For the one moment the page cannot wait for an answer: while it closes.
 * @returns {?string}
 */
export function storedUsableToken(store) {
    const stored = store.getMeta(META_DEVICE, null);
    const revoked = store.getMeta(META_REVOKED, false);
    return stored !== null && !revoked && typeof stored.token === 'string' ? stored.token : null;
}

/**
 * Handover on a shared phone with no network: the entries still have to reach
 * the server, so the previous (still valid) token keeps being used until a
 * new one can be issued.
 */
function fallBackToStoredToken(stored, storedIsUsable, motivo) {
    if (storedIsUsable) {
        return { token: stored.token, issuedNow: false };
    }
    throw new TokenUnavailableError(motivo);
}
