<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Programa;

use Baja\Programa\ProgramaTree;
use PHPUnit\Framework\TestCase;

/**
 * The tree of competition programs (BAJAAPP-11).
 *
 * BACKGROUND
 *
 * A program can sit under another one: the Fórum AeroDesign (AERO_FORUM)
 * is part of AeroDesign (AERO). The access rules that come next (RBAC,
 * BAJAAPP-12) read this tree to answer "does a permission given on AERO
 * reach an AERO_FORUM event?". So the tree decides who may do what, and a
 * mistake here is a security mistake:
 *
 *   - if "above" were too generous, a Baja SAE manager could reach
 *     AeroDesign events;
 *   - if it were too strict, AeroDesign managers would be locked out of
 *     the forum events they run.
 *
 * WHAT IS TESTED
 *
 * ProgramaTree on made-up trees, with no database: walking up (ancestors),
 * walking down (descendants), "is A at or above B", programs that do not
 * exist, and loops. A loop (A under B and B under A) cannot be stopped by
 * the database, and a walk that followed it would never finish, which
 * would hang the page that asked. Every walk must end, and list each
 * program once.
 */
final class ProgramaTreeTest extends TestCase
{
    /** The six real programs, as the migration creates them. */
    private function realPrograms(): ProgramaTree
    {
        return new ProgramaTree([
            'BAJA' => null,
            'AERO' => null,
            'AERO_FORUM' => 'AERO',
            'FORMULA' => null,
            'ELETROQUAD' => null,
            'H2' => null,
        ]);
    }

    /** A deeper made-up tree: TOP > MID > LEAF, and TOP > OTHER. */
    private function threeLevels(): ProgramaTree
    {
        return new ProgramaTree([
            'TOP' => null,
            'MID' => 'TOP',
            'LEAF' => 'MID',
            'OTHER' => 'TOP',
        ]);
    }

    // --- The real programs ------------------------------------------------

    /** The forum is under AeroDesign, and nothing else is above it. */
    public function testTheForumSitsUnderAeroDesign(): void
    {
        $tree = $this->realPrograms();

        $this->assertSame(['AERO'], $tree->ancestorsOf('AERO_FORUM'));
        $this->assertSame(['AERO_FORUM'], $tree->descendantsOf('AERO'));
    }

    /**
     * Baja SAE has nothing above or below it. In particular AeroDesign is
     * not under it, so whatever is given on BAJA never reaches an AERO
     * event.
     */
    public function testBajaIsNeitherAboveNorBelowAnyOtherProgram(): void
    {
        $tree = $this->realPrograms();

        $this->assertSame([], $tree->ancestorsOf('BAJA'));
        $this->assertSame([], $tree->descendantsOf('BAJA'));
        $this->assertFalse($tree->isAtOrAbove('BAJA', 'AERO'));
        $this->assertFalse($tree->isAtOrAbove('BAJA', 'AERO_FORUM'));
    }

    /** "At or above" goes down the tree, never up: AERO covers the forum, the forum does not cover AERO. */
    public function testAtOrAboveOnlyLooksDownwards(): void
    {
        $tree = $this->realPrograms();

        $this->assertTrue($tree->isAtOrAbove('AERO', 'AERO_FORUM'));
        $this->assertTrue($tree->isAtOrAbove('AERO', 'AERO'), 'A program is "at" itself.');
        $this->assertFalse($tree->isAtOrAbove('AERO_FORUM', 'AERO'), 'The forum must not reach its parent.');
    }

    // --- Deeper trees -----------------------------------------------------

    /** Ancestors come nearest first, all the way to the top, and never include the program itself. */
    public function testAncestorsAreListedNearestFirst(): void
    {
        $tree = $this->threeLevels();

        $this->assertSame(['MID', 'TOP'], $tree->ancestorsOf('LEAF'));
        $this->assertSame(['TOP'], $tree->ancestorsOf('MID'));
        $this->assertSame([], $tree->ancestorsOf('TOP'));
    }

    /** Descendants include grandchildren, nearest first. */
    public function testDescendantsIncludeEveryLevelBelow(): void
    {
        $tree = $this->threeLevels();

        $this->assertSame(['MID', 'OTHER', 'LEAF'], $tree->descendantsOf('TOP'));
        $this->assertSame(['LEAF'], $tree->descendantsOf('MID'));
        $this->assertSame([], $tree->descendantsOf('LEAF'));
    }

    /**
     * Siblings do not cover each other: OTHER and MID are both under TOP,
     * but neither is above the other or anything under the other.
     */
    public function testSiblingsAreNotAboveEachOther(): void
    {
        $tree = $this->threeLevels();

        $this->assertFalse($tree->isAtOrAbove('OTHER', 'MID'));
        $this->assertFalse($tree->isAtOrAbove('OTHER', 'LEAF'));
        $this->assertFalse($tree->isAtOrAbove('MID', 'OTHER'));
        $this->assertTrue($tree->isAtOrAbove('TOP', 'LEAF'));
    }

    // --- Programs that do not exist -----------------------------------------

    /**
     * A program that is not in the tree has nothing above or below it, and
     * is never "at or above" anything, not even itself. Otherwise a typo in
     * a stored id could look like a valid place to give permissions.
     */
    public function testAnUnknownProgramIsNotAboveAnything(): void
    {
        $tree = $this->realPrograms();

        $this->assertFalse($tree->has('NOPE'));
        $this->assertSame([], $tree->ancestorsOf('NOPE'));
        $this->assertSame([], $tree->descendantsOf('NOPE'));
        $this->assertFalse($tree->isAtOrAbove('NOPE', 'NOPE'));
        $this->assertFalse($tree->isAtOrAbove('NOPE', 'BAJA'));
        $this->assertFalse($tree->isAtOrAbove('BAJA', 'NOPE'));
    }

    // --- Loops ----------------------------------------------------------------

    /** A under B and B under A: each walk ends, and lists each program once. */
    public function testATwoProgramLoopEnds(): void
    {
        $tree = new ProgramaTree(['A' => 'B', 'B' => 'A']);

        $this->assertSame(['B'], $tree->ancestorsOf('A'));
        $this->assertSame(['A'], $tree->ancestorsOf('B'));
        $this->assertSame(['B'], $tree->descendantsOf('A'));
    }

    /** A program that is its own parent. */
    public function testAProgramUnderItselfEnds(): void
    {
        $tree = new ProgramaTree(['SELF' => 'SELF']);

        $this->assertSame([], $tree->ancestorsOf('SELF'));
        $this->assertSame([], $tree->descendantsOf('SELF'));
        $this->assertTrue($tree->isAtOrAbove('SELF', 'SELF'));
    }

    /** A longer loop, reached from a program that hangs off it. */
    public function testALoopFurtherUpEnds(): void
    {
        $tree = new ProgramaTree([
            'LEAF' => 'X',
            'X' => 'Y',
            'Y' => 'Z',
            'Z' => 'X',
        ]);

        $this->assertSame(['X', 'Y', 'Z'], $tree->ancestorsOf('LEAF'));
        // X's children are LEAF and Z (in id order), then Z's child Y. Y's
        // child would be... X again, the start, so the walk stops there.
        $this->assertSame(['LEAF', 'Z', 'Y'], $tree->descendantsOf('X'));
    }
}
