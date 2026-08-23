<?php

namespace Baja\Certificado\Insercao;

/**
 * What one commit did, in the terms the operator needs to check it.
 *
 * Counted separately rather than reported as one total, because "2000 rows
 * submitted, 1998 created" is a number somebody has to be able to reconcile —
 * and the two missing ones are duplicates they chose to skip, not a failure.
 */
final class Resultado
{
    public function __construct(
        public readonly string $loteId,
        public readonly int $criadas,
        public readonly int $atualizadas,
        public readonly int $ignoradas,
        public readonly int $nomesCorrigidos,
        /** @var array<int, string> */
        public readonly array $eventos
    ) {
    }

    public function total(): int
    {
        return $this->criadas + $this->atualizadas + $this->ignoradas;
    }
}
