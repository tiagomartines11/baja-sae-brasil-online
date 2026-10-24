<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Html;

/**
 * Reads what a results page shows, and nothing else.
 *
 * Two layouts exist:
 *
 *   - prova.php prints one table of
 *     teams (or none: a page with no data shows a "not yet" picture);
 *   - torneio.php prints a bracket: one heading per round ("Final",
 *     "Semifinais"), and one small table per match under it.
 *
 * The menu, the header, the footer, the scripts and the stylesheets are
 * ignored, so a change to any of them gives no snapshot difference.
 */
final class ResultsPageReader
{
    /**
     * @return list<array<string, mixed>> every <table> on the page, in page order
     */
    public static function tables(string $html): array
    {
        $document = new HtmlDocument($html);
        $extractor = new TableExtractor($document);
        $tables = [];
        foreach ($document->query('//table[not(ancestor::table)]') as $table) {
            $tables[] = $extractor->extract($table);
        }
        return $tables;
    }

    /**
     * @return list<array{round: string, matches: list<array<string, mixed>>}>
     *         the bracket, round by round, in page order
     */
    public static function tournamentRounds(string $html): array
    {
        $document = new HtmlDocument($html);
        $extractor = new TableExtractor($document);
        $rounds = [];
        $roundXPath = '//div[contains(concat(" ", normalize-space(@class), " "), " tournament-bracket__round ")]';
        foreach ($document->query($roundXPath) as $round) {
            $title = $document->query('.//h3', $round)[0] ?? null;
            $matches = [];
            foreach ($document->query('.//table', $round) as $table) {
                $matches[] = $extractor->extract($table);
            }
            $rounds[] = [
                'round' => $title !== null ? $document->text($title) : '',
                'matches' => $matches,
            ];
        }
        return $rounds;
    }
}
