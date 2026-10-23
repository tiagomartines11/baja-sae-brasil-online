<?php

namespace Baja\Certificado\Requerimento;

use Baja\Certificado\Token;
use Baja\Model\CertificadoRequerimento;
use Baja\Model\CertificadoRequerimentoQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Writing one report down.
 *
 * Small on purpose. The row is a claim, not a change: nothing here touches
 * `participantes`, and the only way a certificate changes as a result of one
 * of these is a person with the `certificados` permission deciding it should
 * and doing it through the insertion pages, which keep their own audit trail.
 */
final class Gravador
{
    /**
     * How long an open report about the same certificate suppresses a new
     * notification.
     *
     * The row is always written — a second report may say something the first
     * did not, and dropping it would lose it. What this suppresses is the
     * fourth email about one certificate on the afternoon somebody's whole
     * team notices the same typo.
     */
    public const JANELA_DUPLICADA_SEGUNDOS = 86400;

    public static function gravar(Formulario $formulario): CertificadoRequerimento
    {
        $requerimento = new CertificadoRequerimento();
        $requerimento->setRequerimentoId(self::idInedito());
        $requerimento->setCaso($formulario->caso());
        $requerimento->setToken($formulario->token());
        $requerimento->setEventoId($formulario->eventoCodigo());
        $requerimento->setEventoTexto($formulario->eventoTexto());
        $requerimento->setDocumento($formulario->documento());
        $requerimento->setNome($formulario->nome());
        $requerimento->setEmail($formulario->email());
        $requerimento->setTelefone($formulario->telefone());
        $requerimento->setDescricao($formulario->descricao());
        $requerimento->setCriadoEm(new \DateTime());
        $requerimento->setStatus('aberto');
        $requerimento->save();

        return $requerimento;
    }

    /** Record that the notification for this report was accepted by the relay. */
    public static function marcarAvisado(CertificadoRequerimento $requerimento): void
    {
        $requerimento->setAvisadoEm(new \DateTime());
        $requerimento->save();
    }

    /**
     * Whether an open report about the same certificate arrived recently.
     *
     * Only for the cases that carry a token; two people reporting that
     * different certificates are missing are two reports and always were.
     */
    public static function jaAvisadoRecentemente(CertificadoRequerimento $requerimento): bool
    {
        $token = $requerimento->getToken();

        if ($token === null || $token === '') {
            return false;
        }

        $desde = new \DateTime('-' . self::JANELA_DUPLICADA_SEGUNDOS . ' seconds');

        return CertificadoRequerimentoQuery::create()
            ->filterByToken($token)
            ->filterByRequerimentoId($requerimento->getRequerimentoId(), Criteria::NOT_EQUAL)
            ->filterByStatus('aberto')
            ->filterByCriadoEm($desde, Criteria::GREATER_EQUAL)
            ->exists();
    }

    /**
     * A report id that is not already taken.
     *
     * 128 bits of randomness makes a collision a theoretical concern rather
     * than a practical one, but the id is a primary key and a duplicate would
     * surface as an exception on a page that has already told somebody their
     * report was received. Same shape as Insercao\Gravador's lote ids.
     */
    private static function idInedito(): string
    {
        for ($tentativa = 0; $tentativa < 5; $tentativa++) {
            $id = Token::generate();

            if (!CertificadoRequerimentoQuery::create()->filterByRequerimentoId($id)->exists()) {
                return $id;
            }
        }

        throw new \RuntimeException('Could not generate an unused report id.');
    }
}
