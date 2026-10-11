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

    /** True when points were recalculated (Prova::refreshVarsAndPontos()). */
    public bool $refreshRan = false;

    public function anythingChanged(): bool
    {
        return $this->changedCars !== [];
    }
}
