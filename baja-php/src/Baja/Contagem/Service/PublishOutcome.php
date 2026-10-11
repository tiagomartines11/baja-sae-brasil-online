<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

/**
 * What one run of the ScorePublisher did. Exists so that callers, and above
 * all the tests, can see whether the (expensive) points recalculation ran.
 */
final class PublishOutcome
{
    /** True when the publisher stood back because the race has never been started. */
    public bool $skippedNoStart = false;

    /** @var int[] cars whose Input row was created or changed */
    public array $changedCars = [];

    /** True when Prova::refreshVarsAndPontos() was called. */
    public bool $refreshRan = false;

    public function anythingChanged(): bool
    {
        return $this->changedCars !== [];
    }
}
