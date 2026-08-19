<?php

namespace Baja\Model;

use Baja\Certificado\Documento;
use Baja\Certificado\Token;
use Baja\Model\Base\Participante as BaseParticipante;
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
