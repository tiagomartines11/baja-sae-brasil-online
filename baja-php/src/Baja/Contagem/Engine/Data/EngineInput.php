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

    /** @var int[] car numbers of the teams that take part: present at the event AND classified */
    public array $validCars = [];

    /**
     * Cars present at the event but not classified (the prova names one of
     * its fields as the classification field and this car's is not "SIM").
     * A pass typed for one of them is kept and flagged, and never counts
     * unless a reviewer says "contar mesmo assim". The list must also be
     * given to countCars(), or the two entry points would disagree.
     *
     * @var int[]
     */
    public array $unclassifiedCars = [];

    /** "Now", milliseconds since the epoch. Car status is evaluated at this moment. */
    public int $nowMs = 0;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }
}
