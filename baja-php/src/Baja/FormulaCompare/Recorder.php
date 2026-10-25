<?php
declare(strict_types=1);

namespace Baja\FormulaCompare;

use Baja\Formula\EvalEngine;
use Baja\Formula\FormulaEngine;
use Baja\Formula\SafeEngine;
use Baja\Formula\ShadowEngine;
use ParseError;
use Throwable;

/**
 * The engine the comparison puts in Input::solveFormula() while the real
 * code runs (Units). For every formula it is asked, it runs both engines
 * with the same fixed "now", answers with eval()'s result, as the site
 * does, and counts what happened, by formula:
 *
 *   differ     a different value: a page would show something else
 *   evalDies   eval() would have killed PHP on it (FatalProbe found it
 *              beforehand, so it is not run with eval() here); SafeEngine's
 *              answer is used instead, so the rest can still be compared
 *   otherError the same value, but not the same error: one engine raised
 *              one and the other did not, or another kind or message.
 *              No page changes; the config check would word it otherwise.
 *              (eval()'s ParseError and SafeEngine's FormulaSyntaxError
 *              are the same kind: their messages always differ.)
 *
 * and keeps the first EXAMPLES cases of each, with the variables the
 * formula reads.
 */
final class Recorder implements FormulaEngine
{
    public const EXAMPLES = 3;

    private EvalEngine $eval;
    private SafeEngine $safe;

    /** @var array<string, array> key => statistics, see Recorder::blank() */
    private array $formulas = [];

    /**
     * @param int                   $now   the fixed now, for both engines
     * @param array<string, string> $fatal formula key => why eval() dies on it
     */
    public function __construct(int $now, private array $fatal)
    {
        $this->eval = new EvalEngine($now);
        $this->safe = new SafeEngine($now);
    }

    /** How a formula is told apart: its text, or (for a number or a list) its serialized value. */
    public static function key(mixed $formula): string
    {
        return is_string($formula) ? 'text:' . $formula : 'other:' . serialize($formula);
    }

    public function evaluate(mixed $vars, mixed $formula): string|int|float|null
    {
        $key = self::key($formula);
        $stats = &$this->formulas[$key];
        $stats ??= self::blank($formula);
        $stats['evaluations']++;

        [$safeValue, $safeError] = self::run($this->safe, $vars, $formula);
        if (isset($this->fatal[$key])) {
            $stats['evalDies']++;
            self::example($stats['evalDiesExamples'], $vars, $formula, ['value' => null, 'error' => 'FATAL: ' . $this->fatal[$key]], $safeValue, $safeError);
            return self::answer($safeValue, $safeError);
        }

        [$evalValue, $evalError] = self::run($this->eval, $vars, $formula);
        $eval = ['value' => $evalValue, 'error' => self::describe($evalError)];
        if (!ShadowEngine::same($evalValue, $safeValue)) {
            $stats['differ']++;
            self::example($stats['differExamples'], $vars, $formula, $eval, $safeValue, $safeError);
        } elseif (!self::sameError($evalError, $safeError)) {
            $stats['otherError']++;
            self::example($stats['otherErrorExamples'], $vars, $formula, $eval, $safeValue, $safeError);
        }
        return self::answer($evalValue, $evalError);
    }

    /** @return array<string, array> what was recorded, by formula key */
    public function formulas(): array
    {
        return $this->formulas;
    }

    /** @return array<string, mixed> */
    public static function blank(mixed $formula): array
    {
        return [
            'formula' => $formula,
            'evaluations' => 0,
            'differ' => 0, 'differExamples' => [],
            'evalDies' => 0, 'evalDiesExamples' => [],
            'otherError' => 0, 'otherErrorExamples' => [],
        ];
    }

    /**
     * Adds one unit's statistics into the totals: counts summed, examples
     * kept up to EXAMPLES.
     *
     * @param array<string, array> $into
     * @param array<string, array> $more
     */
    public static function merge(array &$into, array $more): void
    {
        foreach ($more as $key => $stats) {
            $into[$key] ??= self::blank($stats['formula']);
            foreach (['evaluations', 'differ', 'evalDies', 'otherError'] as $count) {
                $into[$key][$count] += $stats[$count];
            }
            foreach (['differExamples', 'evalDiesExamples', 'otherErrorExamples'] as $list) {
                $into[$key][$list] = array_slice(array_merge($into[$key][$list], $stats[$list]), 0, self::EXAMPLES);
            }
        }
    }

    /** @return array{0: string|int|float|null, 1: ?Throwable} */
    private static function run(FormulaEngine $engine, mixed $vars, mixed $formula): array
    {
        // Anything a formula prints (eval() prints what follows a closing
        // PHP tag in a formula) is not ours.
        ob_start();
        try {
            return [$engine->evaluate($vars, $formula), null];
        } catch (Throwable $e) {
            return [null, $e];
        } finally {
            ob_end_clean();
        }
    }

    private static function sameError(?Throwable $eval, ?Throwable $safe): bool
    {
        if ($eval === null || $safe === null) {
            return $eval === $safe;
        }
        if ($eval instanceof ParseError) {
            return $safe instanceof ParseError;
        }
        return self::describe($eval) === self::describe($safe);
    }

    private static function answer(string|int|float|null $value, ?Throwable $error): string|int|float|null
    {
        if ($error !== null) {
            throw $error;
        }
        return $value;
    }

    /** @param list<array> $examples */
    private static function example(array &$examples, mixed $vars, mixed $formula, array $eval, mixed $safeValue, ?Throwable $safeError): void
    {
        if (count($examples) >= self::EXAMPLES) {
            return;
        }
        $examples[] = [
            'variables' => self::plain(ShadowEngine::variablesRead($vars, $formula)),
            'eval' => $eval,
            'safe' => ['value' => $safeValue, 'error' => self::describe($safeError)],
        ];
    }

    /**
     * "Class: message". With the fixed now, EvalEngine runs the formula in
     * the namespace Baja\Formula\PinnedClock, which PHP then names in an
     * undefined constant's message; that is the comparison's doing, not
     * the formula's, so it is left out.
     */
    private static function describe(?Throwable $error): ?string
    {
        return $error === null ? null : get_class($error) . ': ' . str_replace('Baja\\Formula\\PinnedClock\\', '', $error->getMessage());
    }

    /** Objects (a run's JSON) as arrays, so that the example can be printed and passed between processes. */
    private static function plain(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array)$value;
        }
        return is_array($value) ? array_map(self::plain(...), $value) : $value;
    }
}
