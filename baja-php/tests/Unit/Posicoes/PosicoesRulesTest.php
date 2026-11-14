<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Posicoes;

use Baja\Posicoes\EventGraph;
use Baja\Posicoes\PosicoesRules;
use PHPUnit\Framework\TestCase;

/**
 * What the judge pages refuse to save, and what the config check warns
 * about, in a prova's "posicoes" (BAJAAPP-102).
 *
 * BACKGROUND
 *
 * A prova's "posicoes" gives each team its position, either inside the
 * prova ({"valor": ...}) or in another resultado ({"resultado": ...}).
 * A mistake there would silently give every team null, and so wrong
 * points, so juiz/prova.php refuses to save it and shows the messages
 * these tests check (in Portuguese, as the page is). See also
 * EventGraphTest for how cycles are found.
 *
 * No database: the event is described by hand.
 */
final class PosicoesRulesTest extends TestCase
{
    /** Tração and a Geral; a tournament; a resultado with no ranking ("pos"). */
    private static function event(): EventGraph
    {
        return new EventGraph('26SU', ['TRA' => null, 'CMP' => null], [
            '26SU_TRA' => ['inputs' => ['TRA'], 'colunas' => (object)['pos' => 'Pontos']],
            '26SU_GER' => ['inputs' => ['TRA', 'CMP'], 'colunas' => (object)['pos' => 'Total']],
            '26SU_SPR' => ['inputs' => ['SPR'], 'colunas' => (object)['type' => 'tournament']],
            '26SU_SGE' => ['inputs' => ['SGE'], 'colunas' => (object)['colunas' => []]],
            '26SU_HID' => ['inputs' => ['TRA'], 'colunas' => (object)['pos' => 'Pontos', 'colunas' => [(object)['header' => 'Pontos', 'val' => '$P', 'val_spoilers' => '?']]]],
        ]);
    }

    /** A good config: no errors. */
    public function testAValidConfigHasNoErrors(): void
    {
        $this->assertSame([], self::errors('{"P_TRA": {"resultado": "26SU_TRA"}, "COL": {"valor": "$NOTA", "ordem": "asc", "desempate": "$X"}}'));
        $this->assertSame([], PosicoesRules::errorsForProva(self::event(), 'CMP', json_decode('{"pontos": {}}')), 'no posicoes at all');
    }

    /** Each mistake gets its own message, naming the entry. */
    public function testMistakesAreExplained(): void
    {
        $this->assertSame(['posicoes.P: o resultado 26BR_TRA não existe neste evento.'], self::errors('{"P": {"resultado": "26BR_TRA"}}'));
        $this->assertSame(['posicoes.P: o resultado 26SU_SGE não tem "pos", então não classifica ninguém.'], self::errors('{"P": {"resultado": "26SU_SGE"}}'));
        $this->assertSame(['posicoes.P: o resultado 26SU_SPR é um torneio, sem classificação por "pos".'], self::errors('{"P": {"resultado": "26SU_SPR"}}'));
        $this->assertSame(['posicoes.P: "ordem" deve ser "asc" ou "desc".'], self::errors('{"P": {"valor": "$X", "ordem": "crescente"}}'));
        $both = 'posicoes.P: use "valor" (posição dentro desta prova) ou "resultado" (posição em outro resultado), um dos dois.';
        $this->assertSame([$both], self::errors('{"P": {"valor": "$X", "resultado": "26SU_TRA"}}'));
        $this->assertSame([$both], self::errors('{"P": {}}'));
        $this->assertSame(['posicoes.P: deve ser um objeto, com "valor" ou "resultado".'], self::errors('{"P": "26SU_TRA"}'));
        $this->assertSame(['"posicoes" deve ser um objeto: {"NOME": {...}}.'], self::errors('["P"]'));
        $this->assertSame(
            ['posicoes.1P: o nome deve ser um nome de variável (letras, números e _, sem começar com número).'],
            self::errors('{"1P": {"valor": "$X"}}'),
        );
    }

    /** A position may not take the name of an input, a var or a min_max: it would overwrite it. */
    public function testANameAlreadyInUseIsRefused(): void
    {
        $params = json_decode('{"inputs": [{"code": "NOTA"}], "min_max": {"M": "$NOTA"}, "posicoes": {"NOTA": {"valor": "$NOTA"}, "M": {"valor": "$M"}}}');

        $this->assertSame([
            'posicoes.NOTA: já existe um input, var ou min_max com esse nome.',
            'posicoes.M: já existe um input, var ou min_max com esse nome.',
        ], PosicoesRules::errorsForProva(self::event(), 'CMP', $params));
    }

    /** CMP is in the Geral: taking a position from it is a cycle. */
    public function testACycleIsRefusedWithTheChain(): void
    {
        $this->assertSame(
            ['Ciclo: CMP → 26SU_GER → CMP. Os pontos desta prova dependeriam deles mesmos.'],
            self::errors('{"P": {"resultado": "26SU_GER"}}'),
        );
    }

    /**
     * On the resultado page: a resultado that a prova reads may not lose its
     * "pos", nor take in a prova that reads it.
     */
    public function testAResultadoThatProvasReadIsChecked(): void
    {
        $graph = self::event()->withProva('CMP', json_decode('{"posicoes": {"P": {"resultado": "26SU_TRA"}}}'));

        $this->assertSame([], PosicoesRules::errorsForResultado($graph, '26SU_TRA', ['TRA'], (object)['pos' => 'Pontos']));
        $this->assertSame(
            ['o resultado 26SU_TRA não tem "pos", então não classifica ninguém. As provas CMP usam a posição dele em "posicoes".'],
            PosicoesRules::errorsForResultado($graph, '26SU_TRA', ['TRA'], (object)[]),
        );
        $this->assertSame(
            ['Ciclo: 26SU_TRA → CMP → 26SU_TRA. Os pontos de uma prova dependeriam deles mesmos.'],
            PosicoesRules::errorsForResultado($graph, '26SU_TRA', ['TRA', 'CMP'], (object)['pos' => 'Pontos']),
        );
    }

    /**
     * 26SU_HID hides its points while spoilers are on. CMP's points, taken
     * from its ranking, would be shown on 26SU_CMP and the Geral, which hide
     * nothing: the config check warns. Once both hide a column too, no warning.
     */
    public function testAPositionTakenFromBehindSpoilersIsWarnedAbout(): void
    {
        $reads = json_decode('{"posicoes": {"P": {"resultado": "26SU_HID"}}}');
        $hidden = (object)['pos' => 'Pontos', 'colunas' => [(object)['header' => 'Pontos', 'val' => '$C', 'val_spoilers' => '?']]];

        // CMP is shown on 26SU_CMP and on the Geral, and neither hides anything.
        $open = self::event()->withProva('CMP', $reads)->withResultado('26SU_CMP', ['CMP'], (object)['pos' => 'Pontos']);
        $this->assertSame(
            ['takes a position from 26SU_HID, which hides its ranking while spoilers are on, but its points are shown unhidden on 26SU_CMP, 26SU_GER: they would give that ranking away'],
            PosicoesRules::warningsForProva($open, 'CMP'),
        );

        $protected = self::event()->withProva('CMP', $reads)
            ->withResultado('26SU_CMP', ['CMP'], $hidden)
            ->withResultado('26SU_GER', ['TRA', 'CMP'], $hidden);
        $this->assertSame([], PosicoesRules::warningsForProva($protected, 'CMP'));
    }

    /** @return list<string> the errors for CMP with these "posicoes" */
    private static function errors(string $posicoes): array
    {
        return PosicoesRules::errorsForProva(self::event(), 'CMP', json_decode('{"posicoes": ' . $posicoes . '}'));
    }
}
