<?php

namespace Baja\Model;

use Baja\Certificado\Documento;
use Baja\Certificado\Token;
use Baja\Model\Base\Participante as BaseParticipante;
use DateTimeImmutable;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Skeleton subclass for representing a row from the 'participantes' table.
 */
class Participante extends BaseParticipante
{
    /**
     * Give every new row a certificate token.
     *
     * Here rather than in each caller because a row without a token is
     * unreachable: /buscar links to /verificar/{token}, and the certificate
     * PDF prints that URL. A participant inserted without one has a
     * certificate that exists and cannot be fetched by anybody, and nothing
     * about the insert would look wrong at the time.
     *
     * The unique index is what actually guarantees uniqueness. A collision on
     * 128 bits of randomness is not a thing that happens, but if it ever did
     * the insert would fail loudly rather than overwrite.
     */
    public function preInsert(?ConnectionInterface $con = null): bool
    {
        if ($this->getToken() === null || $this->getToken() === '') {
            $this->setToken(Token::generate());
        }

        // A batch of one is still a batch. Generating an id here rather than
        // only in the paste flow means every row created through the
        // application belongs to exactly one identifiable batch, so the
        // question "what else came in with this?" has an answer for a
        // single-entry mistake as much as for a pasted one.
        if ($this->getLoteId() === null || $this->getLoteId() === '') {
            $this->setLoteId(Token::generate());
        }

        if ($this->getCriadoEm() === null) {
            $this->setCriadoEm(new DateTimeImmutable());
        }

        // The one field that cannot be filled in for the caller. Throwing is
        // the point: a certificate is a claim SAE makes about a person, and
        // the row is the only place the claim's author survives — the access
        // logs that would otherwise hold it rotate long before the
        // certificate does.
        //
        // Historical rows keep NULL and are untouched by this; the constraint
        // is on creating rows, not on holding them. Nothing inserted
        // participants through this model before this branch, so there is no
        // existing caller to grandfather. If an unattended importer ever
        // needs one, the answer is a user row that says so, not a NULL that
        // says nothing.
        if ($this->getCriadoPor() === null) {
            throw new \LogicException(
                'A participante cannot be created without criado_por. Whoever is '
                . 'asserting this certificate has to be recorded on the row, because '
                . 'the row outlives every log that would otherwise say.'
            );
        }

        return parent::preInsert($con);
    }

    /**
     * Normalize the document columns, and refuse a row that fills both.
     *
     * Worth being clear about how much this actually protects: participants
     * arrive by bulk import, not through this model, so the CHECK constraint
     * on `cpf` is what governs the data in practice and this hook governs the
     * code written later. Both are cheap; the failure they prevent —
     * regenerating the mixed-format column this work package exists to split —
     * is not.
     *
     * A CPF is padded to eleven digits here so that no caller has to remember
     * to, which is the mistake the old numeric column made unavoidable. A
     * foreign document is stored exactly as given: padding it would be lossy,
     * because there is no length that says where the padding stops.
     */
    public function preSave(?ConnectionInterface $con = null): bool
    {
        $cpf = $this->getCpf();
        if ($cpf !== null && $cpf !== '') {
            $normalized = Documento::normalizeCpf((string) $cpf);
            if ($normalized === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Value with %d digits cannot be a CPF. A non-CPF document belongs '
                    . 'in documento_estrangeiro, and which column it belongs in is a '
                    . 'review decision, not one to make here.',
                    strlen(Documento::digits((string) $cpf))
                ));
            }
            $this->setCpf($normalized);
        }

        $estrangeiro = $this->getDocumentoEstrangeiro();
        if ($cpf !== null && $cpf !== '' && $estrangeiro !== null && $estrangeiro !== '') {
            throw new \InvalidArgumentException(
                'A participant has one identity document, so cpf and '
                . 'documento_estrangeiro cannot both be set.'
            );
        }

        return parent::preSave($con);
    }
}
