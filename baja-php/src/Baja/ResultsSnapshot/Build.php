<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot;

/**
 * What one run of the tool produced: the snapshot documents, which are
 * written and compared, and the PHP warnings each page or job raised, which
 * are only ever reported (--warnings).
 */
final class Build
{
    /**
     * @param array<string, array<string, mixed>> $documents relative path => document
     * @param array<string, list<string>>         $warnings  relative path => its distinct warnings, with file:line
     */
    public function __construct(
        public readonly array $documents,
        public readonly array $warnings = [],
    ) {
    }
}
