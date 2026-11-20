<?php
declare(strict_types=1);

namespace Baja\Posicoes;

/**
 * One entry of a prova's "posicoes" (BAJAAPP-102): a variable that holds the
 * team's position (1, 2, 3...), or null when it has none.
 *
 * Two kinds:
 *
 *   inside this prova     {"valor": "$NOTA", "ordem": "desc", "desempate": "$NOTA_FINAL"}
 *                         every team's "valor" is worked out, and the teams of
 *                         this prova are ranked by it
 *   another resultado     {"resultado": "26SU_TRA"}
 *                         the position the team has in that resultado's ranking
 *
 * Every property keeps the JSON as written (null when it is missing); the
 * methods say how it is read. PosicoesRules says what is wrong with an entry.
 */
final class PositionSpec
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $valor = null,
        public readonly mixed $ordem = null,
        public readonly mixed $desempate = null,
        public readonly mixed $resultado = null,
    ) {
    }

    /** Reads one entry; anything that is not a JSON object reads as an empty one. */
    public static function fromJson(string $name, mixed $json): self
    {
        $json = is_object($json) ? $json : (object)[];
        return new self(
            $name,
            $json->valor ?? null,
            $json->ordem ?? null,
            $json->desempate ?? null,
            $json->resultado ?? null,
        );
    }

    /** A position in another resultado's ranking ("resultado"), rather than inside this prova. */
    public function isExternal(): bool
    {
        return $this->resultado !== null && $this->resultado !== '';
    }

    /** "asc" (lowest is 1st) or "desc" (highest is 1st, the default), as a resultado's "pos_order". */
    public function direction(): string
    {
        return strtolower(trim((string)$this->ordem)) === 'asc' ? 'asc' : 'desc';
    }

    /** The tiebreak formula, or null when it is missing or "". "0" is a formula. */
    public function tiebreakFormula(): mixed
    {
        return ($this->desempate === null || $this->desempate === '') ? null : $this->desempate;
    }

    /** The referenced resultado's id, trimmed; null for a position inside this prova. */
    public function resultadoId(): ?string
    {
        return $this->isExternal() ? trim((string)$this->resultado) : null;
    }

    /**
     * Every entry of a prova's params, in the order written. A missing or
     * malformed "posicoes" gives none.
     *
     * @return list<self>
     */
    public static function allOf(mixed $params): array
    {
        $posicoes = is_object($params) ? ($params->posicoes ?? null) : null;
        if (!is_object($posicoes) && !is_array($posicoes)) {
            return [];
        }
        $specs = [];
        foreach ((array)$posicoes as $name => $json) {
            $specs[] = self::fromJson((string)$name, $json);
        }
        return $specs;
    }
}
