<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Capture;

/**
 * Listens to the PHP errors of a page or job without changing how it runs.
 *
 * Installed inside the child process (scripts/results-snapshot/page-prepend.php
 * and job.php). Warnings, notices and deprecations are written down and
 * then handed back to PHP, which logs them and carries on as it would
 * without this class. A fatal error (an uncaught exception is one too) ends
 * the process; fatal() reads it back in the shutdown function.
 */
final class ErrorRecorder
{
    private const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /** @var list<array{type: string, message: string, file: string, line: int}> */
    private array $warnings = [];

    public static function install(): self
    {
        $recorder = new self();
        set_error_handler(static function (int $type, string $message, string $file, int $line) use ($recorder): bool {
            // While "@" is active PHP 8 lowers error_reporting() to the
            // fatal bits only. Production does not log those either.
            if ((error_reporting() & $type) !== 0) {
                $recorder->warnings[] = ['type' => self::name($type), 'message' => $message, 'file' => $file, 'line' => $line];
            }
            return false;
        });
        return $recorder;
    }

    /** @return list<array{type: string, message: string, file: string, line: int}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array{type: string, message: string, file: string, line: int}|null */
    public static function fatal(): ?array
    {
        $last = error_get_last();
        if ($last === null || ($last['type'] & self::FATAL_TYPES) === 0) {
            return null;
        }
        return ['type' => self::name($last['type']), 'message' => $last['message'], 'file' => $last['file'], 'line' => $last['line']];
    }

    /** The word PHP itself prints in front of the message ("Warning", "Deprecated"). */
    public static function name(int $type): string
    {
        return match ($type) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'Fatal error',
            E_RECOVERABLE_ERROR => 'Recoverable fatal error',
            E_PARSE => 'Parse error',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            default => 'Error ' . $type,
        };
    }
}
