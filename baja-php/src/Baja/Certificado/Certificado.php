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
        return (string) $this->evento->getNome();
    }

    public function getLocal(): string
    {
        return (string) $this->evento->getLocal();
    }

    public function getData(): string
    {
        return (string) $this->evento->getData();
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
     * Kept beside getTexto() because the two must not drift: a page that calls
     * someone a juiz above a sentence describing voluntary work as a
     * comissário is worse than either alone.
     */
    public function getFuncaoLabel(): string
    {
        return match ($this->getFuncao()) {
            'competidor'  => 'Competidor',
            'comissario'  => 'Comissário',
            'juiz'        => 'Juiz',
            'comite'      => 'Comissão Técnica',
            'engenheiro'  => 'Engenheiro',
            'orientador'  => 'Professor Orientador',
            'assessor'    => 'Assessor Técnico',
            default       => '',
        };
    }

    /**
     * The body sentence of the certificate.
     *
     * Moved verbatim from certificado.php, including the <b> markup, which the
     * PDF template inlines. The wording varies by role and is not ours to
     * change — a COMISSÃO TÉCNICA certificate reads "Realizou trabalho
     * voluntário na organização da…", which is not what a competitor's says.
     */
    public function getTexto(): string
    {
        $cabecalho = '<b>' . $this->getEventoNome() . '</b>, ' . $this->getLocal()
            . ', no período de <b>' . $this->getData() . '</b>';

        $cargaHoraria = $this->evento->getCargaHoraria();

        switch ($this->getFuncao()) {
            case 'competidor':
                return 'Participou da ' . $cabecalho . ($cargaHoraria
                    ? ', com carga horária de ' . (string) $cargaHoraria . ' horas.'
                    : '.');
            case 'comissario':
                return 'Realizou trabalho voluntário na organização da ' . $cabecalho . ' na função de <b>COMISSÁRIO</b>.';
            case 'juiz':
                return 'Realizou trabalho voluntário na organização da ' . $cabecalho . ' na função de <b>JUIZ</b>.';
            case 'comite':
                return 'Realizou trabalho voluntário na organização da ' . $cabecalho . ' na função de <b>COMISSÃO TÉCNICA</b>.';
            case 'engenheiro':
                return 'Realizou trabalho voluntário na organização da ' . $cabecalho . ' na função de <b>ENGENHEIRO</b>.';
            case 'orientador':
                return 'Participou da ' . $cabecalho . ' na função de <b>PROFESSOR ORIENTADOR</b>.';
            case 'assessor':
                return 'Realizou trabalho voluntário na organização da ' . $cabecalho . ' na função de <b>ASSESSOR TÉCNICO</b>.';
            default:
                return '';
        }
    }
}
