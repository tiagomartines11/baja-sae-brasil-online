<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Programa;

use PHPUnit\Framework\TestCase;
use PropelMigration_1793070000 as ProgramaMigration;

require_once dirname(__DIR__, 3) . '/generated-migrations/PropelMigration_1793070000.php';

/**
 * Which program each existing event goes to, when the database is
 * migrated (BAJAAPP-11).
 *
 * BACKGROUND
 *
 * Before this change, the only thing that said "26BR is a Baja SAE event"
 * was the end of its code, BR. The migration reads that ending ONCE, for
 * the events that already exist, and stores the program in
 * evento.programa_id. After that the stored value is the truth.
 *
 * The table it uses is the one agreed in the plan:
 *
 *     BR, SE, SU, NE  ->  BAJA        (Baja SAE: national and regional)
 *     AR              ->  AERO        (AeroDesign)
 *     FA              ->  AERO_FORUM  (Fórum AeroDesign)
 *     FO              ->  FORMULA     (Fórmula SAE)
 *     EQ              ->  ELETROQUAD
 *     H2              ->  H2          (H2 Challenge)
 *
 * WHY IT MATTERS FOR SECURITY
 *
 * Permissions will be given per program. An event put under the wrong
 * program would be managed by the wrong people. So the migration must
 * never guess: an ending that is not in the table stops it.
 *
 * WHAT IS TESTED
 *
 * The table itself, without a database: every ending above gives its
 * program, and anything else gives none. That the migration really stops,
 * and that its UPDATE gives the same answers as this table, is tested on
 * MySQL in tests/Programa/Db/BackfillTest.php.
 */
final class BackfillMappingTest extends TestCase
{
    /** One made-up event code for every ending in the plan, and its program. */
    public static function everySuffixInThePlan(): array
    {
        return [
            'Baja SAE national' => ['26BR', 'BAJA'],
            'Baja SAE Sudeste' => ['25SE', 'BAJA'],
            'Baja SAE Sul' => ['25SU', 'BAJA'],
            'Baja SAE Nordeste' => ['25NE', 'BAJA'],
            'AeroDesign' => ['25AR', 'AERO'],
            'Fórum AeroDesign' => ['25FA', 'AERO_FORUM'],
            'Fórmula SAE' => ['25FO', 'FORMULA'],
            'Eletroquad' => ['25EQ', 'ELETROQUAD'],
            'H2 Challenge' => ['25H2', 'H2'],
        ];
    }

    /** @dataProvider everySuffixInThePlan */
    public function testEverySuffixInThePlanGivesItsProgram(string $eventoId, string $programaId): void
    {
        $this->assertSame($programaId, ProgramaMigration::programaForEventCode($eventoId));
    }

    /**
     * The table has exactly the plan's nine endings, no more. An extra one
     * would be a program nobody agreed on.
     */
    public function testTheTableHasNothingBeyondThePlan(): void
    {
        $expected = [];
        foreach (self::everySuffixInThePlan() as [$eventoId, $programaId]) {
            $expected[substr($eventoId, 2, 2)] = $programaId;
        }
        ksort($expected);
        $actual = ProgramaMigration::PROGRAMA_BY_SUFFIX;
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    /** Codes the migration must refuse to guess about. */
    public static function codesWithNoKnownProgram(): array
    {
        return [
            'an ending nobody planned' => ['25XX'],
            'lower case' => ['25br'],
            'too short to have an ending' => ['2B'],
            'empty' => [''],
            // The ending is read at the end of a 4-character code, characters
            // 3 and 4. "BR" at the start is not an ending.
            'right letters, wrong place' => ['BR25'],
        ];
    }

    /** @dataProvider codesWithNoKnownProgram */
    public function testAnyOtherCodeHasNoProgram(string $eventoId): void
    {
        $this->assertNull(ProgramaMigration::programaForEventCode($eventoId));
    }

    /** The check that stops the migration lists exactly the codes it cannot place. */
    public function testTheUnknownCodesAreTheOnesListed(): void
    {
        $this->assertSame(
            ['25XX', '24zz'],
            ProgramaMigration::eventCodesWithUnknownSuffix(['26BR', '25XX', '25AR', '24zz', '25H2'])
        );
        $this->assertSame([], ProgramaMigration::eventCodesWithUnknownSuffix(['26BR', '25FA']));
    }

    /**
     * The UPDATE is built from the same table, has a case for every ending,
     * and only fills events that have no program yet: it never overwrites
     * one that was set.
     */
    public function testTheUpdateCoversEverySuffixAndOnlyFillsEmptyOnes(): void
    {
        $sql = ProgramaMigration::backfillSql();

        foreach (ProgramaMigration::PROGRAMA_BY_SUFFIX as $suffix => $programaId) {
            $this->assertStringContainsString("WHEN '$suffix' THEN '$programaId'", $sql);
        }
        $this->assertStringContainsString('WHERE `programa_id` IS NULL', $sql);
    }
}
