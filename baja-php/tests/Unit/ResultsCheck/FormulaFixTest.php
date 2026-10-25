<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\ResultsCheck;

use Baja\ResultsCheck\FormulaFix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one fix the check writes by itself: text joined with "+".
 *
 * BACKGROUND
 *
 * Formulas are PHP. In PHP, "." joins text and "+" adds numbers:
 *
 *     '<b>' . 12 . '</b>'    gives  <b>12</b>
 *     '<b>' + 12 + '</b>'    is an error in PHP 8 (the cell is left empty)
 *
 * FormulaFix::propose(formula) gives the formula with "." instead of "+"
 * wherever a "+" touches a piece of text in quotes (a "string literal"),
 * or null when there is nothing to fix. Everything else is left exactly as
 * written: a "+" between numbers still adds them.
 *
 * HOW TO READ THESE TESTS
 *
 * Most tests here are tables: each line of a "provider" (the methods
 * returning an array) is one case, "name" => [formula as written, what
 * propose() must give]. PHPUnit runs the test method once per line and
 * names the failing line if one fails.
 *
 * In the PHP code below, a formula is written inside double quotes, so a
 * "$" is written "\$" there. The formula itself contains just "$".
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/ResultsCheck/FormulaFixTest.php
 */
final class FormulaFixTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function rewrites(): array
    {
        return [
            // The seed's own case (25BR, 26BR...): both "+" become ".", and
            // the second <strong>, which should have closed the first, is closed.
            'single quotes, as in the seed' => [
                "'<strong>'+ROUND(\$RELPTS,2)+'<strong>'",
                "'<strong>'.ROUND(\$RELPTS,2).'</strong>'",
            ],
            'double quotes' => [
                '"<b>" + $PONTOS + "</b>"',
                '"<b>" . $PONTOS . "</b>"',
            ],
            'text after a number' => [
                "ROUND(\$TEMPO, 2) + ' s'",
                "ROUND(\$TEMPO, 2) . ' s'",
            ],
            // 23SE_PRO in the seed. Inside the brackets numbers are added,
            // and must stay added; only the "+" next to '<b>' joins text.
            'nested brackets: inner "+" adds numbers and stays' => [
                "'<b>'+(ROUND(\$A,2)+(ROUND(\$B,2))+ROUND(\$C,2)).'</b>'",
                "'<b>'.(ROUND(\$A,2)+(ROUND(\$B,2))+ROUND(\$C,2)).'</b>'",
            ],
            'text inside a condition' => [
                "\$OK ? 'Sim: ' + \$N : '-'",
                "\$OK ? 'Sim: ' . \$N : '-'",
            ],
            // "1.'x'" would be read by PHP as the number "1." followed by
            // 'x', so a space is put in between.
            'a number touching the new "."' => [
                "1+' ponto'",
                "1 .' ponto'",
            ],
        ];
    }

    #[DataProvider('rewrites')]
    public function testPlusNextToTextBecomesDot(string $formula, string $expected): void
    {
        $this->assertSame($expected, FormulaFix::propose($formula));
    }

    /** @return array<string, array{string}> */
    public static function leftAlone(): array
    {
        return [
            'numbers added to numbers' => ['$P1 + $P2 + 10'],
            'functions added' => ['ROUND($A, 2) + ROUND($B, 2)'],
            'already joined with "."' => ["'<b>' . \$P . '</b>'"],
            // The "+" here is a character of the text, not an operator.
            'a "+" inside the text' => ["'1+1=' . \$X"],
            'a "+" inside double-quoted text' => ['"A+" . $X'],
            // '5' + $X adds 5: PHP reads text that is a number as a number.
            'text that is a number' => ["'5' + \$X"],
            // Here "+" is a sign, not joining anything.
            'a plus sign in front of text' => ["+'3'"],
        ];
    }

    /** propose() gives null: there is nothing to fix. */
    #[DataProvider('leftAlone')]
    public function testEverythingElseIsLeftAlone(string $formula): void
    {
        $this->assertNull(FormulaFix::propose($formula));
    }

    /**
     * Only a "+" touching text changes, also when the formula has text with
     * a "+" in it: here the first "+" is inside the quotes and stays.
     */
    public function testPlusInsideTextStaysWhilePlusOutsideChanges(): void
    {
        $this->assertSame("'A+B: ' . \$X", FormulaFix::propose("'A+B: ' + \$X"));
    }

    /**
     * The tag fix only comes with a "+" fix: a formula that works is never
     * touched, even with a repeated tag (it is not what this tool fixes).
     */
    public function testTagIsOnlyFixedTogetherWithPlus(): void
    {
        $this->assertNull(FormulaFix::propose("'<b>' . \$X . '<b>'"));
    }

    /** @return array<string, array{string, string}> */
    public static function tags(): array
    {
        return [
            'opened twice, never closed: second one closed' => ["'<b>' . \$X . '<b>'", "'<b>' . \$X . '</b>'"],
            'capitals kept' => ["'<B>' . \$X . '<B>'", "'<B>' . \$X . '</B>'"],
            'double quotes' => ['"<i>" . $X . "<i>"', '"<i>" . $X . "</i>"'],
            'already closed: left alone' => ["'<b>' . \$X . '</b>'", "'<b>' . \$X . '</b>'"],
            // "<br>" never has a closing tag; two of them are two line breaks.
            'a tag that never closes: left alone' => ["'<br>' . \$X . '<br>'", "'<br>' . \$X . '<br>'"],
            'opened three times: not obvious, left alone' => ["'<b>' . \$X . '<b>' . '<b>'", "'<b>' . \$X . '<b>' . '<b>'"],
            'a tag with attributes: not obvious, left alone' => ["'<b class=\"x\">' . \$X . '<b class=\"x\">'", "'<b class=\"x\">' . \$X . '<b class=\"x\">'"],
            'tags in the formula, outside text: not touched' => ['$A <b> $B', '$A <b> $B'],
        ];
    }

    #[DataProvider('tags')]
    public function testUnclosedTagPair(string $formula, string $expected): void
    {
        $this->assertSame($expected, FormulaFix::closeRepeatedTags($formula));
    }

    /**
     * A formula PHP cannot even read in full (here the quote is never
     * closed) is not touched: the rewrite could not tell text from code.
     */
    public function testUnreadableFormulaIsLeftAlone(): void
    {
        $this->assertNull(FormulaFix::propose("'<b>' + \$X + '</b>"));
    }
}
