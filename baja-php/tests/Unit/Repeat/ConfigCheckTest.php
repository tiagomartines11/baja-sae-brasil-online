<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Repeat;

use Baja\Model\Prova;
use Baja\ResultsCheck\Finding;
use Baja\ResultsCheck\ProvaCheck;
use Baja\ResultsCheck\UnknownVariables;
use PHPUnit\Framework\TestCase;

/**
 * The config check (scripts/check-results-configs.php, see
 * docs/results-config-check.md) and repeated input groups (BAJAAPP-103).
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * The config check reads every prova's formulas and lists, among other
 * things, the variables a formula reads that nothing gives it: almost
 * always a typo ($T_7 in a prova with six attempts). To know what is a
 * typo, it needs the list of the prova's fields. With a repeated group,
 * those are the EXPANDED fields (T_1 ... T_6) and the lists ($T_lista),
 * not what is literally written in the config (one "T"). If the check read
 * the config as written, every $T_3 would be reported as a typo.
 *
 * It also reports a group that the admin page would refuse to save.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Repeat/ConfigCheckTest.php
 */
final class ConfigCheckTest extends TestCase
{
    private const INPUTS = [
        ['code' => 'PRESENTE', 'name' => 'Presente', 'type' => 'enum', 'options' => ['SIM', 'NÃO']],
        ['repetir' => 6, 'nome' => 'Tentativa', 'inputs' => [
            ['code' => 'T', 'name' => 'Tempo', 'type' => 'number', 'precision' => 3, 'xor' => 'A'],
            ['code' => 'D', 'name' => 'Distância', 'type' => 'number', 'precision' => 2, 'xor' => 'A'],
        ]],
    ];

    /**
     * $T_1 ... $T_6 and $T_lista are known; $T_7 (there are only six
     * attempts) and $T (the code as written, not a variable) are reported.
     */
    public function testTheExpandedFieldsAndTheListsAreKnown(): void
    {
        $prova = self::prova([
            'inputs' => self::INPUTS,
            'vars' => ['ULTIMA' => '$T_6 ?? $D_6', 'TYPO' => '$T_7', 'AS_WRITTEN' => '$T'],
            'min_max' => ['T' => 'MENOR($T_lista)', 'D' => 'MAIOR($D_lista)'],
        ]);

        $unknown = array_map(fn(Finding $finding) => $finding->where . ' | ' . $finding->detail, UnknownVariables::ofProva($prova, []));

        $this->assertSame([
            'vars.TYPO | $T_7 is given by nothing here, so it is empty for every team',
            'vars.AS_WRITTEN | $T is a var or a min_max: a var only sees the input fields, so here it is empty',
        ], $unknown);
    }

    /**
     * For a resultado, the prova "declares" its expanded fields (so $T_3 in
     * a column is no typo), but not the lists: a resultado's formulas see
     * the stored data, which has no $T_lista.
     */
    public function testAResultadoSeesTheFieldsButNotTheLists(): void
    {
        $declared = UnknownVariables::declaredBy(self::prova(['inputs' => self::INPUTS]));

        $this->assertArrayHasKey('T_3', $declared);
        $this->assertArrayHasKey('D_6', $declared);
        $this->assertArrayNotHasKey('T_lista', $declared);
    }

    /** A group the admin page would refuse is reported, under its own heading. */
    public function testABadGroupIsReported(): void
    {
        $prova = self::prova(['inputs' => [['repetir' => 30, 'nome' => 'Tentativa', 'inputs' => [['code' => 'T', 'name' => 'T', 'type' => 'number']]]]]);

        $repeated = array_values(array_filter(ProvaCheck::run($prova, [])['findings'], fn(Finding $finding) => $finding->problem === Finding::REPEATED));

        $this->assertCount(1, $repeated);
        $this->assertSame('inputs', $repeated[0]->where);
        $this->assertStringContainsString('recebeu 30', $repeated[0]->detail);
    }

    private static function prova(array $params): Prova
    {
        $prova = new Prova();
        $prova->setParams(json_decode((string)json_encode($params)));
        return $prova;
    }
}
