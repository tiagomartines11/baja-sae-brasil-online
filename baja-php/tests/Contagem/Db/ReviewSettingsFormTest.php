<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Review\SettingsForm;
use Baja\Contagem\Review\TotalsImport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Two small pieces of the "Fontes e configurações" page that need no
 * database: the settings form, and the reading of pasted paper totals.
 */
final class ReviewSettingsFormTest extends TestCase
{
    /**
     * SITUATION: the engine has settings that decide how the race is
     * counted: what a car's "pass zero" is, what happens after the finish,
     * whether anything is written to the official results at all.
     *
     * WHAT WENT WRONG: new settings were added to the engine but not to the
     * form. They could only be changed by editing JSON in the database,
     * which nobody will do on race day.
     *
     * EXPECTED: every setting the engine has is a field of the form, and
     * every field comes with a sentence of help. This test fails the day a
     * setting is added to the engine and forgotten on the page.
     */
    public function testEverySettingOfTheEngineIsOnTheFormWithASentenceOfHelp(): void
    {
        $onTheForm = array_merge(
            array_keys(SettingsForm::CHOICE_FIELDS),
            array_keys(SettingsForm::numberFields()),
            array_keys(SettingsForm::BOOLEAN_FIELDS),
            // Drawn by the page itself: a list of the sources, and a list of the prova's own fields.
            ['fonte_principal_id', SettingsForm::CLASSIFICATION_FIELD]
        );

        foreach (array_keys((new Settings())->toArray()) as $setting) {
            $this->assertContains($setting, $onTheForm, 'the setting "' . $setting . '" is missing from the form');
        }
        foreach (SettingsForm::CHOICE_FIELDS as $key => [$label, $hint, $options]) {
            $this->assertNotSame('', trim($hint), $key . ' has no help text');
            $this->assertGreaterThanOrEqual(2, count($options));
        }
        foreach (SettingsForm::numberFields() as $key => [$label, $hint]) {
            $this->assertNotSame('', trim($hint), $key . ' has no help text');
        }
        foreach (SettingsForm::BOOLEAN_FIELDS as $key => [$label, $hint]) {
            $this->assertNotSame('', trim($hint), $key . ' has no help text');
        }
        $this->assertNotSame('', SettingsForm::PRIMARY_SOURCE_HINT);
        $this->assertNotSame('', SettingsForm::CLASSIFICATION_HINT);
    }

    /**
     * SITUATION: round 3 added three settings. The controller ticks "Área
     * de abastecimento" and "Permitir simulação" and picks the prova's
     * field APTO as the classification field. On another day they untick
     * both boxes and choose "(nenhum)".
     *
     * EXPECTED: a ticked box is true, a box that is not ticked (browsers
     * then send nothing at all for it) is false, and the classification
     * field is the chosen code or nothing. A code that is not a field of
     * this prova (a tampered form) is refused by name.
     */
    public function testTheSwitchesAndTheClassificationFieldAreReadFromTheForm(): void
    {
        $fields = ['DESCLAS', 'APTO'];

        $on = SettingsForm::fromPost($this->filledForm([
            'abastecimento' => '1', 'permitir_simulacao' => '1', 'campo_classificacao' => 'APTO',
        ]), [1, 2, 3], null, $fields);
        $this->assertTrue($on->abastecimento);
        $this->assertTrue($on->permitirSimulacao);
        $this->assertSame('APTO', $on->campoClassificacao);

        $form = $this->filledForm(['campo_classificacao' => '']);
        unset($form['abastecimento'], $form['permitir_simulacao']);
        $off = SettingsForm::fromPost($form, [1, 2, 3], null, $fields);
        $this->assertFalse($off->abastecimento);
        $this->assertFalse($off->permitirSimulacao);
        $this->assertNull($off->campoClassificacao);

        try {
            SettingsForm::fromPost($this->filledForm(['campo_classificacao' => 'VOLTAS']), [1, 2, 3], null, $fields);
            $this->fail('a field that cannot be the classification field was accepted');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('Campo de classificação', $refusal->getMessage());
        }
    }

    /**
     * SITUATION: the controller fills in the form: publishing switched off
     * (the race runs beside the old spreadsheet), the start itself as pass
     * zero with a 30-second start window, nothing counting after the
     * finish, a recalculation every 10 seconds, a phone given up on after
     * 5 minutes of silence.
     *
     * EXPECTED: the form becomes a Settings object with exactly those
     * values, and what was not touched keeps what was typed.
     */
    public function testThePostedFormBecomesTheSettings(): void
    {
        $settings = SettingsForm::fromPost($this->filledForm([
            'modo_publicacao' => 'desligada',
            'passagem_inicial' => 'largada',
            'janela_largada_s' => '30',
            'apos_encerramento' => 'nenhuma',
            'recalculo_s' => '10',
            'dispositivo_ausente_s' => '300',
            'fator_atrasado' => '1,8',
        ]), [1, 2, 3]);

        $this->assertSame(Settings::MODO_DESLIGADA, $settings->modoPublicacao);
        $this->assertFalse($settings->publishesToScoring());
        $this->assertSame(Settings::INICIAL_LARGADA, $settings->passagemInicial);
        $this->assertSame(30, $settings->janelaLargadaS);
        $this->assertSame(Settings::APOS_NENHUMA, $settings->aposEncerramento);
        $this->assertSame(10, $settings->recalculoS);
        $this->assertSame(300, $settings->dispositivoAusenteS);
        $this->assertSame(1.8, $settings->fatorAtrasado, 'a decimal typed with a comma is understood');
        $this->assertSame(20000, $settings->janelaMs);
    }

    /**
     * SITUATION: three ways of filling the form in wrongly.
     *
     * EXPECTED: each is refused with a sentence naming the field, instead
     * of being silently replaced by a default (which is what reading stored
     * settings does, and is right there but wrong for a person typing).
     * A start window of zero is NOT a mistake: it means "no start window".
     */
    public function testAWrongValueIsRefusedByName(): void
    {
        $this->assertStringContainsString('Passagem zero', $this->refusalOf(['passagem_inicial' => 'talvez']));
        $this->assertStringContainsString('Intervalo do recálculo', $this->refusalOf(['recalculo_s' => '0']));
        $this->assertStringContainsString('Janela de largada', $this->refusalOf(['janela_largada_s' => '-5']));

        $settings = SettingsForm::fromPost($this->filledForm(['janela_largada_s' => '0']), [1, 2, 3]);
        $this->assertSame(0, $settings->janelaLargadaS);
    }

    /**
     * SITUATION: the controller chooses the mode "fonte principal" and
     * picks, as the primary source, one that is switched off.
     *
     * EXPECTED: refused. The count would be "whatever that source records",
     * and a source that is off records nothing.
     */
    public function testAPrimarySourceThatIsSwitchedOffCannotCarryTheCount(): void
    {
        $form = $this->filledForm(['modo_publicacao' => 'fonte_principal', 'fonte_principal_id' => '2']);

        try {
            SettingsForm::fromPost($form, [1, 2, 3], [1, 3]);
            $this->fail('accepted, but source 2 is switched off');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('desativada', $refusal->getMessage());
        }
        $this->assertSame(2, SettingsForm::fromPost($form, [1, 2, 3], [1, 2, 3])->fontePrincipalId);
    }

    /**
     * SITUATION: somebody pastes the paper totals, one "car, laps" per
     * line. In the first pasted line the car number has a stray letter:
     * "x11, 5".
     *
     * WHAT WENT WRONG: a first line that did not START with a number was
     * taken for a header ("carro, voltas") and skipped without a word. Car
     * 11 vanished from the comparison with the paper sheet, and the import
     * reported success.
     *
     * EXPECTED: a first line is a header only when NONE of its fields is a
     * number. "x11, 5" contains a number, so it is a data line with a typo:
     * the import is refused and line 1 is named. A real header is still
     * skipped.
     */
    public function testAFirstLineWithATypoIsReportedAndNotMistakenForAHeader(): void
    {
        $validCars = [11, 22, 33];

        try {
            TotalsImport::parse("x11, 5\n22, 3", $validCars);
            $this->fail('the line with the typo was skipped in silence');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('Linha 1', $refusal->getMessage());
        }

        $this->assertSame([11 => 5, 22 => 3], TotalsImport::parse("carro, voltas\n11, 5\n22, 3", $validCars));
        $this->assertSame([11 => 5, 22 => 3], TotalsImport::parse("11;5\n22;3", $validCars));
    }

    /**
     * The form as the page sends it when nothing was changed, with the
     * given fields replaced.
     *
     * @param array<string, string> $changes
     * @return array<string, string>
     */
    private function filledForm(array $changes = []): array
    {
        $form = [];
        foreach ((new Settings())->toArray() as $key => $value) {
            if (is_bool($value)) {
                // A checkbox: sent as "1" when ticked, not sent at all otherwise.
                if ($value) {
                    $form[$key] = '1';
                }
                continue;
            }
            $form[$key] = $value === null ? '' : str_replace('.', ',', (string) $value);
        }
        return $changes + $form;
    }

    /**
     * @param array<string, string> $changes
     */
    private function refusalOf(array $changes): string
    {
        try {
            SettingsForm::fromPost($this->filledForm($changes), [1, 2, 3]);
        } catch (InvalidArgumentException $refusal) {
            return $refusal->getMessage();
        }
        $this->fail('the form was accepted, but it should have been refused');
    }
}
