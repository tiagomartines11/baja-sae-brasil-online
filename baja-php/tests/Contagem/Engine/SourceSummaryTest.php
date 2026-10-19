<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Review\SourceAdmin;
use Baja\Contagem\Review\SourceSummary;
use PHPUnit\Framework\TestCase;

/**
 * "O que esta fonte faz" on the Fontes page, and the questions asked before
 * a sensor takes part.
 *
 * The user created a timing sensor, was asked a long question, and could
 * not tell from it whether the sensor's times would replace the counters';
 * once it was dismissed, nothing on the page said so either. The answer
 * comes first now, and stays on the page beside the source.
 */
final class SourceSummaryTest extends TestCase
{
    /**
     * SITUATION: a timing sensor taking part has timed 398 passes with its
     * marks and missed 5 official ones.
     *
     * EXPECTED: no count, and the sensor's time in 398 of the 403 passes,
     * the counters' in the others. Before it timed anything: the rule.
     */
    public function testACountingSensorSaysWhoseTimeCountsAndHowOften(): void
    {
        $this->assertSame(
            'Contagem: não conta. Horário: o do sensor em 398 de 403 passagens até agora; nas outras, o dos contadores.',
            SourceSummary::line(Source::TIPO_SENSOR, Source::PAPEL_VOTANTE, Source::TEMPO_PRECISA, ['atribuidas' => 398, 'passagens_sem_marca' => 5])
        );
        $this->assertSame(SourceSummary::SENSOR_RULE, SourceSummary::line(Source::TIPO_SENSOR, Source::PAPEL_VOTANTE, Source::TEMPO_PRECISA));
        $this->assertSame(SourceSummary::SENSOR_RULE, SourceSummary::sensorLine(['atribuidas' => 0, 'passagens_sem_marca' => 0]));
        $this->assertStringContainsString('vale o do sensor em cada passagem', SourceAdmin::SENSOR_HELP);
    }

    /** EXPECTED: an observer, camera or sensor, changes neither the count nor the times. */
    public function testAnObserverIsOnlyMeasured(): void
    {
        foreach ([Source::TIPO_SENSOR, Source::TIPO_AUTOMATICA] as $type) {
            $this->assertSame(
                'Só medida: não muda a contagem nem o horário das passagens.',
                SourceSummary::line($type, Source::PAPEL_OBSERVADORA, Source::TEMPO_PRECISA, ['atribuidas' => 3])
            );
        }
    }

    /** EXPECTED: a line for every other kind of source, by its timing. */
    public function testEveryOtherSourceHasALine(): void
    {
        $this->assertSame(
            'Contagem: vota em cada passagem. Horário: entra na mediana dos contadores.',
            SourceSummary::line(Source::TIPO_MANUAL, Source::PAPEL_VOTANTE, Source::TEMPO_HUMANA)
        );
        $this->assertSame(
            'Contagem: vota em cada passagem, como um contador. Horário: o desta fonte nas passagens em que ela estiver; nas outras, a mediana dos contadores.',
            SourceSummary::line(Source::TIPO_AUTOMATICA, Source::PAPEL_VOTANTE, Source::TEMPO_PRECISA)
        );
        $this->assertStringStartsWith('Contagem: não conta; os totais só são comparados', SourceSummary::line(Source::TIPO_TOTAIS, Source::PAPEL_VOTANTE, Source::TEMPO_HUMANA));
        $this->assertStringStartsWith('Contagem: não conta voltas.', SourceSummary::line(Source::TIPO_BOX, Source::PAPEL_VOTANTE, Source::TEMPO_HUMANA));
        // A manual source has no role: whatever is stored, it votes.
        $this->assertStringStartsWith('Contagem: vota', SourceSummary::line(Source::TIPO_MANUAL, Source::PAPEL_OBSERVADORA, Source::TEMPO_HUMANA));
    }

    /**
     * EXPECTED: the first sentence of each question answers "whose time
     * counts?"; the sensor's says the count stays the counters'.
     */
    public function testTheQuestionsAnswerWhoseTimeCountsFirst(): void
    {
        $firstSentence = fn(string $text): string => explode('. ', $text, 2)[0];
        $this->assertSame(
            'Horário: em cada passagem em que uma marca do sensor coincide, vale o horário do sensor, não o dos contadores; '
            . 'nas passagens sem marca, vale o dos contadores',
            $firstSentence(SourceAdmin::SENSOR_TIMING_WARNING)
        );
        $this->assertStringContainsString('Contagem: continua só dos contadores', SourceAdmin::SENSOR_TIMING_WARNING);
        $this->assertStringContainsString('"Usar o tempo"', SourceAdmin::SENSOR_TIMING_WARNING);
        $this->assertStringStartsWith('Horário: em cada passagem em que esta fonte estiver, vale o horário dela', SourceAdmin::PRECISE_TIMING_WARNING);
    }
}
