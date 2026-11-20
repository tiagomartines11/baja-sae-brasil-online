<?php

namespace Baja\Certificado\Requerimento;

use Baja\Certificado\Token;
use Baja\Model\CertificadoRequerimento;
use Baja\Model\CertificadoRequerimentoQuery;
use Baja\Url;

/**
 * The participant's status page, /acompanhar?p={protocolo} (BAJAAPP-88).
 *
 * Before this, somebody who filed a report got a receipt and then nothing
 * until a person answered by hand, so they filed again to find out what had
 * happened. This page is where they look instead.
 *
 * The protocol is the credential. It is the row's primary key, 16 random
 * bytes from Token::generate(), so the link is as hard to guess as a
 * certificate token and there is no login: the vhost has no session, and the
 * people reading this are not users of anything. Whoever holds the link sees
 * what the participant would see, which is why the page shows so little —
 * see certificado/acompanhar.php for what it shows and what it never does.
 */
final class Acompanhamento
{
    /** The page's address, as nginx routes it. */
    public const CAMINHO = '/acompanhar';

    /**
     * The link for one report, absolute, as the emails carry it.
     *
     * The protocol goes in the query string rather than the path so that
     * nginx can redact one argument in the access log, the way it already
     * redacts `cpf=`. See baja-app-certificado.conf.template.
     */
    public static function url(CertificadoRequerimento $requerimento): string
    {
        return Url::subdomain('certificado', self::caminho((string) $requerimento->getRequerimentoId()));
    }

    /** The same link without the host, for the pages already on that vhost. */
    public static function caminho(string $protocolo): string
    {
        return self::CAMINHO . '?p=' . rawurlencode($protocolo);
    }

    /**
     * The report this protocol names, or null.
     *
     * A malformed protocol is null without asking the database, and an
     * unknown one is null after asking. The page answers both the same way,
     * so nobody can tell from outside which protocols exist.
     */
    public static function encontrar(string $protocolo): ?CertificadoRequerimento
    {
        if (!Token::isWellFormed($protocolo)) {
            return null;
        }

        return CertificadoRequerimentoQuery::create()->findPk($protocolo);
    }
}
