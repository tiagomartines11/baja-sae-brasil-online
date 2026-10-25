<?php
declare(strict_types=1);

namespace Baja\FormulaCompare;

/**
 * The text the comparison prints, grouped by formula:
 *
 *     Formula engine comparison
 *       17 events, 358 provas, 387 resultados; fixed now 2026-10-25 12:00:00 (1761404400)
 *       1,047 distinct formulas, 3,350 places, 123,456 evaluations (formula x team)
 *
 *     DIFFERENT VALUES: 0 formulas
 *
 *     DELIBERATE: eval() kills PHP, SafeEngine gives null: 1 formula
 *       ...
 *
 * Three kinds, as the Recorder counts them:
 *
 *   DIFFERENT VALUES  a page would show something else. Each must be
 *                     fixed in SafeEngine before it is switched on, or
 *                     added to docs/formula-engine.md's deliberate
 *                     differences with the reason. The exit code is 1.
 *   DELIBERATE        eval() kills PHP on the formula (the whole page
 *                     dies); SafeEngine gives null and the page goes on.
 *   SAME VALUE, OTHER ERROR  no page changes; only the config check's
 *                     wording would. For information.
 *
 * Each formula comes with where it is written (event, prova or resultado,
 * place), how many of its evaluations differ, and up to
 * Recorder::EXAMPLES examples with the variables it reads.
 */
final class Report
{
    /** Places listed per formula; the rest are counted. */
    private const PLACES = 8;

    /**
     * @param array<string, array> $formulas Recorder statistics, by formula key
     * @param array<string, array{formula: mixed, where: list<string>}> $places where each formula is written
     * @param list<string> $died children that died although no known fatal formula was in them
     * @param list<string> $notRun keys of the formulas no team ran, compared once with no variables
     * @param array{events: int, provas: int, resultados: int, now: int, seconds: float} $about
     */
    public function __construct(
        private array $formulas,
        private array $places,
        private array $died,
        private array $notRun,
        private array $about,
    ) {
        ksort($this->formulas, SORT_STRING);
    }

    public function hasUnexplainedDifferences(): bool
    {
        return $this->died !== [] || $this->with('differ') !== [];
    }

    public function render(): string
    {
        $evaluations = array_sum(array_column($this->formulas, 'evaluations'));
        $placeCount = array_sum(array_map(fn($place) => count($place['where']), $this->places));
        $out = [
            '',
            'Formula engine comparison (eval() and SafeEngine)',
            sprintf(
                '  %s, %s, %s; fixed now %s (%d); %.1f s',
                self::count($this->about['events'], 'event'), self::count($this->about['provas'], 'prova'), self::count($this->about['resultados'], 'resultado'),
                date('Y-m-d H:i:s', $this->about['now']), $this->about['now'], $this->about['seconds'],
            ),
            sprintf(
                '  %s distinct formulas in %s places, %s evaluations (formula x team); %s formulas no team runs were compared once, with no variables',
                number_format(count($this->places)), number_format($placeCount), number_format($evaluations), number_format(count($this->notRun)),
            ),
            '',
        ];

        if ($this->died !== []) {
            $out[] = 'PROCESSES THAT DIED: ' . count($this->died) . ' (their formulas were not all compared)';
            foreach ($this->died as $line) {
                $out[] = '  ' . $line;
            }
            $out[] = '';
        }

        $sections = [
            'differ' => 'DIFFERENT VALUES (a page would change; each must be fixed or listed as deliberate)',
            'evalDies' => 'DELIBERATE: eval() kills PHP on these, SafeEngine gives null and the page goes on',
            'otherError' => 'SAME VALUE, OTHER ERROR (no page changes; only the config check\'s wording would)',
        ];
        foreach ($sections as $kind => $title) {
            $formulas = $this->with($kind);
            $out[] = $title . ': ' . count($formulas) . ' ' . (count($formulas) === 1 ? 'formula' : 'formulas');
            foreach ($formulas as $key => $stats) {
                array_push($out, ...$this->formula($key, $stats, $kind));
            }
            $out[] = '';
        }

        $out[] = $this->hasUnexplainedDifferences()
            ? 'Result: DIFFERENCES. See above; docs/formula-engine.md says what to do.'
            : 'Result: no difference but the deliberate ones.';
        return implode("\n", $out) . "\n";
    }

    /** @return array<string, array> the formulas that have some of $kind */
    private function with(string $kind): array
    {
        return array_filter($this->formulas, fn(array $stats) => $stats[$kind] > 0);
    }

    /** @return list<string> */
    private function formula(string $key, array $stats, string $kind): array
    {
        $out = ['', '  formula: ' . self::show($stats['formula'])];
        $where = $this->places[$key]['where'] ?? ['(not found in the configs)'];
        $out[] = '    where:   ' . implode("\n             ", array_slice($where, 0, self::PLACES))
            . (count($where) > self::PLACES ? "\n             ... and " . (count($where) - self::PLACES) . ' more' : '');
        $out[] = sprintf('    %d of %d evaluations', $stats[$kind], $stats['evaluations']);
        foreach ($stats[$kind . 'Examples'] as $example) {
            $out[] = '    with ' . ($example['variables'] === [] ? 'no variables' : self::show($example['variables']));
            $out[] = '      eval: ' . self::outcome($example['eval']);
            $out[] = '      safe: ' . self::outcome($example['safe']);
        }
        return $out;
    }

    /** "1 event", "17 events" */
    private static function count(int $number, string $what): string
    {
        return number_format($number) . ' ' . $what . ($number === 1 ? '' : 's');
    }

    /** @param array{value: mixed, error: ?string} $outcome */
    private static function outcome(array $outcome): string
    {
        return $outcome['error'] ?? self::show($outcome['value']);
    }

    private static function show(mixed $value): string
    {
        $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $text === false ? var_export($value, true) : $text;
    }
}
