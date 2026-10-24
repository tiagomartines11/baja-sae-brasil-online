<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

/**
 * Writes the proposed fixes as SQL, for a person to read and then run.
 *
 * One UPDATE per resultado. Each one does what the judge page does when a
 * resultado is saved with new columns (juiz/resultado.php, "Atualizar"):
 * the current columns go into colunas_backup as backup "-1", the previous
 * "-1" to "-4" move one place down to "-2" to "-5" (the old "-5" is
 * dropped), named backups stay, and then the new columns are written.
 *
 *     UPDATE resultado
 *     SET colunas_backup = JSON_SET(<the backups>, '$."-5"', <old -4>, ..., '$."-1"', colunas),
 *         colunas = CAST('<new JSON>' AS JSON)
 *     WHERE resultado_id = '26BR_REL' AND evento_id = '26BR'
 *       AND colunas = CAST('<the JSON the report saw>' AS JSON);
 *
 * Why it is written like this:
 *
 *   - colunas_backup is set before colunas, and MySQL works an UPDATE's
 *     assignments out from left to right, so the backup takes the columns
 *     as they were. Every value inside JSON_SET reads the row as it was.
 *   - The last condition makes the UPDATE do nothing when someone changed
 *     the resultado after the report was made: the fix was checked against
 *     those columns, not against new ones. Both sides are JSON, so the
 *     comparison is of the JSON's content, not its spacing.
 *   - After each UPDATE a SELECT shows ROW_COUNT(): 1 if it was applied,
 *     0 if the columns had changed in between.
 *   - Before it, a comment shows each formula's old and new text.
 */
final class SqlFix
{
    /**
     * The whole file, all fixes in one transaction.
     *
     * @param list<array{evento: string, resultado: string, nome: string, colunas: string, fix: FixProposal}> $fixes
     *        colunas: the resultado's columns exactly as read from the database
     * @param list<string> $about lines for the header comment (what was checked, when)
     */
    public static function script(array $fixes, array $about): string
    {
        $lines = ['-- Fixes proposed by scripts/check-results-configs.php: text joined with "+" in formulas.'];
        foreach ($about as $line) {
            $lines[] = '-- ' . self::oneLine($line);
        }
        $lines[] = '--';
        $lines[] = '-- Each UPDATE keeps the current columns as backup "-1" (as the judge page does on';
        $lines[] = '-- every change) and only applies if the columns are still what the report saw.';
        $lines[] = '-- The SELECT after each one shows updated = 1 when it was applied, 0 when not.';
        $lines[] = '';
        if ($fixes === []) {
            $lines[] = '-- Nothing to fix.';
            return implode("\n", $lines) . "\n";
        }
        $lines[] = 'SET NAMES utf8mb4;';
        $lines[] = "-- The strings below escape quotes with a backslash, which this mode would turn off.";
        $lines[] = "SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_BACKSLASH_ESCAPES', '');";
        $lines[] = 'START TRANSACTION;';
        $lines[] = '';
        foreach ($fixes as $fix) {
            // Each statement ends with a line break: with this, a blank line between them.
            $lines[] = self::statement($fix['evento'], $fix['resultado'], $fix['nome'], $fix['colunas'], $fix['fix']);
        }
        $lines[] = 'COMMIT;';
        return implode("\n", $lines) . "\n";
    }

    /**
     * The comment, UPDATE and SELECT for one resultado.
     *
     * @param string $currentColunas the columns exactly as read from the database
     */
    public static function statement(string $evento, string $resultado, string $nome, string $currentColunas, FixProposal $fix): string
    {
        $lines = ['-- ' . self::oneLine($resultado . '  ' . $nome)];
        foreach ($fix->changes as $change) {
            $lines[] = '--   ' . self::oneLine($change['where']);
            $lines[] = '--     old: ' . self::oneLine($change['old']);
            $lines[] = '--     new: ' . self::oneLine($change['new']);
        }
        $newColunas = json_encode($fix->colunas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        $backups = "CASE WHEN JSON_TYPE(colunas_backup) = 'OBJECT' THEN colunas_backup ELSE JSON_OBJECT() END";
        $lines[] = 'UPDATE resultado';
        $lines[] = 'SET colunas_backup = JSON_SET(' . $backups . ',';
        for ($n = 5; $n >= 2; $n--) {
            $lines[] = "        '\$.\"-$n\"', JSON_EXTRACT(colunas_backup, '\$.\"-" . ($n - 1) . "\"'),";
        }
        $lines[] = "        '\$.\"-1\"', colunas),";
        $lines[] = '    colunas = CAST(' . self::quote($newColunas) . ' AS JSON)';
        $lines[] = 'WHERE resultado_id = ' . self::quote($resultado) . ' AND evento_id = ' . self::quote($evento);
        $lines[] = '  AND colunas = CAST(' . self::quote($currentColunas) . ' AS JSON);';
        $lines[] = 'SELECT ' . self::quote($resultado) . ' AS resultado, ROW_COUNT() AS updated;';
        return implode("\n", $lines) . "\n";
    }

    /** A MySQL string literal (with backslash escapes, see script()). */
    public static function quote(string $text): string
    {
        return "'" . strtr($text, [
            '\\' => '\\\\',
            "'" => "\\'",
            "\0" => '\\0',
            "\n" => '\\n',
            "\r" => '\\r',
            "\x1a" => '\\Z',
        ]) . "'";
    }

    /** A comment line ends at a line break, so a formula with one is shown on one line. */
    private static function oneLine(string $text): string
    {
        return preg_replace('/\s*[\r\n]+\s*/', ' ', $text) ?? $text;
    }
}
