<?php
declare(strict_types=1);

namespace Baja\Repeat;

/**
 * Repeated columns in a resultado's "colunas" (BAJAAPP-103), the column
 * side of the repeated input groups (InputGroups):
 *
 *     {"header": "Tentativas", "repetir": 6, "val": "$T_{n} ? ROUND($T_{n}, 3) : '-'", "sort": "duasmenor"}
 *
 * is the column with a list of formulas that one would write by hand, one
 * value per line of the cell:
 *
 *     {"header": "Tentativas", "val": ["$T_1 ? ROUND($T_1, 3) : '-'", ..., "$T_6 ? ROUND($T_6, 3) : '-'"], "sort": "duasmenor"}
 *
 * "{n}" in "val" becomes 1, 2 ... "repetir". A "val_spoilers" that uses
 * {n} is repeated the same way; one that does not is kept as it is (one
 * value for the whole cell, as usual). Every other key is kept.
 *
 * One thing differs from the list written by hand: Results\Column marks
 * the expanded column as repeated, and its cell leaves out the lines with
 * no value (null or ""), the attempts not run. The help page recommends
 * ": null" for them, e.g. "$T_{n} !== null ? ROUND($T_{n}, 3) : null".
 *
 * expandColumn() is the one place this happens: Results\Column::fromJson()
 * calls it, so the results page, the API, the premiações, the formula
 * comparison and the config check all read the same expanded column.
 */
final class RepeatedColumns
{
    public const PLACEHOLDER = '{n}';

    /**
     * One entry of "colunas" with its repetition written out; anything that
     * is not a valid repeated column is returned as it is.
     */
    public static function expandColumn(mixed $column): mixed
    {
        if (!self::isRepeated($column) || self::columnErrors($column) !== []) {
            return $column;
        }
        $expanded = clone $column;
        unset($expanded->repetir);
        $expanded->val = self::lines($column->val, $column->repetir);
        if (is_string($column->val_spoilers ?? null) && str_contains($column->val_spoilers, self::PLACEHOLDER)) {
            $expanded->val_spoilers = self::lines($column->val_spoilers, $column->repetir);
        }
        return $expanded;
    }

    /** Whether an entry of "colunas" asks to be repeated. */
    public static function isRepeated(mixed $column): bool
    {
        return is_object($column) && property_exists($column, 'repetir');
    }

    /**
     * What is wrong with the repeated columns of a "colunas" JSON, in
     * Portuguese, for the admin who saves it; empty when nothing is.
     *
     * @return list<string>
     */
    public static function errors(mixed $colunas): array
    {
        $errors = [];
        $columns = is_object($colunas) ? ($colunas->colunas ?? null) : null;
        foreach (is_array($columns) || is_object($columns) ? array_values((array)$columns) : [] as $position => $column) {
            if (self::isRepeated($column)) {
                $header = is_scalar($column->header ?? null) ? '"' . $column->header . '"' : (string)($position + 1);
                foreach (self::columnErrors($column) as $error) {
                    $errors[] = 'Coluna ' . $header . ': ' . $error;
                }
            }
        }
        return $errors;
    }

    /** @return list<string> */
    private static function columnErrors(object $column): array
    {
        $errors = [];
        $times = $column->repetir;
        if (!is_int($times) || $times < 1 || $times > InputGroups::MOST_REPETITIONS) {
            $errors[] = '"repetir" precisa ser um número inteiro de 1 a ' . InputGroups::MOST_REPETITIONS
                . ' (recebeu ' . ($times === null ? 'nada' : json_encode($times, JSON_UNESCAPED_UNICODE)) . ').';
        }
        $val = $column->val ?? null;
        if (!is_string($val) || in_array($val, ['Pos', 'Equipe', 'EquipeNum'], true)) {
            $errors[] = 'com "repetir", "val" precisa ser uma fórmula (um texto), que vira uma linha por repetição.';
        } elseif (!str_contains($val, self::PLACEHOLDER)) {
            $errors[] = 'com "repetir", "val" precisa usar ' . self::PLACEHOLDER . ' no lugar do número da repetição (ex.: $T_' . self::PLACEHOLDER . ').';
        }
        return $errors;
    }

    /** @return list<string> the formula once per repetition, {n} replaced */
    private static function lines(string $formula, int $times): array
    {
        return array_map(fn(int $n) => str_replace(self::PLACEHOLDER, (string)$n, $formula), range(1, $times));
    }
}
