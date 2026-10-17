<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Review\Pagina;
use PHPUnit\Framework\TestCase;

/**
 * Two safety nets that have nothing to do with counting laps and everything
 * to do with finding out when something went wrong.
 *
 * Neither needs the database for its own logic, but the second test starts
 * a small separate PHP program that connects to it, so that one is skipped
 * unless BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewExitGuardTest extends TestCase
{
    /**
     * SITUATION: in development PHP is set to print warnings into the
     * page. One of the JSON addresses of the review screen hits a warning
     * (say, a missing array key) and then answers normally.
     *
     * WHAT WENT WRONG: the browser received
     *     Warning: Undefined array key "x" in ... {"ok":true, ...}
     * which is not JSON any more. The screen could not read the answer to a
     * decision that HAD been stored, and reported an expired session.
     *
     * EXPECTED: a JSON endpoint holds back everything printed before its
     * answer (Pagina::beginJsonEndpoint) and throws it away when it answers
     * (discardStrayOutput; the text goes to the error log instead).
     */
    public function testWhatAJsonEndpointPrintsByAccidentIsHeldBackAndDiscarded(): void
    {
        $levelBefore = ob_get_level();

        Pagina::beginJsonEndpoint();
        // beginJsonEndpoint() also installs a handler for errors nobody
        // caught; inside PHPUnit that must not stay installed.
        restore_exception_handler();
        echo 'Warning: Undefined array key "x" in /var/www/html/juiz/contagem_decidir.php on line 12';
        $discarded = Pagina::discardStrayOutput();

        $this->assertStringContainsString('Undefined array key', $discarded);
        $this->assertSame($levelBefore, ob_get_level(), 'the endpoint\'s buffer is closed, PHPUnit\'s own is left alone');
        $this->assertSame('', Pagina::discardStrayOutput(), 'nothing is left to discard');
    }

    /**
     * SITUATION: a test calls application code that asks "who is logged
     * in?" without having logged anybody in. That code is
     * Baja\Session::initSession(); with nobody logged in it sends the
     * browser to the login page and ends PHP with exit().
     *
     * WHAT WENT WRONG: inside PHPUnit, exit() ends PHPUnit itself, with
     * exit status 0, which means "success". The remaining tests never ran,
     * nothing was printed about them, and the run looked green.
     *
     * EXPECTED: with the guard armed (ReviewDbTestCase does that for every
     * test), a process that is ended in the middle of a test finishes with
     * a failure status and says, in capital letters, which test was cut
     * short. This test proves it by starting a small separate PHP program
     * that arms the guard and then really calls the code that exits.
     */
    public function testATestThatReachesTheLoginRedirectFailsLoudlyInsteadOfEndingTheRun(): void
    {
        if (getenv('BAJA_TEST_DB') !== '1') {
            $this->markTestSkipped('Precisa de banco de dados: rode com BAJA_TEST_DB=1 dentro do container.');
        }
        $projectRoot = dirname(__DIR__, 3);
        $program = '<?php
            require ' . var_export($projectRoot . '/vendor/autoload.php', true) . ';
            require ' . var_export($projectRoot . '/src/config.php', true) . ';
            $_SERVER["REDIRECT_EVENT"] = "00NE";
            \Baja\Tests\Contagem\Db\ReviewExitGuard::testStarted("UmTeste::semLogin");
            // Nobody is logged in: this reaches Session::initSession(), which calls exit().
            \Baja\Session::hasPermission("admin");
            echo "NUNCA CHEGA AQUI";
        ';

        [$exitStatus, $output, $errors] = $this->runPhp($program);

        $this->assertSame(ReviewExitGuard::EXIT_STATUS, $exitStatus, 'the run must end with a failure status, not 0');
        $this->assertStringContainsString('OS TESTES FORAM INTERROMPIDOS', $errors);
        $this->assertStringContainsString('UmTeste::semLogin', $errors);
        $this->assertStringNotContainsString('NUNCA CHEGA AQUI', $output);
    }

    /**
     * The other half: a run that ends normally must not be reported.
     */
    public function testARunThatEndsBetweenTestsIsNotReported(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $program = '<?php
            require ' . var_export($projectRoot . '/vendor/autoload.php', true) . ';
            \Baja\Tests\Contagem\Db\ReviewExitGuard::testStarted("UmTeste::normal");
            \Baja\Tests\Contagem\Db\ReviewExitGuard::testFinished();
        ';

        [$exitStatus, , $errors] = $this->runPhp($program);

        $this->assertSame(0, $exitStatus);
        $this->assertSame('', $errors);
    }

    /**
     * Runs a PHP program in a separate process.
     *
     * @return array{0: int, 1: string, 2: string} exit status, what it printed, what it wrote to the error output
     */
    private function runPhp(string $program): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'could not start a PHP process');
        fwrite($pipes[0], $program);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $errors];
    }
}
