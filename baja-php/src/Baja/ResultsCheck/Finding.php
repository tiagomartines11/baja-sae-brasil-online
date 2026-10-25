<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

/**
 * One problem the check found in one resultado or prova.
 *
 *   problem  what kind of problem: one of the constants below, which are
 *            also the headings of the report
 *   where    which part of the config: 'column "Pontos" val', 'pos'
 *   detail   what is wrong: the error message, or an explanation
 *   formula  the formula, when the problem is in one
 *   teams    how many teams it hits, out of ofTeams (formula errors only)
 *   slot     where the formula is, so that a fix can be put back
 */
final class Finding
{
    /**
     * PHP itself died while checking it (see IsolatedCheck): in production
     * the same formula kills the results page, or the judge's save.
     */
    public const FATAL = 'FATAL ERROR: PHP dies';
    public const FORMULA_ERROR = 'Formula raises an error';
    public const RANKING = 'Ranking config';
    public const EMPTY_COLUMN = 'Column empty for every team';
    /** A variable the formula reads that nothing gives it there (UnknownVariables). */
    public const UNKNOWN_VARIABLE = 'Unknown variables';

    public function __construct(
        public readonly string $problem,
        public readonly string $where,
        public readonly string $detail,
        public readonly mixed $formula = null,
        public readonly ?int $teams = null,
        public readonly ?int $ofTeams = null,
        public readonly ?FormulaSlot $slot = null,
    ) {
    }
}
