<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

/**
 * The tunable numbers of the lap counting engine, read from
 * `prova.params.contagem`.
 *
 * Every key has a default so a prova with no "contagem" block at all still
 * works. Unknown keys are ignored and out-of-range values fall back to the
 * default: a typo on the settings page must never be able to stop a race
 * from being counted.
 */
final class Settings
{
    public const MODO_CONFIRMADAS = 'confirmadas';
    public const MODO_FONTE_PRINCIPAL = 'fonte_principal';
    public const MODO_TODAS = 'todas';

    public const MODOS_PUBLICACAO = [
        self::MODO_CONFIRMADAS,
        self::MODO_FONTE_PRINCIPAL,
        self::MODO_TODAS,
    ];

    /** Which pass groups become official laps. See PublishRule. */
    public string $modoPublicacao = self::MODO_CONFIRMADAS;

    /** The source trusted by the "fonte_principal" mode. */
    public ?int $fontePrincipalId = null;

    /** Entries for the same car closer than this are the same crossing. */
    public int $janelaMs = 20000;

    /** A repeat from the same source inside this window is a double tap. */
    public int $duplicataMs = 5000;

    /** A lap shorter than this fraction of the expected lap is "too short to be real". */
    public float $fracaoVoltaMinima = 0.5;

    /** How far back the field pace looks, in seconds. */
    public int $janelaRitmoS = 900;

    /** A car is "atrasado" after this many expected laps without a pass. */
    public float $fatorAtrasado = 1.5;

    /** A car is "parado" after this many expected laps without a pass. */
    public float $fatorParado = 2.0;

    /** Sources must agree within this for a lap to be eligible for best lap. */
    public int $dispersaoMaxMs = 2000;

    /** A source silent for longer than this is shown as offline. */
    public int $fonteOfflineS = 60;

    /**
     * @param array<string, mixed>|object|null $raw the decoded `contagem` block
     */
    public static function fromArray($raw): self
    {
        $raw = (array) ($raw ?? []);
        $settings = new self();

        $modo = $raw['modo_publicacao'] ?? null;
        if (is_string($modo) && in_array($modo, self::MODOS_PUBLICACAO, true)) {
            $settings->modoPublicacao = $modo;
        }

        $principal = $raw['fonte_principal_id'] ?? null;
        if (is_numeric($principal) && (int) $principal > 0) {
            $settings->fontePrincipalId = (int) $principal;
        }

        $settings->janelaMs = self::positiveInt($raw, 'janela_ms', $settings->janelaMs);
        $settings->duplicataMs = self::positiveInt($raw, 'duplicata_ms', $settings->duplicataMs);
        $settings->janelaRitmoS = self::positiveInt($raw, 'janela_ritmo_s', $settings->janelaRitmoS);
        $settings->dispersaoMaxMs = self::positiveInt($raw, 'dispersao_max_ms', $settings->dispersaoMaxMs);
        $settings->fonteOfflineS = self::positiveInt($raw, 'fonte_offline_s', $settings->fonteOfflineS);

        $settings->fracaoVoltaMinima = self::positiveFloat($raw, 'fracao_volta_minima', $settings->fracaoVoltaMinima);
        $settings->fatorAtrasado = self::positiveFloat($raw, 'fator_atrasado', $settings->fatorAtrasado);
        $settings->fatorParado = self::positiveFloat($raw, 'fator_parado', $settings->fatorParado);

        return $settings;
    }

    /**
     * The same keys `fromArray()` reads, for saving back into prova.params.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'modo_publicacao' => $this->modoPublicacao,
            'fonte_principal_id' => $this->fontePrincipalId,
            'janela_ms' => $this->janelaMs,
            'duplicata_ms' => $this->duplicataMs,
            'fracao_volta_minima' => $this->fracaoVoltaMinima,
            'janela_ritmo_s' => $this->janelaRitmoS,
            'fator_atrasado' => $this->fatorAtrasado,
            'fator_parado' => $this->fatorParado,
            'dispersao_max_ms' => $this->dispersaoMaxMs,
            'fonte_offline_s' => $this->fonteOfflineS,
        ];
    }

    /** @param array<string, mixed> $raw */
    private static function positiveInt(array $raw, string $key, int $default): int
    {
        $value = $raw[$key] ?? null;
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }
        return $default;
    }

    /** @param array<string, mixed> $raw */
    private static function positiveFloat(array $raw, string $key, float $default): float
    {
        $value = $raw[$key] ?? null;
        if (is_numeric($value) && (float) $value > 0) {
            return (float) $value;
        }
        return $default;
    }
}
