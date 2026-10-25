<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula;

use Baja\Formula\EvalEngine;
use Baja\Formula\SafeEngine;
use Baja\Formula\Syntax\FormulaSyntaxError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Formulas that try to do something other than work out a value: run a
 * shell command, write a file, read the server's settings. SafeEngine must
 * refuse every one of them while READING the formula, before any part of
 * it runs.
 *
 * HOW TO READ THESE TESTS
 *
 * Each attack tries to create a file (the "canary", a made-up name in the
 * temporary folder). The test checks two things:
 *
 *   1. SafeEngine::read(), which only reads a formula and never runs it,
 *      refuses it with a FormulaSyntaxError: so it is refused before
 *      running;
 *   2. SafeEngine::evaluate(), which the site uses, refuses it the same
 *      way, and afterwards the canary file does not exist.
 *
 * The first test shows why this matters: eval() DOES create the file.
 *
 * BACKGROUND
 *
 * Formulas are typed in the judge area by event admins. For years the site
 * ran them with PHP's eval(), so anyone with an admin account could run
 * any code on the server. SafeEngine only knows a small part of PHP (see
 * GrammarTest) and only calls the functions of Allowlist.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Formula/SecurityTest.php
 */
final class SecurityTest extends TestCase
{
    private string $canary;

    protected function setUp(): void
    {
        $this->canary = sys_get_temp_dir() . '/formula-canary-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->canary);
        @unlink($this->canary . '.php');
    }

    /** Why: under eval(), a formula is code, and code can write files. */
    public function testEvalRunsWhateverTheFormulaSays(): void
    {
        (new EvalEngine())->evaluate([], "file_put_contents('{$this->canary}', 'pwned')");

        $this->assertFileExists($this->canary, 'eval() wrote the file: any admin could do this on the server.');
    }

    /**
     * Every attack, by what it tries. CANARY is replaced by the file's path.
     *
     * @return array<string, array{0: string}>
     */
    public static function attacks(): array
    {
        return [
            // Calling a dangerous function directly.
            'system()' => ["system('touch CANARY')"],
            'exec()' => ["exec('touch CANARY')"],
            'shell_exec()' => ["shell_exec('touch CANARY')"],
            'passthru()' => ["passthru('touch CANARY')"],
            'file_put_contents()' => ["file_put_contents('CANARY', 'x')"],
            'a function off the list, inside an allowed one' => ["round(file_put_contents('CANARY', 'x'))"],
            'a function off the list, in the branch not taken' => ["1 ? 0 : file_put_contents('CANARY', 'x')"],
            'a function off the list, with a backslash' => ["\\file_put_contents('CANARY', 'x')"],
            'call_user_func()' => ["call_user_func('system', 'touch CANARY')"],
            'assert()' => ["assert(file_put_contents('CANARY', 'x'))"],

            // Backticks run a shell command in PHP.
            'backticks' => ['`touch CANARY`'],
            'backticks inside an allowed function' => ['count(`touch CANARY`)'],

            // eval, include and the other keywords.
            'eval' => ["eval('file_put_contents(\"CANARY\", 1);')"],
            'include' => ["include 'CANARY.php'"],
            'include_once' => ["include_once('CANARY.php')"],
            'require' => ["require 'CANARY.php'"],
            'exit' => ['exit()'],
            'die' => ['die'],
            'print' => ["print 'x'"],
            '__halt_compiler' => ['__halt_compiler()'],

            // A function named by text, or by a variable, then called.
            'a text called as a function' => ["'system'('touch CANARY')"],
            'joined text called as a function' => ["('sys' . 'tem')('touch CANARY')"],
            'a variable called as a function' => ['$F(\'touch CANARY\')'],
            'an array called as a function' => ["['SplFileObject', 'x']()"],
            'array_filter with a function to call' => ["array_filter(['touch CANARY'], 'system')"],

            // Objects.
            'new' => ["new SplFileObject('CANARY', 'w')"],
            'new, in brackets' => ["(new SplFileObject('CANARY', 'w'))->getFilename()"],
            'new DateTime, then another method' => ["(new DateTime())->setTimezone(new DateTimeZone('UTC'))->format('H')"],
            'new DateTime, its time zone replaced' => ["(new DateTime('now', new SplFileObject('CANARY', 'w')))->format('H')"],
            'a method on a variable' => ['$O->write(1)'],
            'static access ::' => ["Closure::fromCallable('system')('touch CANARY')"],
            'clone' => ['clone $O'],

            // Variable variables: a variable named by another variable.
            '$$x' => ['$$NAME'],
            '${...}' => ['${"NA" . "ME"}'],
            '${...} inside text' => ['"${system(\'touch CANARY\')}"'],
            '{$...} inside text' => ['"{$F(\'touch CANARY\')}"'],

            // Changing variables.
            'assignment' => ['$X = 5'],
            'assignment inside a comparison' => ['($X = 5) == 5'],
            'compound assignment' => ['$X .= "a"'],
            'null coalescing assignment' => ['$X ??= 1'],
            'increment' => ['$X++'],
            'decrement' => ['--$X'],
            'list()' => ['list($X) = [1]'],
            'unset()' => ['unset($X)'],
            'adding to an array' => ['$X[] = 1'],

            // Reading the server's settings or the request.
            '$_SERVER' => ["\$_SERVER['PHPBB_DB_PASS']"],
            '$_ENV' => ["\$_ENV['PHPBB_DB_PASS']"],
            '$GLOBALS' => ["\$GLOBALS['_remoteKey']"],
            '$_COOKIE' => ['$_COOKIE'],

            // More than one statement.
            'a second statement' => ["1; file_put_contents('CANARY', 'x')"],
            'leaving PHP' => ['1 ?> hello'],
            'a function definition' => ['function () { return 1; }'],
            'an arrow function' => ['fn() => 1'],
        ];
    }

    #[DataProvider('attacks')]
    public function testAnAttackIsRefusedBeforeAnythingRuns(string $formula): void
    {
        $formula = str_replace('CANARY', $this->canary, $formula);
        file_put_contents($this->canary . '.php', '<?php file_put_contents(' . var_export($this->canary, true) . ', "included");');

        try {
            SafeEngine::read($formula);
            $this->fail('SafeEngine::read() should refuse: ' . $formula);
        } catch (FormulaSyntaxError $error) {
            $this->assertStringContainsString('at character', $error->getMessage(), 'It says where.');
        }

        $vars = ['F' => 'system', 'NAME' => 'X', 'X' => 1, 'O' => new \ArrayObject()];
        try {
            (new SafeEngine())->evaluate($vars, $formula);
            $this->fail('SafeEngine::evaluate() should refuse: ' . $formula);
        } catch (Throwable $error) {
            $this->assertInstanceOf(FormulaSyntaxError::class, $error, 'Refused while reading, not failed while running: ' . $error->getMessage());
        }

        $this->assertFileDoesNotExist($this->canary, 'Nothing of the formula ran.');
        $this->assertSame(1, $vars['X'], 'No variable changed.');
    }

    /** The messages say what is wrong, for the person who wrote the formula. */
    public function testTheMessagesSayWhatIsNotAllowed(): void
    {
        $messages = [
            "system('ls')" => 'function system() is not allowed in a formula: only round, max, min, array_diff, date, ceil, floor, count, array_sum, abs, substr, time, str_pad, implode, array_filter are, at character 1',
            '`ls`' => 'backticks (`...`) run a shell command and are not allowed in a formula, at character 1',
            '$$X' => 'variable variables ($$NAME) are not allowed in a formula, at character 1',
            '$X = 1' => 'assignments ("=") are not allowed in a formula: to compare, write "==", at character 4',
            "new Foo()" => '"new" is not allowed in a formula, except in (new \DateTime(...))->setTimestamp(...)->format(...), at character 1',
            'Foo::bar()' => 'static access (::) is not allowed in a formula, at character 4',
            "include 'x.php'" => '"include" is not allowed in a formula, at character 1',
            "\$_SERVER['X']" => '$_SERVER is not allowed in a formula, at character 1',
            "array_filter(\$L, 'f')" => 'array_filter() takes only 1 argument in a formula, at character 1',
            "round(\$X, 2) . ' pts'; system('ls')" => 'only one formula is allowed, nothing after ";", at character 24',
        ];
        foreach ($messages as $formula => $message) {
            try {
                SafeEngine::read($formula);
                $this->fail('Should be refused: ' . $formula);
            } catch (FormulaSyntaxError $error) {
                $this->assertSame($message, $error->getMessage(), $formula);
            }
        }
    }
}
