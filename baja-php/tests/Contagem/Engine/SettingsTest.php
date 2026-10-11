<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The settings of the lap counting, as stored in `prova.params.contagem`
 * and edited on the "Fontes e configurações" page.
 *
 * One principle runs through all of it: a missing key, an unknown key or a
 * nonsensical value must never be able to stop a race from being counted.
 * Whatever cannot be used falls back to the default.
 */
final class SettingsTest extends TestCase
{
    /** Every key with its default value, exactly as `toArray()` writes them. */
    private const DEFAULTS = [
        'modo_publicacao' => 'confirmadas',
        'fonte_principal_id' => null,
        'passagem_inicial' => 'primeira_passagem',
        'janela_largada_s' => 0,
        'apos_encerramento' => 'proxima_passagem',
        'janela_ms' => 20000,
        'duplicata_ms' => 5000,
        'fracao_volta_minima' => 0.5,
        'janela_ritmo_s' => 900,
        'fator_atrasado' => 1.5,
        'fator_parado' => 2.0,
        'dispersao_max_ms' => 2000,
        'fonte_offline_s' => 60,
        'recalculo_s' => 5,
        'dispositivo_ausente_s' => 600,
    ];

    /**
     * SITUATION: a prova with no "contagem" block at all (null), with an
     * empty one, or with one that came out of json_decode as an object.
     *
     * EXPECTED: the defaults, in all three cases.
     */
    public function testAProvaWithNoSettingsGetsTheDefaults(): void
    {
        $this->assertSame(self::DEFAULTS, Settings::fromArray(null)->toArray());
        $this->assertSame(self::DEFAULTS, Settings::fromArray([])->toArray());
        $this->assertSame(self::DEFAULTS, Settings::fromArray(new \stdClass())->toArray());
        $this->assertSame(self::DEFAULTS, (new Settings())->toArray());
    }

    /**
     * SITUATION: every key is given a value different from its default.
     *
     * EXPECTED: every one of them is read (as an array or as the object
     * json_decode produces), and saving the settings and reading them back
     * gives the same thing.
     */
    public function testEveryKeyIsReadAndSurvivesARoundTrip(): void
    {
        $custom = [
            'modo_publicacao' => 'fonte_principal',
            'fonte_principal_id' => 3,
            'passagem_inicial' => 'largada',
            'janela_largada_s' => 45,
            'apos_encerramento' => 'nenhuma',
            'janela_ms' => 15000,
            'duplicata_ms' => 3000,
            'fracao_volta_minima' => 0.4,
            'janela_ritmo_s' => 600,
            'fator_atrasado' => 1.8,
            'fator_parado' => 2.5,
            'dispersao_max_ms' => 1500,
            'fonte_offline_s' => 90,
            'recalculo_s' => 10,
            'dispositivo_ausente_s' => 300,
        ];
        $this->assertSame(array_keys(self::DEFAULTS), array_keys($custom), 'The test must cover every key.');

        $settings = Settings::fromArray($custom);

        $this->assertSame($custom, $settings->toArray());
        $this->assertSame($custom, Settings::fromArray($settings->toArray())->toArray());
        $this->assertSame($custom, Settings::fromArray((object) $custom)->toArray());
        $this->assertSame('fonte_principal', $settings->modoPublicacao);
        $this->assertSame(3, $settings->fontePrincipalId);
        $this->assertSame('largada', $settings->passagemInicial);
        $this->assertSame(45, $settings->janelaLargadaS);
        $this->assertSame('nenhuma', $settings->aposEncerramento);
        $this->assertSame(15000, $settings->janelaMs);
        $this->assertSame(3000, $settings->duplicataMs);
        $this->assertSame(0.4, $settings->fracaoVoltaMinima);
        $this->assertSame(600, $settings->janelaRitmoS);
        $this->assertSame(1.8, $settings->fatorAtrasado);
        $this->assertSame(2.5, $settings->fatorParado);
        $this->assertSame(1500, $settings->dispersaoMaxMs);
        $this->assertSame(90, $settings->fonteOfflineS);
        $this->assertSame(10, $settings->recalculoS);
        $this->assertSame(300, $settings->dispositivoAusenteS);
    }

    /**
     * SITUATION: the settings page posts everything as text, so numbers
     * arrive as strings.
     *
     * EXPECTED: numeric strings are accepted and stored as numbers.
     */
    public function testNumbersTypedAsTextAreAccepted(): void
    {
        $settings = Settings::fromArray([
            'janela_ms' => '15000',
            'fonte_principal_id' => '2',
            'janela_largada_s' => '30',
            'fator_atrasado' => '1.75',
            'fracao_volta_minima' => '0.25',
        ]);

        $this->assertSame(15000, $settings->janelaMs);
        $this->assertSame(2, $settings->fontePrincipalId);
        $this->assertSame(30, $settings->janelaLargadaS);
        $this->assertSame(1.75, $settings->fatorAtrasado);
        $this->assertSame(0.25, $settings->fracaoVoltaMinima);
    }

    /** @return array<string, array{0: string, 1: mixed}> key, unusable value */
    public static function unusableValues(): array
    {
        $cases = [];
        $numericKeys = ['janela_ms', 'duplicata_ms', 'fracao_volta_minima', 'janela_ritmo_s', 'fator_atrasado', 'fator_parado', 'dispersao_max_ms', 'fonte_offline_s', 'recalculo_s', 'dispositivo_ausente_s', 'fonte_principal_id'];
        foreach ($numericKeys as $key) {
            $cases["$key = 0"] = [$key, 0];
            $cases["$key = -5"] = [$key, -5];
            $cases["$key = \"abc\""] = [$key, 'abc'];
            $cases["$key = null"] = [$key, null];
            $cases["$key = []"] = [$key, []];
        }
        foreach (['modo_publicacao', 'passagem_inicial', 'apos_encerramento'] as $key) {
            $cases["$key = \"qualquer\""] = [$key, 'qualquer'];
            $cases["$key = 1"] = [$key, 1];
            $cases["$key = null"] = [$key, null];
        }
        $cases['janela_largada_s = -1'] = ['janela_largada_s', -1];
        $cases['janela_largada_s = "abc"'] = ['janela_largada_s', 'abc'];
        return $cases;
    }

    /**
     * SITUATION: one key holds something that cannot be used: zero or a
     * negative number where a duration is expected, text where a number is
     * expected, a mode that does not exist.
     *
     * EXPECTED: that key falls back to its default, silently, and every
     * other key is unaffected. A typo on the settings page must not zero a
     * matching window and merge the whole race into one pass.
     */
    #[DataProvider('unusableValues')]
    public function testAnUnusableValueFallsBackToTheDefault(string $key, mixed $value): void
    {
        $this->assertSame(self::DEFAULTS, Settings::fromArray([$key => $value])->toArray());
    }

    /**
     * EXPECTED, for the three values with special limits:
     * - `janela_largada_s` = 0 is meaningful ("no start window") and kept;
     * - `fracao_volta_minima` above 1 would call every ordinary lap "too
     *   short to be real", so it is capped at 1;
     * - unknown keys are ignored.
     */
    public function testTheSpecialLimits(): void
    {
        $this->assertSame(0, Settings::fromArray(['janela_largada_s' => 0])->janelaLargadaS);
        $this->assertSame(1.0, Settings::fromArray(['fracao_volta_minima' => 3])->fracaoVoltaMinima);
        $this->assertSame(1.0, Settings::fromArray(['fracao_volta_minima' => 1])->fracaoVoltaMinima);
        $this->assertSame(0.99, Settings::fromArray(['fracao_volta_minima' => 0.99])->fracaoVoltaMinima);
        $this->assertSame(self::DEFAULTS, Settings::fromArray(['chave_que_nao_existe' => 7, 'janelaMs' => 1])->toArray());
    }

    /**
     * EXPECTED: the lists of allowed values are exactly these, and the two
     * questions other code asks of the settings give the right answers.
     */
    public function testTheAllowedValuesAndTheQuestionsAskedOfThem(): void
    {
        $this->assertSame(['confirmadas', 'fonte_principal', 'todas', 'desligada'], Settings::MODOS_PUBLICACAO);
        $this->assertSame(['primeira_passagem', 'largada'], Settings::PASSAGENS_INICIAIS);
        $this->assertSame(['proxima_passagem', 'nenhuma'], Settings::APOS_ENCERRAMENTO);

        foreach (Settings::MODOS_PUBLICACAO as $mode) {
            $settings = Settings::fromArray(['modo_publicacao' => $mode]);
            $this->assertSame($mode, $settings->modoPublicacao);
            $this->assertSame($mode !== 'desligada', $settings->publishesToScoring(), "Mode $mode.");
        }
        $this->assertTrue(Settings::fromArray(['passagem_inicial' => 'primeira_passagem'])->firstPassIsLapZero());
        $this->assertFalse(Settings::fromArray(['passagem_inicial' => 'largada'])->firstPassIsLapZero());
    }

    /**
     * EXPECTED: "too short to be a lap" is under two matching windows
     * whatever the pace, or under the configured fraction of the expected
     * lap when the pace is known. Both limits are exclusive.
     */
    public function testTheShortLapRule(): void
    {
        $settings = new Settings();

        $this->assertTrue($settings->isImpossiblyShortLap(39999, null));
        $this->assertFalse($settings->isImpossiblyShortLap(40000, null));
        $this->assertTrue($settings->isImpossiblyShortLap(89999, 180000));
        $this->assertFalse($settings->isImpossiblyShortLap(90000, 180000));
        $this->assertTrue($settings->isImpossiblyShortLap(39999, 60000), 'Under two windows, even if the pace would allow it.');
    }
}
