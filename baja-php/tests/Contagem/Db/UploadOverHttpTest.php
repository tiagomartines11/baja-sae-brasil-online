<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\Clock;

/**
 * An upload through the REAL web server: nginx, then PHP-FPM, then the code.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Almost every other test calls the upload logic directly, which is fast
 * and exact but skips everything in front of it. Two promises live only in
 * the web server's configuration, where no such test can see them:
 *
 *   1. The upload address is answered WITHOUT a forum login. Every other
 *      page of the judges' site redirects to the login page; a phone whose
 *      login expired in the third hour of a race must still upload.
 *   2. Uploads are handled by their own small group of PHP workers (the
 *      "contagem" pool), so that a crowd on the results site can never use
 *      up the workers the counting needs. The endpoint says which pool
 *      answered in the header "X-Baja-Pool".
 *
 * The address exists in two forms: plain (/contagem_sync.php, the current
 * event) and with the event in front (/00NE/contagem_sync.php), which is
 * the form the count page uses. Both must behave the same.
 *
 * Needs the dev stack running: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class UploadOverHttpTest extends DbTestCase
{
    /** The web server as the test container reaches it. */
    private const WEB_SERVER = 'http://nginx';
    /** The site name; the web server picks the site from this "Host" header. */
    private const JUIZ_SITE = 'juiz.baja.local';

    protected function setUp(): void
    {
        parent::setUp();
        // The web server is another process with a real clock, so this test
        // uses real time too: the race started a minute ago.
        Clock::freezeAt(null);
        $this->startRace(Clock::nowMs() - 60000);
    }

    /**
     * SITUATION: a phone uploads one entry to the address the count page
     * uses (with the event in front). It sends its token and NO cookie at
     * all: as far as the forum is concerned, nobody is logged in.
     *
     * EXPECTED: HTTP 200, the entry acknowledged and in the database, and
     * the answer came from the "contagem" pool of workers.
     */
    public function testAnUploadWithATokenAndNoLoginIsStoredByTheUploadWorkers(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $uuid = self::newUuid();
        $body = [
            'entries' => [$this->entryPayload($uuid, self::CAR_A, Clock::nowMs() - 5000)],
            'voids' => [],
            'now_ms' => Clock::nowMs(),
            'queue_empty' => false,
        ];

        $response = $this->post('/' . self::EVENTO_ID . '/contagem_sync.php', $token, $body);

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertSame([$uuid], json_decode($response['body'], true)['stored_uuids']);
        $this->assertSame('contagem', $response['pool'], 'answered by the upload workers');
        $this->assertSame(1, $this->countRows('contagem_passagem', 'uuid_cliente = ?', [$uuid]));
    }

    /**
     * SITUATION: the same kind of request to the plain address, without an
     * event in front. No token this time.
     *
     * EXPECTED: it reaches the upload code (which answers 401
     * "token_ausente" as JSON) and is NOT redirected to the login page, and
     * again it is the "contagem" pool that answers. A redirect here would
     * mean the web server treats this form of the address as an ordinary
     * page, with ordinary workers and a login check.
     */
    public function testThePlainAddressIsAlsoRoutedToTheUploadWorkers(): void
    {
        $response = $this->post('/contagem_sync.php', null, ['entries' => []]);

        $this->assertSame(401, $response['status'], $response['body']);
        $this->assertSame('token_ausente', json_decode($response['body'], true)['erro']);
        $this->assertSame('contagem', $response['pool']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: string, pool: string}
     */
    private function post(string $path, ?string $token, array $body): array
    {
        $headers = ['Host: ' . self::JUIZ_SITE, 'Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $pool = '';
        $curl = curl_init(self::WEB_SERVER . $path);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            // Called once per header line of the answer.
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$pool): int {
                if (stripos($line, 'X-Baja-Pool:') === 0) {
                    $pool = trim(substr($line, 12));
                }
                return strlen($line);
            },
        ]);
        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            $error = curl_error($curl);
            curl_close($curl);
            $this->markTestSkipped('O servidor web (' . self::WEB_SERVER . ') não respondeu: ' . $error);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return ['status' => $status, 'body' => (string) $responseBody, 'pool' => $pool];
    }
}
