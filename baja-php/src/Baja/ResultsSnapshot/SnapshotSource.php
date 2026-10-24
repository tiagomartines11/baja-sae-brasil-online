<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot;

/**
 * Where fresh snapshot documents come from.
 *
 * The real one (LiveSnapshotSource) runs the pages against the database.
 * The tests give Command a fake one, so that --check, --update and
 * --warnings can be tested without a database.
 */
interface SnapshotSource
{
    /**
     * @param list<string>|null     $onlyEvents limit to these events, or null for all
     * @param callable(string):void $progress   receives a line of progress now and then
     */
    public function build(?array $onlyEvents, callable $progress): Build;
}
