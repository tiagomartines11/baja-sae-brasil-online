<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Site;

use Baja\Model\JSON\Field;
use Baja\Results\Cell;
use Baja\Results\Column;
use Baja\Results\ResultRow;
use Baja\Results\TeamIdentity;
use Baja\Site\Template;
use PHPUnit\Framework\TestCase;

/**
 * What the pages print for hostile names, labels and options (BAJAAPP-41).
 *
 * Each test builds one piece of a page with values an attacker could have
 * typed somewhere (a team name, a field label, an option of a list) and
 * checks two things in the HTML that comes out:
 *
 *   - no real "<script" tag: the text "&lt;script&gt;" is fine, it is what
 *     the visitor sees written on the page, and it runs nothing;
 *   - no quote that ends an attribute early: a value like
 *     x" autofocus onfocus="alert(1) must stay inside its value="...".
 *
 * And two things must NOT be escaped: a results cell (formula output) and a
 * column header. Both are written by the admin team as part of a resultado's
 * configuration and are meant to contain HTML such as <b>bold</b>, so they
 * must still be bold. Their sanitiser is BAJAAPP-35.
 */
final class EscapedRenderingTest extends TestCase
{
    private const SCRIPT = '<script>alert(1)</script>';
    private const QUOTE = 'x" autofocus onfocus="alert(2)';

    /** Runs $print and returns what it echoed. */
    private function renderedHtml(callable $print): string
    {
        ob_start();
        $print();
        return (string) ob_get_clean();
    }

    /**
     * The proof that a value stayed text: the escaped form is there, the raw
     * dangerous form is not.
     */
    private function assertEscaped(string $html): void
    {
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('" autofocus', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('x&quot; autofocus onfocus=&quot;alert(2)', $html);
    }

    // --- The judge's entry form (Field::printSelf) ------------------------------

    /**
     * A number field whose label holds a script, whose code (it becomes the
     * name and id attributes) holds a quote, and whose stored value holds
     * both.
     */
    public function testANumberFieldEscapesItsLabelCodeAndValue(): void
    {
        $field = new Field(self::QUOTE, self::SCRIPT);
        $field->setNumber(1);

        $html = $field->printSelf(self::SCRIPT . self::QUOTE);

        $this->assertEscaped($html);
    }

    /**
     * A list (select) field: the label and every option, both the text shown
     * and the value="..." sent back, are escaped. The stored choice is still
     * marked as selected, because the comparison uses the raw value.
     */
    public function testAListFieldEscapesItsOptions(): void
    {
        $field = new Field('APROV', self::SCRIPT);
        $field->setEnum(['Aprovado', self::QUOTE, self::SCRIPT]);

        $html = $field->printSelf(self::QUOTE);

        $this->assertEscaped($html);
        $this->assertStringContainsString('<option value="x&quot; autofocus onfocus=&quot;alert(2)" selected>', $html);
        $this->assertStringContainsString('<option value="Aprovado" >Aprovado</option>', $html);
    }

    /** A list of [label, value] pairs: both halves escaped. */
    public function testAnAssociativeListFieldEscapesLabelsAndValues(): void
    {
        $field = new Field('TRECHO', 'Trecho');
        $field->setEnumAssoc([[self::SCRIPT, self::QUOTE]]);

        $this->assertEscaped($field->printSelf(null));
    }

    /** The group and xor markers become attributes too (data-group="..."). */
    public function testGroupMarkersAreEscaped(): void
    {
        $field = new Field('NOTA', 'Nota', self::QUOTE, self::SCRIPT);
        $field->setTime();

        $this->assertEscaped($field->printSelf('10:00'));
    }

    /** A hidden field holding a stored value. */
    public function testAHiddenFieldEscapesItsValue(): void
    {
        $field = new Field('OCULTO', 'Oculto');

        $this->assertEscaped($field->printSelf(self::SCRIPT . self::QUOTE));
    }

    // --- The public results table (Site\Template) ---------------------------------

    /**
     * A column header is NOT escaped. It is part of the resultado's
     * configuration, written by the admin team like the formulas, and may
     * hold markup on purpose: the dev seed has "Pontos <b>sem</b> Finais",
     * which must stay bold. Headers and cells get their sanitiser together
     * in BAJAAPP-35.
     */
    public function testAHeaderIsPrintedAsTheAdminWroteIt(): void
    {
        $html = $this->renderedHtml(fn () => Template::printColumnHeader('Pontos <b>sem</b> Finais'));

        $this->assertSame('<th style="" >Pontos <b>sem</b> Finais</th>', trim(preg_replace('/\s+/', ' ', $html)));
    }

    /** Entities admins typed in headers keep working: "30&nbsp;m" is not "30&amp;nbsp;m". */
    public function testAHeaderWithAnEntityKeepsIt(): void
    {
        $html = $this->renderedHtml(fn () => Template::printColumnHeader('Aceleração 30&nbsp;m [s]'));

        $this->assertStringContainsString('>Aceleração 30&nbsp;m [s]</th>', $html);
    }

    /**
     * One results row: the team's name and school (from the team's
     * registration) are escaped, while the formula cell keeps its <b> and
     * its colour and so still renders bold and red.
     */
    public function testATeamNameIsEscapedButAResultsCellStillRendersBold(): void
    {
        $team = new TeamIdentity(7, 7, self::SCRIPT, self::QUOTE, 'SP', false);
        $formulaOutput = '<b style="color:red">87.5</b>';
        $row = new ResultRow($team, ['Pontos' => new Cell(Column::KIND_FORMULA, $formulaOutput, 87.5)], null, null, []);
        $columns = [
            new Column('#', 'EquipeNum'),
            new Column('Equipe', 'Equipe'),
            new Column('Pontos', '$PONTOS'),
        ];

        $html = $this->renderedHtml(fn () => Template::printResultRow($columns, $row));

        $this->assertEscaped($html);
        $this->assertStringContainsString('<td>7</td>', $html);
        $this->assertStringContainsString('<td>' . $formulaOutput . '</td>', $html);
    }

    /**
     * A team number that is not a number (a typo in the database) is
     * escaped as well; numbers are not trusted just for being in the number
     * column.
     */
    public function testATeamNumberIsEscaped(): void
    {
        $team = new TeamIdentity(1, self::SCRIPT, 'Equipe', 'Escola', 'SP', false);
        $row = new ResultRow($team, [], null, null, []);

        $html = $this->renderedHtml(fn () => Template::printResultRow([new Column('#', 'EquipeNum')], $row));

        $this->assertSame('<tr><td>&lt;script&gt;alert(1)&lt;/script&gt;</td></tr>', $html);
    }
}
