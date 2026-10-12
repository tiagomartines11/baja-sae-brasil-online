<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

/**
 * What one call to the ScorePublisher did.
 */
final class PublishOutcome
{
    /** Nothing was written because the race has no start yet. */
    public bool $skippedNoStart = false;

    /** Nothing was written because the publish mode is "desligada". */
    public bool $skippedPublishingOff = false;

    /** @var int[] cars whose published values were written */
    public array $changedCars = [];

    /**
     * @var int[] cars whose published VOLTAS or LAST disagreed with an
     *            up-to-date full run and were corrected (see ScorePublisher::reconcile())
     */
    public array $correctedCars = [];

    /**
     * @var array<int, array{carro: int, campo: string, publicado: mixed, calculado: int}>
     *      the same corrections, one per field, for showing to a reviewer
     */
    public array $corrections = [];

    /** True when points were recalculated (Prova::refreshVarsAndPontos()). */
    public bool $refreshRan = false;

    public function anythingChanged(): bool
    {
        return $this->changedCars !== [];
    }
}
