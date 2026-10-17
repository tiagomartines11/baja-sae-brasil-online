<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

/**
 * Makes a test run fail LOUDLY when PHP is ended in the middle of a test.
 *
 * WHY THIS EXISTS
 *
 * Some application code ends the whole PHP process on purpose, with exit().
 * The one that matters here is Baja\Session::initSession(): when nobody is
 * logged in, it sends the browser to the login page and calls exit(). On
 * the website that is right. Inside PHPUnit it is a trap: exit() ends
 * PHPUnit itself, at once, with exit status 0 ("all good"). The tests that
 * had not run yet never run, nothing is printed about them, and whoever
 * (or whatever script) started the run sees success.
 *
 * A test reaches that code more easily than one would think: anything that
 * asks "who is logged in?" (Session::getCurrentUser(), hasPermission(),
 * ContagemAccess::isController()) before the test called loginAs().
 *
 * HOW IT WORKS
 *
 * PHP lets a program register a function to be called when the process is
 * ending, for whatever reason. This class registers one. A test tells it
 * "I started" in setUp() and "I finished" in tearDown(). If the process
 * ends while a test is marked as started, something ended PHP under it:
 * the function prints what happened, names the test, and replaces the exit
 * status with a failure, so the run cannot pass for green.
 */
final class ReviewExitGuard
{
    /** Exit status of a run that was cut short. Any value other than 0 means failure. */
    public const EXIT_STATUS = 70;

    private static ?string $runningTest = null;
    private static bool $registered = false;

    public static function testStarted(string $testName): void
    {
        if (!self::$registered) {
            register_shutdown_function([self::class, 'onShutdown']);
            self::$registered = true;
        }
        self::$runningTest = $testName;
    }

    public static function testFinished(): void
    {
        self::$runningTest = null;
    }

    /** Called by PHP itself when the process is ending. */
    public static function onShutdown(): void
    {
        if (self::$runningTest === null) {
            return;
        }
        fwrite(STDERR, PHP_EOL
            . '!!! OS TESTES FORAM INTERROMPIDOS !!!' . PHP_EOL
            . 'O PHP terminou no meio do teste "' . self::$runningTest . '".' . PHP_EOL
            . 'Alguma coisa chamou exit() ou die(). A causa mais comum: o teste chegou a' . PHP_EOL
            . 'Baja\\Session::initSession() sem ninguém logado (faltou $this->loginAs(...)),' . PHP_EOL
            . 'e esse código redireciona para o login e encerra o processo.' . PHP_EOL
            . 'Os testes que viriam depois NÃO foram executados.' . PHP_EOL);
        exit(self::EXIT_STATUS);
    }
}
