<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

/**
 * The pace chart on Controle da corrida: the field pace (the median lap of
 * the field over the last `janela_ritmo_s`) once a minute since the start,
 * as an inline SVG. No script and no library: hovering a point shows its
 * value through the SVG's own <title>.
 *
 * The numbers come from the last recalculation's snapshot (the feed part),
 * the same ones the public feed serves. Minutes in which the pace was
 * unknown (fewer than eight laps in the window) are a gap in the line.
 */
final class PaceChart
{
    private const WIDTH = 720;
    private const HEIGHT = 200;
    private const LEFT = 52;
    private const RIGHT = 12;
    private const TOP = 10;
    private const BOTTOM = 26;

    /** Grid lines are on whole multiples of this: 10 s of lap time. */
    private const Y_STEP_MS = 10000;

    /**
     * One line saying what the pace is now, or why it is unknown.
     *
     * @param array{volta_mediana_ms: ?int, voltas_na_janela: int, janela_s: int}|null $ritmo
     */
    public static function summary(?array $ritmo, int $minimumLaps): string
    {
        if ($ritmo === null) {
            return 'Ritmo ainda não calculado.';
        }
        $window = self::windowText((int) $ritmo['janela_s']);
        $laps = (int) $ritmo['voltas_na_janela'];
        if ($ritmo['volta_mediana_ms'] === null) {
            return sprintf('Ritmo desconhecido: %d de %d voltas necessárias nos últimos %s.', $laps, $minimumLaps, $window);
        }
        return sprintf('Ritmo atual: %s (mediana de %d voltas nos últimos %s).', self::lapText((int) $ritmo['volta_mediana_ms']), $laps, $window);
    }

    /**
     * @param array<int, array{ts_ms: int, volta_mediana_ms: ?int, voltas_na_janela: int}> $history oldest first
     * @param int $largadaMs the start, which the time axis counts from
     * @return string the SVG, or '' when no point of the history has a known pace
     */
    public static function svg(array $history, int $largadaMs): string
    {
        $known = array_values(array_filter($history, fn(array $point): bool => $point['volta_mediana_ms'] !== null));
        if ($known === []) {
            return '';
        }
        $paces = array_column($known, 'volta_mediana_ms');
        $lowMs = intdiv(min($paces), self::Y_STEP_MS) * self::Y_STEP_MS;
        $highMs = (intdiv(max($paces) - 1, self::Y_STEP_MS) + 1) * self::Y_STEP_MS;
        if ($highMs <= $lowMs) {
            $highMs = $lowMs + self::Y_STEP_MS;
        }
        $firstMs = $history[0]['ts_ms'];
        $lastMs = max($history[count($history) - 1]['ts_ms'], $firstMs + 60000);

        $plotWidth = self::WIDTH - self::LEFT - self::RIGHT;
        $plotHeight = self::HEIGHT - self::TOP - self::BOTTOM;
        $x = fn(int $tsMs): float => self::LEFT + ($tsMs - $firstMs) / ($lastMs - $firstMs) * $plotWidth;
        $y = fn(int $paceMs): float => self::TOP + ($highMs - $paceMs) / ($highMs - $lowMs) * $plotHeight;

        $parts = [];
        // Recessive grid: at most five lines, on whole tens of seconds.
        $stepMs = self::Y_STEP_MS * max(1, (int) ceil(($highMs - $lowMs) / self::Y_STEP_MS / 4));
        for ($paceMs = $lowMs; $paceMs <= $highMs; $paceMs += $stepMs) {
            $lineY = self::number($y($paceMs));
            $parts[] = '<line class="ritmo-grade" x1="' . self::LEFT . '" x2="' . (self::WIDTH - self::RIGHT) . '" y1="' . $lineY . '" y2="' . $lineY . '"/>';
            $parts[] = '<text class="ritmo-eixo" x="' . (self::LEFT - 6) . '" y="' . $lineY . '" text-anchor="end" dominant-baseline="middle">' . self::lapText($paceMs) . '</text>';
        }
        foreach ([$firstMs, intdiv($firstMs + $lastMs, 2), $lastMs] as $index => $tsMs) {
            $anchor = ['start', 'middle', 'end'][$index];
            $parts[] = '<text class="ritmo-eixo" x="' . self::number($x($tsMs)) . '" y="' . (self::HEIGHT - 6) . '" text-anchor="' . $anchor . '">'
                . self::raceTimeText($tsMs - $largadaMs) . '</text>';
        }

        // One polyline per stretch of known pace; an unknown minute breaks the line.
        $stretch = [];
        $stretches = [];
        foreach ($history as $point) {
            if ($point['volta_mediana_ms'] === null) {
                $stretches[] = $stretch;
                $stretch = [];
                continue;
            }
            $stretch[] = self::number($x($point['ts_ms'])) . ',' . self::number($y($point['volta_mediana_ms']));
        }
        $stretches[] = $stretch;
        foreach ($stretches as $points) {
            if (count($points) > 1) {
                $parts[] = '<polyline class="ritmo-linha" points="' . implode(' ', $points) . '"/>';
            }
        }

        // A hover target per known minute, larger than the line; the last one is drawn.
        foreach ($known as $index => $point) {
            $isLast = $index === count($known) - 1;
            $parts[] = '<circle class="' . ($isLast ? 'ritmo-atual' : 'ritmo-alvo') . '" cx="' . self::number($x($point['ts_ms']))
                . '" cy="' . self::number($y($point['volta_mediana_ms'])) . '" r="' . ($isLast ? 4 : 6) . '">'
                . '<title>' . self::raceTimeText($point['ts_ms'] - $largadaMs) . ' de corrida: ' . self::lapText($point['volta_mediana_ms'])
                . ' (' . (int) $point['voltas_na_janela'] . ' voltas)</title></circle>';
        }

        return '<svg class="ritmo-grafico" viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '" role="img"'
            . ' aria-label="Ritmo do pelotão ao longo da corrida">' . implode('', $parts) . '</svg>';
    }

    /** A lap time as m:ss. */
    public static function lapText(int $ms): string
    {
        $seconds = (int) round($ms / 1000);
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** Time since the start as h:mm. */
    private static function raceTimeText(int $ms): string
    {
        $minutes = intdiv(max(0, $ms), 60000);
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private static function windowText(int $seconds): string
    {
        return $seconds % 60 === 0 ? ($seconds / 60) . ' min' : $seconds . ' s';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
