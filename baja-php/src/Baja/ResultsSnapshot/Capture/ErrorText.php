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

    /** @param array{type: string, message: string, file: string, line: int} $error */
    public function line(array $error): string
    {
        // An uncaught exception's message carries its whole stack trace;
        // the first line says what happened and where.
        $message = explode("\nStack trace:", $error['message'])[0];
        return $this->relative($error['type'] . ': ' . trim($message) . ' in ' . $error['file'] . ':' . $error['line']);
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
