<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Where a row's numbers come from: the teams' inputs.
 *
 * A resultado can combine several provas (its "inputs"). Each team then has
 * one input row per prova, and the builder merges them into one set of
 * variables per team. A prova can also have several runs per team
 * ("entries"); each run becomes its own row.
 *
 * Some of what is tested here is odd, and marked as such: it is how the
 * site behaves today, and the snapshots of real events depend on it. Those
 * are fixed in their own changes, not silently here.
 *
 * (How to read these tests: see the comment at the top of
 * ColumnFormulasTest.)
 */
final class TeamEntriesTest extends TestCase
{
    private const POINTS = '{"colunas": [{"header": "Pontos", "val": "$PONTOS"}]}';

    /** Inputs of two provas are merged, so one formula can use both. */
    public function testInputsOfSeveralProvasAreMerged(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "Total", "val": "$DINAMICA + $ESTATICA"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['DINAMICA' => 300]),
            Fixtures::team(1, ['ESTATICA' => 200]),
        ]);

        $this->assertCount(1, $table->rows, 'still one team, one row');
        $this->assertSame(500, $table->rows[0]->display('Total'));
    }

    /** Variables worked out by the prova ("vars") can be used too. */
    public function testVarsOfTheInputAreAvailable(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [Fixtures::team(1, [], ['PONTOS' => 33])]);

        $this->assertSame(33, $table->rows[0]->display('Pontos'));
    }

    /** A prova with several runs ("entries") gives one row per run, each with its own values. */
    public function testEachRunIsItsOwnRow(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [
            Fixtures::team(1, ['entries' => [['PONTOS' => 10], ['PONTOS' => 20]]]),
        ]);

        $this->assertSame([10, 20], array_map(fn($row) => $row->display('Pontos'), $table->rows));
        $this->assertSame([1, 1], Fixtures::numbers($table->rows), 'both rows are car 1');
    }

    /**
     * When a team already has several runs, a second prova's input is merged
     * into its FIRST run only, and the other runs are lost. Odd, but it is
     * today's behaviour (BAJAAPP-44 area).
     */
    public function testSingleInputAfterRunsKeepsOnlyTheFirstRun(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "Total", "val": "$PONTOS + $BONUS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['entries' => [['PONTOS' => 10], ['PONTOS' => 20]]]),
            Fixtures::team(1, ['BONUS' => 5]),
        ]);

        $this->assertCount(1, $table->rows);
        $this->assertSame(15, $table->rows[0]->display('Total'));
    }

    /**
     * With a "filter", teams whose filter variable is not truthy are left
     * out. The filter reads the team's own variables: the "vars" and
     * "pontos" the prova worked out, not what the judges typed for a run
     * ("dados", which belong to the run).
     */
    public function testFilterLeavesOutTeams(): void
    {
        $config = Fixtures::config('{"filter": "PRESENTE", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 10], ['PRESENTE' => 1]),
            Fixtures::team(2, ['PONTOS' => 20], ['PRESENTE' => 0]),
            Fixtures::team(3, ['PONTOS' => 30]),
        ]);

        $this->assertSame([1], Fixtures::numbers($table->rows));
    }

    /** A filter variable typed by the judges for a run is not seen by the filter. */
    public function testFilterDoesNotSeeTheRunsOwnData(): void
    {
        $config = Fixtures::config('{"filter": "PRESENTE", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['PRESENTE' => 1, 'PONTOS' => 10])]);

        $this->assertSame([], $table->rows);
    }

    /**
     * The filter is checked after EACH input. A team dropped by its first
     * input comes back with its second one, but without what the first one
     * had: here car 1 loses its PONTOS. That is BAJAAPP-44, kept until its
     * own fix.
     */
    public function testFilterWithSeveralInputsDropsEarlierData(): void
    {
        $config = Fixtures::config('{"filter": "PRESENTE", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 10]),
            Fixtures::team(1, [], ['PRESENTE' => 1]),
        ]);

        $this->assertSame([1], Fixtures::numbers($table->rows));
        $this->assertNull($table->rows[0]->display('Pontos'));
    }

    /** A disqualified team is still listed, and says so (the page writes "Desclassificada"). */
    public function testDisqualifiedTeamIsListedAndMarked(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [
            Fixtures::team(1, ['PONTOS' => 10]),
            Fixtures::team(2, ['PONTOS' => 99], [], 'MG', true),
        ]);

        $this->assertSame([1, 2], Fixtures::numbers($table->rows));
        $this->assertFalse($table->rows[0]->team->isDisqualified());
        $this->assertTrue($table->rows[1]->team->isDisqualified());
        $this->assertSame(99, $table->rows[1]->display('Pontos'), 'its scores are shown as usual');
    }

    /** A disqualified team is ranked like any other: the ranking does not look at it. */
    public function testDisqualifiedTeamIsStillRanked(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 10]),
            Fixtures::team(2, ['PONTOS' => 99], [], 'MG', true),
        ]);

        $this->assertSame([1 => 2, 2 => 1], Fixtures::positions($table->rows));
    }
}
