<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Juiz;

use Baja\Juiz\EntryBlocks;
use Baja\Juiz\EntryValidator;
use Baja\Model\JSON\Field;
use Baja\Model\Prova;
use PHPUnit\Framework\TestCase;

/**
 * The judge's entry page (juiz/entry.php) for a prova with a repeated
 * input group (BAJAAPP-103), and the server's checks when it is saved.
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * The prova used here is Tração with six attempts. Its config says
 * "Presente" once and then a group of two fields, repeated 6 times:
 *
 *     PRESENTE                    (SIM / NÃO)
 *     Tentativa 1:  T_1  D_1      time OR distance (xor "A_1")
 *     Tentativa 2:  T_2  D_2      time OR distance (xor "A_2")
 *     ...
 *     Tentativa 6:  T_6  D_6
 *
 * On the page every attempt ("repetition") is a block of its own, and the
 * blocks follow a rule: attempt n+1 is LOCKED until attempt n is complete.
 * "Complete" means every field has a value, except that of a time-or-
 * distance pair one side is enough. Earlier attempts stay editable.
 *
 * Two pieces of code are tested:
 *
 *   EntryBlocks     what the page shows: each block's state (filled, partly
 *                   filled, empty or locked), where its buttons lead, and
 *                   which block a "Salvar" belongs to
 *   EntryValidator  what the server refuses when the form is saved; the
 *                   browser locks fields, but a request does not have to
 *                   come from the browser, so the rule is checked again
 *
 * (The same rules, while the judge types, are in default/js/repeticoes.js
 * and tested by tests/js/repeticoes.test.js.)
 *
 * HOW TO READ THESE TESTS
 *
 * A form "post" is an array of field code => text, as a browser sends it.
 * The validator answers with a list of problems, field code => message;
 * an empty list (assertSame([], ...)) means the save is accepted.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Juiz/RepeatedGroupsEntryTest.php
 */
final class RepeatedGroupsEntryTest extends TestCase
{
    private const TRACAO = '[
        {"code": "PRESENTE", "name": "Presente", "type": "enum", "options": ["SIM", "NÃO"]},
        {"repetir": 6, "nome": "Tentativa", "inputs": [
            {"code": "T", "name": "Tempo [s]", "type": "number", "precision": 3, "xor": "A"},
            {"code": "D", "name": "Distância [m]", "type": "number", "precision": 2, "xor": "A"}
        ]}
    ]';

    // ------------------------------------------------------------------
    // The server's checks (EntryValidator)
    // ------------------------------------------------------------------

    /** A team that ran twice: Presente, attempt 1 by time, attempt 2 by distance. Accepted. */
    public function testTwoAttemptsAreAccepted(): void
    {
        $this->assertSame([], $this->errors(['PRESENTE' => 'SIM', 'T_1' => '4.512', 'D_2' => '12.25']));
    }

    /** Only Presente filled, no attempt yet: accepted (the Presente pass is complete). */
    public function testPresenteAloneIsAccepted(): void
    {
        $this->assertSame([], $this->errors(['PRESENTE' => 'NÃO']));
    }

    /**
     * THE LOCK. Attempt 3 has a value while attempt 2 is empty: on the page
     * attempt 3 is locked, so this did not come from the page as it is.
     * Refused, with a message on T_3 that says what to do.
     */
    public function testAValueInALockedRepetitionIsRefused(): void
    {
        $errors = $this->errors(['PRESENTE' => 'SIM', 'T_1' => '4.5', 'T_3' => '4.25']);

        $this->assertSame(['T_3'], array_keys($errors));
        $this->assertStringContainsString('"Tentativa 3" está bloqueada até "Tentativa 2" estar completa', $errors['T_3']);
    }

    /** Attempt 6, the last, after all five before it: accepted. */
    public function testAllSixAttemptsAreAccepted(): void
    {
        $post = ['PRESENTE' => 'SIM'];
        for ($n = 1; $n <= 6; $n++) {
            $post['T_' . $n] = '4.' . $n;
        }
        $this->assertSame([], $this->errors($post));
    }

    /** The xor is scoped to its attempt: T_1 and D_2 together are fine, T_1 and D_1 are not. */
    public function testXorIsCheckedPerRepetition(): void
    {
        $this->assertSame([], $this->errors(['PRESENTE' => 'SIM', 'T_1' => '4.5', 'D_2' => '10']));

        $both = $this->errors(['PRESENTE' => 'SIM', 'T_1' => '4.5', 'D_1' => '10']);
        $this->assertArrayHasKey('T_1', $both);
        $this->assertArrayHasKey('D_1', $both);
        $this->assertStringContainsString('só um destes campos pode ser preenchido', $both['T_1']);
    }

    /**
     * An earlier attempt can be changed: correcting attempt 1 while 2 and 3
     * are filled is fine. But emptying attempt 2 while 3 still has a value
     * would leave a gap the page never shows, so it is refused.
     */
    public function testEarlierRepetitionsStayEditableButCannotLeaveAGap(): void
    {
        $this->assertSame([], $this->errors(['PRESENTE' => 'SIM', 'T_1' => '4.1', 'T_2' => '4.2', 'T_3' => '4.3']));

        $gap = $this->errors(['PRESENTE' => 'SIM', 'T_1' => '4.1', 'T_3' => '4.3']);
        $this->assertSame(['T_3'], array_keys($gap));
    }

    /**
     * Attempt 1 waits for Presente, by the old pass rule: the fields before
     * the group are pass 1, attempt 1 is pass 2, and pass 2 waits for pass 1.
     */
    public function testTheFirstRepetitionWaitsForTheFieldsBeforeIt(): void
    {
        $errors = $this->errors(['T_1' => '4.5']);

        $this->assertArrayHasKey('PRESENTE', $errors);
        $this->assertStringContainsString('antes dos campos do grupo 2', $errors['PRESENTE']);
    }

    /**
     * A group of 20 after one field goes up to pass 21. The old checks only
     * knew passes 1 to 10; a lone value in attempt 15 (pass 16) would have
     * been ignored. Now it counts: filling attempt 15 alone is refused.
     */
    public function testPassesAboveTenCount(): void
    {
        $fields = $this->fields('[{"code": "P", "name": "P", "type": "number"}, {"repetir": 20, "nome": "Volta", "inputs": [{"code": "V", "name": "V", "type": "number"}]}]');
        $post = ['submit' => 'Salvar', 'P' => '1'];
        for ($n = 1; $n <= 20; $n++) {
            $post['V_' . $n] = (string)$n;
        }
        $this->assertSame([], EntryValidator::forEntry($fields)->errors($post));

        unset($post['V_14']);
        $this->assertSame(['V_15'], array_keys(EntryValidator::forEntry($fields)->errors($post)));
    }

    /**
     * The same fields written by hand (T_1 ... T_6, pass 2 ... 7) keep the
     * old rules: no repetition lock, as before. Only a group gets the lock.
     */
    public function testHandWrittenFieldsKeepTheOldRules(): void
    {
        $fields = $this->fields('[
            {"code": "PRESENTE", "name": "Presente", "type": "enum", "options": ["SIM", "NÃO"]},
            {"code": "T_1", "name": "T1", "type": "number", "pass": 2},
            {"code": "T_2", "name": "T2", "type": "number", "pass": 3},
            {"code": "T_3", "name": "T3", "type": "number", "pass": 4}
        ]');

        $this->assertSame([], EntryValidator::forEntry($fields)->errors(['submit' => 'Salvar', 'PRESENTE' => 'SIM', 'T_1' => '1', 'T_3' => '3']));
    }

    /** The anchor of a block's Salvar is a button, not an unknown field. */
    public function testTheBlockSalvarIsNotAnUnknownField(): void
    {
        $fields = $this->fields(self::TRACAO);

        $this->assertSame([], EntryValidator::forEntry($fields)->errors(['submit' => 'repeticao-1-2', 'PRESENTE' => 'SIM', 'T_1' => '4', 'T_2' => '4']));
    }

    // ------------------------------------------------------------------
    // What the page shows (EntryBlocks)
    // ------------------------------------------------------------------

    /**
     * The state of each block, from what is stored for the team: attempt 1
     * by time and attempt 2 by distance are both complete (in a time-or-
     * distance pair one side is enough). Attempt 3 is empty and may be
     * filled; 4 to 6 are locked, each waiting for the one before it.
     */
    public function testTheStateOfEachBlock(): void
    {
        $states = EntryBlocks::states($this->fields(self::TRACAO), ['PRESENTE' => true, 'T_1' => true, 'D_2' => true]);

        $this->assertSame([
            'repeticao-1-1' => EntryBlocks::FILLED,
            'repeticao-1-2' => EntryBlocks::FILLED,
            'repeticao-1-3' => EntryBlocks::EMPTY,
            'repeticao-1-4' => EntryBlocks::LOCKED,
            'repeticao-1-5' => EntryBlocks::LOCKED,
            'repeticao-1-6' => EntryBlocks::LOCKED,
        ], $states);
    }

    /** A block with some but not all of its fields: "incompleta", and the next one stays locked. */
    public function testAPartlyFilledBlock(): void
    {
        $fields = $this->fields('[{"repetir": 3, "nome": "Volta", "inputs": [{"code": "A", "name": "A", "type": "number"}, {"code": "B", "name": "B", "type": "number"}]}]');

        $this->assertSame([
            'repeticao-1-1' => EntryBlocks::PARTIAL,
            'repeticao-1-2' => EntryBlocks::LOCKED,
            'repeticao-1-3' => EntryBlocks::LOCKED,
        ], EntryBlocks::states($fields, ['A_1' => true]));
    }

    /**
     * A team with nothing yet: the first attempt is open ("vazia"), the rest
     * locked. (Whether attempt 1 can be typed in also depends on Presente,
     * the old pass rule, which the page applies separately.)
     */
    public function testANewTeam(): void
    {
        $states = EntryBlocks::states($this->fields(self::TRACAO), []);

        $this->assertSame(EntryBlocks::EMPTY, $states['repeticao-1-1']);
        $this->assertSame(EntryBlocks::LOCKED, $states['repeticao-1-2']);
    }

    /**
     * A block that has values is never shown locked, even when the one
     * before it is not complete (the judge emptied it afterwards). A locked
     * field is disabled, a disabled field is not sent, and the save would
     * erase what it holds; instead the server refuses that save (above).
     */
    public function testABlockWithValuesIsNeverLocked(): void
    {
        $states = EntryBlocks::states($this->fields(self::TRACAO), ['PRESENTE' => true, 'T_1' => true, 'T_3' => true]);

        $this->assertSame(EntryBlocks::EMPTY, $states['repeticao-1-2']);
        $this->assertSame(EntryBlocks::FILLED, $states['repeticao-1-3']);
        $this->assertSame(EntryBlocks::EMPTY, $states['repeticao-1-4'], 'attempt 3 is complete, so 4 is open');
    }

    /** Where the buttons of each block lead: the one before, the one after, and all of them. */
    public function testNavigationTargets(): void
    {
        $fields = $this->fields(self::TRACAO);
        $first = $fields[1]->getRepetition();
        $third = $fields[5]->getRepetition();
        $last = $fields[12]->getRepetition();

        $this->assertSame(['previous' => null, 'next' => 'repeticao-1-2'], array_slice(EntryBlocks::targets($first), 0, 2));
        $this->assertSame(['previous' => 'repeticao-1-2', 'next' => 'repeticao-1-4'], array_slice(EntryBlocks::targets($third), 0, 2));
        $this->assertSame(['previous' => 'repeticao-1-5', 'next' => null], array_slice(EntryBlocks::targets($last), 0, 2));
        $this->assertSame(
            ['repeticao-1-1', 'repeticao-1-2', 'repeticao-1-3', 'repeticao-1-4', 'repeticao-1-5', 'repeticao-1-6'],
            EntryBlocks::targets($third)['all']
        );
    }

    /**
     * The HTML of a block's buttons: a Salvar that sends the block's anchor,
     * links to the blocks around it, and a numbered button per attempt,
     * coloured by its state, the current one marked.
     */
    public function testTheBlockButtons(): void
    {
        $fields = $this->fields(self::TRACAO);
        $third = $fields[5]->getRepetition();
        $states = EntryBlocks::states($fields, ['PRESENTE' => true, 'T_1' => true, 'T_2' => true]);

        $html = EntryBlocks::closing($third, $states);

        $this->assertStringContainsString('<button type="submit" name="submit" value="repeticao-1-3" class="salvar-bloco" disabled>Salvar</button>', $html);
        $this->assertStringContainsString('href="#repeticao-1-2">◀ Anterior</a>', $html);
        $this->assertStringContainsString('href="#repeticao-1-4">Próxima ▶</a>', $html);
        $this->assertSame(6, substr_count($html, 'class="repeticao-salto"'));
        $this->assertStringContainsString('data-alvo="repeticao-1-1" data-estado="preenchida"', $html);
        $this->assertStringContainsString('data-alvo="repeticao-1-4" data-estado="bloqueada"', $html);
        $this->assertMatchesRegularExpression('/data-alvo="repeticao-1-3"[^>]*aria-current="true">3</', $html);
    }

    /** The first block has no "Anterior" link (the space is kept, so the buttons do not jump about). */
    public function testTheFirstBlockHasNoPrevious(): void
    {
        $first = $this->fields(self::TRACAO)[1]->getRepetition();

        $html = EntryBlocks::closing($first, []);

        $this->assertStringContainsString('<span class="repeticao-seta repeticao-anterior" aria-disabled="true">', $html);
    }

    /** With more than 8 attempts, a select replaces the row of numbered buttons, which would not fit a phone. */
    public function testManyRepetitionsGetASelect(): void
    {
        $fields = $this->fields('[{"repetir": 12, "nome": "Volta", "inputs": [{"code": "V", "name": "V", "type": "number"}]}]');

        $html = EntryBlocks::closing($fields[0]->getRepetition(), EntryBlocks::states($fields, []));

        $this->assertStringContainsString('<select class="repeticao-select"', $html);
        $this->assertStringNotContainsString('repeticao-salto', $html);
        $this->assertSame(12, substr_count($html, '<option'));
    }

    /** The block's title row: its name, how many there are, and its state. */
    public function testTheBlockTitle(): void
    {
        $third = $this->fields(self::TRACAO)[5]->getRepetition();

        $html = EntryBlocks::opening($third, EntryBlocks::LOCKED);

        $this->assertStringContainsString('<tbody class="repeticao" id="repeticao-1-3" data-grupo="1" data-numero="3" data-estado="bloqueada">', $html);
        $this->assertStringContainsString('Tentativa 3 <small>de 6</small>', $html);
        $this->assertStringContainsString('🔒 Bloqueada', $html);
    }

    /**
     * Which block a save came from: the value of the "submit" button. Only
     * an anchor's exact shape counts, because it ends up in the address the
     * judge is sent back to.
     */
    public function testWhichBlockWasSaved(): void
    {
        $this->assertSame('repeticao-1-3', EntryBlocks::savedBlock(['submit' => 'repeticao-1-3']));
        $this->assertNull(EntryBlocks::savedBlock(['submit' => 'Salvar']));
        $this->assertNull(EntryBlocks::savedBlock(['submit' => 'Salvar e Avançar']));
        $this->assertNull(EntryBlocks::savedBlock([]));
        $this->assertNull(EntryBlocks::savedBlock(['submit' => "repeticao-1-3\r\nX-Evil: 1"]));
        $this->assertNull(EntryBlocks::savedBlock(['submit' => ['repeticao-1-3']]));
    }

    // ------------------------------------------------------------------

    /** Problems found when entry.php's form sends $post for the Tração above. */
    private function errors(array $post): array
    {
        return EntryValidator::forEntry($this->fields(self::TRACAO))->errors(['submit' => 'Salvar'] + $post);
    }

    /** @return Field[] built by Prova::getParamsInputs(), as the real pages build them */
    private function fields(string $inputsJson): array
    {
        $prova = new Prova();
        $prova->setParams((object)['inputs' => json_decode($inputsJson)]);
        return $prova->getParamsInputs();
    }
}
