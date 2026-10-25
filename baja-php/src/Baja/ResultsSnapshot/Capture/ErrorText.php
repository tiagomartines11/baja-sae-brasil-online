<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Capture;

/**
 * Writes recorded PHP errors as the one-line text PHP itself would log,
 * minus what differs between machines.
 *
 *   /var/www/html/resultados/torneio.php  ->  resultados/torneio.php
 *
 * The same page runs from /var/www/html in the dev container and from
 * /home/runner/work/... in CI; a snapshot must not care which.
 */
final class ErrorText
{
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * A warning or error as PHP logs it, with where it happened. Used for
     * the --warnings report, which is for finding the line to fix.
     *
     *   Warning: Undefined array key "overlay" in resultados/torneio.php:23
     *
     * @param array{type: string, message: string, file: string, line: int} $error
     */
    public function line(array $error): string
    {
        // An uncaught exception's message carries its whole stack trace;
        // the first line says what happened and where.
        $message = explode("\nStack trace:", $error['message'])[0];
        return $this->relative($error['type'] . ': ' . trim($message) . ' in ' . $error['file'] . ':' . $error['line']);
    }

    /**
     * A fatal error as it goes into a snapshot: what failed, not where.
     *
     *   Uncaught Error: Call to a member function getStatus() on null in /x/resultados/prova.php:265
     *     ->  Error: Call to a member function getStatus() on null
     *   Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)
     *     ->  Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)
     *
     * A page that dies shows no results, so the error IS the page's result
     * and belongs in the snapshot. The file and line do not: any edit above
     * that line would move it, and a refactor moves it to another file,
     * without the page's result changing at all.
     *
     * @param array{type: string, message: string, file: string, line: int} $error
     */
    public function error(array $error): string
    {
        $message = trim(explode("\nStack trace:", $error['message'])[0]);
        $kind = $error['type'];
        if (preg_match('/^Uncaught ([\w\\\\]+): (.*)$/s', $message, $match) === 1) {
            // The exception class (Error, TypeError, PDOException...).
            [$kind, $message] = [$match[1], $match[2]];
        }
        return $kind . ': ' . $this->withoutLocation($message);
    }

    /**
     * Removes a trailing " in /path/file.php:123" or " in /path/file.php on
     * line 123", and makes any other path relative.
     */
    public function withoutLocation(string $text): string
    {
        $text = (string)preg_replace('/ in \S+(?::\d+| on line \d+)$/', '', trim($text));
        return $this->relative($text);
    }

    /**
     * One line per distinct warning, in the order they first happened.
     *
     * Not how many times each happened: a warning raised inside a sort
     * comparison happens once per comparison, and the number of comparisons
     * changes whenever any score changes. Counting them would turn every
     * result change into a warning change too.
     *
     * @param list<array{type: string, message: string, file: string, line: int}> $warnings
     * @return list<string>
     */
    public function warningLines(array $warnings): array
    {
        $lines = [];
        foreach ($warnings as $warning) {
            $lines[$this->line($warning)] = true;
        }
        return array_keys($lines);
    }

    public function relative(string $text): string
    {
        return str_replace(rtrim($this->projectRoot, '/') . '/', '', $text);
    }
}
