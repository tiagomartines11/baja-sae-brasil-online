<?php
namespace Baja\Juiz;

use Exception;

/**
 * Rules used by juiz/clonar_evento.php when an event is copied into a new one.
 */
class ClonagemEvento
{
    /**
     * The year of an event, read from the first two digits of its ID
     * ("26BR" is 2026).
     *
     * @param string $eventoId ID of the new event, e.g. "26BR"
     * @return int the year, e.g. 2026
     * @throws Exception when the ID does not start with two digits. The
     *                   message is shown to the admin as it is.
     */
    static function anoDoEvento($eventoId) {
        if (!preg_match('/^(\d{2})/', $eventoId, $m)) {
            throw new Exception("O novo ID de evento não começa com um código de ano válido (ex: 25BR).");
        }
        return (int)('20' . $m[1]);
    }
}
