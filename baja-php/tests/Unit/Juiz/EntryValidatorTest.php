<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Juiz;

use Baja\Juiz\EntryValidator;
use Baja\Model\JSON\Field;
use Baja\Model\Prova;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EntryValidator, which decides whether what a judge's form sent
 * may be saved (juiz/save_entry.php and juiz/save_rolling_entry.php).
 *
 * WHY THIS EXISTS
 *
 * A judge types scores on juiz/entry.php (one team at a time) or on
 * juiz/rolling_entry.php (one line per attempt). The browser enforces the
 * rules of each field: a number box refuses letters and values below zero,
 * a list only offers its options, and a small script locks some fields
 * until others are filled. But the server used to store anything it was
 * sent, and a request does not have to come from that browser page: it can
 * be typed by hand, replayed, or come from a tab opened before the prova
 * was reconfigured. Whatever is stored feeds the points and the published
 * results. So the same rules are now checked again on the server.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment. It
 * describes the fields of a prova in JSON, exactly as an organiser types
 * them in juiz/prova.php, pretends a form was sent with some values (that
 * is $_POST, an array of field code => text), and checks the answer.
 *
 * The answer is a list of problems, field code => message. An empty list
 * means "may be saved". The checks used are:
 *
 *   assertSame([], x)              x must be exactly an empty list: accepted
 *   assertArrayHasKey('CODE', x)   x must have a problem for field CODE: refused
 *   assertSame(['A', 'B'], x)      x must be exactly this list
 *   assertStringContainsString(a, b)  text b must contain text a
 *
 * When one fails, PHPUnit prints what it expected and what it got.
 *
 * Every value a browser sends is text, even numbers, so the values below
 * are written as text too: '5', not 5.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Juiz/EntryValidatorTest.php
 */
final class EntryValidatorTest extends TestCase
{
    /**
     * Problems found when the team entry form (entry.php) sends $post, for
     * a prova whose "inputs" are $inputsJson. The fields are built by
     * Prova::getParamsInputs(), the same code the real pages use.
     */
    private function entrada(string $inputsJson, array $post, array $engineOwned = []): array
    {
        return EntryValidator::forEntry($this->campos($inputsJson), $engineOwned)->errors(['submit' => 'Salvar'] + $post);
    }

    /** The same for the rolling entry form (rolling_entry.php). */
    private function rolante(string $inputsJson, array $post): array
    {
        return EntryValidator::forRolling($this->campos($inputsJson))->errors(['submit' => 'Salvar', 'team' => '12'] + $post);
    }

    /** @return Field[] */
    private function campos(string $inputsJson): array
    {
        $prova = new Prova();
        $prova->setParams(json_decode('{"inputs": ' . $inputsJson . '}'));
        return $prova->getParamsInputs();
    }

    // ------------------------------------------------------------------
    // The whole form
    // ------------------------------------------------------------------

    /**
     * The normal case: every field filled with a value the form allows.
     * Nothing is wrong, so the list of problems is empty and it is saved.
     * The button name ("submit") is not a field and must not be refused.
     */
    public function testFormularioCompletoEValidoEhAceito(): void
    {
        $erros = $this->entrada(
            '[{"code": "TEMPO", "name": "Tempo", "type": "number", "precision": 3},
              {"code": "FREIO", "name": "Freio", "type": "enum", "options": ["OK", "NOK"]}]',
            ['TEMPO' => '12.345', 'FREIO' => 'OK']
        );

        $this->assertSame([], $erros);
    }

    /**
     * Both forms now carry a hidden security token, "_csrf" (BAJAAPP-41,
     * see src/Baja/Auth/Csrf.php). Like the button name, it is not one of
     * the prova's fields. If the validator treated it as an unknown field,
     * every save would be refused with "o campo _csrf não existe".
     */
    public function testOCodigoDeSegurancaNaoEhUmCampoDesconhecido(): void
    {
        $inputs = '[{"code": "TEMPO", "name": "Tempo", "type": "number", "precision": 3}]';

        $this->assertSame([], $this->entrada($inputs, ['TEMPO' => '12.345', '_csrf' => str_repeat('a', 64)]));
        $this->assertSame([], $this->rolante($inputs, ['TEMPO' => '12.345', '_csrf' => str_repeat('a', 64)]));
    }

    /**
     * A tab was opened while the prova still had a field PENA; then the
     * organiser removed PENA from the configuration, and the judge saved
     * from the old tab. PENA no longer means anything, and before this
     * change the rest was saved without a word. Now nothing is saved and
     * the judge is told which field and to reload the page.
     */
    public function testCampoRemovidoDaConfiguracaoEhRecusado(): void
    {
        $erros = $this->entrada(
            '[{"code": "TEMPO", "name": "Tempo", "type": "number"}]',
            ['TEMPO' => '10', 'PENA' => '-5']
        );

        $this->assertSame(['PENA'], array_keys($erros));
        $this->assertStringContainsString('Recarregue a página', $erros['PENA']);
    }

    /**
     * "delete" is the name of the delete button of entry.php. It is not a
     * field, so it is not "unknown". (save_entry.php deals with a delete
     * before it ever asks this class.)
     */
    public function testBotaoDeletarNaoEhCampoDesconhecido(): void
    {
        $this->assertSame([], $this->entrada('[{"code": "TEMPO", "name": "Tempo", "type": "number"}]', ['TEMPO' => '10', 'delete' => 'x']));
    }

    /**
     * In a lap counting prova, VOLTAS is written by the lap counting
     * engine and entry.php shows it as text, not as a box. A value sent for
     * it anyway is dropped by save_entry.php and the rest is saved; that is
     * how it worked before and a database test relies on it
     * (tests/Contagem/Db/HttpReviewPagesTest). So it is neither checked
     * nor refused here, even when it is not a number.
     */
    public function testCampoDaContagemDeVoltasEhIgnoradoNaoRecusado(): void
    {
        $erros = $this->entrada(
            '[{"code": "VOLTAS", "name": "Voltas", "type": "number"},
              {"code": "DESCLAS", "name": "Desclassificado", "type": "enum", "options": ["SIM", "NÃO"]}]',
            ['VOLTAS' => 'abc', 'DESCLAS' => 'SIM'],
            ['VOLTAS']
        );

        $this->assertSame([], $erros);
    }

    // ------------------------------------------------------------------
    // number
    // ------------------------------------------------------------------

    /**
     * The number box has min="0" (Field::printSelf), so 0 itself is
     * allowed: the value exactly at the limit. "0" is also a real value,
     * not an empty box: it completes the group.
     */
    public function testNumeroZeroEhAceito(): void
    {
        $this->assertSame([], $this->entrada('[{"code": "N", "name": "N", "type": "number"}]', ['N' => '0']));
    }

    /** Just below the limit: min="0" refuses -1, and so must the server. */
    public function testNumeroNegativoEhRecusadoEmCampoNormal(): void
    {
        $erros = $this->entrada('[{"code": "N", "name": "Nota", "type": "number"}]', ['N' => '-1']);

        $this->assertArrayHasKey('N', $erros);
        $this->assertStringContainsString('não aceita valores negativos', $erros['N']);
    }

    /**
     * "-0" (negative zero) is the number zero written with a minus sign.
     * The browser accepts it in a min="0" box (it is not below zero), so
     * refusing it would refuse something the page allows.
     */
    public function testMenosZeroEhAceitoEmCampoNormal(): void
    {
        $this->assertSame([], $this->entrada('[{"code": "N", "name": "N", "type": "number"}]', ['N' => '-0']));
    }

    /**
     * A penalty field has "negative": true and the box gets max="0"
     * instead: zero and below are fine (0, -0 and -5), anything above
     * zero is refused.
     */
    public function testCampoNegativoAceitaZeroEAbaixoERecusaAcima(): void
    {
        $penalidade = '[{"code": "PEN", "name": "Penalidade", "type": "number", "negative": true}]';

        $this->assertSame([], $this->entrada($penalidade, ['PEN' => '0']));
        $this->assertSame([], $this->entrada($penalidade, ['PEN' => '-0']));
        $this->assertSame([], $this->entrada($penalidade, ['PEN' => '-5']));
        $this->assertArrayHasKey('PEN', $this->entrada($penalidade, ['PEN' => '5']));
    }

    /**
     * Some stored configurations say "negative": "true", in quotes. The
     * page treats any non-empty text as true, so the server must too.
     */
    public function testNegativeEscritoComoTextoTambemValeComoNegativo(): void
    {
        $erros = $this->entrada('[{"code": "PEN", "name": "Penalidade", "type": "number", "negative": "true"}]', ['PEN' => '5']);

        $this->assertArrayHasKey('PEN', $erros);
    }

    /**
     * "precision": 3 means the box has step="0.001": up to 3 decimal
     * places. 1.234 is exactly at the limit and allowed; 1.2345 has one
     * place too many and is refused. 1.2340 is the same number as 1.234
     * (the zero at the end changes nothing) and is allowed.
     */
    public function testPrecisaoLimitaAsCasasDecimais(): void
    {
        $tempo = '[{"code": "T", "name": "Tempo", "type": "number", "precision": 3}]';

        $this->assertSame([], $this->entrada($tempo, ['T' => '1.234']));
        $this->assertSame([], $this->entrada($tempo, ['T' => '1.2340']));
        $erros = $this->entrada($tempo, ['T' => '1.2345']);
        $this->assertArrayHasKey('T', $erros);
        $this->assertStringContainsString('no máximo 3 casas decimais', $erros['T']);
    }

    /**
     * Without "precision" the step is 1: whole numbers only. 5.0 is the
     * whole number 5 written with a decimal point, and the browser allows
     * it; 5.5 is not whole and is refused.
     */
    public function testSemPrecisaoSoAceitaInteiros(): void
    {
        $voltas = '[{"code": "V", "name": "Voltas", "type": "number"}]';

        $this->assertSame([], $this->entrada($voltas, ['V' => '5']));
        $this->assertSame([], $this->entrada($voltas, ['V' => '5.0']));
        $this->assertStringContainsString('só números inteiros', $this->entrada($voltas, ['V' => '5.5'])['V']);
    }

    /**
     * "precision": 12 is used for free-form grades. A value with 12
     * decimal places must not be refused because of a rounding error in
     * the computer's arithmetic: the check counts digits instead.
     */
    public function testPrecisaoDozeAceitaDozeCasas(): void
    {
        $this->assertSame([], $this->entrada('[{"code": "G", "name": "Nota", "type": "number", "precision": 12}]', ['G' => '0.123456789012']));
    }

    /**
     * Brazilians write 1,5 for one and a half. A Brazilian browser shows
     * the comma in the box but always SENDS "1.5", with a dot. So "1,5"
     * never comes from the page. It used to be saved as 1 (PHP reads up to
     * the comma), silently losing the half. Now it is refused, and the
     * message says to use a dot.
     */
    public function testVirgulaComoSeparadorDecimalEhRecusada(): void
    {
        $erros = $this->entrada('[{"code": "T", "name": "Tempo", "type": "number", "precision": 3}]', ['T' => '1,5']);

        $this->assertStringContainsString('use ponto, não vírgula', $erros['T']);
    }

    /**
     * Text that is not a number at all used to be saved as 0
     * (doubleval("abc") is 0), giving the team a real-looking score. Also
     * refused: forms the browser never sends, like a plus sign, a space
     * before the number, or a dot with nothing after it.
     */
    public function testTextoQueNaoEhNumeroEhRecusado(): void
    {
        $campo = '[{"code": "N", "name": "N", "type": "number"}]';

        foreach (['abc', '+1', ' 1', '1.', '.', '-', '1 000', '0x1A'] as $valor) {
            $this->assertArrayHasKey('N', $this->entrada($campo, ['N' => $valor]), "o valor '$valor' deveria ser recusado");
        }
    }

    /**
     * Forms the browser does send: ".5" (no zero before the dot), "007"
     * and "1e3" (scientific notation, 1000; Chrome lets you type an "e").
     * A number so large that the computer cannot hold it ("1e400") is
     * refused, as the browser does.
     */
    public function testOutrasFormasDeNumeroDoNavegador(): void
    {
        $campo = '[{"code": "N", "name": "N", "type": "number", "precision": 1}]';

        $this->assertSame([], $this->entrada($campo, ['N' => '.5']));
        $this->assertSame([], $this->entrada($campo, ['N' => '007']));
        $this->assertSame([], $this->entrada($campo, ['N' => '1e3']));
        $this->assertArrayHasKey('N', $this->entrada($campo, ['N' => '1e400']));
    }

    /**
     * An unknown "type", e.g. a typo like "num", is drawn as a number box
     * (Field::printSelf, and the help page says so). So it follows the
     * number rules.
     */
    public function testTipoDesconhecidoSegueAsRegrasDeNumero(): void
    {
        $this->assertArrayHasKey('N', $this->entrada('[{"code": "N", "name": "N", "type": "num"}]', ['N' => 'abc']));
    }

    /**
     * A list of values for a number field (a request with N[]=1&N[]=2)
     * cannot come from a number box. It used to be glued into the text
     * "1,2" and saved as 1.
     */
    public function testListaDeValoresEmCampoNumericoEhRecusada(): void
    {
        $this->assertArrayHasKey('N', $this->entrada('[{"code": "N", "name": "N", "type": "number"}]', ['N' => ['1', '2']]));
    }

    // ------------------------------------------------------------------
    // enum
    // ------------------------------------------------------------------

    /**
     * An enum is a list to pick from. The browser sends the option text
     * exactly as written in the configuration, byte for byte. So only that
     * exact text is accepted: "NÃO" yes, but not "NAO" (no accent), "não"
     * (small letters) or " NÃO" (a space before). Those can only come from
     * a request typed by hand, and a formula that compares with 'NÃO'
     * would treat them as something else.
     */
    public function testEnumSoAceitaOTextoExatoDaOpcao(): void
    {
        $campo = '[{"code": "DESCLAS", "name": "Desclassificado", "type": "enum", "options": ["SIM", "NÃO"]}]';

        $this->assertSame([], $this->entrada($campo, ['DESCLAS' => 'NÃO']));
        foreach (['NAO', 'não', 'Não', ' NÃO', 'TALVEZ'] as $valor) {
            $this->assertArrayHasKey('DESCLAS', $this->entrada($campo, ['DESCLAS' => $valor]), "o valor '$valor' deveria ser recusado");
        }
    }

    /**
     * With "multiple": true the judge can pick several options, and the
     * browser sends them as a list. Every item must be an option. The
     * empty first line of the list can be picked too, so "" is allowed in
     * the list.
     */
    public function testEnumMultiploAceitaListaDeOpcoes(): void
    {
        $cones = '[{"code": "CONES", "name": "Cones", "type": "enum", "multiple": true, "options": ["1", "2", "3"]}]';

        $this->assertSame([], $this->entrada($cones, ['CONES' => ['1', '3']]));
        $this->assertSame([], $this->entrada($cones, ['CONES' => ['', '2']]));
        $this->assertArrayHasKey('CONES', $this->entrada($cones, ['CONES' => ['1', '9']]));
    }

    /** Without "multiple" a list cannot come from the page. */
    public function testEnumSimplesRecusaLista(): void
    {
        $erros = $this->entrada('[{"code": "F", "name": "Freio", "type": "enum", "options": ["OK", "NOK"]}]', ['F' => ['OK', 'NOK']]);

        $this->assertStringContainsString('aceita só um', $erros['F']);
    }

    // ------------------------------------------------------------------
    // enum-assoc
    // ------------------------------------------------------------------

    /**
     * An enum-assoc shows a label ("Atende") but sends the value paired
     * with it (1.2). So the value is accepted and the label is not. The
     * value "0" is a real choice ("Não atende"), not an empty field.
     */
    public function testEnumAssocAceitaOValorNaoORotulo(): void
    {
        $ergo = '[{"code": "ERGO", "name": "Ergonomia", "type": "enum-assoc", "options": [["Atende", 1.2], ["Atende parcialmente", 0.6], ["Não atende", 0]]}]';

        $this->assertSame([], $this->entrada($ergo, ['ERGO' => '1.2']));
        $this->assertSame([], $this->entrada($ergo, ['ERGO' => '0']));
        $this->assertArrayHasKey('ERGO', $this->entrada($ergo, ['ERGO' => 'Atende']));
        $this->assertArrayHasKey('ERGO', $this->entrada($ergo, ['ERGO' => '5']));
    }

    // ------------------------------------------------------------------
    // time
    // ------------------------------------------------------------------

    /**
     * A time box (<input type="time">) has no step attribute, which
     * means steps of one minute: it sends "14:35". It may send "14:35:00"
     * when it was loaded with that value. Seconds other than zero, hours
     * past 23 or other ways of writing a time never come from it.
     */
    public function testHoraAceitaSoHorasEMinutos(): void
    {
        $hora = '[{"code": "HORA", "name": "Hora", "type": "time"}]';

        $this->assertSame([], $this->entrada($hora, ['HORA' => '14:35']));
        $this->assertSame([], $this->entrada($hora, ['HORA' => '00:00']));
        $this->assertSame([], $this->entrada($hora, ['HORA' => '23:59']));
        $this->assertSame([], $this->entrada($hora, ['HORA' => '14:35:00']));
        $this->assertStringContainsString('sem segundos', $this->entrada($hora, ['HORA' => '14:35:20'])['HORA']);
        foreach (['24:00', '2:35', '14h35', '14:60', 'agora'] as $valor) {
            $this->assertArrayHasKey('HORA', $this->entrada($hora, ['HORA' => $valor]), "o valor '$valor' deveria ser recusado");
        }
    }

    // ------------------------------------------------------------------
    // hidden
    // ------------------------------------------------------------------

    /**
     * "hidden" is a Field type that no configuration can produce today
     * (Prova::getParamsInputs turns any unknown type into a number), so
     * the field is built by hand here. The page writes it as a hidden
     * input holding the stored value, which can be any text; a list
     * cannot come from it.
     */
    public function testCampoOcultoAceitaQualquerTextoMasNaoLista(): void
    {
        $oculto = new Field('H', 'Oculto');
        $validador = EntryValidator::forEntry([$oculto]);

        $this->assertSame([], $validador->errors(['H' => 'qualquer coisa']));
        $this->assertArrayHasKey('H', $validador->errors(['H' => ['a', 'b']]));
    }

    // ------------------------------------------------------------------
    // pass and xor: which fields may be filled together
    // ------------------------------------------------------------------

    /**
     * Fields with the same "xor" letter are "one or the other": the page
     * locks the remaining ones as soon as one has a value. Here a car
     * either completes the course (time T_1) or not (distance D_1). Only
     * one may arrive filled; the empty one does not count as missing.
     */
    public function testXorAceitaUmERecusaDois(): void
    {
        $tracao = '[{"xor": "A", "code": "T_1", "name": "Tempo 1", "type": "number", "precision": 3},
                    {"xor": "A", "code": "D_1", "name": "Distância 1", "type": "number", "precision": 2}]';

        $this->assertSame([], $this->entrada($tracao, ['T_1' => '9.5']));
        $this->assertSame([], $this->entrada($tracao, ['D_1' => '30.25', 'T_1' => '']));
        $erros = $this->entrada($tracao, ['T_1' => '9.5', 'D_1' => '30.25']);
        $this->assertSame(['T_1', 'D_1'], array_keys($erros));
        $this->assertStringContainsString('só um destes campos', $erros['T_1']);
    }

    /**
     * Group 2 ("pass": 2, e.g. the second attempt) is locked on the page
     * until every field of group 1 has a value. So a request with a value
     * in group 2 and a hole in group 1 never comes from the page, and the
     * missing group 1 field is named.
     */
    public function testGrupoDoisPrecisaDoGrupoUmCompleto(): void
    {
        $tentativas = '[{"code": "T1", "name": "Tentativa 1", "pass": 1, "type": "number"},
                        {"code": "R1", "name": "Resultado 1", "pass": 1, "type": "number"},
                        {"code": "T2", "name": "Tentativa 2", "pass": 2, "type": "number"}]';

        $this->assertSame([], $this->entrada($tentativas, ['T1' => '10', 'R1' => '1', 'T2' => '11']));
        $erros = $this->entrada($tentativas, ['T1' => '10', 'R1' => '', 'T2' => '11']);
        $this->assertSame(['R1'], array_keys($erros));
        $this->assertStringContainsString('antes dos campos do grupo 2', $erros['R1']);
    }

    /**
     * An empty field is fine on its own (no box is "required"): here the
     * judge filled group 1 and left group 2 for later. That is how a
     * first attempt is saved.
     */
    public function testCampoVazioEhAceitoQuandoOutroGrupoEstaCompleto(): void
    {
        $tentativas = '[{"code": "T1", "name": "Tentativa 1", "pass": 1, "type": "number"},
                        {"code": "T2", "name": "Tentativa 2", "pass": 2, "type": "number"}]';

        $this->assertSame([], $this->entrada($tentativas, ['T1' => '10', 'T2' => '']));
        $this->assertSame([], $this->entrada($tentativas, ['T1' => '10']));
    }

    /**
     * The "Salvar" buttons only work once every field of a group has a
     * value. A form with a half-filled group, or with nothing at all, can
     * only come from outside the page. The answer names the empty fields
     * of the group the judge started.
     */
    public function testSemNenhumGrupoCompletoNadaEhSalvo(): void
    {
        $campos = '[{"code": "A", "name": "A", "type": "number"},
                    {"code": "B", "name": "B", "type": "number"}]';

        $this->assertSame(['B'], array_keys($this->entrada($campos, ['A' => '1', 'B' => ''])));
        $this->assertSame(['A', 'B'], array_keys($this->entrada($campos, [])));
    }

    /**
     * On entry.php groups 3 to 10 work like group 1 (they are never
     * locked), so completing only group 3 is enough to save.
     */
    public function testGrupoTresCompletoBastaNaTelaDeEquipe(): void
    {
        $campos = '[{"code": "A", "name": "A", "pass": 1, "type": "number"},
                    {"code": "C", "name": "C", "pass": 3, "type": "number"}]';

        $this->assertSame([], $this->entrada($campos, ['C' => '1']));
    }

    /**
     * A field in a group the page script does not handle ("pass": 0 is
     * in some stored configurations) is never locked and never decides
     * the button, so it never blocks a save.
     */
    public function testCampoForaDosGruposNuncaBloqueia(): void
    {
        $campos = '[{"code": "A", "name": "A", "pass": 1, "type": "number"},
                    {"code": "Z", "name": "Z", "pass": 0, "type": "number"}]';

        $this->assertSame([], $this->entrada($campos, ['A' => '1']));
    }

    // ------------------------------------------------------------------
    // rolling entry (one line per attempt)
    // ------------------------------------------------------------------

    /**
     * The rolling form adds a team list ("team") to the fields. That is
     * not an unknown field. The values follow the same rules as on the
     * team form: here a valid line is accepted, and a text in a number
     * field is refused.
     */
    public function testEntradaRolanteSegueAsMesmasRegras(): void
    {
        $fila = '[{"code": "SENHA", "name": "Senha", "type": "number"},
                  {"code": "STATUS", "name": "Status", "type": "enum", "options": ["Fila", "No show", "Atendido"]}]';

        $this->assertSame([], $this->rolante($fila, ['SENHA' => '17', 'STATUS' => 'Atendido']));
        $this->assertArrayHasKey('SENHA', $this->rolante($fila, ['SENHA' => 'dezessete', 'STATUS' => 'Atendido']));
        $this->assertArrayHasKey('VELHO', $this->rolante($fila, ['SENHA' => '17', 'STATUS' => 'Atendido', 'VELHO' => '1']));
    }

    /**
     * The script of rolling_entry.php only looks at groups 1 and 2. A
     * field in group 3 never enables "Salvar" there, unlike on the team
     * form (see testGrupoTresCompletoBastaNaTelaDeEquipe).
     */
    public function testEntradaRolanteSoOlhaOsGruposUmEDois(): void
    {
        $campos = '[{"code": "A", "name": "A", "pass": 1, "type": "number"},
                    {"code": "C", "name": "C", "pass": 3, "type": "number"}]';

        $this->assertArrayHasKey('A', $this->rolante($campos, ['C' => '1']));
        $this->assertSame([], $this->rolante($campos, ['A' => '1']));
    }

    // ------------------------------------------------------------------
    // showing the form again
    // ------------------------------------------------------------------

    /**
     * After a refused save the form is drawn again with what the judge
     * typed. typedValue() turns a posted value into what Field::printSelf()
     * expects: a list joined with commas (as save_entry.php stores it),
     * nothing for an empty box, and "0" kept as "0".
     */
    public function testValorDigitadoParaMostrarDeNovo(): void
    {
        $this->assertSame('1,3', EntryValidator::typedValue(['C' => ['1', '3']], 'C'));
        $this->assertNull(EntryValidator::typedValue(['C' => ''], 'C'));
        $this->assertNull(EntryValidator::typedValue(['C' => ['']], 'C'));
        $this->assertNull(EntryValidator::typedValue([], 'C'));
        $this->assertSame('0', EntryValidator::typedValue(['C' => '0'], 'C'));
    }

    /**
     * The judge's text goes back into the page, so it must be escaped:
     * otherwise a request carrying '"><script>' would run that script in
     * the judge's browser.
     */
    public function testValorDigitadoEhEscapadoNaPagina(): void
    {
        $campo = $this->campos('[{"code": "N", "name": "N", "type": "number"}]')[0];

        $this->assertStringNotContainsString('"><script>', $campo->printSelf('"><script>'));
    }

    // ------------------------------------------------------------------
    // the page still draws what these rules assume
    // ------------------------------------------------------------------

    /**
     * The rules above copy what the browser enforces. That enforcement
     * comes from the HTML Field::printSelf() writes. If someone changes
     * that HTML (another step, a max, a required), this test fails, as a
     * reminder that EntryValidator must change with it.
     */
    public function testAPaginaAindaDesenhaOsLimitesQueEstasRegrasCopiam(): void
    {
        [$numero, $penalidade, $lista, $assoc, $hora] = $this->campos(
            '[{"code": "N", "name": "N", "type": "number", "precision": 3, "pass": 2, "xor": "A"},
              {"code": "P", "name": "P", "type": "number", "negative": true},
              {"code": "L", "name": "L", "type": "enum", "options": ["SIM", "NÃO"]},
              {"code": "E", "name": "E", "type": "enum-assoc", "options": [["Atende", 1.2]]},
              {"code": "H", "name": "H", "type": "time"}]'
        );

        $this->assertMatchesRegularExpression('/data-group="2" data-xor="A" [^>]*type="number" min="0" step="0.001" name="N"/', $numero->printSelf());
        $this->assertMatchesRegularExpression('/type="number" max="0" step="1" name="P"/', $penalidade->printSelf());
        $this->assertStringContainsString('<option value="NÃO" >NÃO</option>', $lista->printSelf());
        $this->assertStringContainsString('<option value="1.2" >Atende</option>', $assoc->printSelf());
        $this->assertMatchesRegularExpression('/name="H" type="time" id="H"/', $hora->printSelf());
        foreach ([$numero, $penalidade, $lista, $assoc, $hora] as $campo) {
            $this->assertStringNotContainsString('required', $campo->printSelf());
            $this->assertStringNotContainsString(' max="1', $campo->printSelf());
        }
    }
}
