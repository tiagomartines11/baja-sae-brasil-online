<?php
declare(strict_types=1);

namespace Baja\Tests\Prova\Db\Support;

/**
 * Opens and submits juiz pages in a database test, as a logged-in user,
 * without a web server: each request runs the real page file in a PHP
 * process of its own (run-juiz-page.php explains how, and what stands in
 * for nginx and the forum). A process per request, because the pages call
 * exit, and because that is how php-fpm runs them: nothing carries over
 * from one request to the next but the database.
 *
 * Any PHP warning or error the page raises fails the test, with its text:
 * on the site it would be in the log, and the page may be broken.
 */
trait JuizPages
{
    /**
     * @param array<string, string> $query the address's ?a=b part
     * @return array{status: int, location: string, body: string}
     */
    private function openPage(string $event, string $page, string $username, array $query): array
    {
        return $this->runPage(['page' => $page, 'username' => $username, 'event' => $event, 'get' => $query, 'post' => []]);
    }

    /**
     * Sends a form the way a browser does: to $page?$query, with $fields.
     *
     * @param array<string, string> $query
     * @param array<string, string> $fields
     * @return array{status: int, location: string, body: string}
     */
    private function submitForm(string $event, string $page, string $username, array $query, array $fields): array
    {
        return $this->runPage(['page' => $page, 'username' => $username, 'event' => $event, 'get' => $query, 'post' => $fields]);
    }

    /** The form token a page printed (Baja\Auth\Csrf), to send back with the form. */
    private function formTokenIn(string $html): string
    {
        $this->assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $found), 'the page has no form token');
        return $found[1];
    }

    /** @return array{status: int, location: string, body: string} */
    private function runPage(array $request): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', __DIR__ . '/run-juiz-page.php', (string)json_encode($request)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'could not start PHP for ' . $request['page']);
        $out = (string)stream_get_contents($pipes[1]);
        $errors = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertSame('', $errors, $request['page'] . ' raised PHP errors or warnings');
        $answer = json_decode($out, true);
        $this->assertIsArray($answer, $request['page'] . ' did not finish normally; it printed: ' . substr($out, 0, 500));
        return $answer;
    }
}
