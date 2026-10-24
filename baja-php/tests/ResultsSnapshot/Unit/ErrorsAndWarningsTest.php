<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsSnapshot\Unit;

use Baja\ResultsSnapshot\Capture\ErrorText;
use Baja\ResultsSnapshot\WarningReport;
use PHPUnit\Framework\TestCase;

/**
 * What a snapshot says about a page that fails, and where the PHP warnings
 * go instead.
 *
 * WHY THIS MATTERS
 *
 * A page that dies with a fatal error shows the public no results, so "it
 * died, with this error" IS its result and must be in the snapshot: if a
 * refactor makes a working page die, --check has to fail. But WHERE it died
 * (file and line) is not a result. Any edit above that line moves it, and a
 * refactor moves it to another file. Keeping the location would make
 * unrelated edits fail --check.
 *
 * Warnings are not results at all. They are left out of the snapshot files
 * and only listed by --warnings, so that cleaning them up never needs a
 * snapshot update.
 */
final class ErrorsAndWarningsTest extends TestCase
{
    public function testAnUncaughtExceptionIsRecordedAsItsClassAndMessageOnly(): void
    {
        // What PHP reports for the BAJAAPP-50 kind of crash: the message of
        // an uncaught exception, with location and stack trace.
        $fatal = [
            'type' => 'Fatal error',
            'message' => "Uncaught Error: Call to a member function setTimezone() on null in /var/www/html/resultados/prova.php:296\n"
                . "Stack trace:\n#0 {main}\n  thrown",
            'file' => '/var/www/html/resultados/prova.php',
            'line' => 296,
        ];

        $this->assertSame('Error: Call to a member function setTimezone() on null', (new ErrorText('/var/www/html'))->error($fatal));
    }

    public function testMovingTheFailingLineOrFileDoesNotChangeTheRecordedError(): void
    {
        // The same crash after a refactor moved the code into a shared
        // builder class, on another machine.
        $before = ['type' => 'Fatal error', 'file' => '/var/www/html/resultados/prova.php', 'line' => 296,
            'message' => 'Uncaught TypeError: strip_tags(): Argument #1 ($string) must be of type string, array given in /var/www/html/resultados/prova.php:296'];
        $after = ['type' => 'Fatal error', 'file' => '/home/runner/work/x/baja-php/src/Baja/Resultados/Builder.php', 'line' => 41,
            'message' => 'Uncaught TypeError: strip_tags(): Argument #1 ($string) must be of type string, array given in /home/runner/work/x/baja-php/src/Baja/Resultados/Builder.php:41'];

        $this->assertSame(
            (new ErrorText('/var/www/html'))->error($before),
            (new ErrorText('/home/runner/work/x/baja-php'))->error($after),
        );
    }

    public function testAFatalErrorThatIsNotAnExceptionKeepsPhpsOwnWordForIt(): void
    {
        $fatal = ['type' => 'Fatal error', 'file' => '/app/resultados/prova.php', 'line' => 80,
            'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)'];

        $this->assertSame('Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
            (new ErrorText('/app'))->error($fatal));
    }

    public function testTheReportGroupsWarningsByMessageMostWidespreadFirst(): void
    {
        $report = WarningReport::render([
            '22BR/22BR_ACE.json' => ['Warning: Undefined property: stdClass::$highlight in resultados/prova.php:57'],
            '22BR/22BR_GER.json' => [
                'Warning: Undefined property: stdClass::$highlight in resultados/prova.php:57',
                'Warning: Undefined array key "POS" in resultados/prova.php:146',
            ],
            '22BR/formulas.ACE.json' => [],
        ]);

        $this->assertSame(
            "PHP warnings (report only, never compared): 2 distinct, in 2 snapshots.\n\n"
            . "Warning: Undefined property: stdClass::\$highlight in resultados/prova.php:57   (2 snapshots)\n"
            . "    22BR/22BR_ACE.json\n"
            . "    22BR/22BR_GER.json\n"
            . "Warning: Undefined array key \"POS\" in resultados/prova.php:146   (1 snapshot)\n"
            . "    22BR/22BR_GER.json\n",
            $report
        );
    }

    public function testAReportWithNothingToReportSaysSo(): void
    {
        $this->assertSame("PHP warnings (report only, never compared): none.\n", WarningReport::render(['22BR/x.json' => []]));
    }
}
