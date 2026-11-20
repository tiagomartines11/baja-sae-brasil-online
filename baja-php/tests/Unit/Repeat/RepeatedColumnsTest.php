<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Repeat;

use Baja\Repeat\RepeatedColumns;
use Baja\Results\Column;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\TeamInput;
use Baja\Results\ViewerContext;
use Baja\Results\EventStatus;
use Baja\ResultsCheck\Finding;
use Baja\ResultsCheck\FixProposal;
use Baja\ResultsCheck\FormulaSlot;
use Baja\ResultsCheck\ResultadoCheck;
use PHPUnit\Framework\TestCase;

/**
 * Repeated columns in a resultado (BAJAAPP-103).
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * A resultado is a public results table. Its config (typed in
 * juiz/resultado.php, in JSON) lists the columns; each column has a
 * formula, "val", worked out for every team. A column can also have a
 * LIST of formulas: the cell then shows one value per line, e.g. the time
 * of every attempt:
 *
 *     {"header": "Tentativas", "val": ["$T_1 ? ... : '-'", "$T_2 ? ... : '-'", ...]}
 *
 * With six attempts that list is long and repetitive, so a column can now
 * say it once, with {n} where the attempt number goes:
 *
 *     {"header": "Tentativas", "repetir": 6, "val": "$T_{n} ? ROUND($T_{n}, 3) : '-'"}
 *
 * RepeatedColumns::expandColumn() turns that into the list. These tests
 * check the expansion, and that the pages and the config check all read the
 * expanded column.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Repeat/RepeatedColumnsTest.php
 */
final class RepeatedColumnsTest extends TestCase
{
    /** The example of the ticket. */
    private const TENTATIVAS = '{"header": "Tentativas", "repetir": 6, "val": "$T_{n} ? ROUND($T_{n}, 3) : \'-\'", "sort": "duasmenor"}';

    /** It becomes exactly the list of formulas one would write by hand. */
    public function testARepeatedColumnBecomesTheListOfFormulas(): void
    {
        $expanded = RepeatedColumns::expandColumn(json_decode(self::TENTATIVAS));

        $this->assertEquals(json_decode('{"header": "Tentativas", "sort": "duasmenor", "val": [
            "$T_1 ? ROUND($T_1, 3) : \'-\'", "$T_2 ? ROUND($T_2, 3) : \'-\'", "$T_3 ? ROUND($T_3, 3) : \'-\'",
            "$T_4 ? ROUND($T_4, 3) : \'-\'", "$T_5 ? ROUND($T_5, 3) : \'-\'", "$T_6 ? ROUND($T_6, 3) : \'-\'"
        ]}'), $expanded);
    }

    /**
     * The results pages read columns through Column::fromJson(), so a
     * repeated column is an ordinary list column for every one of them:
     * results page, API, premiações.
     */
    public function testEveryReaderSeesAListColumn(): void
    {
        $column = Column::fromJson(json_decode(self::TENTATIVAS));

        $this->assertSame(Column::KIND_LIST, $column->kind());
        $this->assertCount(6, $column->val);
        $this->assertSame('duasmenor', $column->sort);
    }

    /**
     * Built by the real results builder, the cell holds one value per
     * attempt, as for a hand-written list ("-" where there was none).
     */
    public function testTheCellShowsOneLinePerRepetition(): void
    {
        $config = ResultsConfig::fromColunas(json_decode('{"colunas": [' . self::TENTATIVAS . ']}'));
        $team = new TeamInput(7, 7, 'Equipe', 'Escola', 'SP', false, (object)['T_1' => 4.5123, 'T_2' => null, 'T_3' => 4.25]);

        $table = ResultsBuilder::build($config, [$team], ViewerContext::publicView(new EventStatus(spoilers: false, finished: true)));
        $byHand = ResultsBuilder::build(
            ResultsConfig::fromColunas(json_decode('{"colunas": [{"header": "Tentativas", "sort": "duasmenor", "val": [
                "$T_1 ? ROUND($T_1, 3) : \'-\'", "$T_2 ? ROUND($T_2, 3) : \'-\'", "$T_3 ? ROUND($T_3, 3) : \'-\'",
                "$T_4 ? ROUND($T_4, 3) : \'-\'", "$T_5 ? ROUND($T_5, 3) : \'-\'", "$T_6 ? ROUND($T_6, 3) : \'-\'"]}]}')),
            [$team],
            ViewerContext::publicView(new EventStatus(spoilers: false, finished: true)),
        );

        $this->assertEquals($byHand->rows, $table->rows);
        $this->assertStringContainsString('4.512', (string)$table->rows[0]->display('Tentativas'));
    }

    /**
     * A val_spoilers with {n} is repeated too (one "SPOILERS" per line);
     * one without {n} stays a single value for the whole cell, as usual.
     */
    public function testValSpoilersIsRepeatedOnlyWhenItUsesN(): void
    {
        $perLine = RepeatedColumns::expandColumn(json_decode('{"header": "T", "repetir": 2, "val": "$T_{n}", "val_spoilers": "\'S{n}\'"}'));
        $once = RepeatedColumns::expandColumn(json_decode('{"header": "T", "repetir": 2, "val": "$T_{n}", "val_spoilers": "\'SPOILERS\'"}'));

        $this->assertSame(["'S1'", "'S2'"], $perLine->val_spoilers);
        $this->assertSame("'SPOILERS'", $once->val_spoilers);
    }

    /** A column without "repetir" is not touched, whatever it holds. */
    public function testOtherColumnsAreLeftAlone(): void
    {
        $plain = json_decode('{"header": "Pontos", "val": "ROUND($PTS{n}, 2)"}');

        $this->assertSame($plain, RepeatedColumns::expandColumn($plain));
    }

    /** Wrong repeated columns are refused on save (juiz/resultado.php), each with a message. */
    public function testBadRepeatedColumnsAreRefused(): void
    {
        $cases = [
            'repetir is a text' => ['{"header": "X", "repetir": "6", "val": "$T_{n}"}', 'Coluna "X": "repetir" precisa ser um número inteiro de 1 a 20'],
            'repetir is 0' => ['{"header": "X", "repetir": 0, "val": "$T_{n}"}', 'recebeu 0'],
            'repetir is 21' => ['{"header": "X", "repetir": 21, "val": "$T_{n}"}', 'recebeu 21'],
            'val is a list' => ['{"header": "X", "repetir": 2, "val": ["$T_1", "$T_2"]}', '"val" precisa ser uma fórmula'],
            'val is Pos' => ['{"header": "X", "repetir": 2, "val": "Pos"}', '"val" precisa ser uma fórmula'],
            'val without {n}' => ['{"header": "X", "repetir": 2, "val": "$T_1"}', 'precisa usar {n}'],
        ];
        foreach ($cases as $case => [$json, $message]) {
            $errors = RepeatedColumns::errors(json_decode('{"colunas": [' . $json . ']}'));
            $this->assertStringContainsString($message, implode("\n", $errors), $case);
        }
        $this->assertSame([], RepeatedColumns::errors(json_decode('{"colunas": [' . self::TENTATIVAS . ']}')));
        $this->assertSame([], RepeatedColumns::errors(null), 'blank colunas');
    }

    /** A wrong repeated column is left as written, not guessed at. */
    public function testAWrongColumnIsNotExpanded(): void
    {
        $wrong = json_decode('{"header": "X", "repetir": 0, "val": "$T_{n}"}');

        $this->assertSame($wrong, RepeatedColumns::expandColumn($wrong));
    }

    /**
     * The config check (scripts/check-results-configs.php) runs every
     * formula of the expanded column, one per line, and names each line.
     */
    public function testTheConfigCheckSeesEachLine(): void
    {
        $slots = FormulaSlot::ofResultado(json_decode('{"colunas": [{"header": "T", "repetir": 3, "val": "$T_{n}"}]}'));

        $this->assertSame(['$T_1', '$T_2', '$T_3'], array_map(fn(FormulaSlot $slot) => $slot->formula, $slots));
        $this->assertSame(['colunas', 0, 'val', 2], $slots[2]->path);
    }

    /** The config check also reports a wrong repeated column, under its own heading. */
    public function testTheConfigCheckReportsAWrongRepeatedColumn(): void
    {
        $check = ResultadoCheck::run(json_decode('{"colunas": [{"header": "T", "repetir": 50, "val": "$T_{n}"}]}'), []);

        $repeated = array_values(array_filter($check['findings'], fn(Finding $finding) => $finding->problem === Finding::REPEATED));
        $this->assertCount(1, $repeated);
        $this->assertStringContainsString('recebeu 50', $repeated[0]->detail);
    }

    /**
     * The check can propose rewriting a broken formula ("<b>" + $X becomes
     * "<b>" . $X). In a repeated column there is one formula with {n}, not
     * the lines that were run, so no line can be written back: the error is
     * reported, and no fix is proposed for it.
     */
    public function testNoFixIsProposedInsideARepeatedColumn(): void
    {
        $colunas = json_decode('{"colunas": [{"header": "T", "repetir": 2, "val": "\'<b>\' + $T_{n}"}]}');
        $team = new TeamInput(7, 7, 'Equipe', 'Escola', 'SP', false, (object)['T_1' => 4.5, 'T_2' => 4.25]);

        $check = ResultadoCheck::run($colunas, [$team]);
        $errors = array_filter($check['findings'], fn(Finding $finding) => $finding->problem === Finding::FORMULA_ERROR);

        $this->assertNotEmpty($errors, 'the broken lines are reported');
        $this->assertNull(FixProposal::for($colunas, [$team], $check['findings']));
    }
}
