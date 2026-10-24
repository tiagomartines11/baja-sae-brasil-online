<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Juiz;

use Baja\Juiz\MenuEvento;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MenuEvento::trocarPrefixoEvento(), used when an event is cloned
 * (juiz/clonar_evento.php).
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment. It
 * builds a menu, passes it through the function, and then "asserts" what
 * must come out. assertEquals(expected, actual) fails the test, and shows
 * both values, when they differ.
 *
 * The menus are written as JSON text and turned into PHP values with
 * json_decode(), exactly the way Evento::getMenu() does it. That keeps them
 * looking like what an organiser types in the event page.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Juiz/MenuEventoTest.php
 */
final class MenuEventoTest extends TestCase
{
    /**
     * Helper: clone a menu written as JSON from 25BR to 26BR and give the
     * result back as JSON, so the tests can compare plain text.
     */
    private function clonar(string $menuJson): string
    {
        $menu = json_decode($menuJson);
        $resultado = MenuEvento::trocarPrefixoEvento($menu, '25BR', '26BR');
        return json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** A first-level item is just a result ID: its prefix changes. */
    public function testTrocaIdDePrimeiroNivel(): void
    {
        $this->assertEquals('["26BR_GER"]', $this->clonar('["25BR_GER"]'));
    }

    /** IDs inside a submenu written as a list change too, and keep their order. */
    public function testTrocaIdsDentroDeSubmenuEmLista(): void
    {
        $this->assertEquals(
            '[{"Segurança":["26BR_SGE","26BR_SGD"]}]',
            $this->clonar('[{"Segurança":["25BR_SGE","25BR_SGD"]}]')
        );
    }

    /**
     * In a submenu written as an object, the keys are only labels ("1",
     * "Mapa"): they stay. Values change when they are IDs; the link to
     * mapa.php has no prefix and stays.
     */
    public function testTrocaValoresMasNaoRotulosEmSubmenuObjeto(): void
    {
        $this->assertEquals(
            '[{"Enduro":{"1":"26BR_GRI","2":"26BR_END","Mapa":"mapa.php"}}]',
            $this->clonar('[{"Enduro":{"1":"25BR_GRI","2":"25BR_END","Mapa":"mapa.php"}}]')
        );
    }

    /**
     * Only a value that STARTS with "25BR_" is an ID of the old event. A link
     * that has "25BR_" in the middle points at a file somewhere else, which
     * was not renamed, so it must not be touched.
     */
    public function testNaoMexeEmLinkQueSoContemOPrefixo(): void
    {
        $menu = '[{"Regulamento":"https://exemplo.org/arquivos/25BR_regulamento.pdf"}]';
        $this->assertEquals($menu, $this->clonar($menu));
    }

    /** An ID from a different event (e.g. one copied by hand from 24BR) stays as it is. */
    public function testNaoMexeEmIdDeOutroEvento(): void
    {
        $this->assertEquals('["24BR_GER","26BR_GER"]', $this->clonar('["24BR_GER","25BR_GER"]'));
    }

    /**
     * "125BR_X" does not start with "25BR_", so it is not an ID of 25BR. This
     * is the case a simple search-and-replace on the text would get wrong.
     */
    public function testNaoTrocaQuandoOPrefixoNaoEstaNoInicio(): void
    {
        $this->assertEquals('["125BR_X"]', $this->clonar('["125BR_X"]'));
    }

    /** An event without a menu has null, and null must come back unchanged. */
    public function testMenuVazio(): void
    {
        $this->assertNull(MenuEvento::trocarPrefixoEvento(null, '25BR', '26BR'));
    }

    /** The full example menu from the help page goes through intact. */
    public function testMenuCompleto(): void
    {
        $antes = '["25BR_GER",{"Dinâmicas":["25BR_ACE","25BR_VEL"]},'
            . '{"Fila":"https://fila.bajasaebrasil.net/fila.php?evento=25BR&fila=2"}]';
        $depois = '["26BR_GER",{"Dinâmicas":["26BR_ACE","26BR_VEL"]},'
            . '{"Fila":"https://fila.bajasaebrasil.net/fila.php?evento=25BR&fila=2"}]';
        $this->assertEquals($depois, $this->clonar($antes));
    }
}
