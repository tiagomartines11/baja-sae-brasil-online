<?php
declare(strict_types=1);

namespace Baja\Results;

/**
 * One column of a resultado, as the organiser wrote it in the resultado's
 * "colunas" JSON:
 *
 *     {"header": "Pontos", "val": "$PONTOS * 2", "size": 60}
 *
 * Every property is kept exactly as written (null when it is missing), so
 * the two behaviours (Behaviour\PageBehaviour, Behaviour\PremiacaoBehaviour)
 * can test them the way the code they replace did: one asks "is it truthy?",
 * the other "is it set?".
 *
 *   header        the column title, and the name its value is stored under
 *   val           a formula, a list of formulas (one line each), or one of
 *                 the words EquipeNum, Equipe, Pos (see kind())
 *   val_spoilers  what to show instead while spoilers are on
 *   highlight     for a list: the formula whose value is shown in bold
 *   val_api       the raw value for the API, when stripping the HTML of the
 *                 display is not good enough (see Cell)
 *   size, sort, hidden   only for drawing the table header
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
    ) {
    }

    /** Reads one entry of the "colunas" list. */
    public static function fromJson(mixed $json): self
    {
        $json = is_object($json) ? $json : (object)[];
        return new self(
            $json->header ?? null,
            $json->val ?? null,
            $json->val_spoilers ?? null,
            $json->highlight ?? null,
            $json->val_api ?? null,
            $json->size ?? null,
            $json->sort ?? null,
            $json->hidden ?? null,
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
