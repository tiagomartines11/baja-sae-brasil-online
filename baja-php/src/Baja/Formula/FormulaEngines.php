<?php
declare(strict_types=1);

namespace Baja\Formula;

use Baja\Logger;
use Baja\Util\Env;

/**
 * Which engine runs the formulas: the FORMULA_ENGINE setting (an environment
 * variable, from baja-infra/.env).
 *
 *   eval    (the default, also when unset) EvalEngine, as the site always ran
 *   safe    SafeEngine: no eval(); only what formulas need is allowed
 *
 * Anything else is a mistake in .env: it is logged, and eval is used.
 */
final class FormulaEngines
{
    public const EVAL = 'eval';
    public const SAFE = 'safe';
    public const SETTING = 'FORMULA_ENGINE';

    /** The engine the setting asks for, in this environment. */
    public static function configured(): FormulaEngine
    {
        return self::named(Env::get(self::SETTING));
    }

    /**
     * The engine for a setting's value; null and "" mean the default.
     *
     * @param null|callable(string, array): void $warn where a wrong value is
     *        reported (message, context); the app's log by default
     */
    public static function named(?string $setting, ?callable $warn = null): FormulaEngine
    {
        $name = strtolower(trim((string)$setting));
        return match ($name) {
            '', self::EVAL => new EvalEngine(),
            self::SAFE => new SafeEngine(),
            default => self::unknown($name, $warn ?? Logger::warning(...)),
        };
    }

    private static function unknown(string $name, callable $warn): FormulaEngine
    {
        $warn('Unknown ' . self::SETTING . ', using eval', ['value' => $name, 'expected' => [self::EVAL, self::SAFE]]);
        return new EvalEngine();
    }
}
