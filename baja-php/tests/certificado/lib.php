<?php
/**
 * A very small test harness.
 *
 * Deliberately dependency-free rather than PHPUnit. The container's entrypoint
 * runs a plain `composer install`, which installs require-dev too, so adding
 * PHPUnit would ship a test framework to production to get assertions in
 * development. This file is the part of PHPUnit these tests actually use.
 */

final class T
{
    /** @var array<int, array{name: string, ok: bool, detail: string}> */
    public static array $results = [];
    public static ?string $group = null;
    public static int $skipped = 0;

    public static function group(string $name): void
    {
        self::$group = $name;
    }

    public static function ok(string $name, bool $condition, string $detail = ''): void
    {
        self::$results[] = [
            'name'   => (self::$group ? self::$group . ' — ' : '') . $name,
            'ok'     => $condition,
            'detail' => $detail,
        ];
    }

    public static function same($expected, $actual, string $name): void
    {
        $ok = $expected === $actual;
        self::ok($name, $ok, $ok ? '' : sprintf(
            "expected %s\n       got      %s",
            self::render($expected),
            self::render($actual)
        ));
    }

    public static function notSame($unexpected, $actual, string $name): void
    {
        self::ok($name, $unexpected !== $actual, sprintf('both were %s', self::render($actual)));
    }

    public static function skip(string $name, string $why): void
    {
        self::$skipped++;
        printf("  SKIP  %s (%s)\n", (self::$group ? self::$group . ' — ' : '') . $name, $why);
    }

    private static function render($v): string
    {
        return is_string($v) ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    public static function report(): int
    {
        $failed = 0;
        foreach (self::$results as $r) {
            if ($r['ok']) {
                printf("  ok    %s\n", $r['name']);
                continue;
            }
            $failed++;
            printf("  FAIL  %s\n", $r['name']);
            if ($r['detail'] !== '') {
                printf("        %s\n", str_replace("\n", "\n        ", $r['detail']));
            }
        }
        printf(
            "\n%d passed, %d failed, %d skipped\n",
            count(self::$results) - $failed,
            $failed,
            self::$skipped
        );

        return $failed === 0 ? 0 : 1;
    }
}

/**
 * Build a syntactically valid CPF from any 9 leading digits.
 *
 * Fixtures must never contain a real CPF — this repository is public. Deriving
 * the check digits means the fixtures still exercise the real validation path
 * without any of them belonging to a person.
 */
function synthetic_cpf(string $nineDigits): string
{
    if (strlen($nineDigits) !== 9 || !ctype_digit($nineDigits)) {
        throw new InvalidArgumentException('need exactly 9 digits');
    }

    $digits = array_map('intval', str_split($nineDigits));

    for ($round = 0; $round < 2; $round++) {
        $weight = count($digits) + 1;
        $sum    = 0;
        foreach ($digits as $d) {
            $sum += $d * $weight--;
        }
        $rest      = $sum % 11;
        $digits[]  = $rest < 2 ? 0 : 11 - $rest;
    }

    return implode('', $digits);
}

/** True when the tests are allowed to write to the configured database. */
function test_db_available(): bool
{
    return getenv('BAJA_TEST_DB') === '1';
}
