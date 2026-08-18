<?php

namespace Baja\Certificado;

use Baja\Model\Evento;
use Baja\Model\Participante;
use Baja\Model\ParticipanteQuery;
use Baja\Url;

/**
 * One certificate: a participantes row and the event it belongs to.
 *
 * Wraps the pair so that the verification page and the PDF describe the same
 * participation in the same words. They used to be one file, and the page did
 * not exist.
 */
final class Certificado
{
    private function __construct(
        private Participante $participante,
        private Evento $evento
    ) {
    }

    /**
     * Resolve a certificate from its public token, or null.
     *
     * The syntactic check comes first so that junk never reaches a query. Null
     * covers every failure — malformed token, no such token, a row whose event
     * has gone — because callers must not be able to tell those apart.
     */
    public static function fromToken(string $token): ?self
    {
        if (!Token::isWellFormed($token)) {
            return null;
        }

        $participante = ParticipanteQuery::create()
            ->filterByToken($token)
            ->findOne();

        if ($participante === null) {
            return null;
        }

        $evento = $participante->getEvento();

        return $evento === null ? null : new self($participante, $evento);
    }

    public static function fromParticipante(Participante $participante): ?self
    {
        $evento = $participante->getEvento();

        return $evento === null ? null : new self($participante, $evento);
    }

    /**
     * The participant's name as recorded on this row, and no other.
     *
     * The same person may be on file under a different spelling for a
     * different event. Showing what this row says is what lets the person
     * looking at it notice that it is wrong and ask for a correction.
     */
    public function getNome(): string
    {
        return trim((string) $this->participante->getNome());
    }

    public function getFuncao(): string
    {
        return (string) $this->participante->getFuncao();
    }

    public function getToken(): string
    {
        return (string) $this->participante->getToken();
    }

    public function getEvento(): Evento
    {
        return $this->evento;
    }

    public function getEventoNome(): string
    {
        return self::decodeEntities((string) $this->evento->getNome());
    }

    public function getLocal(): string
    {
        return self::decodeEntities((string) $this->evento->getLocal());
    }

    public function getData(): string
    {
        return self::decodeEntities((string) $this->evento->getData());
    }

    /**
     * Event names carry HTML entities as literal text — "Baja SAE&nbsp;BRASIL"
     * is stored with those six characters in it.
     *
     * That went unnoticed because the only consumer was the PDF template,
     * which interpolated the value into HTML without escaping, so dompdf
     * resolved the entity. The web pages escape their output, as they must,
     * which would render the entity visibly. Decoding first and escaping after
     * keeps both correct and is not a way around the escaping.
     */
    private static function decodeEntities(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Public URL of the verification page. Printed on the certificate. */
    public function getVerificationUrl(): string
    {
        return Url::subdomain('certificado', '/verificar/' . $this->getToken());
    }

    public function getPdfUrl(): string
    {
        return Url::subdomain('certificado', '/verificar/' . $this->getToken() . '/pdf');
    }

    /** Filename offered for the download. The token, never the document. */
    public function getPdfFilename(): string
    {
        return 'certificado_' . $this->getToken() . '.pdf';
    }

    /**
     * How this role is named to a reader.
     *
     * Delegated to Funcao, which is the only place the mapping lives. It used
     * to be a switch here, beside a second switch in getTexto() that had to
     * agree with it — and the pair had already drifted: `fiscal` was in
     * neither, so a fiscal's certificate rendered with no role and no body
     * sentence at all.
     */
    public function getFuncaoLabel(): string
    {
        return Funcao::label($this->getFuncao());
    }

    /**
     * The body sentence of the certificate.
     */
    public function getTexto(): string
    {
        $cabecalho = '<b>' . $this->getEventoNome() . '</b>, ' . $this->getLocal()
            . ', no período de <b>' . $this->getData() . '</b>';

        return Funcao::texto($this->getFuncao(), $cabecalho, $this->evento->getCargaHoraria());
    }
}
