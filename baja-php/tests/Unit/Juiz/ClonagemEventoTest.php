<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Juiz;

use Baja\Juiz\ClonagemEvento;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ClonagemEvento::anoDoEvento(), used by juiz/clonar_evento.php to
 * find the year of the new event from its ID.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment.
 * assertSame(expected, actual) fails the test when the two differ.
 *
 * For an error, the test first says what it expects (expectException and
 * expectExceptionMessage) and then calls the function. If the function does
 * NOT throw that error with that message, the test fails.
 *
 * Why the error matters: clonar_evento.php shows the message of any
 * \Exception to the admin ("Falha ao clonar evento: ..."). The page used to
 * throw a class that did not exist, so the admin got a PHP crash instead of
 * the message. These tests check that the error is a real \Exception and
 * carries the text the admin should read.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Juiz/ClonagemEventoTest.php
 */
final class ClonagemEventoTest extends TestCase
{
    /** The first two digits are the year: 26BR is 2026. */
    public function testAnoVemDosDoisPrimeirosDigitos(): void
    {
        $this->assertSame(2026, ClonagemEvento::anoDoEvento('26BR'));
        $this->assertSame(2005, ClonagemEvento::anoDoEvento('05NE'));
    }

    /**
     * An ID that does not start with a year is refused with a message the
     * admin can act on. It must be a plain \Exception, the class the page
     * catches and prints.
     */
    public function testIdSemAnoInformaOMotivo(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('O novo ID de evento não começa com um código de ano válido (ex: 25BR).');

        ClonagemEvento::anoDoEvento('BR26');
    }

    /** One digit is not enough: "6BR" has no two-digit year. */
    public function testUmDigitoSoNaoBasta(): void
    {
        $this->expectException(\Exception::class);

        ClonagemEvento::anoDoEvento('6BR');
    }
}
