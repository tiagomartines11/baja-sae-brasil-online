<?php
declare(strict_types=1);

namespace Baja\Results;

use Baja\Repeat\RepeatedColumns;

/**
 * One column of a resultado, as the organiser wrote it in the resultado's
 * "colunas" JSON:
 *
 *     {"header": "Pontos", "val": "$PONTOS * 2", "size": 60}
 *
 * Every property is kept exactly as written (null when it is missing).
 *
 *   header        the column title, and the name its value is stored under
 *   val           a formula, a list of formulas (one line each), or one of
 *                 the words EquipeNum, Equipe, Pos (see kind())
 *   val_spoilers  what to show instead while spoilers are on
 *   highlight     for a list: the formula whose value is shown in bold
 *   val_api       the raw value for the API, when stripping the HTML of the
 *                 display is not good enough (see Cell)
 *   size, sort, hidden   only for drawing the table header
 *
 * A column written with "repetir" (BAJAAPP-103) is read as the list it
 * stands for, and remembers it: $repeated. Its cell then leaves out the
 * lines with no value (null or ""), the passes not run yet; a list written
 * by hand shows every line, as it always did.
 */
final class Column
{
    public const KIND_TEAM_NUMBER = 'team_number';
    public const KIND_TEAM = 'team';
    public const KIND_POSITION = 'position';
    public const KIND_LIST = 'list';
    public const KIND_FORMULA = 'formula';

    public function __construct(
        public readonly mixed $header,
        public readonly mixed $val,
        public readonly mixed $valSpoilers = null,
        public readonly mixed $highlight = null,
        public readonly mixed $valApi = null,
        public readonly mixed $size = null,
        public readonly mixed $sort = null,
        public readonly mixed $hidden = null,
        public readonly bool $repeated = false,
    ) {
    }

    /**
     * Reads one entry of the "colunas" list. A repeated column ("repetir",
     * BAJAAPP-103) is read as the list it stands for (Repeat\RepeatedColumns),
     * and marked $repeated. A "repetir" that could not be expanded (a wrong
     * column, which the config check reports) leaves an ordinary column.
     */
    public static function fromJson(mixed $json): self
    {
        $askedToRepeat = RepeatedColumns::isRepeated($json);
        $json = is_object($json) ? RepeatedColumns::expandColumn($json) : (object)[];
        return new self(
            $json->header ?? null,
            $json->val ?? null,
            $json->val_spoilers ?? null,
            $json->highlight ?? null,
            $json->val_api ?? null,
            $json->size ?? null,
            $json->sort ?? null,
            $json->hidden ?? null,
            $askedToRepeat && is_array($json->val ?? null),
        );
    }

    /**
     * What the column shows:
     *
     *   val "EquipeNum"  the team's car number
     *   val "Equipe"     the team's name (the page adds the school)
     *   val "Pos"        the team's position in the ranking
     *   val [...]        a list of formulas, one line each
     *   anything else    one formula
     */
    public function kind(): string
    {
        return match (true) {
            $this->val === 'EquipeNum' => self::KIND_TEAM_NUMBER,
            $this->val === 'Equipe' => self::KIND_TEAM,
            $this->val === 'Pos' => self::KIND_POSITION,
            is_array($this->val) => self::KIND_LIST,
            default => self::KIND_FORMULA,
        };
    }

    /**
     * Whether spoilers hide this column: it has a "val_spoilers". Missing,
     * "" or an empty list mean it is always shown, as the pages always read it.
     */
    public function hasSpoilerValue(): bool
    {
        return (bool)$this->valSpoilers;
    }

    /**
     * The lines a cell shows, from the values of its formulas: all of them,
     * or for a repeated column only those with a value. null and "" are no
     * value; 0 and "-" are values.
     *
     * @param list<mixed> $lines
     * @return list<mixed>
     */
    public function shownLines(array $lines): array
    {
        if (!$this->repeated) {
            return $lines;
        }
        return array_values(array_filter($lines, fn($line) => $line !== null && $line !== ''));
    }

    /** The highlight formula, or null when it is missing or "" (R6). "0" is a formula. */
    public function highlightFormula(): mixed
    {
        return ($this->highlight === null || $this->highlight === '') ? null : $this->highlight;
    }

    /**
     * The array key the column's value is stored under. PHP turns a null
     * key into "", and the pages always relied on that for a column with no
     * header.
     */
    public function key(): string|int
    {
        $header = $this->header ?? '';
        return is_int($header) ? $header : (string)$header;
    }
}
