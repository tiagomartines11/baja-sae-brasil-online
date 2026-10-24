<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\ResultsCheck;

use Baja\ResultsCheck\FixProposal;
use Baja\ResultsCheck\ResultadoCheck;
use Baja\ResultsCheck\SqlFix;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The SQL the check writes with --sql, which a person reads and then runs
 * against the production database. These tests check its shape; the
 * statements were also run against the dev seed (see the PR).
 *
 * For each resultado with a fix there is ONE statement, which must:
 *
 *   1. first copy the current columns into the backups, exactly as the
 *      judge page does when someone saves new columns (juiz/resultado.php,
 *      "Atualizar"): the current columns become backup "-1", the old "-1"
 *      becomes "-2", and so on down to "-5";
 *   2. then write the new columns;
 *   3. only do so if the columns are still exactly what the check saw
 *      (the "guard"), so that a change made in the meantime is never
 *      overwritten;
 *   4. come after a comment showing the old and the new formula.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/ResultsCheck/SqlFixTest.php
 */
final class SqlFixTest extends TestCase
{
    /** The columns as stored in the database, in MySQL's own spacing. */
    private const STORED = '{"pos": "Pontos", "colunas": [{"val": "\'<b>\'+$PTS+\'<b>\'", "header": "Pontos"}]}';

    public function testOneStatementHasCommentBackupNewColumnsAndGuard(): void
    {
        $sql = SqlFix::statement('26BR', '26BR_REL', 'Relatório de Projeto', self::STORED, self::fix());

        $this->assertSame(<<<'SQL'
            -- 26BR_REL  Relatório de Projeto
            --   column "Pontos" val
            --     old: '<b>'+$PTS+'<b>'
            --     new: '<b>'.$PTS.'</b>'
            UPDATE resultado
            SET colunas_backup = JSON_SET(CASE WHEN JSON_TYPE(colunas_backup) = 'OBJECT' THEN colunas_backup ELSE JSON_OBJECT() END,
                    '$."-5"', JSON_EXTRACT(colunas_backup, '$."-4"'),
                    '$."-4"', JSON_EXTRACT(colunas_backup, '$."-3"'),
                    '$."-3"', JSON_EXTRACT(colunas_backup, '$."-2"'),
                    '$."-2"', JSON_EXTRACT(colunas_backup, '$."-1"'),
                    '$."-1"', colunas),
                colunas = CAST('{"pos":"Pontos","colunas":[{"val":"\'<b>\'.$PTS.\'</b>\'","header":"Pontos"}]}' AS JSON)
            WHERE resultado_id = '26BR_REL' AND evento_id = '26BR'
              AND colunas = CAST('{"pos": "Pontos", "colunas": [{"val": "\'<b>\'+$PTS+\'<b>\'", "header": "Pontos"}]}' AS JSON);
            SELECT '26BR_REL' AS resultado, ROW_COUNT() AS updated;

            SQL, $sql);
    }

    /**
     * MySQL works out an UPDATE's "SET a = ..., b = ..." from left to right,
     * and the second one already sees the first's result. So the backup
     * must be written BEFORE colunas, or it would back up the new columns.
     */
    public function testTheBackupIsTakenBeforeTheColumnsChange(): void
    {
        $sql = SqlFix::statement('26BR', '26BR_REL', 'x', self::STORED, self::fix());

        $backup = strpos($sql, 'SET colunas_backup =');
        $columns = strpos($sql, '    colunas = CAST(');
        $this->assertNotFalse($backup);
        $this->assertNotFalse($columns);
        $this->assertLessThan($columns, $backup);
        $this->assertStringContainsString("'\$.\"-1\"', colunas)", $sql, 'Backup "-1" is the current colunas.');
    }

    /**
     * The guard compares with the columns exactly as they were read, not as
     * PHP would write them again: CAST(... AS JSON) makes MySQL compare the
     * content, and the text given is the stored text, unchanged.
     */
    public function testTheGuardIsTheExactStoredColumns(): void
    {
        $sql = SqlFix::statement('26BR', '26BR_REL', 'x', self::STORED, self::fix());

        $this->assertStringContainsString('AND colunas = CAST(' . SqlFix::quote(self::STORED) . ' AS JSON);', $sql);
    }

    /**
     * Text inside SQL quotes: a quote or a backslash in a formula must not
     * end the text early. (The file also switches off NO_BACKSLASH_ESCAPES
     * for its session, so that a backslash means what it says here.)
     */
    public function testQuotesAndBackslashesAreEscaped(): void
    {
        $this->assertSame("'it\\'s'", SqlFix::quote("it's"));
        $this->assertSame("'a\\\\b'", SqlFix::quote('a\\b'));
        $this->assertSame("'line\\none'", SqlFix::quote("line\none"));
    }

    /** A formula over several lines is shown on one line in the comment, so it stays a comment. */
    public function testAFormulaWithLineBreaksStaysInsideTheComment(): void
    {
        $stored = '{"colunas": [{"val": "\'<b>\'\n+ $PTS", "header": "P"}]}';
        $sql = SqlFix::statement('26BR', '26BR_X', 'x', $stored, self::fix($stored));

        $this->assertStringContainsString("--     old: '<b>' + \$PTS\n", $sql);
    }

    /** The whole file: one transaction around all statements; the settings it needs first. */
    public function testTheScriptWrapsEverythingInOneTransaction(): void
    {
        $script = SqlFix::script(
            [['evento' => '26BR', 'resultado' => '26BR_REL', 'nome' => 'x', 'colunas' => self::STORED, 'fix' => self::fix()]],
            ['Made from event 26BR.'],
        );

        $this->assertStringStartsWith('-- Fixes proposed by scripts/check-results-configs.php', $script);
        $this->assertStringContainsString("-- Made from event 26BR.\n", $script);
        $this->assertLessThan(strpos($script, 'START TRANSACTION;'), strpos($script, "REPLACE(@@SESSION.sql_mode, 'NO_BACKSLASH_ESCAPES', '')"));
        $this->assertLessThan(strpos($script, 'UPDATE resultado'), strpos($script, 'START TRANSACTION;'));
        $this->assertStringEndsWith("COMMIT;\n", $script);
    }

    /** Nothing to fix: a file that says so and changes nothing. */
    public function testNothingToFix(): void
    {
        $script = SqlFix::script([], ['Made from event 22SE.']);

        $this->assertStringContainsString('-- Nothing to fix.', $script);
        $this->assertStringNotContainsString('UPDATE resultado', $script);
        $this->assertStringNotContainsString('START TRANSACTION', $script);
    }

    /** A real proposal for the stored columns, made the way the tool makes it. */
    private static function fix(string $stored = self::STORED): FixProposal
    {
        $colunas = json_decode($stored);
        $teams = [Fixtures::team(1, ['PTS' => 5])];
        $fix = FixProposal::for($colunas, $teams, ResultadoCheck::run($colunas, $teams)['findings']);
        self::assertNotNull($fix);
        return $fix;
    }
}
