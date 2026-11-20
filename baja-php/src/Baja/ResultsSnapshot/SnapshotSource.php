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

    /**
     * Those of $events that are no event in the database. A code that is
     * well formed but missing is a typo, not an event with no results, so
     * Command stops on it instead of building nothing for it.
     *
     * @param list<string> $events
     * @return list<string>
     */
    public function missingEvents(array $events): array;
}
