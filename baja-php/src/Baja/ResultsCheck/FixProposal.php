<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Baja\Results\TeamInput;

/**
 * The mechanical fix for one resultado: its config with every formula that
 * raised an error and has text joined with "+" rewritten by FormulaFix.
 *
 * A rewrite is only kept when it works: the whole new config is checked
 * again for every team (ResultadoCheck), and a rewritten formula that still
 * raises an error is put back as it was and listed in notVerified. The
 * others are checked again without it, until every rewrite left runs
 * without error for every team. A column's value is read by the columns
 * after it ($Pontos), so a fix can also make ANOTHER formula fail where it
 * did not before; then nothing is proposed for the resultado at all.
 *
 *   changes      what is rewritten: where, old and new formula
 *   notVerified  rewrites dropped because they still raised an error
 *   colunas      the config with the changes, ready to be saved
 */
final class FixProposal
{
    /**
     * @param list<array{where: string, old: string, new: string}> $changes
     * @param list<array{where: string, old: string, new: string, error: string}> $notVerified
     */
    private function __construct(
        public readonly array $changes,
        public readonly array $notVerified,
        public readonly object $colunas,
    ) {
    }

    /**
     * @param list<Finding> $findings what ResultadoCheck::run() found for this config
     * @param list<TeamInput> $inputs
     * @return ?self null when nothing can be fixed this way
     */
    public static function for(object $colunas, array $inputs, array $findings): ?self
    {
        $candidates = [];
        foreach ($findings as $finding) {
            $slot = $finding->slot;
            if ($finding->problem !== Finding::FORMULA_ERROR || $slot === null || $slot->path === [] || !is_string($slot->formula)) {
                continue;
            }
            $fixed = FormulaFix::propose($slot->formula);
            if ($fixed !== null) {
                // Keyed by place: a formula that raised two errors is one change.
                $candidates[json_encode($slot->path)] = ['slot' => $slot, 'new' => $fixed];
            }
        }

        $before = self::errorCounts($findings);
        $notVerified = [];
        while ($candidates !== []) {
            $fixed = self::copy($colunas);
            foreach ($candidates as $candidate) {
                $fixed = self::withValue($fixed, $candidate['slot']->path, $candidate['new']);
            }
            $failing = [];
            $elsewhere = null;
            foreach (ResultadoCheck::run($fixed, $inputs)['findings'] as $finding) {
                if ($finding->problem !== Finding::FORMULA_ERROR) {
                    continue;
                }
                $key = $finding->slot === null ? null : json_encode($finding->slot->path);
                if (isset($candidates[$key]) && $finding->formula === $candidates[$key]['new']) {
                    $failing[$key] = $finding->detail;
                } elseif ($finding->teams > ($before[$finding->where . "\n" . $finding->detail] ?? 0)) {
                    // A later column reads the fixed one ($Pontos) and now
                    // fails where it did not before.
                    $elsewhere ??= $finding->where . ' would then fail: ' . $finding->detail;
                }
            }
            if ($failing === [] && $elsewhere !== null) {
                // Not knowing which rewrite caused it, none is proposed.
                foreach ($candidates as $candidate) {
                    $notVerified[] = self::change($candidate) + ['error' => $elsewhere];
                }
                break;
            }
            if ($failing === []) {
                return new self(self::changes($candidates), $notVerified, $fixed);
            }
            foreach ($failing as $key => $error) {
                $notVerified[] = self::change($candidates[$key]) + ['error' => $error];
                unset($candidates[$key]);
            }
        }
        return $notVerified === [] ? null : new self([], $notVerified, $colunas);
    }

    /**
     * How many teams each formula error hits, by place and message.
     *
     * @param list<Finding> $findings
     * @return array<string, int>
     */
    private static function errorCounts(array $findings): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            if ($finding->problem === Finding::FORMULA_ERROR) {
                $counts[$finding->where . "\n" . $finding->detail] = (int)$finding->teams;
            }
        }
        return $counts;
    }

    /**
     * @param array<string, array{slot: FormulaSlot, new: string}> $candidates
     * @return list<array{where: string, old: string, new: string}>
     */
    private static function changes(array $candidates): array
    {
        return array_values(array_map([self::class, 'change'], $candidates));
    }

    /**
     * @param array{slot: FormulaSlot, new: string} $candidate
     * @return array{where: string, old: string, new: string}
     */
    private static function change(array $candidate): array
    {
        return ['where' => $candidate['slot']->where, 'old' => $candidate['slot']->formula, 'new' => $candidate['new']];
    }

    /** A deep copy of decoded JSON, so the original stays as it was. */
    private static function copy(object $json): object
    {
        return json_decode((string)json_encode($json, JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * $node with the value at $path replaced. Objects are changed in place
     * (they are a copy already), arrays are values in PHP and so rebuilt.
     *
     * @param list<string|int> $path
     */
    private static function withValue(mixed $node, array $path, mixed $value): mixed
    {
        if ($path === []) {
            return $value;
        }
        $key = array_shift($path);
        if (is_object($node)) {
            $node->$key = self::withValue($node->$key ?? null, $path, $value);
        } else {
            $node[$key] = self::withValue($node[$key] ?? null, $path, $value);
        }
        return $node;
    }
}
