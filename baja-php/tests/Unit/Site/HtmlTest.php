<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Site;

use Baja\Site\Html;
use PHPUnit\Framework\TestCase;

/**
 * The escape helper every page prints stored text through (BAJAAPP-41).
 *
 * Why it exists: names, labels and whatever judges type are text, but a page
 * is HTML. Printed as they are, some characters mean something to the
 * browser:
 *
 *   <  starts a tag, so "<script>...</script>" in a team name is a program
 *      the browser runs, for every visitor who opens the page;
 *   "  and ' end an attribute, so a name with a quote, put in
 *      value="...", cuts the value short and breaks the form;
 *   &  starts an entity like &amp;.
 *
 * Html::e() writes each of these as an entity (&lt; &quot; ...), which the
 * browser shows as the character itself but never reads as markup. Each test
 * below is one kind of dangerous value and what it must come out as.
 */
final class HtmlTest extends TestCase
{
    /** The everyday case: ordinary text, accents included, is not touched. */
    public function testOrdinaryTextIsUnchanged(): void
    {
        $this->assertSame('Equipe Mangue Baja - Ação 4x4', Html::e('Equipe Mangue Baja - Ação 4x4'));
    }

    /** A script tag becomes harmless text: the browser shows it, it does not run it. */
    public function testAScriptTagBecomesText(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            Html::e('<script>alert(1)</script>')
        );
    }

    /**
     * Double quotes: inside value="..." an unescaped " would end the value,
     * and the rest of the name would become new attributes, here an event
     * handler that runs a script.
     */
    public function testDoubleQuotesCannotEndAnAttribute(): void
    {
        $name = 'x" onfocus="alert(1)';
        $html = '<input value="' . Html::e($name) . '">';

        $this->assertSame('<input value="x&quot; onfocus=&quot;alert(1)">', $html);
        // The only real quotes left are the two around the value.
        $this->assertSame(2, substr_count($html, '"'));
    }

    /** Single quotes too: some pages put attributes in '...'. */
    public function testSingleQuotesCannotEndAnAttribute(): void
    {
        $this->assertSame("<a title='O&apos;Brien'>", "<a title='" . Html::e("O'Brien") . "'>");
    }

    /** A bare & is written as &amp;, as HTML wants. */
    public function testAnAmpersandIsEncoded(): void
    {
        $this->assertSame('P&amp;D Racing', Html::e('P&D Racing'));
    }

    /**
     * Text that already holds an entity is NOT encoded a second time.
     *
     * Admins have typed entities into event names and headers for years:
     * "Baja SAE&nbsp;BRASIL" in the seed. Encoding again would show the
     * visitor "&nbsp;" literally. Leaving it is safe: an entity stands for one
     * character of text and can never open a tag or end a quote, as the last
     * assertion shows (&lt; stays an encoded <, not a real one).
     */
    public function testExistingEntitiesAreKept(): void
    {
        $this->assertSame('Baja SAE&nbsp;BRASIL', Html::e('Baja SAE&nbsp;BRASIL'));
        $this->assertSame('30&nbsp;m &amp; mais', Html::e('30&nbsp;m &amp; mais'));
        $this->assertSame('&lt;b&gt; &lt;i&gt;', Html::e('&lt;b&gt; <i>'));
    }

    /** Something that looks like an entity but is not one is still encoded. */
    public function testAFakeEntityIsEncoded(): void
    {
        $this->assertSame('&amp;naoexiste', Html::e('&naoexiste'));
    }

    /** A missing value (null) prints as nothing, not as an error. */
    public function testNullIsEmpty(): void
    {
        $this->assertSame('', Html::e(null));
    }

    /** Numbers print as PHP prints them; a team number stays "12". */
    public function testNumbersPrintAsThemselves(): void
    {
        $this->assertSame('12', Html::e(12));
        $this->assertSame('87.5', Html::e(87.5));
        $this->assertSame('0', Html::e(0));
    }

    /**
     * Broken UTF-8 (a byte that is not a character) would make
     * htmlspecialchars return an empty string and hide the whole value. It is
     * replaced by the "unknown character" sign instead.
     */
    public function testInvalidUtf8IsReplacedNotDropped(): void
    {
        $this->assertSame("Equipe \u{FFFD}", Html::e("Equipe \xC3"));
    }

    // --- Html::field(), for what a form field shows and sends back -------------
    //
    // Whatever an <input> or <textarea> shows is what the form saves. So it
    // must show the stored text exactly, entities included, or editing a
    // prova would quietly change its parameters.

    /** An entity in a stored value is shown as the entity, so it is saved back unchanged. */
    public function testAFieldShowsEntitiesAsTyped(): void
    {
        // The browser reads "&amp;nbsp;" as the six characters "&nbsp;".
        $this->assertSame('30&amp;nbsp;m', Html::field('30&nbsp;m'));
        $this->assertSame('a &amp;lt; b', Html::field('a &lt; b'));
    }

    /** A field still cannot be broken out of: quotes and tags are encoded. */
    public function testAFieldEscapesQuotesAndTags(): void
    {
        $this->assertSame(
            '&lt;/textarea&gt;&lt;script&gt;x&lt;/script&gt; &quot;',
            Html::field('</textarea><script>x</script> "')
        );
        $this->assertSame('', Html::field(null));
    }

    // --- Html::js(), for data inside <script> ----------------------------------
    //
    // Inside a <script> block HTML entities mean nothing, so e() is the wrong
    // tool. The danger there is "</script>": the browser ends the script at
    // that text, wherever it is, and reads what follows as HTML.

    /** "</script>" inside a string cannot end the script block. */
    public function testJsCannotEndTheScriptBlock(): void
    {
        $out = Html::js(['nome' => '</script><script>alert(1)</script>']);

        $this->assertStringNotContainsString('</script>', $out);
        $this->assertStringNotContainsString('<', $out);
        // And JavaScript (here: PHP's JSON reader, which reads the same
        // syntax) gets back exactly the original text.
        $this->assertSame(['nome' => '</script><script>alert(1)</script>'], json_decode($out, true));
    }

    /** Quotes and & are encoded too, so the value is safe in an attribute like onclick='...'. */
    public function testJsEncodesQuotesAndAmpersands(): void
    {
        // Each character comes out as backslash, "u" and its code: ' is 0027.
        $backslashU = chr(92) . 'u';
        $this->assertSame('"' . $backslashU . '0027' . $backslashU . '0022' . $backslashU . '0026"', Html::js('\'"&'));
    }

    /** Accents stay readable: only the characters above are encoded. */
    public function testJsKeepsAccents(): void
    {
        $this->assertSame('"Aceleração"', Html::js('Aceleração'));
    }
}
