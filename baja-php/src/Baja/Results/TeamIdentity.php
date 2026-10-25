<?php
declare(strict_types=1);

namespace Baja\Results;

/**
 * Who a row is about. Values come straight from the database (through the
 * formula variables NUM, EQUIPE, ESCOLA, ESTADO), with their database types.
 */
final class TeamIdentity
{
    public function __construct(
        public readonly mixed $teamId,
        public readonly mixed $number,
        public readonly mixed $name,
        public readonly mixed $school,
        public readonly mixed $state,
        public readonly mixed $disqualified,
    ) {
    }

    public function isDisqualified(): bool
    {
        return $this->disqualified == true;
    }
}
