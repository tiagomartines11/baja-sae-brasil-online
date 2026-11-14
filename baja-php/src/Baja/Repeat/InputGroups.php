<?php
declare(strict_types=1);

namespace Baja\Repeat;

/**
 * Repeated input groups in a prova's "inputs" (BAJAAPP-103).
 *
 * A dynamic prova allows up to six passes. Instead of writing every field
 * six times, the config can say it once:
 *
 *     "inputs": [
 *       {"code": "PRESENTE", "name": "Presente", "type": "enum", "options": ["SIM", "NÃO"]},
 *       {"repetir": 6, "nome": "Tentativa", "inputs": [
 *         {"code": "T", "name": "Tempo [s]", "type": "number", "precision": 3, "xor": "A"},
 *         {"code": "D", "name": "Distância [m]", "type": "number", "precision": 2, "xor": "A"}
 *       ]}
 *     ]
 *
 * expand() turns that into the fields a hand-written config would have:
 *
 *     PRESENTE                                       pass 1 (no pass given)
 *     T_1 "Tempo [s] (Tentativa 1)"  xor "A_1"       pass 2
 *     D_1 "Distância [m] (Tentativa 1)"  xor "A_1"   pass 2
 *     T_2 ...                         xor "A_2"       pass 3
 *     ...
 *     D_6 "Distância [m] (Tentativa 6)"  xor "A_6"   pass 7
 *
 *   code   the field's code, "_", the repetition number
 *   name   the field's name, then the group's "nome" and the number in brackets
 *   pass   each repetition is a pass of its own, numbered after the
 *          highest pass of the fields before the group (a field without
 *          "pass" is in pass 1); a field after the group keeps its own
 *   xor    scoped to its repetition: "A" becomes "A_3" in the third, so
 *          filling T_3 locks D_3 and nothing else
 *
 * Everything else ("type", "precision", "options", "sensitive"...) is
 * copied as written. Storage, formulas and results do not change: a team's
 * dados have exactly the keys a hand-written config would give them, so
 * data saved under a hand-written T_1 ... T_6 config is read the same way.
 *
 * Every reader of "inputs" goes through here: Prova::getParamsInputs()
 * (judge screen, save, validation, dashboard, lap counting), the formula
 * scope (Input), the config check (ResultsCheck\UnknownVariables) and the
 * help page. A field that only appeared in the raw "inputs" would be a bug.
 *
 * A group that is wrong (see errors()) is left out of the expansion rather
 * than guessed at; prova.php refuses to save such a config in the first place.
 */
final class InputGroups
{
    /** The most repetitions a group may have. */
    public const MOST_REPETITIONS = 20;

    /**
     * @param mixed $inputs a prova's "inputs", as Prova::getParams() decodes them
     */
    public static function expand(mixed $inputs): ExpandedInputs
    {
        $expanded = [];
        $repetitions = [];
        $lists = [];
        $errors = [];
        // code => true when that field came out of a group
        $codes = [];
        $collisions = [];
        $highestPass = 0;
        $group = 0;

        foreach (is_array($inputs) || is_object($inputs) ? array_values((array)$inputs) : [] as $item) {
            if (!self::isGroup($item)) {
                $expanded[] = $item;
                if (is_object($item)) {
                    $highestPass = max($highestPass, self::passOf($item));
                    $code = self::codeOf($item);
                    if ($code !== null) {
                        self::checkListSuffix($code, $errors);
                        self::remember($code, false, $codes, $collisions);
                    }
                }
                continue;
            }

            $group++;
            $label = self::label($item, $group);
            $times = $item->repetir ?? null;
            if (!is_int($times) || $times < 1 || $times > self::MOST_REPETITIONS) {
                $errors[] = $label . ': "repetir" precisa ser um número inteiro de 1 a ' . self::MOST_REPETITIONS
                    . ' (recebeu ' . self::shown($times) . ').';
                continue;
            }
            $fields = self::groupFields($item, $label, $errors);
            if ($fields === []) {
                if (!is_array($item->inputs ?? null) || $item->inputs === []) {
                    $errors[] = $label . ': o grupo não tem campos ("inputs" vazio ou ausente).';
                }
                continue;
            }

            $name = is_string($item->nome ?? null) ? trim($item->nome) : '';
            $firstPass = $highestPass + 1;
            for ($number = 1; $number <= $times; $number++) {
                $repeatedCodes = array_map(fn(object $field) => $field->code . '_' . $number, $fields);
                $repetition = new Repetition($group, $name, $number, $times, $firstPass + $number - 1, $repeatedCodes);
                foreach ($fields as $field) {
                    $copy = self::repeated($field, $repetition);
                    $expanded[] = $copy;
                    self::remember($copy->code, true, $codes, $collisions);
                }
                $repetitions[] = $repetition;
            }
            foreach ($fields as $field) {
                $lists[$field->code] = array_map(fn(int $n) => $field->code . '_' . $n, range(1, $times));
            }
            $highestPass = $firstPass + $times - 1;
        }

        foreach (array_keys($collisions) as $code) {
            $errors[] = 'O código ' . $code . ' aparece mais de uma vez depois de expandir os grupos repetidos. '
                . 'Cada código precisa ser único (um campo T num grupo vira T_1, T_2...).';
        }

        return new ExpandedInputs($expanded, $repetitions, $lists, $errors);
    }

    /**
     * What prova.php refuses to save: the errors of the repeated groups of
     * a prova's parameters, as ConfigJson decoded them (null for blank).
     *
     * @return list<string>
     */
    public static function errorsOfParams(mixed $params): array
    {
        return is_object($params) ? self::expand($params->inputs ?? [])->errors : [];
    }

    /**
     * Whether an entry of "inputs" is a repeated group rather than a field:
     * it has "repetir" or a list of "inputs" of its own.
     */
    public static function isGroup(mixed $item): bool
    {
        return is_object($item) && (property_exists($item, 'repetir') || property_exists($item, 'inputs'));
    }

    /** Whether "inputs" has at least one repeated group. */
    public static function hasGroups(mixed $inputs): bool
    {
        foreach (is_array($inputs) || is_object($inputs) ? (array)$inputs : [] as $item) {
            if (self::isGroup($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The fields of a group that can be repeated. A field that cannot be
     * (no code, a group inside the group, its own "pass") is reported and
     * left out.
     *
     * @param list<string> $errors
     * @return list<object>
     */
    private static function groupFields(object $group, string $label, array &$errors): array
    {
        $fields = [];
        foreach (is_array($group->inputs ?? null) ? $group->inputs : [] as $position => $field) {
            $where = $label . ', campo ' . ($position + 1);
            if (self::isGroup($field)) {
                $errors[] = $where . ': um grupo repetido não pode ter outro grupo dentro.';
                continue;
            }
            if (!is_object($field)) {
                $errors[] = $where . ': precisa ser um objeto {"code": ..., "name": ...}.';
                continue;
            }
            $code = self::codeOf($field);
            if ($code === null) {
                $errors[] = $where . ': falta o "code".';
                continue;
            }
            if (property_exists($field, 'pass')) {
                $errors[] = $where . ' (' . $code . '): não use "pass" dentro de um grupo; cada repetição já é um pass, numerado pelo sistema.';
            }
            self::checkListSuffix($code, $errors);
            $fields[] = $field;
        }
        return $fields;
    }

    /** One field of a group, as it is in one repetition. */
    private static function repeated(object $field, Repetition $repetition): object
    {
        $copy = clone $field;
        $copy->code = $field->code . '_' . $repetition->number;
        $name = is_scalar($field->name ?? null) ? (string)$field->name : (string)$field->code;
        $copy->name = $name . ' (' . $repetition->title() . ')';
        $copy->pass = $repetition->pass;
        if (isset($field->xor) && is_scalar($field->xor) && (string)$field->xor !== '') {
            $copy->xor = $field->xor . '_' . $repetition->number;
        }
        return $copy;
    }

    /** The pass a field is in, as Field reads it: missing is 1. */
    private static function passOf(object $field): int
    {
        $pass = $field->pass ?? 1;
        return is_numeric($pass) ? (int)$pass : 1;
    }

    private static function codeOf(object $field): ?string
    {
        $code = $field->code ?? null;
        return is_string($code) && $code !== '' ? $code : (is_int($code) ? (string)$code : null);
    }

    /** "Grupo Tentativa", or "Grupo 2" when it has no "nome": how errors name a group. */
    private static function label(object $group, int $number): string
    {
        $name = is_string($group->nome ?? null) ? trim($group->nome) : '';
        return 'Grupo ' . ($name !== '' ? '"' . $name . '"' : $number);
    }

    /**
     * @param list<string> $errors
     */
    private static function checkListSuffix(string $code, array &$errors): void
    {
        if (str_ends_with($code, ExpandedInputs::LIST_SUFFIX)) {
            $errors[] = 'O código ' . $code . ' termina em "' . ExpandedInputs::LIST_SUFFIX . '", que é reservado para as listas dos grupos repetidos ($T_lista).';
        }
    }

    /**
     * Notes a code and whether it came out of a group. A code seen twice is
     * a collision when at least one of the two came out of a group: two
     * hand-written fields with the same code are an old config's business,
     * and refusing to save it now would be new.
     *
     * @param array<string, bool> $codes
     * @param array<string, true> $collisions
     */
    private static function remember(string $code, bool $fromGroup, array &$codes, array &$collisions): void
    {
        if (array_key_exists($code, $codes) && ($fromGroup || $codes[$code])) {
            $collisions[$code] = true;
        }
        $codes[$code] = ($codes[$code] ?? false) || $fromGroup;
    }

    private static function shown(mixed $value): string
    {
        return $value === null ? 'nada' : (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
