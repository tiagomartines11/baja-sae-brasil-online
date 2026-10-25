<?php
declare(strict_types=1);

namespace Baja\Results;

use Baja\Model\Evento;

/**
 * The two flags of an event that decide whether spoilers apply:
 *
 *   spoilers  the organisers turned spoilers on (hide some columns)
 *   finished  the event is over (finalizado); then nothing is hidden
 */
final class EventStatus
{
    public function __construct(
        public readonly bool $spoilers,
        public readonly bool $finished,
    ) {
    }

    public static function of(Evento $evento): self
    {
        return new self((bool)$evento->getSpoilers(), (bool)$evento->getFinalizado());
    }
}
