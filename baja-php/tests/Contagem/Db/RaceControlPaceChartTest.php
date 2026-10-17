<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Review\PaceChart;
use PHPUnit\Framework\TestCase;

/**
 * The pace chart on Controle da corrida, which needs no database: it draws
 * the field pace history the snapshot holds (see PublicFeedPaceTest).
 */
final class RaceControlPaceChartTest extends TestCase
{
    private const START_MS = 1760000000000;

    /** A point $minutes after the start; null for "unknown". */
    private static function point(int $minutes, ?int $paceMs, int $laps): array
    {
        return ['ts_ms' => self::START_MS + $minutes * 60000, 'volta_mediana_ms' => $paceMs, 'voltas_na_janela' => $laps];
    }

    /**
     * SITUATION: the controller looks at the pace line above the chart:
     * once with the pace known, once with only five laps in the window,
     * once before any recalculation.
     *
     * EXPECTED: the pace with the number of laps and the window; or how
     * many laps are missing; or that there is nothing yet.
     */
    public function testTheSummarySaysThePaceOrWhyItIsUnknown(): void
    {
        $this->assertSame(
            'Ritmo atual: 3:05 (mediana de 24 voltas nos últimos 15 min).',
            PaceChart::summary(['volta_mediana_ms' => 185000, 'voltas_na_janela' => 24, 'janela_s' => 900], 8)
        );
        $this->assertSame(
            'Ritmo desconhecido: 5 de 8 voltas necessárias nos últimos 15 min.',
            PaceChart::summary(['volta_mediana_ms' => null, 'voltas_na_janela' => 5, 'janela_s' => 900], 8)
        );
        $this->assertSame('Ritmo ainda não calculado.', PaceChart::summary(null, 8));
        // The hint under the chart names the same window.
        $this->assertSame('15 min', PaceChart::windowText(900));
        $this->assertSame('90 s', PaceChart::windowText(90));
    }

    /**
     * SITUATION: the pace is unknown for the first minutes, known for three
     * minutes, unknown for one (laps left the window during a long red
     * flag), then known again for two.
     *
     * EXPECTED: two stretches of line, not one bridging the gap; one hover
     * point per known minute, saying the race time, the pace and the laps
     * behind it. With no known minute at all there is no chart.
     */
    public function testAnUnknownMinuteBreaksTheLine(): void
    {
        $history = [
            self::point(0, null, 0),
            self::point(1, null, 4),
            self::point(2, 180000, 8),
            self::point(3, 182000, 10),
            self::point(4, 185000, 12),
            self::point(5, null, 6),
            self::point(6, 200000, 9),
            self::point(7, 195000, 11),
        ];

        $svg = PaceChart::svg($history, self::START_MS);

        $this->assertSame(2, substr_count($svg, '<polyline'));
        $this->assertSame(5, substr_count($svg, '<circle'));
        $this->assertStringContainsString('<title>0h04 de corrida: 3:05 (12 voltas)</title>', $svg);
        $this->assertSame('', PaceChart::svg([self::point(0, null, 0), self::point(1, null, 3)], self::START_MS));
    }

    /**
     * SITUATION: the pace was known until the fourth minute, then a long red
     * flag emptied the window and it has been unknown since; the line above
     * the chart says "Ritmo desconhecido".
     *
     * WHAT WENT WRONG: the chart still drew its "current value" dot on the
     * last known minute, so it showed an old pace as the current one.
     *
     * EXPECTED: no current-value dot while the pace is unknown now; every
     * known minute is still a hover point. Once the pace is known again,
     * the dot is on the last minute.
     */
    public function testNoCurrentDotWhileThePaceIsUnknownNow(): void
    {
        $history = [
            self::point(2, 180000, 8),
            self::point(3, 182000, 10),
            self::point(4, 185000, 12),
            self::point(5, null, 6),
            self::point(6, null, 3),
        ];

        $svg = PaceChart::svg($history, self::START_MS);

        $this->assertStringNotContainsString('ritmo-atual', $svg);
        $this->assertSame(3, substr_count($svg, 'class="ritmo-alvo"'));

        $history[] = self::point(7, 190000, 8);
        $svg = PaceChart::svg($history, self::START_MS);
        $this->assertSame(1, substr_count($svg, 'class="ritmo-atual"'));
        $this->assertStringContainsString('<title>0h07 de corrida: 3:10 (8 voltas)</title></circle></svg>', $svg);
    }

    /**
     * SITUATION: the controller reads the chart's axes 1 h 30 min into the
     * race, with laps of about three minutes.
     *
     * WHAT WENT WRONG: both axes were written d:dd, so the race time "1:30"
     * on the bottom axis read like a lap time.
     *
     * EXPECTED: the race time is written with its unit, "1h30", and the
     * bottom axis says what it is; lap times stay m:ss.
     */
    public function testTheTimeAxisReadsAsRaceTime(): void
    {
        $history = [self::point(0, 180000, 8), self::point(90, 185000, 20)];

        $svg = PaceChart::svg($history, self::START_MS);

        $this->assertStringContainsString('>1h30</text>', $svg);
        $this->assertStringContainsString('>0h45</text>', $svg);
        $this->assertStringContainsString('>tempo de corrida</text>', $svg);
        $this->assertStringContainsString('>3:00</text>', $svg);
        $this->assertStringNotContainsString('>1:30</text>', $svg);
    }
}
