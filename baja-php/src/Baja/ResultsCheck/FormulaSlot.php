<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Baja\Results\Column;
use Baja\Results\ResultsConfig;

/**
 * One place in a config that holds a formula, e.g. the "val" of the column
 * "Pontos", or the prova's points formula "PONTOS".
 *
 *   where    how the report names it: 'column "Pontos" val', 'tiebreak',
 *            'pontos.PONTOS'
 *   path     the keys that lead to it in the decoded JSON, to put a fixed
 *            formula back: ['colunas', 6, 'val']
 *   formula  the formula as written
 */
final class FormulaSlot
{
    /** @param list<string|int> $path */
    public function __construct(
        public readonly string $where,
        public readonly array $path,
        public readonly mixed $formula,
    ) {
    }

    /**
     * Every formula of a resultado's "colunas" JSON that the results builder
     * runs (Baja\Results\Build\ColumnEvaluator), in the order of the config:
     * each column's val (one per line for a list), its highlight (lists
     * only), val_spoilers and val_api, then the tiebreak.
     *
     * @return list<self>
     */
    public static function ofResultado(object $colunas): array
    {
        $slots = [];
        foreach ((array)($colunas->colunas ?? null) as $index => $json) {
            $column = Column::fromJson($json);
            $name = self::columnName($column, $index);
            $kind = $column->kind();
            $isFormula = $kind === Column::KIND_FORMULA || $kind === Column::KIND_LIST;
            if ($kind === Column::KIND_FORMULA) {
                $slots[] = new self($name . ' val', ['colunas', $index, 'val'], $column->val);
            }
            if ($kind === Column::KIND_LIST) {
                foreach ($column->val as $line => $formula) {
                    $slots[] = new self($name . ' val[' . $line . ']', ['colunas', $index, 'val', $line], $formula);
                }
                if ($column->highlightFormula() !== null) {
                    $slots[] = new self($name . ' highlight', ['colunas', $index, 'highlight'], $column->highlight);
                }
            }
            // The builder shows val_spoilers whenever it is truthy, whatever the column's kind.
            if ($column->valSpoilers) {
                if (is_array($column->valSpoilers)) {
                    foreach ($column->valSpoilers as $line => $formula) {
                        $slots[] = new self($name . ' val_spoilers[' . $line . ']', ['colunas', $index, 'val_spoilers', $line], $formula);
                    }
                } else {
                    $slots[] = new self($name . ' val_spoilers', ['colunas', $index, 'val_spoilers'], $column->valSpoilers);
                }
            }
            if ($isFormula && $column->valApi !== null) {
                $slots[] = new self($name . ' val_api', ['colunas', $index, 'val_api'], $column->valApi);
            }
        }
        $tiebreak = ResultsConfig::fromColunas($colunas)->tiebreakFormula();
        if ($tiebreak !== null) {
            $slots[] = new self('tiebreak', ['tiebreak'], $tiebreak);
        }
        return $slots;
    }

    /**
     * Every formula of a prova's params, in the order Input::updateVars()
     * and Input::updatePontos() run them: vars, min_max, pontos.
     *
     * @return list<self>
     */
    public static function ofProva(mixed $params): array
    {
        $slots = [];
        foreach (['vars', 'min_max', 'pontos'] as $group) {
            foreach ((array)(is_object($params) ? ($params->$group ?? null) : null) as $name => $formula) {
                $slots[] = new self($group . '.' . $name, [$group, $name], $formula);
            }
        }
        return $slots;
    }

    /** 'column "Pontos"', or 'column 4 (no header)' (counting from 1). */
    public static function columnName(Column $column, int|string $index): string
    {
        if ($column->header === null || $column->header === '') {
            return 'column ' . (is_int($index) ? $index + 1 : $index) . ' (no header)';
        }
        return 'column "' . $column->key() . '"';
    }
}
