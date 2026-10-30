<?php
declare(strict_types=1);

namespace Baja\Juiz;

use DateTimeImmutable;

/**
 * Reads the two date fields of juiz/evento.php (BAJAAPP-12): the event's
 * first day (data_inicio) and last day (data_fim), and says whether they
 * can be saved.
 *
 * The last day matters beyond the page: a role given on the event ends a
 * few days after it (Baja\Rbac\Expiry\GrantExpiry). So a date that is not
 * a real day, or a last day before the first, is refused rather than saved
 * and left for someone to notice.
 *
 * What counts as valid:
 *   - an empty field                         -> saved as empty (not known yet)
 *   - a real day as YYYY-MM-DD, what an
 *     <input type="date"> sends               -> saved
 *   - anything else, or last before first    -> NOT saved, $erro is set
 *
 * Either may be empty without the other: an event may know its last day
 * before its first, or the other way round. Without data_fim, a role on
 * the event needs an explicit end.
 */
final class DatasEvento
{
    private function __construct(
        public readonly ?string $inicio,
        public readonly ?string $fim,
        public readonly ?string $erro,
    ) {
    }

    /**
     * @param mixed $inicio $_POST['data_inicio']
     * @param mixed $fim    $_POST['data_fim']
     */
    public static function doFormulario(mixed $inicio, mixed $fim): self
    {
        $dataInicio = self::dia($inicio);
        $dataFim = self::dia($fim);
        if ($dataInicio === false) {
            return new self(null, null, 'a data de início não é um dia válido');
        }
        if ($dataFim === false) {
            return new self(null, null, 'a data de fim não é um dia válido');
        }
        if ($dataInicio !== null && $dataFim !== null && $dataFim < $dataInicio) {
            return new self(null, null, 'a data de fim é anterior à de início');
        }
        return new self($dataInicio, $dataFim, null);
    }

    public function valido(): bool
    {
        return $this->erro === null;
    }

    /** 'YYYY-MM-DD', null when empty, false when not a real day. */
    private static function dia(mixed $valor): string|false|null
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        // is_string: data_fim[]=x arrives as an array.
        if (!is_string($valor) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', trim($valor), $partes) !== 1
            || !checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
            return false;
        }
        return (new DateTimeImmutable(trim($valor)))->format('Y-m-d');
    }
}
