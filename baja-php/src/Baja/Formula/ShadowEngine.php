<?php
declare(strict_types=1);

namespace Baja\Formula;

use Baja\Logger;
use Closure;
use Throwable;

/**
 * Runs every formula with two engines, answers with the first one's
 * result (or throws its error), and reports each formula where the second
 * one would have answered something else. FORMULA_ENGINE=shadow, on a site
 * or for one event, means: eval() as always (the page does not change),
 * SafeEngine beside it, and every difference in the log.
 *
 * A difference is a different VALUE (what a page would show): 3 and 3.0
 * differ, two errors do not (both give null). Each one is reported once
 * per request (a page runs the same formula for every team), with the
 * variables the formula reads, and at most MOST_REPORTS of them, then
 * one note that there were more, so a page with a systematic difference
 * does not spend its time logging.
 */
final class ShadowEngine implements FormulaEngine
{
    public const MOST_REPORTS = 100;

    /** @var array<string, true> the differences reported in this request */
    private array $reported = [];

    private int $notReported = 0;

    /** @var Closure(array): void */
    private Closure $report;

    /**
     * @param FormulaEngine          $answers the engine whose result is used (EvalEngine)
     * @param FormulaEngine          $checked the engine compared with it (SafeEngine)
     * @param callable(array): void  $report  told about each difference: formula,
     *                                        variables, and each engine's value and error
     */
    public function __construct(private FormulaEngine $answers, private FormulaEngine $checked, callable $report)
    {
        $this->report = Closure::fromCallable($report);
    }

    /** Reports go to the app's log (docker compose logs baja-app), as warnings. */
    public static function logToApp(): Closure
    {
        return static function (array $difference): void {
            Logger::warning('Formula engines differ', $difference + ['page' => $_SERVER['REQUEST_URI'] ?? null]);
        };
    }

    public function evaluate(mixed $vars, mixed $formula): string|int|float|null
    {
        [$value, $error] = self::run($this->answers, $vars, $formula);
        [$otherValue, $otherError] = self::run($this->checked, $vars, $formula);

        if (!self::same($value, $otherValue)) {
            $this->difference($vars, $formula, [$value, $error], [$otherValue, $otherError]);
        }
        if ($error !== null) {
            throw $error;
        }
        return $value;
    }

    /** How many differences were left out of the log in this request, past MOST_REPORTS. */
    public function notReported(): int
    {
        return $this->notReported;
    }

    /** @return array{0: string|int|float|null, 1: ?Throwable} */
    private static function run(FormulaEngine $engine, mixed $vars, mixed $formula): array
    {
        try {
            return [$engine->evaluate($vars, $formula), null];
        } catch (Throwable $e) {
            return [null, $e];
        }
    }

    /**
     * The same value for a page: same type and same value. serialize()
     * tells 0.0 from -0.0 ("-0" on a page), which === does not.
     */
    public static function same(string|int|float|null $a, string|int|float|null $b): bool
    {
        return $a === $b && (!is_float($a) || serialize($a) === serialize($b));
    }

    /**
     * @param array{0: mixed, 1: ?Throwable} $answer
     * @param array{0: mixed, 1: ?Throwable} $other
     */
    private function difference(mixed $vars, mixed $formula, array $answer, array $other): void
    {
        $difference = [
            'formula' => $formula,
            'variables' => self::variablesRead($vars, $formula),
            'eval' => ['value' => $answer[0], 'error' => self::describe($answer[1])],
            'safe' => ['value' => $other[0], 'error' => self::describe($other[1])],
        ];
        $key = serialize($difference);
        if (isset($this->reported[$key])) {
            return;
        }
        if (count($this->reported) >= self::MOST_REPORTS) {
            if ($this->notReported++ === 0) {
                ($this->report)(['formula' => null, 'note' => 'More differences in this request; only the first ' . self::MOST_REPORTS . ' are logged.']);
            }
            return;
        }
        $this->reported[$key] = true;
        ($this->report)($difference);
    }

    /**
     * Only the variables the formula names (a team has dozens), or all of
     * them when the formula cannot be read.
     *
     * @return array<string, mixed>
     */
    private static function variablesRead(mixed $vars, mixed $formula): array
    {
        $all = (array)$vars;
        try {
            $names = SafeEngine::read($formula)->variables;
        } catch (Throwable) {
            return $all;
        }
        $read = [];
        foreach ($names as $name) {
            $read[$name] = $all[$name] ?? null;
        }
        return $read;
    }

    private static function describe(?Throwable $error): ?string
    {
        return $error === null ? null : get_class($error) . ': ' . $error->getMessage();
    }
}
