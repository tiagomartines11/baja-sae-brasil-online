<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Source;
use Baja\Model\ContagemFonte;

/**
 * "O que esta fonte faz", in one line: what a source does to the count and
 * to the pass times, by its type, role and timing. Shown beside each source
 * on the Fontes page and beside the role at creation, so that what was
 * confirmed once (a sensor's marks replacing the counters' times) can be
 * read again at any time.
 *
 * The facts behind the sensor's line: a mark matched to a pass is that
 * pass's time (PassTimeFuser::useSensorMarks), unless a reviewer's "usar o
 * tempo" or a precise source in the pass decides it; a pass with no matched
 * mark (missed, or left ambiguous) keeps the counters' time; the sensor
 * never counts a pass. An observer changes nothing.
 */
final class SourceSummary
{
    public const LABEL = 'O que esta fonte faz:';

    public const OBSERVER = 'Só medida: não muda a contagem nem o horário das passagens.';

    public const SENSOR_RULE = 'Contagem: não conta. Horário: o do sensor em cada passagem em que uma marca dele coincide; '
        . 'nas outras, o dos contadores.';

    /** The line of a source as it is stored; `$sensorStats` is SourceStats::$sensor of a counting sensor. */
    public static function forSource(ContagemFonte $fonte, ?array $sensorStats = null): string
    {
        return self::line((string) $fonte->getTipo(), (string) $fonte->getPapel(), (string) $fonte->getQualidadeTempo(), $sensorStats);
    }

    /**
     * @param array<string, mixed>|null $sensorStats 'atribuidas' and 'passagens_sem_marca', for a counting sensor
     */
    public static function line(string $type, string $role, string $timing, ?array $sensorStats = null): string
    {
        if ($type === Source::TIPO_TOTAIS) {
            return 'Contagem: não conta; os totais só são comparados com a contagem oficial. Horário: nenhum.';
        }
        if ($type === Source::TIPO_BOX) {
            return 'Contagem: não conta voltas. Horário: nenhum. Registra as entradas e saídas do box e do abastecimento.';
        }
        if ($role === Source::PAPEL_OBSERVADORA && ($type === Source::TIPO_AUTOMATICA || $type === Source::TIPO_SENSOR)) {
            return self::OBSERVER;
        }
        if ($type === Source::TIPO_SENSOR) {
            return self::sensorLine($sensorStats);
        }
        $count = $type === Source::TIPO_AUTOMATICA
            ? 'Contagem: vota em cada passagem, como um contador.'
            : 'Contagem: vota em cada passagem.';
        return $timing === Source::TEMPO_PRECISA
            ? $count . ' Horário: o desta fonte nas passagens em que ela estiver; nas outras, a mediana dos contadores.'
            : $count . ' Horário: entra na mediana dos contadores.';
    }

    /** "Contagem: não conta. Horário: o do sensor em 398 de 403 passagens até agora; nas outras, o dos contadores." */
    public static function sensorLine(?array $sensorStats): string
    {
        $timed = (int) ($sensorStats['atribuidas'] ?? 0);
        $total = $timed + (int) ($sensorStats['passagens_sem_marca'] ?? 0);
        if ($sensorStats === null || $total === 0) {
            return self::SENSOR_RULE;
        }
        return sprintf('Contagem: não conta. Horário: o do sensor em %d de %d passagens até agora; nas outras, o dos contadores.', $timed, $total);
    }

    /**
     * What each role does, for the role chosen at creation: [role => line],
     * for an automatic source and a timing sensor.
     *
     * @return array<string, string>
     */
    public static function roleChoices(): array
    {
        return [
            Source::PAPEL_OBSERVADORA => self::OBSERVER,
            Source::PAPEL_VOTANTE => 'Automática: vota em cada passagem, como um contador. Sensor: não conta; o horário dele vale nas passagens '
                . 'em que uma marca coincide, e nas outras o dos contadores.',
        ];
    }
}
