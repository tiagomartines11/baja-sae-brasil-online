<?php

namespace Baja\Certificado;

/**
 * The identity document a participant is recorded under.
 *
 * Two columns, because two things were being kept in one: `cpf`, always
 * eleven digits and zero-padded, and `documento_estrangeiro`, anything else,
 * stored exactly as given.
 *
 * Nothing in this class decides which of the two a value belongs in. That
 * question is about a person — a passport number can pass the CPF check digits
 * by coincidence, and a CPF mistyped at registration fails them — and it is
 * answered by review, not by code. What this class does is normalize a value
 * once its column is already known.
 */
final class Documento
{
    /** A CPF is eleven digits. Nothing else is a CPF. */
    public const CPF_LENGTH = 11;

    /**
     * Everything that is not a digit, removed.
     *
     * People type 000.000.000-00, 00000000000, and 0000000000 — the last one
     * usually because a spreadsheet read the CPF as a number and ate the
     * leading zero on the way out.
     */
    public static function digits(string $raw): string
    {
        return preg_replace('/\D/', '', $raw);
    }

    /**
     * A submitted value as it would be stored in `cpf`, or null if it cannot
     * be one.
     *
     * Longer than eleven digits is not a CPF, and truncating it to make it fit
     * would invent a different person's number. Callers treat null as "do not
     * search the cpf column for this", not as an error.
     */
    public static function normalizeCpf(string $raw): ?string
    {
        $digits = self::digits($raw);

        if ($digits === '' || strlen($digits) > self::CPF_LENGTH) {
            return null;
        }

        return str_pad($digits, self::CPF_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * A foreign document reduced to a comparable form.
     *
     * Case-folded, non-alphanumerics dropped, and leading zeros stripped from
     * both sides of any later comparison, because the digits-only entry habit
     * means the same passport may be on file with or without them. Two
     * genuinely different passport numbers differing only by a leading zero
     * would collide under this; the name check is what stops that mattering.
     */
    public static function normalizeEstrangeiro(string $raw): string
    {
        $value = preg_replace('/[^A-Za-z0-9]/', '', $raw);

        return ltrim(strtoupper($value), '0');
    }

    /**
     * Whether the eleven digits carry a valid CPF check digit pair.
     *
     * Used for reporting and for tests, never to decide whether to run a
     * search. Rejecting invalid CPFs at the form would lock out every foreign
     * participant and everyone whose CPF was mistyped at registration — the
     * two groups least able to get it corrected.
     */
    public static function isValidCpf(string $raw): bool
    {
        $digits = self::digits($raw);

        if (strlen($digits) !== self::CPF_LENGTH) {
            return false;
        }

        // 00000000000, 11111111111 and the rest satisfy the arithmetic below
        // but are not issued. Excluded explicitly, as every CPF validator does.
        if (preg_match('/\A(\d)\1{10}\z/', $digits) === 1) {
            return false;
        }

        $numbers = array_map('intval', str_split($digits));

        foreach ([9, 10] as $position) {
            $weight = $position + 1;
            $sum    = 0;
            for ($i = 0; $i < $position; $i++) {
                $sum += $numbers[$i] * $weight--;
            }
            $rest     = $sum % 11;
            $expected = $rest < 2 ? 0 : 11 - $rest;

            if ($numbers[$position] !== $expected) {
                return false;
            }
        }

        return true;
    }
}
