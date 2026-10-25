<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\ResultsCheck;

use Baja\ResultsCheck\Finding;
use Baja\ResultsCheck\FormulaSlot;
use Baja\ResultsCheck\Report;
use PHPUnit\Framework\TestCase;

/**
 * How the report shows a FATAL error: a formula that kills PHP.
 *
 * BACKGROUND
 *
 * Each resultado and prova is checked in a PHP process of its own. When
 * one of those processes dies, the check records a Finding::FATAL for it
 * (see IsolatedCheck, and tests/ResultsCheck/Db/FatalFormulaTest.php for
 * the whole thing against a database). This file is only about the text:
 * Report::render() is given findings made by hand, as plain PHP objects,
 * so no database and no process is involved.
 *
 * What the reader must get:
 *   - the fatal ones FIRST, before any event, and what each means in
 *     production;
 *   - under the event, the formula and, for a nested ternary, both ways
 *     to read it, with no fix: a person must choose.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/ResultsCheck/ReportFatalTest.php
 */
final class ReportFatalTest extends TestCase
{
    private const FORMULA = '$DTA == 1 ? 0 : $DTA == 2 ? -30 : -90';
    private const MESSAGE = 'Unparenthesized `a ? b : c ? d : e` is not supported. Use either `(a ? b : c) ? d : e` or `a ? b : (c ? d : e)`';

    /**
     * One resultado dies on a nested ternary. The report opens with a
     * banner naming it, and its block under the event shows the formula,
     * the error and both readings.
     */
    public function testANestedTernaryIsListedFirstWithBothReadings(): void
    {
        $slot = new FormulaSlot('column "Pontos" val', ['colunas', 3, 'val'], self::FORMULA);
        $report = Report::render([self::event(
            resultados: [self::deadResultado([new Finding(Finding::FATAL, $slot->where, self::MESSAGE, self::FORMULA, slot: $slot)])],
        )], null);

        $this->assertStringStartsWith(
            "!!! 1 FATAL ERROR !!!\n"
            . "PHP stops dead on these: no try/catch can catch it, and nothing after it runs.\n"
            . "\n"
            . "  18SU_APR  Apresentação  (resultado: its results page dies outright in production)\n"
            . "      column \"Pontos\" val: " . self::MESSAGE . "\n"
            . "\n"
            . "The formulas, and how to read them, are under each event below.\n"
            . "\n"
            . "=== 18SU ===\n",
            $report,
        );
        $this->assertStringContainsString(
            "18SU_APR  Apresentação  (PHP died while checking it, so its other checks did not run)\n"
            . "  FATAL ERROR: PHP dies\n"
            . "    column \"Pontos\" val\n"
            . "      formula: \$DTA == 1 ? 0 : \$DTA == 2 ? -30 : -90\n"
            . "      fatal:   " . self::MESSAGE . "\n"
            . "      A \"? :\" inside another without brackets: PHP 8 refuses to guess which one comes first.\n"
            . "      PHP 7 read it as:  (\$DTA == 1 ? 0 : \$DTA == 2) ? -30 : -90\n"
            . "      probably meant:    \$DTA == 1 ? 0 : (\$DTA == 2 ? -30 : -90)\n"
            . "      no fix:  a person must pick the reading and add the brackets; the two can give different results.\n",
            $report,
        );
        $this->assertStringContainsString("\n1 resultados and 0 provas have a FATAL ERROR, listed at the top.\n", $report);
    }

    /**
     * When no single formula dies on its own, the finding is about the
     * resultado (here a prova) as a whole: no formula line, and no
     * readings, which only make sense for a nested ternary.
     */
    public function testAFatalErrorNotPinnedToAFormula(): void
    {
        $message = 'Allowed memory size of 536870912 bytes exhausted (tried to allocate 20480 bytes)'
            . '  (none of its formulas dies when run alone, so which one is to blame is not known)';
        $report = Report::render([self::event(
            provas: [['prova' => '18SU_END', 'nome' => 'Enduro', 'teams' => null, 'findings' => [new Finding(Finding::FATAL, 'the prova as a whole', $message)]]],
        )], null);

        $this->assertStringContainsString("  18SU_END  Enduro  (prova: a judge saving an entry for it gets a fatal error)\n", $report);
        $this->assertStringContainsString(
            "    FATAL ERROR: PHP dies\n"
            . "      the prova as a whole\n"
            . "        fatal:   " . $message . "\n",
            $report,
        );
        $this->assertStringNotContainsString('formula:', $report);
        $this->assertStringNotContainsString('PHP 7 read it as', $report);
    }

    /** Without fatal errors there is no banner: the report starts with the first event, as before. */
    public function testNoBannerWithoutFatalErrors(): void
    {
        $report = Report::render([self::event()], null);

        $this->assertStringStartsWith('=== 18SU ===  no problems', $report);
        $this->assertStringNotContainsString('FATAL ERROR,', $report);
    }

    /** An event as IsolatedCheck hands it to Report::render(). */
    private static function event(array $resultados = [], array $provas = []): array
    {
        return ['event' => '18SU', 'resultados' => $resultados, 'provas' => $provas];
    }

    /** A resultado whose check died: no team count, no fix. */
    private static function deadResultado(array $findings): array
    {
        return ['resultado' => '18SU_APR', 'nome' => 'Apresentação', 'teams' => null, 'findings' => $findings, 'fix' => null, 'skipped' => null];
    }
}
