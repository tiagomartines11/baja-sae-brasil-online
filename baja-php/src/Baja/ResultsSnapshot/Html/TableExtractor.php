<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Html;

use DOMElement;

/**
 * Turns one HTML <table> into plain data:
 *
 *     [
 *         'caption' => 'Melhor de 3',            // only if the table has one
 *         'columns' => ['#', 'Equipe Escola', 'Total'],
 *         'rows'    => [
 *             ['#' => '12', 'Equipe Escola' => 'SACI<br><i ...>UNIFEI</i>', 'Total' => '<b>377.64</b>'],
 *             ...
 *         ],
 *         'footer'  => 'Prova Finalizada<br>Última Atualização: [timestamp]',
 *     ]
 *
 * Each row maps a column's header text to the cell's inner HTML, so a diff
 * says "Total of team 12 changed" rather than "line 812 changed". Rows stay
 * in page order: the order IS the result (positions, ties).
 *
 * Some things a visitor sees are not inside the cell but on the <td> or
 * <tr> itself: the gold/silver/bronze medal is `<td class='ouro'>`, a
 * tournament winner is `<tr class="... --winner">`. They are kept, under
 * the keys "td.class" (per column) and "tr.class", and only when present.
 * Inline style attributes are layout and are not kept.
 */
final class TableExtractor
{
    /** Key under which a row keeps the class attribute of its cells. */
    public const CELL_CLASSES = 'td.class';

    /** Key under which a row keeps its own class attribute. */
    public const ROW_CLASS = 'tr.class';

    public function __construct(private HtmlDocument $document)
    {
    }

    /**
     * @return array{caption?: string, columns: list<string>, rows: list<array<string, mixed>>, footer: ?string}
     */
    public function extract(DOMElement $table): array
    {
        $columns = $this->columnLabels($table);
        $rows = [];
        foreach ($this->bodyRows($table) as $tr) {
            $rows[] = $this->row($tr, $columns);
        }

        $extracted = [];
        $caption = $this->document->query('./caption', $table)[0] ?? null;
        if ($caption !== null) {
            $extracted['caption'] = $this->document->text($caption);
        }
        $extracted['columns'] = $columns;
        $extracted['rows'] = $rows;
        $extracted['footer'] = $this->footer($table);
        return $extracted;
    }

    /**
     * The header texts, left to right. A header with no text (the trophy
     * icon above the positions) is named after its image; two headers with
     * the same text get " (2)", " (3)" so that no cell is lost.
     *
     * @return list<string>
     */
    private function columnLabels(DOMElement $table): array
    {
        $headerRows = $this->document->query('./thead/tr', $table);
        $headerRow = end($headerRows) ?: ($this->document->query('.//tr[th]', $table)[0] ?? null);
        if ($headerRow === null) {
            return [];
        }

        $labels = [];
        foreach ($this->document->query('./th|./td', $headerRow) as $index => $th) {
            $label = $this->document->text($th);
            if ($label === '') {
                $image = $this->document->query('.//img', $th)[0] ?? null;
                $source = $image !== null ? (string)parse_url($image->getAttribute('src'), PHP_URL_PATH) : '';
                $label = $source !== '' ? '[img ' . basename($source) . ']' : self::positionalLabel($index);
            }
            $labels[] = self::unique($label, $labels);
        }
        return $labels;
    }

    /** @return list<DOMElement> rows with data cells, outside <thead> and <tfoot> */
    private function bodyRows(DOMElement $table): array
    {
        return $this->document->query('.//tr[td][not(ancestor::thead) and not(ancestor::tfoot)]', $table);
    }

    /**
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    private function row(DOMElement $tr, array $columns): array
    {
        $row = [];
        $cellClasses = [];
        foreach ($this->document->query('./td|./th', $tr) as $index => $cell) {
            $label = $columns[$index] ?? self::positionalLabel($index);
            $row[$label] = $this->document->innerHtml($cell);
            $class = HtmlDocument::classOf($cell);
            if ($class !== '') {
                $cellClasses[$label] = $class;
            }
        }
        if ($cellClasses !== []) {
            $row[self::CELL_CLASSES] = $cellClasses;
        }
        $rowClass = HtmlDocument::classOf($tr);
        if ($rowClass !== '') {
            $row[self::ROW_CLASS] = $rowClass;
        }
        return $row;
    }

    /** The text under the table (status and last update), clock masked. */
    private function footer(DOMElement $table): ?string
    {
        $parts = [];
        foreach ($this->document->query('./tfoot//th|./tfoot//td', $table) as $cell) {
            $html = $this->document->innerHtml($cell);
            if ($html !== '') {
                $parts[] = Text::maskClock($html);
            }
        }
        return $parts === [] ? null : implode(' | ', $parts);
    }

    private static function positionalLabel(int $index): string
    {
        return '(column ' . ($index + 1) . ')';
    }

    /** @param list<string> $taken */
    private static function unique(string $label, array $taken): string
    {
        $candidate = $label;
        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = $label . ' (' . $n . ')';
        }
        return $candidate;
    }
}
