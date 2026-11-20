<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Posicoes;

use Baja\Posicoes\EventGraph;
use PHPUnit\Framework\TestCase;

/**
 * Which prova depends on which, through "posicoes" (BAJAAPP-102).
 *
 * BACKGROUND
 *
 * A prova's points are STORED. A prova with
 *     "posicoes": {"P_TRA": {"resultado": "26SU_TRA"}}
 * reads the ranking of the resultado 26SU_TRA, which adds up the points of
 * the provas in its "Inputs" (here, TRA). So when TRA's points change, the
 * prova must be worked out again, and then whatever depends on IT.
 *
 * EventGraph answers two questions about an event:
 *   - in what order to work the provas out again (recalculationOrder);
 *   - whether a config would make a prova depend on itself, a "cycle"
 *     (cycleFromProva, cycleFromResultado), which can never be worked out.
 *
 * These tests describe small events by hand; no database.
 */
final class EventGraphTest extends TestCase
{
    /**
     * The Compliance case: CMP takes positions from the Tração and
     * Aceleração results; LVL takes a position from the Compliance result.
     *
     *     TRA ─┐
     *          ├─> CMP ──> LVL      (an arrow: "is read by")
     *     AVF ─┘
     */
    private static function compliance(): EventGraph
    {
        return new EventGraph('26SU', [
            'TRA' => null,
            'AVF' => null,
            'CMP' => self::reads('26SU_TRA', '26SU_AVF'),
            'LVL' => self::reads('26SU_CMP'),
            'SEG' => null,
        ], [
            '26SU_TRA' => self::resultado('TRA'),
            '26SU_AVF' => self::resultado('AVF'),
            '26SU_CMP' => self::resultado('CMP'),
            '26SU_GER' => self::resultado('TRA', 'AVF', 'CMP', 'SEG'),
        ]);
    }

    /** Changing Tração: Compliance first, then LVL, which reads Compliance. */
    public function testDependentsAreWorkedOutInOrder(): void
    {
        $graph = self::compliance();

        $this->assertSame(['CMP'], $graph->dependentsOf('TRA'));
        $this->assertSame(['CMP', 'LVL'], $graph->recalculationOrder(['TRA']));
        $this->assertSame(['LVL'], $graph->recalculationOrder(['CMP']));
        $this->assertSame([], $graph->recalculationOrder(['SEG']), 'nobody reads the Geral');
    }

    /**
     * The Tração resultado's config changed: the provas reading it are
     * worked out again, with their dependents.
     */
    public function testAResultadoChangeRecalculatesItsReaders(): void
    {
        $graph = self::compliance();

        $this->assertSame(['CMP'], $graph->provasReading('26SU_TRA'));
        $this->assertSame(['CMP', 'LVL'], $graph->recalculationOrder([], $graph->provasReading('26SU_TRA')));
    }

    /**
     * A diamond: B and C both read A, and D reads both B and C. D must come
     * after BOTH, and only once, whatever order they are found in.
     */
    public function testEachProvaComesAfterEverythingItReadsAndOnlyOnce(): void
    {
        $graph = new EventGraph('99XX', [
            'A' => null,
            'B' => self::reads('R_A'),
            'C' => self::reads('R_A', 'R_B'),
            'D' => self::reads('R_C', 'R_B'),
        ], [
            'R_A' => self::resultado('A'),
            'R_B' => self::resultado('B'),
            'R_C' => self::resultado('C'),
        ]);

        $this->assertSame(['B', 'C', 'D'], $graph->recalculationOrder(['A']));
    }

    /**
     * A cycle that was saved anyway (straight into the database, say):
     * X reads R_Y, which includes Y, which reads R_X, which includes X.
     * Each prova is still listed once, so a recalculation ends.
     */
    public function testACycleIsListedOnceAndEnds(): void
    {
        $graph = new EventGraph('99XX', [
            'A' => null,
            'X' => self::reads('R_A', 'R_Y'),
            'Y' => self::reads('R_X'),
        ], [
            'R_A' => self::resultado('A'),
            'R_X' => self::resultado('X'),
            'R_Y' => self::resultado('Y'),
        ]);

        $this->assertSame(['X', 'Y'], $graph->recalculationOrder(['A']));
        $this->assertSame(['Y'], $graph->recalculationOrder(['X']), 'X itself was just worked out');
    }

    /** CMP reading the Geral, which adds up CMP: a cycle, named step by step. */
    public function testACycleThroughAProvaIsFound(): void
    {
        $graph = self::compliance()->withProva('CMP', self::reads('26SU_TRA', '26SU_GER'));

        $this->assertSame(['CMP', '26SU_GER', 'CMP'], $graph->cycleFromProva('CMP'));
        $this->assertNull(self::compliance()->cycleFromProva('CMP'), 'as it is, no cycle');
    }

    /**
     * The same from the resultado's side: putting LVL in the Tração
     * resultado's Inputs. LVL reads Compliance, which reads Tração.
     */
    public function testACycleThroughAResultadoIsFound(): void
    {
        $graph = self::compliance()->withResultado('26SU_TRA', ['TRA', 'LVL'], null);

        $this->assertSame(['26SU_TRA', 'LVL', '26SU_CMP', 'CMP', '26SU_TRA'], $graph->cycleFromResultado('26SU_TRA'));
        $this->assertNull(self::compliance()->cycleFromResultado('26SU_GER'));
    }

    /** Inputs are read the way they are typed on the resultado page: spaces and empty items do not count. */
    public function testInputsAreTrimmed(): void
    {
        $graph = (new EventGraph('99XX', ['A' => null, 'B' => self::reads('R')], []))->withResultado('R', [' A ', ''], null);

        $this->assertSame(['B'], $graph->dependentsOf('A'));
    }

    /** An event where nobody reads a resultado has no links: nothing to do after a save. */
    public function testHasLinks(): void
    {
        $this->assertTrue(self::compliance()->hasLinks());
        $this->assertFalse((new EventGraph('99XX', ['A' => (object)['posicoes' => (object)['P' => (object)['valor' => '$X']]]], []))->hasLinks());
    }

    // --- Helpers ---------------------------------------------------------------

    /** A prova's params whose "posicoes" read these resultados. */
    private static function reads(string ...$resultados): object
    {
        $posicoes = [];
        foreach ($resultados as $index => $resultado) {
            $posicoes['P' . $index] = (object)['resultado' => $resultado];
        }
        return (object)['posicoes' => (object)$posicoes];
    }

    /** @return array{inputs: list<string>, colunas: object} a resultado ranking these provas */
    private static function resultado(string ...$provas): array
    {
        return ['inputs' => $provas, 'colunas' => (object)['pos' => 'Total', 'colunas' => []]];
    }
}
