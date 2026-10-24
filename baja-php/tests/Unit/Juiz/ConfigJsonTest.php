<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Juiz;

use Baja\Juiz\ConfigJson;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ConfigJson::decodificar(), which decides whether the JSON typed in
 * juiz/prova.php, juiz/resultado.php and juiz/evento.php may be saved.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment: it hands
 * some text to the function and checks what comes back. The checks are
 * "assertions":
 *
 *   assertTrue(x)            fails unless x is true
 *   assertFalse(x)           fails unless x is false
 *   assertNull(x)            fails unless x is null (nothing)
 *   assertSame(a, b)         fails unless a and b are equal AND of the same type
 *   assertEquals(a, b)       fails unless a and b hold the same values
 *
 * When one fails, PHPUnit prints what it expected and what it got.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Juiz/ConfigJsonTest.php
 */
final class ConfigJsonTest extends TestCase
{
    /** Correct JSON is accepted and decoded, objects as stdClass like json_decode(). */
    public function testJsonValidoEhDecodificado(): void
    {
        $resultado = ConfigJson::decodificar('{"type": "rolling", "inputs": [1, 2]}');

        $this->assertTrue($resultado->valido());
        $this->assertNull($resultado->erro);
        $this->assertEquals((object) ['type' => 'rolling', 'inputs' => [1, 2]], $resultado->valor);
    }

    /**
     * A comma after the last element is the most common typo, and it is not
     * valid JSON. It must be refused, with the message PHP gives, so the page
     * can show it instead of saving null.
     */
    public function testVirgulaSobrandoNoFinalEhRecusada(): void
    {
        $resultado = ConfigJson::decodificar('["26BR_ACE", "26BR_VEL",]');

        $this->assertFalse($resultado->valido());
        $this->assertSame('Syntax error', $resultado->erro);
        $this->assertNull($resultado->valor);
    }

    /** Single quotes are fine in PHP and JavaScript but not in JSON. */
    public function testAspasSimplesSaoRecusadas(): void
    {
        $this->assertFalse(ConfigJson::decodificar("{'type': 'rolling'}")->valido());
    }

    /**
     * An empty box is accepted and means "no configuration" (null). The pages
     * show a stored null as an empty box, so this has to work, or the rest of
     * the form could never be saved again for such a record.
     */
    public function testTextoVazioViraNull(): void
    {
        $resultado = ConfigJson::decodificar('');

        $this->assertTrue($resultado->valido());
        $this->assertNull($resultado->valor);
    }

    /** Spaces and new lines only count as empty too. */
    public function testSoEspacosViraNull(): void
    {
        $resultado = ConfigJson::decodificar("  \r\n\t ");

        $this->assertTrue($resultado->valido());
        $this->assertNull($resultado->valor);
    }

    /** No text at all (the field was not sent) is treated like an empty box. */
    public function testCampoAusenteViraNull(): void
    {
        $this->assertTrue(ConfigJson::decodificar(null)->valido());
    }

    /**
     * "null" is valid JSON, so it is accepted and stores null. It is the way
     * to clear a configuration on purpose. This is the case a check like
     * "json_decode() returned null, so it failed" would get wrong.
     */
    public function testLiteralNullEhAceito(): void
    {
        $resultado = ConfigJson::decodificar('null');

        $this->assertTrue($resultado->valido());
        $this->assertNull($resultado->valor);
    }
}
