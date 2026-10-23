<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Settings;
use Baja\Model\Prova;

/**
 * Which input fields of a prova its scoring formulas read.
 *
 * The formulas live in `prova.params`: `vars`, `min_max` and `pontos`, each
 * a map of name => PHP expression that Input::solveFormula() evaluates
 * with the row's fields as variables. A field is read when its code
 * appears in one of them as `$CODE`.
 *
 * Why lap counting cares: the classification field is filled with "SIM"
 * for every car that may race ("Marcar todos os presentes como aptos").
 * At 26SE that field was DESCLAS, which the Enduro formula reads as
 * "$DESCLAS=='SIM' ? 0 : ...": SIM there means DISQUALIFIED, and every car
 * of the race scored 0. The classification field must be a field of its
 * own (APTO), one no formula reads.
 */
final class ScoringFields
{
    /** The parts of `prova.params` that hold formulas. */
    private const FORMULA_KEYS = ['vars', 'min_max', 'pontos'];

    /**
     * Codes of the prova's input fields that a scoring formula reads, in
     * the order of the inputs.
     *
     * @return string[]
     */
    public static function readByScoring(Prova $prova): array
    {
        $params = $prova->getParams();
        $named = [];
        foreach (self::FORMULA_KEYS as $key) {
            foreach ((array) ($params->$key ?? []) as $formula) {
                if (!is_string($formula)) {
                    continue;
                }
                preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $formula, $matches);
                foreach ($matches[1] as $variable) {
                    $named[$variable] = true;
                }
            }
        }

        $read = [];
        foreach ($prova->getParamsInputs() as $field) {
            $code = (string) $field->getCode();
            if (isset($named[$code])) {
                $read[] = $code;
            }
        }
        return $read;
    }

    /**
     * The classification field in force when a scoring formula reads it:
     * marking the cars that may race would change their points. Null when
     * there is no such conflict.
     */
    public static function classificationConflict(Prova $prova): ?string
    {
        $field = Settings::fromArray($prova->getParams()->contagem ?? null)->campoClassificacao;
        return $field !== null && in_array($field, self::readByScoring($prova), true) ? $field : null;
    }

    /**
     * Why such a field cannot be the classification field, said the same
     * way on the settings page, on the hub and on the review screen.
     */
    public static function whyNot(string $field): string
    {
        return 'O campo ' . $field . ' é lido pela fórmula de pontuação desta prova, e lá ' . $field . ' = '
            . CarRoster::CLASSIFIED_VALUE . ' pode significar outra coisa (no Enduro, DESCLAS = SIM quer dizer desclassificado, '
            . 'e o carro fica com 0 ponto). Marcar os carros aptos nele muda os pontos de todos. '
            . 'Crie um campo novo só para isso, por exemplo APTO (SIM/NÃO), e escolha esse como campo de classificação.';
    }
}
