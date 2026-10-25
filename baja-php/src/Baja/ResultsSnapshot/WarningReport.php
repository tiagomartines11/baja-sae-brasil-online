<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot;

/**
 * The --warnings report: every PHP warning, notice and deprecation the
 * pages and jobs raised, grouped by message, with the snapshots that raised
 * it.
 *
 *     Warning: Undefined array key "overlay" in resultados/torneio.php:23   (5 snapshots)
 *         23SU/23SU_SPR.torneio.json
 *         ...
 *
 * Warnings are deliberately NOT in the snapshot files and never fail a run.
 * They carry a file and line, which any edit above them moves, and the
 * coming refactors should be free to make them disappear. A page's results
 * are what is compared; this report is only for finding what to clean up.
 */
final class WarningReport
{
    /** @param array<string, list<string>> $warnings snapshot path => its distinct warnings */
    public static function render(array $warnings): string
    {
        $byMessage = [];
        foreach ($warnings as $path => $lines) {
            foreach ($lines as $line) {
                $byMessage[$line][] = $path;
            }
        }
        if ($byMessage === []) {
            return "PHP warnings (report only, never compared): none.\n";
        }

        // Most widespread first; ties by message, so the order is stable.
        uksort($byMessage, static fn(string $a, string $b): int => [count($byMessage[$b]), $a] <=> [count($byMessage[$a]), $b]);

        $out = 'PHP warnings (report only, never compared): ' . count($byMessage) . ' distinct, in '
            . count(array_filter($warnings)) . " snapshots.\n\n";
        foreach ($byMessage as $line => $paths) {
            sort($paths, SORT_STRING);
            $out .= $line . '   (' . count($paths) . ' snapshot' . (count($paths) === 1 ? '' : 's') . ")\n";
            foreach ($paths as $path) {
                $out .= '    ' . $path . "\n";
            }
        }
        return $out;
    }
}
