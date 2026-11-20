<?php

namespace Baja\Certificado\Requerimento;

use Baja\Model\CertificadoRequerimento;

/**
 * What Gravador::registrar() did with one submission.
 *
 * Four outcomes, and they differ in exactly three things: whether a row was
 * written, whether the person gets a receipt, and whether staff get an alert.
 *
 *                              row    receipt  staff alert
 *   NOVO                       yes    yes      yes
 *   CERTIFICADO_JA_REPORTADO   yes    yes      no
 *   DUPLICADO                  yes    yes      no
 *   REPETIDO                   no     no       no
 *
 * REPETIDO is the button pressed twice: the same report, from the same
 * address, while the first is still open. Sending a second receipt would only
 * mean that repeated presses fill somebody's inbox.
 *
 * DUPLICADO is the same report from a different address. The new address may
 * be the one that works, so the row is kept and points at the original
 * (`duplicado_de`), and its sender gets a receipt with their own protocol.
 *
 * CERTIFICADO_JA_REPORTADO is a new report about a certificate that already
 * has an open report from the last day: another document reporting it, or
 * the same person reporting it under the other case. A separate row, not
 * linked, since it is not the same request; staff decide what it is. It is
 * what Gravador::jaAvisadoRecentemente() used to catch, before BAJAAPP-89
 * replaced it.
 */
final class Registro
{
    public const NOVO                     = 'novo';
    public const CERTIFICADO_JA_REPORTADO = 'certificado_ja_reportado';
    public const DUPLICADO                = 'duplicado';
    public const REPETIDO                 = 'repetido';

    /**
     * @param CertificadoRequerimento $requerimento the row written; for
     *        REPETIDO, the open one this submission repeats
     */
    public function __construct(
        public readonly string $situacao,
        public readonly CertificadoRequerimento $requerimento
    ) {
    }

    /** Whether a new row was written. */
    public function gravou(): bool
    {
        return $this->situacao !== self::REPETIDO;
    }

    /** Whether staff should be told about this one. */
    public function avisarEquipe(): bool
    {
        return $this->situacao === self::NOVO;
    }

    /**
     * The protocol to show the person who submitted.
     *
     * Always the row that belongs to them: their new row, or, for REPETIDO,
     * the one they already filed from this same address. Never the original
     * a DUPLICADO points at — that protocol belongs to whoever sent it, and
     * this page has no idea who is reading it.
     */
    public function protocolo(): string
    {
        return (string) $this->requerimento->getRequerimentoId();
    }
}
