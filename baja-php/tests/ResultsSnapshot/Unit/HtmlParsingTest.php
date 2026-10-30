<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsSnapshot\Unit;

use Baja\ResultsSnapshot\Html\HtmlDocument;
use Baja\ResultsSnapshot\Html\ResultsPageReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the snapshot tool reads out of broken HTML, pinned character by
 * character.
 *
 * WHY THIS MATTERS
 *
 * A snapshot test compares what the code produces today with a file that
 * was written earlier. It is only useful if a difference means "the results
 * changed". If the same page, with the same results, can produce a
 * different file depending on WHICH MACHINE runs the tool (your laptop, the
 * dev container, CI), then a difference means nothing: people learn to
 * re-run --update without reading, and a real change slips through.
 *
 * That happened (BAJAAPP-85). The tool used to parse pages with PHP's
 * DOMDocument::loadHTML, which hands the work to libxml2, a C library
 * installed on the machine, not by Composer. When GitHub's ubuntu-latest
 * moved to a newer libxml2, that library started reading one broken tag in
 * 22SE_SGD differently, and the snapshot check failed on every branch
 * without a single line of our code or data changing.
 *
 * So pages are now parsed by masterminds/html5, a parser written in PHP and
 * pinned in composer.lock: every machine runs the very same parser code.
 * These tests feed it the kinds of broken markup the results pages really
 * print and assert the exact output. If one fails after a dependency or
 * PHP update, the parser's behaviour moved, and every snapshot built with
 * it would move too: find out why before running --update.
 *
 * Every test works on made-up HTML, so none of them needs a database.
 */
final class HtmlParsingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}> cell HTML as the page prints it => cell as the snapshot keeps it
     */
    public static function cells(): array
    {
        return [
            // The tag that broke CI. 22SE_SGD's Status formula printed
            // "<spanstyle=..." (no space), so the tag NAME is "spanstyle"
            // and its colour is lost. Older libxml2 read it as below; the
            // newer one kept the quote and colour as part of the name.
            '22SE_SGD tag with a missing space' => [
                "<spanstyle='color:red;font-weight:bold'>NOK</span>",
                '<spanstyle>NOK</spanstyle>',
            ],
            // The same typo in 22SE_SUS (cone and gate markers). Those
            // branches never run on the seed's data, but a page could print
            // them.
            '22SE_SUS cone and gate with a missing space' => [
                "170.89(3<imgclass='cone'>/5<imgclass='gate'>)",
                '170.89(3<imgclass>/5<imgclass>)</imgclass></imgclass>',
            ],
            // The correct cone and gate markers. Single and double quotes
            // and `<br />` versus `<br>` look the same in a browser, so they
            // come out the same.
            'cone and gate pictures' => [
                "170.89 (3<img class='cone'>/5<img class=\"gate\">)<br />-",
                '170.89 (3<img class="cone">/5<img class="gate">)<br>-',
            ],
            // Tags left open are closed where the cell ends.
            'unclosed tags' => [
                '<b>377.64<i>(1º)',
                '<b>377.64<i>(1º)</i></b>',
            ],
            // Attribute values without quotes get quotes, and a bare & in
            // one is written as &amp;.
            'attributes without quotes' => [
                '<span class=ouro title=Alfa&Beta>1</span>',
                '<span class="ouro" title="Alfa&amp;Beta">1</span>',
            ],
            // Named and numbered entities become the characters they stand
            // for, except & and <, which must stay escaped to remain HTML.
            // &nbsp; becomes a non-breaking space, which the whitespace
            // clean-up then counts as a space.
            'entities' => [
                'A &amp; B &lt; C&nbsp;D &eacute; &#233; &#xE9; &quot;x&quot;',
                'A &amp; B &lt; C D é é é "x"',
            ],
            // Accented names are read as UTF-8, not garbled.
            'accented text' => [
                'FATECNÓLOGOS<br><i class="nomeEscola">FATEC SÃO PAULO</i>',
                'FATECNÓLOGOS<br><i class="nomeEscola">FATEC SÃO PAULO</i>',
            ],
            // Tags closed in the wrong order are put back into a tree.
            '<b> and <span> closed in the wrong order' => [
                '<b><span style="color:green">OK</b></span> fim',
                '<b><span style="color:green">OK</span></b> fim',
            ],
            '<span> and <b> closed in the wrong order' => [
                "<span style='color:red'><b>NOK</span></b>",
                '<span style="color:red"><b>NOK</b></span>',
            ],
        ];
    }

    #[DataProvider('cells')]
    public function testACellIsReadTheSameWayOnEveryMachine(string $printed, string $kept): void
    {
        $document = new HtmlDocument('<html><body><table><tr><td>' . $printed . '</td></tr></table></body></html>');
        $cell = $document->query('//td')[0];

        $this->assertSame($kept, $document->innerHtml($cell));
    }

    public function testTheVisibleTextOfACellHasItsEntitiesDecoded(): void
    {
        // Column headers are compared by their text, not their HTML.
        $document = new HtmlDocument('<table><tr><th>Pen.&nbsp;ICTS &amp; Doc<br>(pts)</th></tr></table>');

        $this->assertSame('Pen. ICTS & Doc (pts)', $document->text($document->query('//th')[0]));
    }

    public function testABrokenTableStillBecomesRowsAndColumns(): void
    {
        // prova.php prints `<th ">Equipe` (a stray quote, dropped). A cell
        // left open ends at the next cell or at </tr>, a row left open ends
        // at the next <tr>, and a page that died mid-table keeps the rows
        // it printed.
        $tables = ResultsPageReader::tables(
            '<table><tr><th ">Equipe<th>Total</tr>'
            . '<tr><td>Alfa<td><b>377.64</tr>'
            . '<tr><td>Beta</td><td>12,5</td>'
            . '<tr><td>Gama</td><td>9'
        );

        $this->assertSame([[
            'columns' => ['Equipe', 'Total'],
            'rows' => [
                ['Equipe' => 'Alfa', 'Total' => '<b>377.64</b>'],
                ['Equipe' => 'Beta', 'Total' => '12,5'],
                ['Equipe' => 'Gama', 'Total' => '9'],
            ],
            'footer' => null,
        ]], $tables);
    }

    public function testANewRowDoesNotCloseAHeaderLeftOpen(): void
    {
        // A known limit of this parser, pinned so that nobody meets it by
        // surprise: `<th>Total<tr>`, with neither </th> nor </tr>, puts the
        // next rows INSIDE the open header (a browser would close it; an
        // open <td> is closed correctly). No results page prints that
        // today: every snapshot came out the same as with the old parser.
        // If a page ever does, its columns will look like the one below;
        // fix the page's markup.
        $tables = ResultsPageReader::tables('<table><tr><th>Equipe<th>Total<tr><td>Alfa</td><td>1</td></tr></table>');

        $this->assertSame(['Equipe', 'TotalAlfa1'], $tables[0]['columns']);
    }
}
