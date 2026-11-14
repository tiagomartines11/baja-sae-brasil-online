<?php
declare(strict_types=1);

namespace Baja\Repeat;

/**
 * A prova's "inputs" after InputGroups::expand(): every repeated group
 * written out as ordinary fields, plus what the rest of the site needs to
 * know about the groups.
 *
 *   inputs       the field definitions, exactly as a hand-written config
 *                would have them (T_1, D_1, ... T_6, D_6)
 *   repetitions  one per repetition of every group, in order
 *   lists        the repeated codes: "T" => ["T_1", ..., "T_6"]
 *   errors       what is wrong with the groups, in Portuguese, for the
 *                admin who saves the config; empty when nothing is
 */
final class ExpandedInputs
{
    /** The suffix of the list variables ($T_lista). No field code may end with it. */
    public const LIST_SUFFIX = '_lista';

    /** @var array<string, Repetition> code => the repetition the field belongs to */
    private array $repetitionOfCode = [];

    /**
     * @param list<mixed>                 $inputs
     * @param list<Repetition>            $repetitions
     * @param array<string, list<string>> $lists
     * @param list<string>                $errors
     */
    public function __construct(
        public readonly array $inputs,
        public readonly array $repetitions,
        public readonly array $lists,
        public readonly array $errors,
    ) {
        foreach ($repetitions as $repetition) {
            foreach ($repetition->codes as $code) {
                $this->repetitionOfCode[$code] = $repetition;
            }
        }
    }

    /** The repetition a field belongs to; null for a field outside any group. */
    public function repetitionOf(string $code): ?Repetition
    {
        return $this->repetitionOfCode[$code] ?? null;
    }

    /**
     * The repetition right before this one in the same group (Tentativa 2
     * for Tentativa 3); null for the first.
     */
    public function previous(Repetition $repetition): ?Repetition
    {
        foreach ($this->repetitions as $other) {
            if ($other->group === $repetition->group && $other->number === $repetition->number - 1) {
                return $other;
            }
        }
        return null;
    }

    /**
     * The extra formula variables of one team: for each repeated code T,
     * $T_lista holds the values of T_1 ... T_N that were filled in, in
     * order. Empty (null or "") values are left out, so a team that ran
     * twice of six has a list of two.
     *
     * @param mixed $dados the team's dados, as Input::getDados() returns them
     * @return array<string, list<mixed>> "T_lista" => [values]
     */
    public function listVariables(mixed $dados): array
    {
        $dados = is_object($dados) || is_array($dados) ? (array)$dados : [];
        $variables = [];
        foreach ($this->lists as $code => $codes) {
            $values = [];
            foreach ($codes as $repeated) {
                $value = $dados[$repeated] ?? null;
                if ($value !== null && $value !== '') {
                    $values[] = $value;
                }
            }
            $variables[$code . self::LIST_SUFFIX] = $values;
        }
        return $variables;
    }
}
