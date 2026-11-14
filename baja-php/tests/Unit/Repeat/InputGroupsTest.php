<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Repeat;

use Baja\Model\Input;
use Baja\Model\Prova;
use Baja\Repeat\InputGroups;
use PHPUnit\Framework\TestCase;

/**
 * Repeated input groups (BAJAAPP-103): one group in a prova's config that
 * stands for the same fields written N times.
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * A prova's config (typed by an organiser in juiz/prova.php, in JSON) has
 * a list "inputs": the fields a judge fills in for each team. A dynamic
 * prova allows up to six attempts ("passes"), so the same two fields, time
 * and distance, used to be written six times, as T_1, D_1, T_2, D_2 ...
 * Now the config can say it once:
 *
 *     {"repetir": 6, "nome": "Tentativa", "inputs": [
 *        {"code": "T", "name": "Tempo [s]", ...},
 *        {"code": "D", "name": "Distância [m]", ...}
 *     ]}
 *
 * InputGroups::expand() turns that back into the twelve ordinary fields.
 * Everything else in the site (the judge's page, the checks on what is
 * saved, the formulas) works with the expanded list, so the data stored for
 * each team looks exactly as if the twelve fields had been written by hand.
 * These tests check the expansion itself.
 *
 * WORDS USED BELOW
 *
 *   code   the field's short name; it is the key the value is saved under
 *          and the name of the formula variable ($T_3)
 *   pass   a number that splits the judge's form into steps; the judge
 *          finishes one pass before the next one opens
 *   xor    "one or the other": two fields with the same xor letter cannot
 *          both have a value (a car either finishes, and gets a time, or
 *          it does not, and gets a distance)
 *
 * HOW TO READ THESE TESTS
 *
 * Each method starting with "test" is one small experiment: a config in
 * JSON, what expand() makes of it, and assertSame(expected, actual), which
 * fails (and prints both) when the two differ.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Repeat/InputGroupsTest.php
 */
final class InputGroupsTest extends TestCase
{
    /** The example of the ticket: Presente, then six attempts of time-or-distance. */
    private const TRACAO = '[
        {"code": "PRESENTE", "name": "Presente", "type": "enum", "options": ["SIM", "NÃO"]},
        {"repetir": 6, "nome": "Tentativa", "inputs": [
            {"code": "T", "name": "Tempo [s]", "type": "number", "precision": 3, "xor": "A"},
            {"code": "D", "name": "Distância [m]", "type": "number", "precision": 2, "xor": "A"}
        ]}
    ]';

    /** The same prova written out by hand, as configs had to be before. */
    private const TRACAO_BY_HAND = '[
        {"code": "PRESENTE", "name": "Presente", "type": "enum", "options": ["SIM", "NÃO"]},
        {"code": "T_1", "name": "Tempo [s] (Tentativa 1)", "type": "number", "precision": 3, "xor": "A_1", "pass": 2},
        {"code": "D_1", "name": "Distância [m] (Tentativa 1)", "type": "number", "precision": 2, "xor": "A_1", "pass": 2},
        {"code": "T_2", "name": "Tempo [s] (Tentativa 2)", "type": "number", "precision": 3, "xor": "A_2", "pass": 3},
        {"code": "D_2", "name": "Distância [m] (Tentativa 2)", "type": "number", "precision": 2, "xor": "A_2", "pass": 3},
        {"code": "T_3", "name": "Tempo [s] (Tentativa 3)", "type": "number", "precision": 3, "xor": "A_3", "pass": 4},
        {"code": "D_3", "name": "Distância [m] (Tentativa 3)", "type": "number", "precision": 2, "xor": "A_3", "pass": 4},
        {"code": "T_4", "name": "Tempo [s] (Tentativa 4)", "type": "number", "precision": 3, "xor": "A_4", "pass": 5},
        {"code": "D_4", "name": "Distância [m] (Tentativa 4)", "type": "number", "precision": 2, "xor": "A_4", "pass": 5},
        {"code": "T_5", "name": "Tempo [s] (Tentativa 5)", "type": "number", "precision": 3, "xor": "A_5", "pass": 6},
        {"code": "D_5", "name": "Distância [m] (Tentativa 5)", "type": "number", "precision": 2, "xor": "A_5", "pass": 6},
        {"code": "T_6", "name": "Tempo [s] (Tentativa 6)", "type": "number", "precision": 3, "xor": "A_6", "pass": 7},
        {"code": "D_6", "name": "Distância [m] (Tentativa 6)", "type": "number", "precision": 2, "xor": "A_6", "pass": 7}
    ]';

    public static function setUpBeforeClass(): void
    {
        // Linking objects (an input to its prova) needs Propel's description
        // of the tables. Loading it reads PHP files only; it does not connect.
        require_once dirname(__DIR__, 3) . '/src/loadDatabase.php';
    }

    // ------------------------------------------------------------------
    // What a group becomes
    // ------------------------------------------------------------------

    /**
     * The codes: each field of the group once per repetition, with "_" and
     * the repetition number, in the order a judge fills them in (all of
     * attempt 1, then all of attempt 2...). Fields outside the group stay.
     */
    public function testTheCodesAreTheFieldsNumberedPerRepetition(): void
    {
        $this->assertSame(
            ['PRESENTE', 'T_1', 'D_1', 'T_2', 'D_2', 'T_3', 'D_3', 'T_4', 'D_4', 'T_5', 'D_5', 'T_6', 'D_6'],
            $this->codes(InputGroups::expand(self::json(self::TRACAO))->inputs)
        );
    }

    /** The names the judge reads: the field's name, then the group's "nome" and the number. */
    public function testTheNamesSayWhichRepetitionAFieldIsIn(): void
    {
        $inputs = InputGroups::expand(self::json(self::TRACAO))->inputs;

        $this->assertSame('Presente', $inputs[0]->name);
        $this->assertSame('Tempo [s] (Tentativa 1)', $inputs[1]->name);
        $this->assertSame('Distância [m] (Tentativa 1)', $inputs[2]->name);
        $this->assertSame('Distância [m] (Tentativa 6)', $inputs[12]->name);
    }

    /** A group with no "nome" still numbers its fields: "Tempo (3)". */
    public function testAGroupWithoutANameStillNumbersItsFields(): void
    {
        $inputs = InputGroups::expand(self::json('[{"repetir": 3, "inputs": [{"code": "T", "name": "Tempo"}]}]'))->inputs;

        $this->assertSame('Tempo (3)', $inputs[2]->name);
    }

    /**
     * Each repetition is a pass of its own, numbered AFTER the fields that
     * come before the group. PRESENTE has no "pass", which means pass 1, so
     * attempt 1 is pass 2 and attempt 6 is pass 7.
     */
    public function testEachRepetitionIsItsOwnPassAfterTheFieldsBeforeIt(): void
    {
        $passes = [];
        foreach (InputGroups::expand(self::json(self::TRACAO))->inputs as $input) {
            $passes[$input->code] = $input->pass ?? null;
        }

        $this->assertNull($passes['PRESENTE'], 'a field outside the group is left exactly as written');
        $this->assertSame(2, $passes['T_1']);
        $this->assertSame(2, $passes['D_1']);
        $this->assertSame(3, $passes['T_2']);
        $this->assertSame(7, $passes['D_6']);
    }

    /** With nothing before it, the group starts at pass 1; with pass 3 before it, at pass 4. */
    public function testThePassesStartAfterTheHighestPassBeforeTheGroup(): void
    {
        $alone = InputGroups::expand(self::json('[{"repetir": 2, "inputs": [{"code": "T", "name": "T"}]}]'))->inputs;
        $this->assertSame([1, 2], array_map(fn($input) => $input->pass, $alone));

        $afterPass3 = InputGroups::expand(self::json('[
            {"code": "A", "name": "A", "pass": 3},
            {"code": "B", "name": "B"},
            {"repetir": 2, "inputs": [{"code": "T", "name": "T"}]}
        ]'))->inputs;
        $this->assertSame([4, 5], [$afterPass3[2]->pass, $afterPass3[3]->pass]);
    }

    /**
     * Two groups in one prova: the second one's passes come after the
     * first one's, and fields after a group keep their own pass.
     */
    public function testTwoGroupsAndAFieldAfterThem(): void
    {
        $inputs = InputGroups::expand(self::json('[
            {"repetir": 2, "nome": "Ida", "inputs": [{"code": "I", "name": "Ida"}]},
            {"repetir": 3, "nome": "Volta", "inputs": [{"code": "V", "name": "Volta"}]},
            {"code": "OBS", "name": "Observação"}
        ]'));

        $passes = array_map(fn($input) => $input->pass ?? null, $inputs->inputs);
        $this->assertSame([1, 2, 3, 4, 5, null], $passes);
        $this->assertSame([1, 1, 2, 2, 2], array_map(fn($repetition) => $repetition->group, $inputs->repetitions));
    }

    /**
     * "xor" is scoped to its repetition: in attempt 3 both fields are "A_3".
     * Filling T_3 then locks D_3 and nothing else, while two plain "A"s
     * would have made all twelve fields one single either-or.
     */
    public function testXorIsScopedToItsRepetition(): void
    {
        $xor = [];
        foreach (InputGroups::expand(self::json(self::TRACAO))->inputs as $input) {
            $xor[$input->code] = $input->xor ?? null;
        }

        $this->assertNull($xor['PRESENTE']);
        $this->assertSame('A_1', $xor['T_1']);
        $this->assertSame('A_1', $xor['D_1']);
        $this->assertSame('A_3', $xor['T_3']);
        $this->assertSame('A_3', $xor['D_3']);
        $this->assertSame('A_6', $xor['D_6']);
    }

    /** Everything else about a field (type, precision, options, sensitive...) is copied as written. */
    public function testTheRestOfAFieldIsCopied(): void
    {
        $inputs = InputGroups::expand(self::json('[{"repetir": 2, "inputs": [
            {"code": "F", "name": "Freio", "type": "enum", "options": ["OK", "NOK"], "sensitive": true}
        ]}]'))->inputs;

        $this->assertSame('enum', $inputs[1]->type);
        $this->assertSame(['OK', 'NOK'], $inputs[1]->options);
        $this->assertTrue($inputs[1]->sensitive);
    }

    /** Expanding does not change the config it was given (the admin page shows it again as typed). */
    public function testTheConfigItselfIsNotChanged(): void
    {
        $config = self::json(self::TRACAO);
        $before = json_encode($config);

        InputGroups::expand($config);

        $this->assertSame($before, json_encode($config));
    }

    /**
     * Besides the fields, expand() says which repetition each one is in, for
     * the judge's page (one block per repetition) and the save checks.
     */
    public function testTheRepetitionsAreListed(): void
    {
        $expanded = InputGroups::expand(self::json(self::TRACAO));

        $this->assertCount(6, $expanded->repetitions);
        $third = $expanded->repetitions[2];
        $this->assertSame('Tentativa 3', $third->title());
        $this->assertSame(['T_3', 'D_3'], $third->codes);
        $this->assertSame(4, $third->pass);
        $this->assertSame('repeticao-1-3', $third->anchor());
        $this->assertSame($third, $expanded->repetitionOf('D_3'));
        $this->assertNull($expanded->repetitionOf('PRESENTE'));
        $this->assertSame($expanded->repetitions[1], $expanded->previous($third));
        $this->assertNull($expanded->previous($expanded->repetitions[0]));
    }

    // ------------------------------------------------------------------
    // The same as writing it by hand
    // ------------------------------------------------------------------

    /**
     * THE MAIN PROMISE: the group expands to exactly the fields one would
     * have written by hand. So data saved under a hand-written T_1 ... T_6
     * config is read the same way, and nothing stored has to change.
     * (tests/Prova/Db/RepeatedGroupsPointsTest checks the points too.)
     */
    public function testAGroupExpandsToExactlyTheHandWrittenFields(): void
    {
        $this->assertEquals(
            InputGroups::expand(self::json(self::TRACAO_BY_HAND))->inputs,
            InputGroups::expand(self::json(self::TRACAO))->inputs
        );
    }

    /** The same, as the judge's pages see it: Prova::getParamsInputs() gives the same fields. */
    public function testTheJudgePagesGetTheSameFields(): void
    {
        $byHand = $this->fields(self::TRACAO_BY_HAND);
        $grouped = $this->fields(self::TRACAO);

        $describe = fn(array $fields) => array_map(fn($field) => [
            $field->getCode(), $field->getName(), $field->getPass(), $field->getXor(), $field->getType(), $field->getPrecision(),
        ], $fields);
        $this->assertEquals($describe($byHand), $describe($grouped));
    }

    /** A config without any group comes back exactly as it was given. */
    public function testAConfigWithoutGroupsIsLeftAlone(): void
    {
        $config = self::json(self::TRACAO_BY_HAND);
        $expanded = InputGroups::expand($config);

        $this->assertSame($config, $expanded->inputs);
        $this->assertSame([], $expanded->repetitions);
        $this->assertSame([], $expanded->lists);
        $this->assertSame([], $expanded->errors);
    }

    // ------------------------------------------------------------------
    // $T_lista
    // ------------------------------------------------------------------

    /**
     * For each repeated code T the formulas also get $T_lista: the values of
     * T_1 ... T_6 that were filled in, in order. Empty ones are left out,
     * so a car that ran twice has a list of two.
     */
    public function testTheListsHoldTheFilledValuesInOrder(): void
    {
        $expanded = InputGroups::expand(self::json(self::TRACAO));
        $dados = (object)['PRESENTE' => 'SIM', 'T_1' => 4.5, 'D_1' => null, 'T_2' => null, 'D_2' => 12.25, 'T_3' => 4.25, 'T_4' => ''];

        $this->assertSame(
            ['T_lista' => [4.5, 4.25], 'D_lista' => [12.25]],
            $expanded->listVariables($dados)
        );
        $this->assertSame(['T_lista' => [], 'D_lista' => []], $expanded->listVariables(null), 'no data yet: empty lists');
    }

    /**
     * The formulas of a prova really see $T_lista: here a "vars" formula
     * counts the attempts and a "min_max" one takes the best time. Run with
     * the site's own code (Input::updateVars), with no database.
     */
    public function testTheProvaFormulasSeeTheLists(): void
    {
        $prova = new Prova();
        $prova->setParams((object)[
            'inputs' => self::json(self::TRACAO),
            'vars' => (object)['TENTATIVAS' => 'CONTAR($T_lista) + CONTAR($D_lista)'],
            'min_max' => (object)['T' => 'MENOR($T_lista)'],
        ]);
        $input = new Input();
        $input->setProva($prova);
        $input->setDados(['PRESENTE' => 'SIM', 'T_1' => 4.5, 'D_2' => 12.25, 'T_3' => 4.25]);

        $input->updateVars();

        $this->assertEquals((object)['TENTATIVAS' => 3, 'T' => 4.25], $input->getVars());
    }

    /**
     * A prova without groups gets no list, and its formulas see the stored
     * dados exactly as before (the same object, not a copy), so nothing
     * changes for the configs that exist today.
     */
    public function testAProvaWithoutGroupsSeesItsDadosAsBefore(): void
    {
        $prova = new Prova();
        $prova->setParams((object)['inputs' => self::json(self::TRACAO_BY_HAND)]);
        $input = new Input();
        $input->setProva($prova);
        $input->setDados(['T_1' => 4.5]);

        $this->assertEquals((object)['T_1' => 4.5], $input->getFormulaDados());
        $this->assertIsObject($input->getFormulaDados());
    }

    // ------------------------------------------------------------------
    // Configs that are refused
    // ------------------------------------------------------------------

    /**
     * A config with a problem is refused when the organiser saves it
     * (juiz/prova.php shows these messages). Each case below is one wrong
     * config and a piece of the message it must get.
     */
    public function testBadConfigsAreRefusedWithAMessage(): void
    {
        $cases = [
            'repetir is a text' => ['[{"repetir": "6", "inputs": [{"code": "T", "name": "T"}]}]', '"repetir" precisa ser um número inteiro de 1 a 20 (recebeu "6")'],
            'repetir is 0' => ['[{"repetir": 0, "inputs": [{"code": "T", "name": "T"}]}]', 'recebeu 0'],
            'repetir is 21' => ['[{"repetir": 21, "inputs": [{"code": "T", "name": "T"}]}]', 'recebeu 21'],
            'repetir is 2.5' => ['[{"repetir": 2.5, "inputs": [{"code": "T", "name": "T"}]}]', 'recebeu 2.5'],
            'repetir is missing' => ['[{"nome": "Tentativa", "inputs": [{"code": "T", "name": "T"}]}]', 'Grupo "Tentativa": "repetir" precisa ser'],
            'empty group' => ['[{"repetir": 6, "nome": "Tentativa", "inputs": []}]', 'Grupo "Tentativa": o grupo não tem campos'],
            'group without inputs' => ['[{"repetir": 6}]', 'Grupo 1: o grupo não tem campos'],
            'group in a group' => ['[{"repetir": 2, "inputs": [{"code": "A", "name": "A"}, {"repetir": 2, "inputs": [{"code": "B", "name": "B"}]}]}]', 'campo 2: um grupo repetido não pode ter outro grupo dentro'],
            'field without code' => ['[{"repetir": 2, "inputs": [{"name": "Sem código"}]}]', 'campo 1: falta o "code"'],
            'pass inside a group' => ['[{"repetir": 2, "inputs": [{"code": "T", "name": "T", "pass": 3}]}]', 'não use "pass" dentro de um grupo'],
            'collides with a field before' => ['[{"code": "T_2", "name": "T 2"}, {"repetir": 3, "inputs": [{"code": "T", "name": "T"}]}]', 'O código T_2 aparece mais de uma vez'],
            'collides with a field after' => ['[{"repetir": 3, "inputs": [{"code": "T", "name": "T"}]}, {"code": "T_3", "name": "T 3"}]', 'O código T_3 aparece mais de uma vez'],
            'two groups, same code' => ['[{"repetir": 2, "inputs": [{"code": "T", "name": "T"}]}, {"repetir": 2, "inputs": [{"code": "T", "name": "T"}]}]', 'O código T_1 aparece mais de uma vez'],
            'code ending in _lista' => ['[{"code": "X_lista", "name": "X"}]', 'O código X_lista termina em "_lista"'],
            'group code ending in _lista' => ['[{"repetir": 2, "inputs": [{"code": "T_lista", "name": "T"}]}]', 'O código T_lista termina em "_lista"'],
        ];
        foreach ($cases as $case => [$json, $message]) {
            $errors = InputGroups::expand(self::json($json))->errors;
            $this->assertNotSame([], $errors, $case . ': should be refused');
            $this->assertStringContainsString($message, implode("\n", $errors), $case);
        }
    }

    /** 1 and 20 are the limits, and both are fine. */
    public function testOneAndTwentyRepetitionsAreFine(): void
    {
        $this->assertSame([], InputGroups::expand(self::json('[{"repetir": 1, "inputs": [{"code": "T", "name": "T"}]}]'))->errors);
        $twenty = InputGroups::expand(self::json('[{"code": "P", "name": "P"}, {"repetir": 20, "inputs": [{"code": "T", "name": "T"}]}]'));
        $this->assertSame([], $twenty->errors);
        $this->assertSame(21, $twenty->inputs[20]->pass, 'pass goes past the old limit of 10');
    }

    /**
     * Two hand-written fields with the same code are an old config's own
     * business: they were saved before and must still save. Only a clash
     * that involves a group is new, and refused.
     */
    public function testOldDuplicateCodesWithoutGroupsAreNotRefused(): void
    {
        $this->assertSame([], InputGroups::expand(self::json('[{"code": "T", "name": "T"}, {"code": "T", "name": "T de novo"}]'))->errors);
    }

    /** A wrong group is left out of the fields (not guessed at); the rest still works. */
    public function testAWrongGroupIsLeftOut(): void
    {
        $expanded = InputGroups::expand(self::json('[{"code": "P", "name": "P"}, {"repetir": 0, "inputs": [{"code": "T", "name": "T"}]}]'));

        $this->assertSame(['P'], $this->codes($expanded->inputs));
    }

    /** What prova.php checks on save: the parameters as decoded, or null for a blank box. */
    public function testErrorsOfParams(): void
    {
        $this->assertSame([], InputGroups::errorsOfParams(null));
        $this->assertSame([], InputGroups::errorsOfParams(self::json('{"pontos": {"X": "1"}}')));
        $this->assertCount(1, InputGroups::errorsOfParams(self::json('{"inputs": [{"repetir": 99, "inputs": [{"code": "T", "name": "T"}]}]}')));
    }

    // ------------------------------------------------------------------

    private static function json(string $json): mixed
    {
        $decoded = json_decode($json);
        self::assertNotNull($decoded, 'the JSON of the test itself is broken');
        return $decoded;
    }

    /** @return list<string> */
    private function codes(array $inputs): array
    {
        return array_map(fn($input) => $input->code, $inputs);
    }

    private function fields(string $inputsJson): array
    {
        $prova = new Prova();
        $prova->setParams((object)['inputs' => self::json($inputsJson)]);
        return $prova->getParamsInputs();
    }
}
