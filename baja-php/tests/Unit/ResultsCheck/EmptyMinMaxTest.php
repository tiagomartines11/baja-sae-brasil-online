<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\ResultsCheck;

use Baja\ResultsCheck\EmptyMinMax;
use Baja\ResultsCheck\Finding;
use Baja\ResultsCheck\FormulaSlot;
use Baja\Tests\Unit\Formula\Support\ComparesEngines;
use PHPUnit\Framework\TestCase;

/**
 * The config check's warning for max() and min() of a list that can be
 * empty for some team (BAJAAPP-56).
 *
 * HOW TO READ THESE TESTS
 *
 * Each test gives one formula to the check, and says what it reports, as
 * "where | what" lines (or nothing). Most also run the formula, with both
 * formula engines, for a team for whom the list IS empty, to show that the
 * warning is right: what is reported fails on that team, and what is not
 * reported works. assertFails() and assertGives() are the engine tests'
 * (tests/Unit/Formula/Support/ComparesEngines.php): both engines must agree.
 *
 * BACKGROUND
 *
 * max(array_diff([$T_1, $T_2], [null])) is "the best of the runs that
 * count". For a team with no run that counts the list is empty. PHP 7's
 * max([]) gave false (0 in a sum); PHP 8 throws, and the value is null. Most
 * of the seed's provas whose stored points differ from today's formulas are
 * this case: the points were stored under PHP 7, and saving the prova again
 * would store null instead (BAJAAPP-56).
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/ResultsCheck/EmptyMinMaxTest.php
 */
final class EmptyMinMaxTest extends TestCase
{
    use ComparesEngines;

    private const EMPTY = 'then PHP 8 throws a ValueError and the value is empty (PHP 7 gave false, which counts as 0)';

    /** The configs' usual pattern: the runs, without the ones that are null. */
    public function testTheBestOfTheRunsThatCount(): void
    {
        $formula = 'MAX(0,ROUND(max(array_diff([$CORR1_1, $CORR1_2], [null]))*$PESO_1,2))';

        $this->assertSame(
            ['pontos.SUS1PTS | max() of a list that is empty for a team whose $CORR1_1 and $CORR1_2 are all null; ' . self::EMPTY],
            self::check(['pontos.SUS1PTS' => $formula]),
        );
        $this->assertFails('ValueError: max(): Argument #1 ($value) must contain at least one element', $formula, ['CORR1_1' => null, 'CORR1_2' => null, 'PESO_1' => 10]);
        $this->assertGives(8.0, $formula, ['CORR1_1' => 0.8, 'CORR1_2' => null, 'PESO_1' => 10], 'a team that ran is fine');
    }

    /** One variable is enough: min(array_diff([$PONT], [null])) is empty when $PONT is null. */
    public function testOneVariable(): void
    {
        $this->assertSame(
            ['min_max.APD | min() of a list that is empty for a team whose $PONT is null; ' . self::EMPTY],
            self::check(['min_max.APD' => 'min(array_diff([$PONT],[null]))']),
        );
        $this->assertFails('ValueError: min()', 'min(array_diff([$PONT],[null]))', ['PONT' => null]);
    }

    /** Written as [], the list is always empty. */
    public function testAnEmptyListWrittenOut(): void
    {
        $this->assertSame(['x | max() of a list that is always empty; ' . self::EMPTY], self::check(['x' => 'max([])']));
    }

    /**
     * Never empty, so nothing is said: two or more arguments, a list written
     * out without array_diff (max([null, null]) is null, not an error), and
     * a value written in the list that array_diff does not take out.
     */
    public function testListsThatAreNeverEmpty(): void
    {
        $formulas = [
            'a' => 'max($T_1, $T_2)',
            'b' => 'MAX(0, $X)',
            'c' => 'max([$T_1, $T_2])',
            'd' => 'max(array_diff([0, $CORR1_1, $CORR1_2], [null]))',
            'e' => 'min(array_diff([$A, $B], []))',
            'f' => 'max(array_filter([$A, 1]))',
        ];
        $this->assertSame([], self::check($formulas));

        $nothing = ['T_1' => null, 'T_2' => null, 'X' => null, 'CORR1_1' => null, 'CORR1_2' => null, 'A' => null, 'B' => null];
        $this->assertGives(null, $formulas['a'], $nothing);
        $this->assertGives(null, $formulas['c'], $nothing);
        $this->assertGives(0, $formulas['d'], $nothing, 'the 0 written in the list stays');
        $this->assertGives(null, $formulas['e'], $nothing, 'nothing is taken out');
        $this->assertGives(1, $formulas['f'], $nothing);
    }

    /** A value written in the list that array_diff DOES take out does not help. */
    public function testAWrittenValueThatIsTakenOut(): void
    {
        $this->assertSame(
            ['x | max() of a list that is empty for a team whose $A is null or 0; ' . self::EMPTY],
            self::check(['x' => 'max(array_diff([0, $A], [null, 0]))']),
        );
        $this->assertFails('ValueError', 'max(array_diff([0, $A], [null, 0]))', ['A' => null]);
    }

    /** array_filter() with one argument keeps the truthy values: none may be left. */
    public function testArrayFilter(): void
    {
        $this->assertSame(
            ['x | min() of a list that is empty when no item in it is truthy (all null, 0, "" or false); ' . self::EMPTY],
            self::check(['x' => 'min(array_filter([$A, $B]))']),
        );
        $this->assertFails('ValueError', 'min(array_filter([$A, $B]))', ['A' => 0, 'B' => null]);
    }

    /** Lists that are not written out ([...]) cannot be read, so they can be empty. */
    public function testAListThatIsNotWrittenOut(): void
    {
        $this->assertSame(
            ['x | max() of a list that is empty when everything in it is taken out; ' . self::EMPTY],
            self::check(['x' => 'max(array_diff($RUNS, [null]))']),
        );
        $this->assertSame(
            ['x | max() of a list that is empty for a team whose $A and $B are all one of the values taken out; ' . self::EMPTY],
            self::check(['x' => 'max(array_diff([$A, $B], $OUT))']),
        );
    }

    /** Inside "? :" or "??", either answer can be the empty one. */
    public function testEitherAnswerOfATernary(): void
    {
        $this->assertSame(
            ['x | max() of a list that is always empty; ' . self::EMPTY],
            self::check(['x' => 'max($A ? [1] : [])']),
        );
        $this->assertSame(
            ['x | max() of a list that is empty for a team whose $B is null; ' . self::EMPTY],
            self::check(['x' => 'max($A ?? array_diff([$B], [null]))']),
        );
        $this->assertFails('ValueError', 'max($A ? [1] : [])', ['A' => false]);
    }

    /**
     * A "? :" that only reaches the call when the list has something in it
     * is safe: 26BR_AVF's min_max does exactly that. A condition about
     * something else does not protect the list (23SU_AVF's ERRO_ACE).
     */
    public function testACountBeforeTheCall(): void
    {
        $guarded = [
            'ACE' => '(count(array_diff([$ACE_1, $ACE_2], [null])) > 0) ? min(array_diff([$ACE_1, $ACE_2], [null])) : null',
            'b' => 'count(array_diff([$A], [null])) ? max(array_diff([$A], [null])) : 0',
            'c' => '0 < count(array_diff([$A], [null])) ? max(array_diff([$A], [null])) : 0',
            'd' => 'count(array_diff([$A], [null])) == 0 ? 0 : max(array_diff([$A], [null]))',
            'e' => '!count(array_diff([$A], [null])) ? 0 : max(array_diff([$A], [null]))',
            'f' => '$X && count(array_diff([$A], [null])) >= 1 ? max(array_diff([$A], [null])) : 0',
        ];
        $this->assertSame([], self::check($guarded));
        $nothing = ['ACE_1' => null, 'ACE_2' => null, 'A' => null, 'X' => true];
        $this->assertGives(null, $guarded['ACE'], $nothing);
        $this->assertGives(0, $guarded['d'], $nothing);

        $notGuarded = [
            'ERRO_ACE' => '($EST_ACE)?(abs($EST_ACE-min(array_diff([$ACE_1, $ACE_2], [null])))/min(array_diff([$ACE_1, $ACE_2], [null]))):null',
            'other list' => 'count(array_diff([$B], [null])) > 0 ? max(array_diff([$A], [null])) : 0',
            'wrong way round' => 'count(array_diff([$A], [null])) > 0 ? 0 : max(array_diff([$A], [null]))',
            'not a guarantee' => 'count(array_diff([$A], [null])) >= 0 ? max(array_diff([$A], [null])) : 0',
            'never true' => 'count(array_diff([$A], [null])) !== 0.0 ? max(array_diff([$A], [null])) : 0',
            'or' => '$X || count(array_diff([$A], [null])) ? max(array_diff([$A], [null])) : 0',
        ];
        $this->assertSame(
            [
                'ERRO_ACE | min() of a list that is empty for a team whose $ACE_1 and $ACE_2 are all null; ' . self::EMPTY,
                'other list | max() of a list that is empty for a team whose $A is null; ' . self::EMPTY,
                'wrong way round | max() of a list that is empty for a team whose $A is null; ' . self::EMPTY,
                'not a guarantee | max() of a list that is empty for a team whose $A is null; ' . self::EMPTY,
                'never true | max() of a list that is empty for a team whose $A is null; ' . self::EMPTY,
                'or | max() of a list that is empty for a team whose $A is null; ' . self::EMPTY,
            ],
            self::check($notGuarded),
            'the same call twice in one formula (ERRO_ACE) is said once',
        );
        $this->assertFails('ValueError', $notGuarded['ERRO_ACE'], ['EST_ACE' => 2.0, 'ACE_1' => null, 'ACE_2' => null]);
        $this->assertFails('ValueError', $notGuarded['or'], ['X' => true, 'A' => null]);
    }

    /** A formula that cannot be read is left out (its error is reported already), and so is one that is not text. */
    public function testWhatIsNotReadIsLeftOut(): void
    {
        $this->assertSame([], self::check(['a' => 'max(array_diff([$A], [null])', 'b' => 0]));
    }

    /** Each finding is of its own kind, with the formula and the place. */
    public function testTheFinding(): void
    {
        $slot = new FormulaSlot('min_max.TC', ['min_max', 'TC'], 'min(array_diff([$TC_1,$TC_2],[null]))');
        [$finding] = EmptyMinMax::of([$slot]);

        $this->assertSame(Finding::EMPTY_MIN_MAX, $finding->problem);
        $this->assertSame('min_max.TC', $finding->where);
        $this->assertSame('min(array_diff([$TC_1,$TC_2],[null]))', $finding->formula);
        $this->assertSame($slot, $finding->slot);
        $this->assertNull($finding->teams, 'it is about what CAN happen, not about the teams of today');
    }

    /**
     * @param array<string, mixed> $formulas where => formula
     * @return list<string>
     */
    private static function check(array $formulas): array
    {
        $slots = [];
        foreach ($formulas as $where => $formula) {
            $slots[] = new FormulaSlot((string)$where, [], $formula);
        }
        return array_map(fn(Finding $f) => $f->where . ' | ' . $f->detail, EmptyMinMax::of($slots));
    }
}
