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
    /** Count, review and feed as usual, but write nothing to the scoring system. */
    public const MODO_DESLIGADA = 'desligada';

    public const MODOS_PUBLICACAO = [
        self::MODO_CONFIRMADAS,
        self::MODO_FONTE_PRINCIPAL,
        self::MODO_TODAS,
        self::MODO_DESLIGADA,
    ];

    /** Each car's first counted pass after the start is its pass 0, not a lap. */
    public const INICIAL_PRIMEIRA_PASSAGEM = 'primeira_passagem';
    /** The start itself is every car's pass 0; every counted pass is a lap. */
    public const INICIAL_LARGADA = 'largada';

    public const PASSAGENS_INICIAIS = [self::INICIAL_PRIMEIRA_PASSAGEM, self::INICIAL_LARGADA];

    /** After the finish, each car's next pass still counts; later ones do not. */
    public const APOS_PROXIMA_PASSAGEM = 'proxima_passagem';
    /** Nothing after the finish counts. */
    public const APOS_NENHUMA = 'nenhuma';

    public const APOS_ENCERRAMENTO = [self::APOS_PROXIMA_PASSAGEM, self::APOS_NENHUMA];

    /** Which pass groups become official laps. See PublishRule. */
    public string $modoPublicacao = self::MODO_CONFIRMADAS;

    /** The source trusted by the "fonte_principal" mode. */
    public ?int $fontePrincipalId = null;

    /**
     * Which pass is "lap zero". The counting line is after the start line,
     * so by default a car's first crossing completes no lap: laps = counted
     * passes - 1. See PassRoles.
     */
    public string $passagemInicial = self::INICIAL_PRIMEIRA_PASSAGEM;

    /**
     * Only with passagem_inicial = "largada": entries in the first N seconds
     * after the start are the initial crossing and never count, so a counter
     * who types the start pack anyway gives nobody an extra lap.
     */
    public int $janelaLargadaS = 0;

    /** What to do with passes after the finish. */
    public string $aposEncerramento = self::APOS_PROXIMA_PASSAGEM;

    /** Entries for the same car closer than this are the same crossing. */
    public int $janelaMs = 20000;

    /** A repeat from the same source inside this window is a double tap. */
    public int $duplicataMs = 5000;

    /** A lap shorter than this fraction of the expected lap is "too short to be real". Never above 1. */
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

    /** The full recalculation (flags, pace, best laps) runs at most once in this many seconds. */
    public int $recalculoS = 5;

    /** A device silent for longer than this is no longer waited for. */
    public int $dispositivoAusenteS = 600;

    /**
     * Name of the prova's input field that says whether a car is classified
     * ("SIM"), for example "APTO". Null: every present car takes part.
     */
    public ?string $campoClassificacao = null;

    /** Whether the fueling station is logged as an area of its own. */
    public bool $abastecimento = false;

    /** Whether simulated entries are accepted. Only for rehearsals; off for a real race. */
    public bool $permitirSimulacao = false;

    /**
     * @param array<string, mixed>|object|null $raw the decoded `contagem` block
     */
    public static function fromArray($raw): self
    {
        $raw = (array) ($raw ?? []);
        $settings = new self();

        $settings->modoPublicacao = self::oneOf($raw, 'modo_publicacao', self::MODOS_PUBLICACAO, $settings->modoPublicacao);
        $settings->passagemInicial = self::oneOf($raw, 'passagem_inicial', self::PASSAGENS_INICIAIS, $settings->passagemInicial);
        $settings->aposEncerramento = self::oneOf($raw, 'apos_encerramento', self::APOS_ENCERRAMENTO, $settings->aposEncerramento);

        $principal = $raw['fonte_principal_id'] ?? null;
        if (is_numeric($principal) && (int) $principal > 0) {
            $settings->fontePrincipalId = (int) $principal;
        }

        // Zero is a meaningful value here ("no start window"), unlike the others.
        $janelaLargada = $raw['janela_largada_s'] ?? null;
        if (is_numeric($janelaLargada) && (int) $janelaLargada >= 0) {
            $settings->janelaLargadaS = (int) $janelaLargada;
        }

        $settings->janelaMs = self::positiveInt($raw, 'janela_ms', $settings->janelaMs);
        $settings->duplicataMs = self::positiveInt($raw, 'duplicata_ms', $settings->duplicataMs);
        $settings->janelaRitmoS = self::positiveInt($raw, 'janela_ritmo_s', $settings->janelaRitmoS);
        $settings->dispersaoMaxMs = self::positiveInt($raw, 'dispersao_max_ms', $settings->dispersaoMaxMs);
        $settings->fonteOfflineS = self::positiveInt($raw, 'fonte_offline_s', $settings->fonteOfflineS);
        $settings->recalculoS = self::positiveInt($raw, 'recalculo_s', $settings->recalculoS);
        $settings->dispositivoAusenteS = self::positiveInt($raw, 'dispositivo_ausente_s', $settings->dispositivoAusenteS);

        $settings->fatorAtrasado = self::positiveFloat($raw, 'fator_atrasado', $settings->fatorAtrasado);
        $settings->fatorParado = self::positiveFloat($raw, 'fator_parado', $settings->fatorParado);
        // Above 1 every ordinary lap would be "too short to be real".
        $settings->fracaoVoltaMinima = min(1.0, self::positiveFloat($raw, 'fracao_volta_minima', $settings->fracaoVoltaMinima));

        $campo = $raw['campo_classificacao'] ?? null;
        if (is_string($campo) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $campo) === 1) {
            $settings->campoClassificacao = $campo;
        }
        $settings->abastecimento = self::onOrOff($raw, 'abastecimento');
        $settings->permitirSimulacao = self::onOrOff($raw, 'permitir_simulacao');

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
            'passagem_inicial' => $this->passagemInicial,
            'janela_largada_s' => $this->janelaLargadaS,
            'apos_encerramento' => $this->aposEncerramento,
            'janela_ms' => $this->janelaMs,
            'duplicata_ms' => $this->duplicataMs,
            'fracao_volta_minima' => $this->fracaoVoltaMinima,
            'janela_ritmo_s' => $this->janelaRitmoS,
            'fator_atrasado' => $this->fatorAtrasado,
            'fator_parado' => $this->fatorParado,
            'dispersao_max_ms' => $this->dispersaoMaxMs,
            'fonte_offline_s' => $this->fonteOfflineS,
            'recalculo_s' => $this->recalculoS,
            'dispositivo_ausente_s' => $this->dispositivoAusenteS,
            'campo_classificacao' => $this->campoClassificacao,
            'abastecimento' => $this->abastecimento,
            'permitir_simulacao' => $this->permitirSimulacao,
        ];
    }

    /**
     * False in "desligada" mode: the race is counted and reviewed, but the
     * scoring system (Input.dados, prova.tempo, points) is left alone. Used
     * to run beside the old spreadsheets without both writing VOLTAS.
     */
    public function publishesToScoring(): bool
    {
        return $this->modoPublicacao !== self::MODO_DESLIGADA;
    }

    /**
     * Too short to be a lap: under the configured fraction of the lap
     * expected of that car, or, whatever the pace (it is unknown early in
     * the race), under two matching windows. No car laps the track in 40
     * seconds; two passes that close are one crossing recorded twice.
     *
     * @param int|null $expectedMs the expected lap of the car, null while the pace is unknown
     */
    public function isImpossiblyShortLap(int $lapMs, ?int $expectedMs): bool
    {
        if ($lapMs < 2 * $this->janelaMs) {
            return true;
        }
        return $expectedMs !== null && $lapMs < $this->fracaoVoltaMinima * $expectedMs;
    }

    public function firstPassIsLapZero(): bool
    {
        return $this->passagemInicial === self::INICIAL_PRIMEIRA_PASSAGEM;
    }

    /**
     * @param array<string, mixed> $raw
     * @param string[] $allowed
     */
    private static function oneOf(array $raw, string $key, array $allowed, string $default): string
    {
        $value = $raw[$key] ?? null;
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * A switch. On only for an unmistakable yes: true, 1, "1", "true", "on",
     * "sim". Anything else, including a typo, is off, which is the safe
     * side for both switches that use this.
     *
     * @param array<string, mixed> $raw
     */
    private static function onOrOff(array $raw, string $key): bool
    {
        $value = $raw[$key] ?? null;
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'sim'], true);
        }
        return $value === true || $value === 1;
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
