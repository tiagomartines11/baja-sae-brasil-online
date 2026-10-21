<?php

namespace Baja\Certificado;

use Baja\Util\Env;

/**
 * Settings for the public certificate pages.
 */
final class Config
{
    /*
     * There is deliberately no CONTACT_EMAIL here any more.
     *
     * These pages used to print comite@bajasaebrasil.net as the way to report
     * a wrong certificate. /requerimento replaced it, and the address was removed
     * rather than kept beside it: two channels means two queues, and the
     * unstructured one — no protocol number, no status, no record of who
     * answered, no retention policy, and a CPF sitting in a mailbox forever —
     * is the one people pick, because it asks nothing of them.
     *
     * What replaces it as the reply-able end of the conversation is the
     * confirmation /requerimento sends, so SMTP_FROM_EMAIL has to be a mailbox
     * somebody reads. See Baja\Certificado\Requerimento\Aviso.
     */

    /**
     * Whether the log line about a rejected PRIVACY_NOTICE_URL has gone out.
     *
     * Once per process, not once per call: the footer is rendered on every
     * page, and a misconfiguration that writes a line per page view buries
     * everything else in the log without saying anything new.
     */
    private static bool $privacyNoticeUrlRejected = false;

    /**
     * Where the Aviso de Privacidade lives, or '' when there is nothing to link.
     *
     * Read from the PRIVACY_NOTICE_URL environment variable, which
     * baja-infra/docker-compose.yml passes through and .env.example documents.
     * It is not written here because the notice is SAE BRASIL's document,
     * published on SAE BRASIL's own site: where it lives is a fact about the
     * deployment, not about this code. Staging and production each point at
     * the published notice, a dev stack usually has nothing set, and moving
     * the document should not need a release.
     *
     * Empty means no link. The three places that print it — the footer of
     * every certificate page, the /requerimento form and the confirmation
     * email — say nothing about a notice rather than link to a 404.
     *
     * Only an absolute https:// URL is accepted. Anything else is treated as
     * empty, and the log says so once per process. This value lands in an
     * href on a public page and in the body of an email sent to an address
     * nobody has verified, so a wrong value is not a broken link: a
     * javascript: scheme is script running on the certificate pages, a
     * relative path is a link into this site that goes nowhere, and a plain
     * http:// URL sends a participant to a privacy notice over a connection
     * anyone on the path can rewrite. Dropping the link is the failure that
     * costs nothing, and the log line is how the mistake gets found.
     */
    public static function privacyNoticeUrl(): string
    {
        $url = trim((string) Env::get('PRIVACY_NOTICE_URL', ''));

        if ($url === '') {
            return '';
        }

        if (self::isAbsoluteHttpsUrl($url)) {
            return $url;
        }

        if (!self::$privacyNoticeUrlRejected) {
            self::$privacyNoticeUrlRejected = true;
            error_log(
                'Certificado\Config: PRIVACY_NOTICE_URL is not an absolute https:// URL; '
                . 'no Aviso de Privacidade will be linked. Value: ' . $url
            );
        }

        return '';
    }

    /**
     * Whether a value is a URL with an https scheme and a host.
     *
     * filter_var alone is not enough: it accepts any scheme it knows, and
     * http:// is one of them.
     */
    private static function isAbsoluteHttpsUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * The one message every failure returns.
     *
     * Identical for "no such document", "document found but no row's name
     * matches", and "no such token", on purpose. Any difference between those
     * answers turns the form into an oracle that confirms whether a given
     * person competed, which is itself personal data.
     */
    public const FAILURE_MESSAGE = 'Não foi possível localizar um certificado com os dados informados.';

    /**
     * Shown once a document has been tried and failed too many times.
     *
     * A third state rather than a variation on the message above, and it does
     * not reopen the oracle that one exists to close: failures are counted for
     * any value submitted, so an invented CPF reaches this state after five
     * attempts exactly as a real one does. It reports the caller's own recent
     * history, which they already know.
     *
     * Saying nothing was worse than a small disclosure here. Somebody who
     * mistypes their name a few times, works out the right spelling and is
     * then told their certificate does not exist has been given a false
     * answer, with no way to tell that waiting would fix it.
     */
    public const THROTTLED_MESSAGE = 'Muitas tentativas com este documento. Aguarde %s e tente novamente.';
}
