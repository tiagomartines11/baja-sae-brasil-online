<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Rbac;

use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeTree;
use Baja\Rbac\Scope\ScopeType;
use Baja\Tests\Unit\Rbac\Support\MapParentFinder;
use PHPUnit\Framework\TestCase;

/**
 * The scope tree (BAJAAPP-12), on a made-up tree with no database.
 *
 * BACKGROUND
 *
 * Every permission is given on a NODE of this tree:
 *
 *     platform
 *       programa BAJA                 programa AERO
 *         evento 26BR                   programa AERO_FORUM
 *           prova 26BR/END                evento 26FA
 *           prova 26BR/ABS
 *           fila  26BR/1
 *
 * and reaches every node BELOW it. "Is A at or above B?" is therefore the
 * question every access check comes down to. If it said yes too easily,
 * someone with a role on one prova could act on its sibling, or on the
 * whole event. If it said no too easily, people would be locked out of
 * what they run.
 *
 * WHAT IS TESTED
 *
 *   - the way up from each kind of node, in order, ending at the platform;
 *   - "at or above" is true going down, false going up and sideways;
 *   - a node that does not exist is UNKNOWN: no way up, nothing is above
 *     it, and the tree says so (a warning) instead of guessing;
 *   - a loop in the data ends the walk as unknown, instead of hanging;
 *   - a new kind of node can be added with one more finder.
 *
 * The real finders, over the real tables, are tested in
 * tests/Rbac/Db/AuthorizationTest.php.
 */
final class ScopeTreeTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> warnings the tree gave */
    private array $warnings = [];

    private function tree(array $extraFinders = []): ScopeTree
    {
        $platform = ScopeNode::platform();
        return new ScopeTree(array_merge([
            new MapParentFinder(ScopeType::PROGRAMA, [
                'BAJA' => $platform,
                'AERO' => $platform,
                'AERO_FORUM' => ScopeNode::programa('AERO'),
            ]),
            new MapParentFinder(ScopeType::EVENTO, [
                '26BR' => ScopeNode::programa('BAJA'),
                '26FA' => ScopeNode::programa('AERO_FORUM'),
            ]),
            new MapParentFinder(ScopeType::PROVA, [
                '26BR/END' => ScopeNode::evento('26BR'),
                '26BR/ABS' => ScopeNode::evento('26BR'),
            ]),
            new MapParentFinder(ScopeType::FILA, [
                '26BR/1' => ScopeNode::evento('26BR'),
            ]),
        ], $extraFinders), function (string $message, array $context): void {
            $this->warnings[] = [$message, $context];
        });
    }

    /** @param ScopeNode[]|null $nodes */
    private static function keys(?array $nodes): ?array
    {
        return $nodes === null ? null : array_map(fn(ScopeNode $n) => $n->key(), $nodes);
    }

    // --- The way up ---------------------------------------------------------

    public function testAProvaGoesUpThroughItsEventAndProgramToThePlatform(): void
    {
        $this->assertSame(
            ['prova:26BR/END', 'evento:26BR', 'programa:BAJA', 'platform:'],
            self::keys($this->tree()->pathToRoot(ScopeNode::prova('26BR', 'END')))
        );
    }

    public function testAnEventOfTheForumGoesUpThroughBothAeroPrograms(): void
    {
        $this->assertSame(
            ['programa:AERO_FORUM', 'programa:AERO', 'platform:'],
            self::keys($this->tree()->ancestorsOf(ScopeNode::evento('26FA')))
        );
    }

    public function testTheQueueHangsFromItsEvent(): void
    {
        $this->assertSame(
            ['evento:26BR', 'programa:BAJA', 'platform:'],
            self::keys($this->tree()->ancestorsOf(ScopeNode::fila('26BR', 1)))
        );
    }

    public function testThePlatformHasNothingAbove(): void
    {
        $this->assertSame([], self::keys($this->tree()->ancestorsOf(ScopeNode::platform())));
    }

    // --- At or above: down yes, up and sideways no ---------------------------

    public function testEachNodeIsAtOrAboveEverythingBelowIt(): void
    {
        $tree = $this->tree();
        $prova = ScopeNode::prova('26BR', 'END');

        $this->assertTrue($tree->isAtOrAbove($prova, $prova), 'A node is "at" itself.');
        $this->assertTrue($tree->isAtOrAbove(ScopeNode::evento('26BR'), $prova));
        $this->assertTrue($tree->isAtOrAbove(ScopeNode::programa('BAJA'), $prova));
        $this->assertTrue($tree->isAtOrAbove(ScopeNode::platform(), $prova));
    }

    /** Nothing reaches UP: a prova is not above its event, an event not above its program. */
    public function testNothingIsAboveItsOwnParent(): void
    {
        $tree = $this->tree();

        $this->assertFalse($tree->isAtOrAbove(ScopeNode::prova('26BR', 'END'), ScopeNode::evento('26BR')));
        $this->assertFalse($tree->isAtOrAbove(ScopeNode::evento('26BR'), ScopeNode::programa('BAJA')));
        $this->assertFalse($tree->isAtOrAbove(ScopeNode::programa('AERO_FORUM'), ScopeNode::programa('AERO')));
    }

    /** Nothing reaches SIDEWAYS: two provas of one event, a prova and a queue, two programs. */
    public function testSiblingsAreNotAboveEachOther(): void
    {
        $tree = $this->tree();

        $this->assertFalse($tree->isAtOrAbove(ScopeNode::prova('26BR', 'END'), ScopeNode::prova('26BR', 'ABS')));
        $this->assertFalse($tree->isAtOrAbove(ScopeNode::prova('26BR', 'END'), ScopeNode::fila('26BR', 1)));
        $this->assertFalse($tree->isAtOrAbove(ScopeNode::programa('BAJA'), ScopeNode::evento('26FA')));
        $this->assertFalse($tree->isAtOrAbove(ScopeNode::programa('BAJA'), ScopeNode::programa('AERO')));
    }

    // --- Unknown nodes -----------------------------------------------------------

    /**
     * A prova that does not exist has no way up at all, so nothing (not even
     * the platform) is above it, and every check on it will deny. The tree
     * warns, so a broken link in a page shows in the logs.
     */
    public function testANodeThatDoesNotExistIsUnknownAndWarned(): void
    {
        $tree = $this->tree();
        $missing = ScopeNode::prova('26BR', 'NOPE');

        $this->assertNull($tree->pathToRoot($missing));
        $this->assertFalse($tree->exists($missing));
        $this->assertFalse($tree->isAtOrAbove(ScopeNode::platform(), $missing));
        $this->assertNotEmpty($this->warnings);
        $this->assertSame('prova:26BR/NOPE', $this->warnings[0][1]['node']);
    }

    /** A prova of an event that does not exist is unknown too, even if the finder had it. */
    public function testANodeWhoseParentIsMissingIsUnknown(): void
    {
        $tree = $this->tree([
            new MapParentFinder(ScopeType::PROVA, ['99XX/END' => ScopeNode::evento('99XX')]),
        ]);

        $this->assertNull($tree->pathToRoot(ScopeNode::prova('99XX', 'END')));
    }

    /** Ids that are not of the form <event>/<id> are unknown, not an error. */
    public function testAMalformedIdIsUnknown(): void
    {
        $tree = $this->tree();

        $this->assertNull($tree->pathToRoot(new ScopeNode(ScopeType::PROVA, 'no-slash')));
        $this->assertNull($tree->pathToRoot(new ScopeNode(ScopeType::FILA, '26BR/1/2')));
    }

    /** There is one platform node, with an empty id. "platform:x" is not it. */
    public function testOnlyTheRealPlatformNodeIsThePlatform(): void
    {
        $this->assertNull($this->tree()->pathToRoot(new ScopeNode(ScopeType::PLATFORM, 'x')));
    }

    /** A type no finder answers for is unknown. */
    public function testATypeWithoutAFinderIsUnknown(): void
    {
        $tree = new ScopeTree([], fn() => null);

        $this->assertNull($tree->pathToRoot(ScopeNode::evento('26BR')));
    }

    // --- Loops -----------------------------------------------------------------

    /**
     * Programs X under Y and Y under X: corrupt data. The walk stops and the
     * node is unknown (denied), rather than looping forever or guessing
     * where the platform is.
     */
    public function testALoopEndsAsUnknown(): void
    {
        $tree = new ScopeTree([
            new MapParentFinder(ScopeType::PROGRAMA, [
                'X' => ScopeNode::programa('Y'),
                'Y' => ScopeNode::programa('X'),
            ]),
        ], function (string $message, array $context): void {
            $this->warnings[] = [$message, $context];
        });

        $this->assertNull($tree->pathToRoot(ScopeNode::programa('X')));
        $this->assertStringContainsString('loops', $this->warnings[0][0]);
    }

    // --- How grants name nodes -------------------------------------------------

    /**
     * A grant stores its node as two plain strings, type and id
     * (user_role.scope_type, scope_id). Every type comes back as the same
     * node. This is why a new kind of node later needs a ScopeType case and
     * a ParentFinder, and no change to grants.
     */
    public function testEveryNodeTypeIsStoredAsPlainTypeAndId(): void
    {
        $nodes = [
            ScopeNode::platform(), ScopeNode::programa('AERO'), ScopeNode::evento('26BR'),
            ScopeNode::prova('26BR', 'END'), ScopeNode::fila('26BR', 3),
        ];
        foreach ($nodes as $node) {
            $this->assertTrue($node->equals(ScopeNode::fromStored($node->type->value, $node->id)), $node->key());
        }
    }

    /**
     * A grant stored on a type this code does not know (written by a later
     * version, or by hand) is on no node at all, so it reaches nothing.
     */
    public function testAStoredTypeThisCodeDoesNotKnowIsNoNode(): void
    {
        $this->assertNull(ScopeNode::fromStored('sessao', '26BR/END/1'));
    }
}
