<?php

namespace Baja\Util;

/**
 * Cloudflare Turnstile, the human check on /requerimento.
 *
 * Turnstile rather than reCAPTCHA, which the repository already has a dead
 * `RECAPTCHA_KEY` for. Three reasons, in order of weight:
 *
 *  - The public certificate pages carry no analytics and set no non-essential
 *    cookie, which is why they carry no consent banner either — see
 *    Baja\Certificado\Template. reCAPTCHA sets cookies and would make that
 *    false, and the fix would be a banner on the page a participant reaches
 *    when something about their own certificate is wrong.
 *  - reCAPTCHA is an international transfer of personal data to a new
 *    controller (LGPD Art. 33). Cloudflare is already in front of every
 *    request to this stack — see 00-cloudflare-realip.conf — so Turnstile
 *    adds no recipient that was not already in the path.
 *  - Turnstile is usually invisible, and the people filling this form in are
 *    already annoyed.
 *
 * Unconfigured, this reports itself as disabled rather than failing closed.
 * Failing closed would mean a missing environment variable takes down the
 * only structured channel a person has for getting their own certificate
 * corrected — an LGPD Art. 18 problem traded for a spam one. Instead the
 * page still works, the absence is logged on every render, and the
 * notification email says the submission was not verified, so staff can see
 * from their inbox that something is misconfigured.
 */
final class Turnstile
{
    /** The form field the widget writes its token into. */
    public const CAMPO = 'cf-turnstile-response';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** The widget script. Named here so the page and the CSP cannot disagree. */
    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private const TIMEOUT_SECONDS = 5;

    /**
     * Whether both keys are present.
     *
     * Both, not either: a site key with no secret renders a widget nothing
     * checks, which is worse than no widget at all because it looks like a
     * control.
     */
    public static function habilitado(): bool
    {
        return self::siteKey() !== '' && self::secretKey() !== '';
    }

    public static function siteKey(): string
    {
        return (string) Env::get('TURNSTILE_SITE_KEY', '');
    }

    private static function secretKey(): string
    {
        return (string) Env::get('TURNSTILE_SECRET_KEY', '');
    }

    /**
     * Whether this submission carries a token Cloudflare accepts.
     *
     * Returns true when Turnstile is not configured, because in that case
     * there is nothing to verify and the caller has already been told so by
     * habilitado(). It never returns true on a failed check.
     *
     * A network failure reaching Cloudflare returns false. That is the one
     * place this fails closed, and deliberately: an unreachable verifier is
     * indistinguishable from a verifier being bypassed, the person can retry,
     * and the alternative is that anyone who can black-hole one outbound
     * request gets an unguarded form.
     */
    public static function verificar(string $token, string $remoteIp = ''): bool
    {
        if (!self::habilitado()) {
            return true;
        }

        if ($token === '') {
            return false;
        }

        $campos = [
            'secret'   => self::secretKey(),
            'response' => $token,
        ];

        // Optional, and only sent when nginx has given us something that
        // looks like an address. Behind Cloudflare $remote_addr is the real
        // client — 00-cloudflare-realip.conf restores it — but on a direct
        // origin it may be a proxy, and a wrong address makes Cloudflare
        // reject a legitimate token.
        if ($remoteIp !== '' && filter_var($remoteIp, FILTER_VALIDATE_IP) !== false) {
            $campos['remoteip'] = $remoteIp;
        }

        $ch = curl_init(self::VERIFY_URL);

        if ($ch === false) {
            error_log('Turnstile: curl_init failed; refusing the submission.');

            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($campos),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $corpo  = curl_exec($ch);
        $erro   = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($corpo === false || $status !== 200) {
            error_log(sprintf(
                'Turnstile: verification unreachable (HTTP %d%s); refusing the submission.',
                $status,
                $erro === '' ? '' : ', ' . $erro
            ));

            return false;
        }

        $dados = json_decode((string) $corpo, true);

        if (!is_array($dados)) {
            error_log('Turnstile: verification returned unparseable JSON; refusing the submission.');

            return false;
        }

        if (($dados['success'] ?? false) === true) {
            return true;
        }

        // The error codes name a configuration problem as often as a bot —
        // invalid-input-secret is the one worth finding in a log. No user
        // input is logged, only Cloudflare's own codes.
        $codigos = $dados['error-codes'] ?? [];
        error_log('Turnstile: verification failed: ' . implode(',', is_array($codigos) ? $codigos : []));

        return false;
    }
}
