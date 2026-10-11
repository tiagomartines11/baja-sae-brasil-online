<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

use Baja\Contagem\Engine\Settings;

/**
 * Everything the engine needs, already loaded. The engine itself touches
 * neither the database nor the clock, which is what makes it testable:
 * the same input always gives the same result.
 */
final class EngineInput
{
    public Settings $settings;

    /** @var Source[] */
    public array $sources = [];

    /** @var Entry[] every raw entry of the prova, in any order */
    public array $entries = [];

    /** @var Decision[] */
    public array $decisions = [];

    /** @var Ocorrencia[] */
    public array $ocorrencias = [];

    /**
     * Imported totals, for sources of tipo "totais".
     *
     * @var array<int, array<int, int>> fonteId => [carro => voltas]
     */
    public array $totals = [];

    /** @var int[] car numbers of the teams present at the event */
    public array $validCars = [];

    /** "Now", milliseconds since the epoch. Car status is evaluated at this moment. */
    public int $nowMs = 0;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }
}
