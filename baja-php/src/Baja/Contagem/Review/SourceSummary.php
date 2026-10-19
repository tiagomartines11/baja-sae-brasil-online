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

    /** The start of the line of a source switched off: what it does once it is on follows. */
    public const INACTIVE = 'Desativada: não muda nada a partir de agora. Quando ativa:';

    /** The line of a source as it is stored; `$sensorStats` is SourceStats::$sensor of a counting sensor. */
    public static function forSource(ContagemFonte $fonte, ?array $sensorStats = null): string
    {
        return self::line((string) $fonte->getTipo(), (string) $fonte->getPapel(), (string) $fonte->getQualidadeTempo(), $sensorStats, (bool) $fonte->getAtiva());
    }

    /**
     * @param array<string, mixed>|null $sensorStats 'passagens_com_horario', 'passagens_com_marca' and 'passagens_sem_marca', for a counting sensor
     * @param bool $active false for a source switched off: it changes nothing now (Source::wasCountingAt)
     */
    public static function line(string $type, string $role, string $timing, ?array $sensorStats = null, bool $active = true): string
    {
        if (!$active && $type !== Source::TIPO_TOTAIS && $type !== Source::TIPO_BOX) {
            // Its figures are those of the time it was on: the rule, not them.
            return self::INACTIVE . ' ' . self::line($type, $role, $timing);
        }
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

    /**
     * "Contagem: não conta. Horário: o do sensor em 398 de 403 passagens
     * contadas até agora; nas outras, o dos contadores." Both numbers are
     * counted passes: those its marks time, out of those with a mark or
     * missed by it. A pass with its mark that a "Usar o tempo" or a precise
     * source times is said apart.
     */
    public static function sensorLine(?array $sensorStats): string
    {
        if ($sensorStats === null || !isset($sensorStats['passagens_com_horario'], $sensorStats['passagens_com_marca'])) {
            // None yet, or figures from before these were kept.
            return self::SENSOR_RULE;
        }
        $timed = (int) $sensorStats['passagens_com_horario'];
        $marked = (int) $sensorStats['passagens_com_marca'];
        $total = $marked + (int) ($sensorStats['passagens_sem_marca'] ?? 0);
        if ($total === 0) {
            return self::SENSOR_RULE;
        }
        $others = $marked > $timed
            ? sprintf('nas outras, o dos contadores (em %d com marca, o de um "Usar o tempo" ou de uma fonte de tempo preciso)', $marked - $timed)
            : 'nas outras, o dos contadores';
        return sprintf('Contagem: não conta. Horário: o do sensor em %d de %d passagens contadas até agora; %s.', $timed, $total, $others);
    }

    /**
     * What a new source of each type does, by role where it has one, for
     * the "Nova fonte" form: [type => [title => line]]. The form shows the
     * lines of the type chosen.
     *
     * @return array<string, array<string, string>>
     */
    public static function typeChoices(): array
    {
        return [
            Source::TIPO_MANUAL => [
                'Manual' => self::line(Source::TIPO_MANUAL, Source::PAPEL_VOTANTE, Source::TEMPO_HUMANA)
                    . ' Com tempo "Precisa": vale o horário desta fonte nas passagens em que ela estiver.',
            ],
            Source::TIPO_AUTOMATICA => [
                'Observadora' => self::OBSERVER,
                'Votante' => self::line(Source::TIPO_AUTOMATICA, Source::PAPEL_VOTANTE, Source::TEMPO_HUMANA),
            ],
            Source::TIPO_SENSOR => [
                'Observador' => self::OBSERVER,
                'Votante' => self::SENSOR_RULE,
            ],
            Source::TIPO_TOTAIS => [
                'Totais' => self::line(Source::TIPO_TOTAIS, Source::PAPEL_VOTANTE, Source::TEMPO_HUMANA),
            ],
        ];
    }
}
