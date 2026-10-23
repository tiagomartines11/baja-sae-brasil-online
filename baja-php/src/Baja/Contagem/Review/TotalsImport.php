<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Model\ContagemFonte;
use Baja\Model\ContagemTotal;
use Baja\Model\ContagemTotalQuery;
use InvalidArgumentException;
use Propel\Runtime\Propel;

/**
 * Imports paper totals ("car, laps") for a source of tipo "totais".
 *
 * These are the lap counts somebody kept on paper during the race. They
 * never count laps themselves; the review screen shows them next to the
 * official count so a difference stands out.
 */
final class TotalsImport
{
    private const MAX_LAPS = 9999;
    private const MAX_ERRORS_SHOWN = 10;
    private const MAX_UPLOAD_BYTES = 200000;

    /**
     * Reads pasted text or the contents of a CSV file.
     *
     * Accepts one "car, laps" pair per line, separated by a comma, a
     * semicolon, a tab or spaces. Blank lines are skipped, and so is a first
     * line that is clearly a header ("carro,voltas"): one with no number in
     * it at all. A first line such as "x12, 48" is a typo in a data line,
     * not a header, and is reported instead of being silently dropped (the
     * car would otherwise vanish from the comparison).
     *
     * @param int[] $validCars car numbers of the event
     * @return array<int, int> car => laps
     * @throws InvalidArgumentException listing every bad line, in Portuguese; nothing is imported then
     */
    public static function parse(string $text, array $validCars): array
    {
        // A file saved by a spreadsheet often starts with a byte-order mark.
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        $totals = [];
        $errors = [];
        $sawData = false;
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $fields = preg_split('/\s*[,;\t]\s*|\s+/', $line) ?: [];
            $isFirstLine = !$sawData;
            $sawData = true;
            if ($isFirstLine && self::looksLikeHeader($fields)) {
                continue;
            }

            $error = self::lineError($fields, $validCars, $totals);
            if ($error !== null) {
                $errors[] = 'Linha ' . ($index + 1) . ': ' . $error;
                continue;
            }
            $totals[(int) $fields[0]] = (int) $fields[1];
        }

        if ($errors !== []) {
            $shown = array_slice($errors, 0, self::MAX_ERRORS_SHOWN);
            $more = count($errors) - count($shown);
            throw new InvalidArgumentException(
                implode(' ', $shown) . ($more > 0 ? ' (e mais ' . $more . ' linhas com erro)' : '')
            );
        }
        if ($totals === []) {
            throw new InvalidArgumentException('Nenhuma linha "carro, voltas" encontrada.');
        }
        return $totals;
    }

    /**
     * The contents of an uploaded CSV file.
     *
     * @param array<string, mixed> $upload the $_FILES entry
     * @throws InvalidArgumentException when the file did not arrive whole or is too big to be a list of totals
     */
    public static function readUpload(array $upload): string
    {
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'] ?? null)
            || !is_uploaded_file($upload['tmp_name'])) {
            throw new InvalidArgumentException('Não foi possível receber o arquivo.');
        }
        if ((int) ($upload['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Arquivo grande demais para uma lista de totais.');
        }
        $contents = file_get_contents($upload['tmp_name']);
        if ($contents === false) {
            throw new InvalidArgumentException('Não foi possível ler o arquivo.');
        }
        return $contents;
    }

    /**
     * Replaces everything previously imported for this source: an import is
     * the whole sheet, so a car missing from the new sheet must not keep its
     * old number.
     *
     * @param array<int, int> $totals car => laps
     */
    public static function replace(ContagemFonte $fonte, array $totals): void
    {
        $connection = Propel::getWriteConnection('resultados');
        $connection->beginTransaction();
        try {
            ContagemTotalQuery::create()->filterByFonteId($fonte->getFonteId())->delete($connection);
            foreach ($totals as $car => $laps) {
                $row = new ContagemTotal();
                $row->setFonteId($fonte->getFonteId());
                $row->setEquipeNumero($car);
                $row->setVoltas($laps);
                $row->save($connection);
            }
            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();
            throw $failure;
        }
    }

    /**
     * @param string[] $fields
     */
    private static function looksLikeHeader(array $fields): bool
    {
        foreach ($fields as $field) {
            if (is_numeric($field)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string[] $fields
     * @param int[] $validCars
     * @param array<int, int> $totalsSoFar
     */
    private static function lineError(array $fields, array $validCars, array $totalsSoFar): ?string
    {
        if (count($fields) !== 2 || !ctype_digit($fields[0]) || !ctype_digit($fields[1])) {
            return 'esperado "carro, voltas".';
        }
        if (strlen($fields[0]) > 6 || strlen($fields[1]) > 6) {
            return 'número grande demais.';
        }
        $car = (int) $fields[0];
        $laps = (int) $fields[1];
        if (!in_array($car, $validCars, true)) {
            return 'o carro ' . $car . ' não existe neste evento.';
        }
        if ($laps > self::MAX_LAPS) {
            return 'número de voltas fora do esperado.';
        }
        if (isset($totalsSoFar[$car])) {
            return 'o carro ' . $car . ' aparece mais de uma vez.';
        }
        return null;
    }
}
