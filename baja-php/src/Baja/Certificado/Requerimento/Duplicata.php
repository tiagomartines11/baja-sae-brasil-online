<?php

namespace Baja\Certificado\Requerimento;

use Baja\Model\CertificadoRequerimento;
use Baja\Model\CertificadoRequerimentoQuery;
use Baja\Util\Env;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * When two reports are the same report.
 *
 * BAJAAPP-89: one student pressed "Enviar" six times in seven seconds, the
 * page took six seconds to answer each time, and staff got six alerts about
 * one missing certificate. The rule here is what lets Gravador::registrar()
 * recognise the second press as the first one again.
 *
 * Two reports are the same when all of these match:
 *
 *  - the document, as Formulario stores it (trimmed, otherwise as typed).
 *    The column's collation already ignores case, so "ab123" and "AB123" are
 *    one passport;
 *  - the case — "this certificate is wrong" and "this certificate should not
 *    exist" are two different requests even about one certificate;
 *  - the subject: the certificate's token when the case names one; otherwise
 *    the event picked from the list; otherwise the event typed by hand,
 *    compared without case and without surrounding spaces;
 *  - and the earlier one is still open. Once staff have closed a report, a
 *    new one is a new request ("you said you fixed it and it is still
 *    wrong") and has to reach somebody.
 *
 * What it deliberately does not compare: the name, the phone and the
 * description. The second press of a button carries the same text as the
 * first, but somebody who comes back an hour later to add a sentence is still
 * reporting the same thing, and should be told so rather than open a second
 * case.
 */
final class Duplicata
{
    /** The statuses that still count as somebody's open request. */
    public const ABERTOS = ['aberto', 'em_analise'];

    /**
     * How long an open report about a certificate keeps quiet the staff alert
     * for a different report about the same certificate.
     *
     * The behaviour the old Gravador::jaAvisadoRecentemente() had, kept:
     * a whole team noticing one typo on one afternoon is one alert. The row is
     * still written, unlinked — it is a different person, or the same person
     * with a different spelling of their document, and staff decide which.
     */
    public const JANELA_MESMO_CERTIFICADO_SEGUNDOS = 86400;

    /**
     * Every open report this submission repeats, oldest first.
     *
     * Usually none or one. More than one happens when the same report came in
     * from two addresses: the second was kept as a duplicate of the first,
     * and both are open.
     *
     * @return CertificadoRequerimento[]
     */
    public static function abertas(Formulario $formulario): array
    {
        $consulta = CertificadoRequerimentoQuery::create()
            ->filterByDocumento($formulario->documento())
            ->filterByStatus(self::ABERTOS, Criteria::IN)
            ->filterByCaso($formulario->caso());

        $token  = $formulario->token();
        $evento = $formulario->eventoCodigo();

        if ($token !== null) {
            $consulta->filterByToken($token);
        } elseif ($evento !== null) {
            $consulta->filterByToken(null, Criteria::ISNULL)->filterByEventoId($evento);
        } else {
            $consulta->filterByToken(null, Criteria::ISNULL)->filterByEventoId(null, Criteria::ISNULL);
        }

        $abertas = [];

        foreach ($consulta->orderByCriadoEm(Criteria::ASC)->orderByRequerimentoId()->find() as $existente) {
            // The typed event is compared here, in PHP, rather than in the
            // WHERE: the column's collation is also accent-insensitive, and
            // "São Carlos 2009" and "Sao Carlos 2009" being one report is a
            // decision this rule should make on purpose, not inherit.
            if ($token === null && $evento === null
                && self::textoDoEvento($existente->getEventoTexto()) !== self::textoDoEvento($formulario->eventoTexto())) {
                continue;
            }

            $abertas[] = $existente;
        }

        return $abertas;
    }

    /**
     * Of the open matches, the one a new duplicate should point at.
     *
     * The earliest that is itself an original, so that the staff page shows
     * one original with its duplicates under it rather than a chain. Failing
     * that (the original was closed and only its duplicates are still open),
     * the earliest open one.
     *
     * @param CertificadoRequerimento[] $abertas as abertas() returns them
     */
    public static function original(array $abertas): ?CertificadoRequerimento
    {
        foreach ($abertas as $existente) {
            if ($existente->getDuplicadoDe() === null) {
                return $existente;
            }
        }

        return $abertas[0] ?? null;
    }

    /**
     * Of the open matches, the one already filed from this email address.
     *
     * Compared without case, the way Limite counts addresses: "Ana@x.com"
     * and "ana@x.com" are one inbox.
     *
     * @param CertificadoRequerimento[] $abertas as abertas() returns them
     */
    public static function doMesmoEmail(array $abertas, string $email): ?CertificadoRequerimento
    {
        $email = mb_strtolower($email, 'UTF-8');

        foreach ($abertas as $existente) {
            if (mb_strtolower((string) $existente->getEmail(), 'UTF-8') === $email) {
                return $existente;
            }
        }

        return null;
    }

    /**
     * Whether the same certificate already has an open report from the last day.
     *
     * Asked only when abertas() found nothing, so whatever this finds is a
     * different request about the same certificate: another document, or the
     * other case. Only for the cases that name a certificate; two people
     * reporting that different certificates are missing are two reports and
     * always were. See JANELA_MESMO_CERTIFICADO_SEGUNDOS.
     */
    public static function certificadoJaReportado(Formulario $formulario): bool
    {
        $token = $formulario->token();

        if ($token === null) {
            return false;
        }

        return CertificadoRequerimentoQuery::create()
            ->filterByToken($token)
            ->filterByStatus(self::ABERTOS, Criteria::IN)
            ->filterByCriadoEm(new \DateTime('-' . self::JANELA_MESMO_CERTIFICADO_SEGUNDOS . ' seconds'), Criteria::GREATER_EQUAL)
            ->exists();
    }

    /**
     * The name of the MySQL lock that serialises submissions of one report.
     *
     * Built from the same fields abertas() compares, so that two submissions
     * that would match each other always wait for the same lock, and two that
     * would not never wait for each other.
     *
     * A keyed digest, never the fields themselves: lock names are visible to
     * anybody who can read the server's process list, and the document is a
     * CPF. Keyed for the reason Limite::chave() gives — an unkeyed sha256 of
     * an 11-digit space is reversible by lookup. MySQL caps a lock name at 64
     * characters, hence the truncation; 192 bits is still far more than two
     * different reports will ever collide in.
     */
    public static function nomeDaTrava(Formulario $formulario): string
    {
        $campos = implode("\n", [
            mb_strtolower($formulario->documento(), 'UTF-8'),
            $formulario->caso(),
            self::assunto($formulario),
        ]);

        $secret = Env::get('CERT_BACKOFF_SECRET');

        $digest = $secret
            ? hash_hmac('sha256', $campos, (string) $secret)
            : hash('sha256', $campos);

        return 'cert_req:' . substr($digest, 0, 48);
    }

    /** What the report is about, as one comparable string. */
    private static function assunto(Formulario $formulario): string
    {
        if ($formulario->token() !== null) {
            return 'token:' . $formulario->token();
        }

        if ($formulario->eventoCodigo() !== null) {
            return 'evento:' . $formulario->eventoCodigo();
        }

        return 'texto:' . self::textoDoEvento($formulario->eventoTexto());
    }

    /** A typed event name, the way two of them are compared. */
    private static function textoDoEvento(?string $texto): string
    {
        return mb_strtolower(trim((string) $texto), 'UTF-8');
    }
}
