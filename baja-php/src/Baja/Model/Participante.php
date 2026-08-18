<?php

namespace Baja\Model;

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
}
