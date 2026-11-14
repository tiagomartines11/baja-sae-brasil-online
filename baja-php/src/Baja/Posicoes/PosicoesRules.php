<?php
declare(strict_types=1);

namespace Baja\Posicoes;

/**
 * What is wrong with a prova's "posicoes", or with a resultado that provas
 * take positions from (BAJAAPP-102). The judge pages refuse to save a config
 * with errors (juiz/prova.php, juiz/resultado.php); the config check
 * (ResultsCheck) reports them, and the warnings, for configs already saved.
 *
 * The messages are in Portuguese: the judge pages show them as they are.
 */
final class PosicoesRules
{
    /**
     * Why the prova's params cannot be saved; empty when they can. $graph is
     * the event as it is, and is checked with these params in place.
     *
     * @return list<string>
     */
    public static function errorsForProva(EventGraph $graph, string $provaId, mixed $params): array
    {
        $posicoes = is_object($params) ? ($params->posicoes ?? null) : null;
        if ($posicoes === null) {
            return [];
        }
        if (!is_object($posicoes)) {
            return ['"posicoes" deve ser um objeto: {"NOME": {...}}.'];
        }

        $errors = [];
        $taken = self::namesOf($params);
        foreach ((array)$posicoes as $name => $json) {
            $name = (string)$name;
            $where = 'posicoes.' . $name;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                $errors[] = "$where: o nome deve ser um nome de variável (letras, números e _, sem começar com número).";
            }
            if (isset($taken[$name])) {
                $errors[] = "$where: já existe um input, var ou min_max com esse nome.";
            }
            if (!is_object($json)) {
                $errors[] = "$where: deve ser um objeto, com \"valor\" ou \"resultado\".";
                continue;
            }
            $spec = PositionSpec::fromJson($name, $json);
            $hasValor = $spec->valor !== null && $spec->valor !== '';
            if ($hasValor === $spec->isExternal()) {
                $errors[] = "$where: use \"valor\" (posição dentro desta prova) ou \"resultado\" (posição em outro resultado), um dos dois.";
                continue;
            }
            if ($spec->ordem !== null && !in_array(strtolower(trim((string)$spec->ordem)), ['asc', 'desc'], true)) {
                $errors[] = "$where: \"ordem\" deve ser \"asc\" ou \"desc\".";
            }
            if ($spec->isExternal()) {
                $error = self::resultadoError($graph, (string)$spec->resultadoId());
                if ($error !== null) {
                    $errors[] = "$where: $error";
                }
            }
        }

        $cycle = $graph->withProva($provaId, $params)->cycleFromProva($provaId);
        if ($cycle !== null) {
            $errors[] = 'Ciclo: ' . implode(' → ', $cycle) . '. Os pontos desta prova dependeriam deles mesmos.';
        }
        return $errors;
    }

    /**
     * Why the resultado cannot be saved with these Inputs and "colunas";
     * empty when it can. Only what concerns "posicoes": a cycle, and a
     * resultado that provas read losing its "pos".
     *
     * @return list<string>
     */
    public static function errorsForResultado(EventGraph $graph, string $resultadoId, mixed $inputs, mixed $colunas): array
    {
        $graph = $graph->withResultado($resultadoId, $inputs, $colunas);
        $errors = [];
        $readers = $graph->provasReading($resultadoId);
        if ($readers !== []) {
            $error = self::resultadoError($graph, $resultadoId);
            if ($error !== null) {
                $errors[] = $error . ' As provas ' . implode(', ', $readers) . ' usam a posição dele em "posicoes".';
            }
        }
        $cycle = $graph->cycleFromResultado($resultadoId);
        if ($cycle !== null) {
            $errors[] = 'Ciclo: ' . implode(' → ', $cycle) . '. Os pontos de uma prova dependeriam deles mesmos.';
        }
        return $errors;
    }

    /**
     * Configs that save, but probably should not be so: a prova that takes
     * a position from a resultado that hides its ranking behind spoilers,
     * while a resultado showing this prova does not. Its points would give
     * the hidden ranking away.
     *
     * @return list<string>
     */
    public static function warningsForProva(EventGraph $graph, string $provaId): array
    {
        $unprotected = [];
        foreach ($graph->resultadosOf($provaId) as $own) {
            if (!$graph->resultadoConfig($own)?->hasSpoilerColumns()) {
                $unprotected[] = $own;
            }
        }
        if ($unprotected === []) {
            return [];
        }
        $warnings = [];
        foreach ($graph->referencedBy($provaId) as $resultadoId) {
            if ($graph->resultadoConfig($resultadoId)?->hasSpoilerColumns()) {
                $warnings[] = "takes a position from $resultadoId, which hides its ranking while spoilers are on, "
                    . 'but its points are shown unhidden on ' . implode(', ', $unprotected) . ': they would give that ranking away';
            }
        }
        return $warnings;
    }

    /** Why a resultado cannot give positions; null when it can. */
    private static function resultadoError(EventGraph $graph, string $resultadoId): ?string
    {
        $config = $graph->resultadoConfig($resultadoId);
        if ($config === null) {
            return "o resultado $resultadoId não existe neste evento.";
        }
        if ($config->type === 'tournament') {
            return "o resultado $resultadoId é um torneio, sem classificação por \"pos\".";
        }
        if ($config->rankingKey() === null) {
            return "o resultado $resultadoId não tem \"pos\", então não classifica ninguém.";
        }
        return null;
    }

    /**
     * The names a prova already gives its teams before this stage: input
     * codes, vars and min_max.
     *
     * @return array<string, true>
     */
    private static function namesOf(object $params): array
    {
        $names = [];
        foreach ((array)($params->inputs ?? []) as $field) {
            if (is_object($field) && isset($field->code) && is_scalar($field->code)) {
                $names[(string)$field->code] = true;
            }
        }
        foreach (['vars', 'min_max'] as $group) {
            $value = $params->$group ?? null;
            if (is_object($value) || is_array($value)) {
                foreach (array_keys((array)$value) as $name) {
                    $names[(string)$name] = true;
                }
            }
        }
        return $names;
    }
}
