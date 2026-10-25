<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

/**
 * Who may use juiz/remote.php, the endpoint the old Enduro spreadsheet sends
 * its laps to (Plane BAJAAPP-72). Tested through the REAL web server, like
 * the other Http* tests (see HttpAccessTest for why).
 *
 * WHAT THE ENDPOINT DOES
 *
 * The spreadsheet POSTs a small JSON document, for example
 *
 *     {"key": "<the secret>", "cars": [{"number": 11, "laps": 9, ...}], "raceTime": 600}
 *
 * and the endpoint writes each car's laps into the event's Enduro (prova
 * "END") and sets the race clock. {"key": ..., "truncate": "all"} empties
 * the laps instead. Nobody logs in: the only thing that tells the
 * spreadsheet apart from a stranger is "key", which must equal the server's
 * REMOTE_KEY setting (baja-infra/.env).
 *
 * WHAT WENT WRONG
 *
 * The endpoint compared the two with `!=`. That operator lets PHP convert
 * values before comparing them, and in PHP 8 `true != "any text"` is false.
 * So {"key": true} was taken for the right key, by anybody. And on a
 * server where REMOTE_KEY was empty, {"key": ""} matched it.
 *
 * WHAT THESE TESTS EXPECT
 *
 *   - the right key still works, exactly as before;
 *   - anything else is answered 403 (Forbidden) and changes NOTHING, in
 *     particular values that PHP's `==` would have taken for the right key;
 *   - on a server with no REMOTE_KEY, every request is answered 403.
 *
 * WHICH TESTS RUN WHERE
 *
 * The test reads REMOTE_KEY from its own environment, which is the same as
 * the web server's (both run in the baja-app container). The first two
 * tests need it set, the last one needs it empty, so one run of the suite
 * runs one side and skips the other. To run the last test, recreate the
 * app container with the key empty, then put it back:
 *
 *     REMOTE_KEY= docker compose up -d baja-app && docker compose restart nginx
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --filter HttpRemoteKeyTest
 *     docker compose up -d baja-app && docker compose restart nginx
 *
 * (The nginx restart is the one from docs/dev-workflow.md, FAQ: a recreated
 * container gets a new address, which nginx only picks up on restart.)
 *
 * Needs the dev stack running: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class HttpRemoteKeyTest extends ReviewDbTestCase
{
    use HttpBrowser;

    /** The race clock the test starts with; any write by the endpoint moves it. */
    private const CLOCK_BEFORE = 1700000000;

    /** What car A's row holds before every test. */
    private const ROW_BEFORE = ['VOLTAS' => 4, 'LAST' => 100, 'BEST' => 50, 'DESCLAS' => 'SIM'];

    /** A request that, when accepted, writes car A's laps AND moves the race clock. */
    private const WRITE_LAPS_AND_CLOCK = [
        'cars' => [['number' => self::CAR_A, 'laps' => 9, 'bestLapTime' => 61, 'lastPass' => 700]],
        'raceTime' => 600,
    ];

    /** A request that, when accepted, empties the laps of every car. */
    private const TRUNCATE = ['truncate' => 'all'];

    protected function setUp(): void
    {
        parent::setUp();

        // remote.php only ever speaks to the prova called END. Make one in the
        // test event, scored like the test prova, with the lap counting's
        // publishing switched off: that is the mode in which the spreadsheet
        // is allowed to write, so a request that gets past the key check
        // really does change the database.
        $params = json_decode((string) $this->fetchValue(
            'SELECT params FROM prova WHERE evento_id = ? AND prova_id = ?',
            [self::EVENTO_ID, self::PROVA_ID]
        ), true);
        $params['contagem'] = ['modo_publicacao' => 'desligada'];
        $this->executeSql(
            'INSERT INTO prova (evento_id, prova_id, nome, status, params, tempo) VALUES (?, ?, ?, 0, ?, ?)',
            [self::EVENTO_ID, 'END', 'Enduro (teste da chave do remote)', json_encode($params), self::CLOCK_BEFORE]
        );
        $this->executeSql(
            'INSERT INTO input (evento_id, prova_id, equipe_id, dados) VALUES (?, ?, ?, ?)',
            [self::EVENTO_ID, 'END', self::CAR_A, json_encode(self::ROW_BEFORE)]
        );
    }

    /**
     * SITUATION: the spreadsheet sends car A's laps and the race time, with
     * the right key.
     *
     * EXPECTED: as before the fix. The answer is "OK", car A has the new
     * laps, and the race clock moved to "600 seconds ago".
     */
    public function testTheRightKeyStillWorks(): void
    {
        $rightKey = $this->configuredKeyOrSkip();

        $answer = $this->send(['key' => $rightKey] + self::WRITE_LAPS_AND_CLOCK);

        $this->assertSame(200, $answer['status']);
        $this->assertSame('OK', trim($answer['body']));
        $this->assertEquals(9, $this->rowOfCarA()['VOLTAS']);
        $this->assertEquals(61, $this->rowOfCarA()['BEST']);
        $this->assertEqualsWithDelta(time() - 600, $this->clock(), 5);
    }

    /**
     * SITUATION: somebody who does not know the key sends both requests the
     * spreadsheet can send (write laps and clock, and truncate), with every
     * kind of "key" we could think of. Several of them are what `!=` used
     * to let through, or what a careless fix would let through:
     *
     *   true                 PHP 8: true == any non-empty string
     *   0, 1, "0", false     other values PHP converts before comparing
     *   null, "", none       "" matched an empty REMOTE_KEY; all three must fail here too
     *   ["<right key>"]      the right key, wrapped in a list
     *   the right key minus its last character, or plus one more
     *                        a comparison that stopped early would accept these
     *   the right key, but sent as a form instead of JSON
     *                        the endpoint only ever reads JSON
     *
     * EXPECTED: every one is answered 403 with an empty body (no warning
     * printed either), and afterwards car A's row and the race clock are
     * exactly what they were.
     */
    public function testAnythingButTheRightKeyIsRefusedAndChangesNothing(): void
    {
        $rightKey = $this->configuredKeyOrSkip();

        // label => the JSON body's "key" part. An empty array means "no key at all".
        $wrongKeys = [
            'key true' => ['key' => true],
            'key false' => ['key' => false],
            'key 0' => ['key' => 0],
            'key 1' => ['key' => 1],
            'key "0"' => ['key' => '0'],
            'key ""' => ['key' => ''],
            'key null' => ['key' => null],
            'no key at all' => [],
            'the right key inside a list' => ['key' => [$rightKey]],
            'a wrong key' => ['key' => 'this-is-not-the-key'],
            'the right key minus its last character' => ['key' => substr($rightKey, 0, -1)],
            'the right key plus one more character' => ['key' => $rightKey . 'x'],
        ];

        foreach ($wrongKeys as $label => $keyPart) {
            foreach (['write' => self::WRITE_LAPS_AND_CLOCK, 'truncate' => self::TRUNCATE] as $what => $request) {
                $answer = $this->send($keyPart + $request);
                $this->assertSame(403, $answer['status'], "$label, $what");
                $this->assertSame('', $answer['body'], "$label, $what");
            }
        }

        // Not JSON at all: the same fields as an HTML form would send them.
        $answer = $this->request(
            'POST', '/00NE/remote.php', [], http_build_query(['key' => $rightKey] + self::TRUNCATE),
            self::JUIZ_SITE, ['Content-Type: application/x-www-form-urlencoded']
        );
        $this->assertSame(403, $answer['status'], 'the right key sent as a form');

        $this->assertNothingChanged();
    }

    /**
     * SITUATION: the server has no REMOTE_KEY (it is empty in .env, or
     * missing from it). Somebody sends requests with no key, an empty key,
     * true, and some text.
     *
     * WHAT WENT WRONG: with REMOTE_KEY empty, an empty key was "equal" to
     * it, and the request was accepted.
     *
     * EXPECTED: all are answered 403 and nothing changes. (The server also
     * writes "REMOTE_KEY is not configured" to its error log, so whoever
     * looks at why the spreadsheet stopped working finds the reason;
     * see it with `docker compose logs baja-app`.)
     *
     * Only runs on a stack without REMOTE_KEY; see the top of this file.
     * "Missing from .env" cannot be tried from here: docker-compose.yml
     * passes REMOTE_KEY to the app either way, so PHP sees an empty value.
     */
    public function testWithoutAConfiguredKeyEverythingIsRefused(): void
    {
        if ((string) getenv('REMOTE_KEY') !== '') {
            $this->markTestSkipped('REMOTE_KEY está definido; este teste precisa do stack sem ele (veja o início do arquivo).');
        }

        foreach (['no key at all' => [], 'key ""' => ['key' => ''], 'key true' => ['key' => true], 'some text' => ['key' => 'anything']] as $label => $keyPart) {
            foreach (['write' => self::WRITE_LAPS_AND_CLOCK, 'truncate' => self::TRUNCATE] as $what => $request) {
                $answer = $this->send($keyPart + $request);
                $this->assertSame(403, $answer['status'], "$label, $what");
            }
        }

        $this->assertNothingChanged();
    }

    // ------------------------------------------------------------------

    /** The server's key, or skip the test when the stack runs without one. */
    private function configuredKeyOrSkip(): string
    {
        $key = (string) getenv('REMOTE_KEY');
        if ($key === '') {
            $this->markTestSkipped('REMOTE_KEY não está definido neste ambiente.');
        }
        return $key;
    }

    /**
     * POSTs one JSON document to remote.php, as the spreadsheet does.
     *
     * @param array<string, mixed> $body
     * @return array{status: int, body: string, location: string, contentType: string, cookies: array<string, string>}
     */
    private function send(array $body): array
    {
        return $this->request(
            'POST', '/00NE/remote.php', [], (string) json_encode($body),
            self::JUIZ_SITE, ['Content-Type: application/json']
        );
    }

    private function assertNothingChanged(): void
    {
        $this->assertEquals(self::ROW_BEFORE, $this->rowOfCarA(), 'car A\'s row was not touched');
        $this->assertSame(self::CLOCK_BEFORE, $this->clock(), 'the race clock was not moved');
    }

    /** @return array<string, mixed> */
    private function rowOfCarA(): array
    {
        return (array) json_decode((string) $this->fetchValue(
            'SELECT dados FROM input WHERE evento_id = ? AND prova_id = ? AND equipe_id = ?',
            [self::EVENTO_ID, 'END', self::CAR_A]
        ), true);
    }

    private function clock(): int
    {
        return (int) $this->fetchValue(
            'SELECT tempo FROM prova WHERE evento_id = ? AND prova_id = ?',
            [self::EVENTO_ID, 'END']
        );
    }
}
