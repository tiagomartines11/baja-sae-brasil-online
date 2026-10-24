<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Files;

/**
 * Says, in one line each, where two snapshot documents differ:
 *
 *     tables[0].rows[3] (# 5).Total: "<b>201.23</b>" -> "<b>201.24</b>"
 *     tables[0].rows: 60 items -> 59 items
 *     tables[0].rows[59] (# 98): removed {"#":"98",...}
 *
 * Rows of a results table and inputs of a prova are named after their team
 * (the "#" column, or "equipe"), because "row 3" alone means little to a
 * reviewer.
 */
final class JsonDiff
{
    private const MAX_VALUE_LENGTH = 160;

    /** @return list<string> */
    public static function lines(mixed $old, mixed $new): array
    {
        $lines = [];
        self::walk($old, $new, '', $lines);
        return $lines;
    }

    /** @param list<string> $lines */
    private static function walk(mixed $old, mixed $new, string $path, array &$lines): void
    {
        if (is_array($old) && is_array($new)) {
            if (array_is_list($old) && array_is_list($new)) {
                self::walkList($old, $new, $path, $lines);
            } else {
                self::walkObject($old, $new, $path, $lines);
            }
            return;
        }
        // == would call 1 and "1" equal; the snapshot must not.
        if ($old !== $new) {
            $lines[] = self::label($path) . ': ' . self::show($old) . ' -> ' . self::show($new);
        }
    }

    /**
     * @param list<mixed> $old
     * @param list<mixed> $new
     * @param list<string> $lines
     */
    private static function walkList(array $old, array $new, string $path, array &$lines): void
    {
        if (count($old) !== count($new)) {
            $lines[] = self::label($path) . ': ' . count($old) . ' items -> ' . count($new) . ' items';
        }
        $common = min(count($old), count($new));
        for ($i = 0; $i < $common; $i++) {
            self::walk($old[$i], $new[$i], $path . '[' . $i . ']' . self::teamOf($new[$i]), $lines);
        }
        for ($i = $common; $i < count($old); $i++) {
            $lines[] = self::label($path . '[' . $i . ']' . self::teamOf($old[$i])) . ': removed ' . self::show($old[$i]);
        }
        for ($i = $common; $i < count($new); $i++) {
            $lines[] = self::label($path . '[' . $i . ']' . self::teamOf($new[$i])) . ': added ' . self::show($new[$i]);
        }
    }

    /**
     * @param array<mixed> $old
     * @param array<mixed> $new
     * @param list<string> $lines
     */
    private static function walkObject(array $old, array $new, string $path, array &$lines): void
    {
        foreach ($old as $key => $value) {
            $childPath = $path . self::key((string)$key);
            if (!array_key_exists($key, $new)) {
                $lines[] = self::label($childPath) . ': removed ' . self::show($value);
            } else {
                self::walk($value, $new[$key], $childPath, $lines);
            }
        }
        foreach ($new as $key => $value) {
            if (!array_key_exists($key, $old)) {
                $lines[] = self::label($path . self::key((string)$key)) . ': added ' . self::show($value);
            }
        }
    }

    /** " (# 12)" for a table row, " (equipe 12)" for an input, "" otherwise. */
    private static function teamOf(mixed $item): string
    {
        if (is_array($item) && isset($item['#']) && is_scalar($item['#'])) {
            return ' (# ' . $item['#'] . ')';
        }
        if (is_array($item) && isset($item['equipe']) && is_scalar($item['equipe'])) {
            return ' (equipe ' . $item['equipe'] . ')';
        }
        return '';
    }

    private static function key(string $key): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1 ? '.' . $key : '[' . json_encode($key, JSON_UNESCAPED_UNICODE) . ']';
    }

    private static function label(string $path): string
    {
        return $path === '' ? '(whole file)' : ltrim($path, '.');
    }

    private static function show(mixed $value): string
    {
        $text = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (mb_strlen((string)$text) > self::MAX_VALUE_LENGTH) {
            $text = mb_substr((string)$text, 0, self::MAX_VALUE_LENGTH) . '...';
        }
        return (string)$text;
    }
}
