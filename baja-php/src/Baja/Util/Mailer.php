<?php

namespace Baja\Util;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Outbound mail for baja-app, over SMTP.
 *
 * SMTP and nothing else. The application image is php:8.3-fpm-alpine, which
 * has no sendmail binary, so mail() has never worked here and would fail
 * silently if anything called it — the failure mode where a form says "we
 * have received your report" and nobody ever does.
 *
 * Separate from phpBB's mailer, which already queues and sends. Reusing it
 * would couple the certificate vhost to the forum, and the certificate vhost
 * is deliberately uncoupled: it runs with SKIP_AUTH so a lookup does not pay
 * for a forum session, on the principle that certificate availability must
 * not depend on the forum being up. A report is part of that surface.
 *
 * Plain text only. There is no HTML body and no attachments, because nothing
 * this sends benefits from either and both are how a mail path grows a
 * rendering bug or an injection surface.
 *
 * Env vars, all required together (see .env.example):
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS
 *   SMTP_SECURITY   tls | ssl | none   (default tls)
 *   SMTP_FROM_EMAIL, SMTP_FROM_NAME
 *
 * SMTP_FROM_EMAIL has to be an address the relay is authorised to send as,
 * or SPF and DMARC will bin the message at the recipient. That is a DNS
 * question, not a code one, and it is the thing most likely to be wrong the
 * first time this is switched on.
 */
final class Mailer
{
    private const TIMEOUT_SECONDS = 15;

    /**
     * Whether enough is configured to attempt a send.
     *
     * Callers check this before doing work they would have to undo. Nothing
     * here falls back to a default: a plausible-but-wrong SMTP host produces
     * a silent non-delivery, which is worse than a refusal.
     */
    public static function configurado(): bool
    {
        foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_FROM_EMAIL'] as $chave) {
            if (!Env::has($chave)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Send one plain-text message.
     *
     * @param array<int, string> $para       recipient addresses, already validated
     * @param array<int, string> $responderA Reply-To addresses, optional
     *
     * @return bool whether the relay accepted it. False is logged here; the
     *              caller decides what to tell the user.
     */
    public static function enviar(
        array $para,
        string $assunto,
        string $corpo,
        array $responderA = [],
        string $nomeResposta = ''
    ): bool {
        $para = array_values(array_filter($para, static fn (string $e): bool => self::enderecoValido($e)));

        if ($para === []) {
            error_log('Mailer: no valid recipient; nothing sent.');

            return false;
        }

        if (!self::configurado()) {
            error_log('Mailer: SMTP is not configured (SMTP_HOST/SMTP_PORT/SMTP_FROM_EMAIL); nothing sent.');

            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host    = (string) Env::get('SMTP_HOST');
            $mail->Port    = (int) Env::getInt('SMTP_PORT', 587);
            $mail->Timeout = self::TIMEOUT_SECONDS;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            // A relay on a private network may legitimately take no
            // credentials; an empty user means "do not authenticate" rather
            // than "authenticate as nobody".
            $usuario = (string) Env::get('SMTP_USER', '');
            if ($usuario !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $usuario;
                $mail->Password = (string) Env::get('SMTP_PASS', '');
            }

            switch (strtolower((string) Env::get('SMTP_SECURITY', 'tls'))) {
                case 'ssl':
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    break;
                case 'none':
                    // Only defensible for a relay on the same private network.
                    // LGPD Art. 46 asks for the transfer to be protected, and
                    // these messages carry a participant's name and document.
                    $mail->SMTPSecure = '';
                    $mail->SMTPAutoTLS = false;
                    break;
                default:
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom(
                (string) Env::get('SMTP_FROM_EMAIL'),
                self::cabecalhoLimpo((string) Env::get('SMTP_FROM_NAME', 'SAE BRASIL'))
            );

            foreach ($para as $endereco) {
                $mail->addAddress($endereco);
            }

            foreach ($responderA as $endereco) {
                if (self::enderecoValido($endereco)) {
                    $mail->addReplyTo($endereco, self::cabecalhoLimpo($nomeResposta));
                }
            }

            $mail->Subject = self::cabecalhoLimpo($assunto);
            $mail->isHTML(false);
            $mail->Body = $corpo;

            $mail->send();

            return true;
        } catch (PHPMailerException|\Throwable $e) {
            // Never the recipient addresses and never the body: this log is
            // read by whoever is debugging the relay, not by whoever is
            // entitled to the contents.
            error_log('Mailer: send failed: ' . $e->getMessage());

            return false;
        }
    }

    public static function enderecoValido(string $endereco): bool
    {
        if ($endereco === '' || strlen($endereco) > 254) {
            return false;
        }

        // A CR or LF in an address is header injection, and it reaches here
        // from a public form. PHPMailer rejects these too; checking first
        // means a malformed value is a validation message rather than an
        // exception in a log.
        if (preg_match('/[\r\n]/', $endereco) === 1) {
            return false;
        }

        return filter_var($endereco, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * A value safe to put in a header.
     *
     * Line breaks removed rather than escaped. Nothing that legitimately
     * belongs in a subject line or a display name contains one.
     */
    private static function cabecalhoLimpo(string $valor): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $valor));
    }
}
