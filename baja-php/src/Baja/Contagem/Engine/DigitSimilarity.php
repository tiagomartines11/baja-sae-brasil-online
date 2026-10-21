<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

/**
 * How much does one typed car number look like another? Used to recognise
 * the usual typing mistakes when deciding which car a stray entry was
 * probably meant for.
 */
final class DigitSimilarity
{
    /** Two neighbouring digits swapped: 41 typed as 14. */
    public const TROCADOS = 0;
    /** One digit wrong: 41 typed as 47. */
    public const UM_DIGITO_DIFERENTE = 1;
    /** One digit missing or one too many: 41 typed as 4, or as 411. */
    public const DIGITO_FALTANDO = 2;
    /** The two numbers have nothing obvious in common. */
    public const NENHUMA = 3;

    /** Smaller means more alike. One of the constants above. */
    public static function rank(int $typed, int $intended): int
    {
        $typedDigits = (string) $typed;
        $intendedDigits = (string) $intended;

        if (strlen($typedDigits) === strlen($intendedDigits)) {
            if (self::isNeighbourSwap($typedDigits, $intendedDigits)) {
                return self::TROCADOS;
            }
            if (self::countDifferentPositions($typedDigits, $intendedDigits) === 1) {
                return self::UM_DIGITO_DIFERENTE;
            }
            return self::NENHUMA;
        }

        if (abs(strlen($typedDigits) - strlen($intendedDigits)) === 1) {
            $shorter = strlen($typedDigits) < strlen($intendedDigits) ? $typedDigits : $intendedDigits;
            $longer = strlen($typedDigits) < strlen($intendedDigits) ? $intendedDigits : $typedDigits;
            if (self::isOneDigitRemoved($longer, $shorter)) {
                return self::DIGITO_FALTANDO;
            }
        }

        return self::NENHUMA;
    }

    /** A few words in Portuguese for the reviewer, or '' when there is no resemblance. */
    public static function describe(int $rank): string
    {
        switch ($rank) {
            case self::TROCADOS:
                return 'dígitos trocados';
            case self::UM_DIGITO_DIFERENTE:
                return 'um dígito diferente';
            case self::DIGITO_FALTANDO:
                return 'um dígito a mais ou a menos';
            default:
                return '';
        }
    }

    private static function isNeighbourSwap(string $a, string $b): bool
    {
        $length = strlen($a);
        for ($i = 0; $i < $length - 1; $i++) {
            if ($a[$i] === $a[$i + 1]) {
                continue;
            }
            $swapped = $a;
            $swapped[$i] = $a[$i + 1];
            $swapped[$i + 1] = $a[$i];
            if ($swapped === $b) {
                return true;
            }
        }
        return false;
    }

    private static function countDifferentPositions(string $a, string $b): int
    {
        $different = 0;
        $length = strlen($a);
        for ($i = 0; $i < $length; $i++) {
            if ($a[$i] !== $b[$i]) {
                $different++;
            }
        }
        return $different;
    }

    private static function isOneDigitRemoved(string $longer, string $shorter): bool
    {
        $length = strlen($longer);
        for ($i = 0; $i < $length; $i++) {
            if (substr($longer, 0, $i) . substr($longer, $i + 1) === $shorter) {
                return true;
            }
        }
        return false;
    }
}
