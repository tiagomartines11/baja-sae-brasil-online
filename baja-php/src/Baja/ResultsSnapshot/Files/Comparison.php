<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Files;

/**
 * Committed snapshots against freshly built ones, file by file.
 *
 * Files are compared by their decoded JSON, not by their bytes, so a file
 * that only gained Windows line endings on someone's checkout is not a
 * difference. Anything else is.
 */
final class Comparison
{
    /** How many difference lines to print per file before summarising. */
    private const LINES_PER_FILE = 25;

    /** @var array<string, list<string>> path => what differs, only for files that differ */
    private array $differences = [];

    /**
     * @param array<string, string> $committed path => contents
     * @param array<string, string> $fresh     path => contents
     */
    public function __construct(array $committed, array $fresh)
    {
        $paths = array_unique(array_merge(array_keys($committed), array_keys($fresh)));
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            if (!array_key_exists($path, $fresh)) {
                $this->differences[$path] = ['committed, but no longer produced (resultado or prova gone, or event no longer finalizado = 1)'];
            } elseif (!array_key_exists($path, $committed)) {
                $this->differences[$path] = ['new: produced now, but not committed'];
            } elseif ($committed[$path] !== $fresh[$path]) {
                $lines = JsonDiff::lines(json_decode($committed[$path], true), json_decode($fresh[$path], true));
                if ($lines !== []) {
                    $this->differences[$path] = $lines;
                }
            }
        }
    }

    public function isClean(): bool
    {
        return $this->differences === [];
    }

    /** @return array<string, list<string>> */
    public function differences(): array
    {
        return $this->differences;
    }

    /** The report printed by --check: per file, its differences, "committed -> now". */
    public function report(): string
    {
        $out = '';
        foreach ($this->differences as $path => $lines) {
            $out .= $path . "\n";
            foreach (array_slice($lines, 0, self::LINES_PER_FILE) as $line) {
                $out .= '    ' . $line . "\n";
            }
            if (count($lines) > self::LINES_PER_FILE) {
                $out .= '    ... and ' . (count($lines) - self::LINES_PER_FILE) . " more differences in this file\n";
            }
        }
        return $out;
    }
}
