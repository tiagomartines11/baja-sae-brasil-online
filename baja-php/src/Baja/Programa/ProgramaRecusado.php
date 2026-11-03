<?php
declare(strict_types=1);

namespace Baja\Programa;

use RuntimeException;

/**
 * A create or edit of a program that ProgramaAdmin refused (BAJAAPP-90).
 * Nothing was written: no program row, no audit row.
 *
 * $reason is one of the constants, for tests to tell the cases apart; the
 * message is in Portuguese, for the page to show as it is.
 */
final class ProgramaRecusado extends RuntimeException
{
    public const INVALID_CODE = 'invalid_code';
    public const CODE_TAKEN = 'code_taken';
    public const INVALID_NAME = 'invalid_name';
    public const UNKNOWN_PARENT = 'unknown_parent';
    public const UNKNOWN_PROGRAM = 'unknown_program';

    /** The new parent is the program itself or one of the programs under it. */
    public const CYCLE = 'cycle';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
