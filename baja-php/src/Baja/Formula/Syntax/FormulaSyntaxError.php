<?php
declare(strict_types=1);

namespace Baja\Formula\Syntax;

use ParseError;

/**
 * A formula SafeEngine refuses to run: it is not valid, or it uses
 * something that is not allowed (a function off the list, an assignment,
 * backticks...). It says what and where:
 *
 *     function system() is not allowed in a formula, at character 1
 *
 * A ParseError, like the one eval() throws for a formula that is not valid
 * PHP, so code that catches one catches both. Input::solveFormula() turns
 * it into null, as it does every error.
 */
final class FormulaSyntaxError extends ParseError
{
    /**
     * @param string $problem  what is wrong, without the position
     * @param int    $position where, counting characters from 1
     */
    public function __construct(public readonly string $problem, public readonly int $position)
    {
        parent::__construct($problem . ', at character ' . $position);
    }
}
