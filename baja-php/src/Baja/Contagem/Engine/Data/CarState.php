<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * Where one car stands at "now": its official count, its times and whether
 * it still seems to be running.
 */
final class CarState
{
    public const CORRENDO = 'correndo';
    public const ATRASADO = 'atrasado';
    public const PARADO = 'parado';
    public const NO_BOX = 'no_box';
    /** Logged as inside the fueling station. When a car is in both areas, the one it entered last is shown. */
    public const ABASTECENDO = 'abastecendo';
    public const FORA = 'fora';

    public int $carro;

    /** Official lap count. */
    public int $voltas = 0;

    /** Fused time of the last counted pass. */
    public ?int $ultimaPassagemMs = null;

    /** Duration of the last counted lap. */
    public ?int $ultimaVoltaMs = null;

    /** Best lap among those eligible for best lap. */
    public ?int $melhorVoltaMs = null;

    /** What the pace model expects this car's lap to take right now. Null when unknown. */
    public ?int $voltaEsperadaMs = null;

    public string $status = self::FORA;

    /** Since when the car is atrasado / parado / no_box / abastecendo. Null for the other statuses. */
    public ?int $statusDesdeMs = null;

    /**
     * How many passes each source has for this car after decisions.
     *
     * @var array<int, int> fonteId => count
     */
    public array $contagemPorFonte = [];

    /** Position in the standings (1 = leader). Null for a car with no laps. */
    public ?int $posicao = null;

    public function __construct(int $carro)
    {
        $this->carro = $carro;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'carro' => $this->carro,
            'voltas' => $this->voltas,
            'ultima_passagem_ms' => $this->ultimaPassagemMs,
            'ultima_volta_ms' => $this->ultimaVoltaMs,
            'melhor_volta_ms' => $this->melhorVoltaMs,
            'volta_esperada_ms' => $this->voltaEsperadaMs,
            'status' => $this->status,
            'status_desde_ms' => $this->statusDesdeMs,
            'contagem_por_fonte' => $this->contagemPorFonte,
            'posicao' => $this->posicao,
        ];
    }
}
