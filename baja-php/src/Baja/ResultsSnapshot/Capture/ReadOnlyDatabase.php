<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Capture;

use Propel\Runtime\Propel;

/**
 * Makes the app's database connection refuse every write.
 *
 * The snapshot tool only reads. That is a promise about code, so it is also
 * enforced where it cannot be broken by code: right after connecting, every
 * process of the tool (the command itself and every page and job it starts)
 * switches its MySQL session to read-only. From then on any INSERT, UPDATE
 * or DELETE, including a save() some page might do, fails with MySQL error
 * 1792 instead of changing data, and that failure shows up in the snapshot.
 *
 * This is what makes it safe to point the tool at a copy of production.
 * Connecting as a MySQL account that only has SELECT is safer still; CI
 * does that (see docs/results-snapshots.md).
 */
final class ReadOnlyDatabase
{
    public static function enforce(): void
    {
        Propel::getWriteConnection('resultados')->exec('SET SESSION transaction_read_only = ON');
    }

    /** Whether the current session is read-only. Used by the tests. */
    public static function isEnforced(): bool
    {
        $statement = Propel::getReadConnection('resultados')->query('SELECT @@SESSION.transaction_read_only');
        return (int)$statement->fetchColumn() === 1;
    }
}
