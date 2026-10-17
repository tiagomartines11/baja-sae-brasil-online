<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Access\CounterLinks;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Contagem\Sync\SyncHandler;
use Baja\Model\ContagemFonte;
use Baja\Model\Prova;
use Baja\Model\ProvaQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Session;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Propel;

/**
 * Base class of every lap counting test that needs a real database.
 *
 * HOW TO RUN THESE TESTS
 *
 * They run inside the dev container, where MySQL and Redis are reachable:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite db
 *
 * Without BAJA_TEST_DB=1 every test is skipped. That switch exists so that
 * nobody writes test rows into a database by accident. Never set it on a
 * production machine.
 *
 * WHAT EVERY TEST STARTS WITH
 *
 * Before each test a small, made-up world is created, and after each test
 * it is deleted again, so tests cannot affect each other and nothing is
 * left behind:
 *
 *   - an event "00NE" (a "Nordeste stage of the year 2000", which never
 *     existed). The id has the shape of a real one so that the web server
 *     routes /00NE/... to it and tests can go through real HTTP;
 *   - three teams in it, with car numbers 11, 22 and 33;
 *   - a prova "ZZL" of type "laps", scored like a real endurance race:
 *     the car with the most laps gets 400 points and the others get
 *     400 x (their laps / the leader's laps). A car marked DESCLAS = "SIM"
 *     (disqualified) gets 0;
 *   - three human counters ("sources"): Contagem 1, 2 and 3.
 *
 * Deleting the event deletes everything that hangs from it (the prova, its
 * sources, entries, laps, scores), because the tables are linked with
 * ON DELETE CASCADE. Test users are named "zzt_..." and deleted by prefix.
 * The building and removing itself is in TestWorld.
 *
 * WHAT THIS CLASS GIVES A TEST
 *
 * $this->prova, $this->service and $this->fontes, plus the helper methods
 * below, grouped by what they stand for: the race, a phone, a user, and
 * looking at the result.
 */
abstract class DbTestCase extends TestCase
{
    protected const EVENTO_ID = TestWorld::EVENTO_ID;
    protected const PROVA_ID = TestWorld::PROVA_ID;
    protected const USER_PREFIX = TestWorld::USER_PREFIX;

    /** Car numbers of the three made-up teams. */
    protected const CAR_A = TestWorld::CARS[0];
    protected const CAR_B = TestWorld::CARS[1];
    protected const CAR_C = TestWorld::CARS[2];

    /**
     * A fixed moment used as "the race started here", in milliseconds
     * since 1970. Tests describe every other moment as "so many seconds
     * after the start", which is easier to read than a real date.
     */
    protected const RACE_START_MS = 1760000000000;

    protected Prova $prova;
    protected ContagemService $service;

    /** @var ContagemFonte[] the three manual sources: [0] Contagem 1, [1] Contagem 2, [2] Contagem 3 */
    protected array $fontes = [];

    /** @var array<int, int> fonte id => id of the stand-in device used by addEntry() */
    private array $helperDeviceIds = [];

    protected function setUp(): void
    {
        if (getenv('BAJA_TEST_DB') !== '1') {
            $this->markTestSkipped('Precisa de banco de dados: rode com BAJA_TEST_DB=1 dentro do container.');
        }
        TestWorld::boot();

        // The application learns "which event is this request about" from
        // this server variable. Permission codes are prefixed with it.
        $_SERVER['REDIRECT_EVENT'] = self::EVENTO_ID;

        // A previous run that crashed half way may have left its world behind.
        TestWorld::delete();
        $this->fontes = TestWorld::create();
        $this->newRequest();

        $this->helperDeviceIds = [];

        // The made-up race starts at a fixed moment in the past. For the
        // tests "now" is stopped one hour into that race, so every run sees
        // the same times; a test that cares about the time of day moves it
        // with Clock::freezeAt().
        Clock::freezeAt(self::RACE_START_MS + 3600 * 1000);
    }

    /**
     * Starts a new simulated web request.
     *
     * In production every upload, page view or button press is a separate
     * PHP request that starts with an empty memory and loads what it needs
     * from the database. A test plays many of them one after another in a
     * single PHP process, so before each one the memory is emptied by hand
     * and the prova is loaded afresh. The helpers below call this for you;
     * call it yourself before using $this->service directly after another
     * "request" has changed something.
     */
    protected function newRequest(): void
    {
        TestWorld::forgetLoadedRows();
        $this->prova = ProvaQuery::create()->findPk([self::EVENTO_ID, self::PROVA_ID]);
        $this->service = ContagemService::forProva($this->prova);
    }

    protected function tearDown(): void
    {
        // A skipped test never connected, so there is nothing to clean up.
        if (!TestWorld::isBooted()) {
            return;
        }
        Clock::freezeAt(null);
        CounterLinks::setForumLookup(null);
        $this->logout();
        TestWorld::delete();
    }

    // ------------------------------------------------------------------
    // Helpers that describe a race
    // ------------------------------------------------------------------

    /** A moment so many seconds after the start of the race. */
    protected function at(int $secondsAfterStart): int
    {
        return self::RACE_START_MS + $secondsAfterStart * 1000;
    }

    /**
     * Race control presses "Largar": stores a start at $tsMs and reruns the
     * engine, exactly as the race control page does.
     */
    protected function startRace(int $tsMs): void
    {
        $this->newRequest();
        $this->service->withLock(function () use ($tsMs): void {
            $this->service->recordOcorrencia(Ocorrencia::LARGADA, $tsMs, null, self::USER_PREFIX . 'controle');
        });
    }

    /**
     * Puts one raw entry straight into the database: "this source says car
     * $car crossed the line at $tsMs". It does NOT run the engine; call
     * $this->recompute() (or upload through the endpoint) to see the effect.
     *
     * @return int the id of the new entry (passagem_id)
     */
    protected function addEntry(ContagemFonte $fonte, int $car, int $tsMs): int
    {
        $connection = Propel::getWriteConnection('resultados');
        $connection->prepare(
            'INSERT INTO contagem_passagem
                 (fonte_id, dispositivo_id, uuid_cliente, equipe_numero, ts_dispositivo_ms,
                  ts_ms, qualidade_tempo, digitacao_ms, modo_entrada, recebido_em_ms)
             VALUES (?, ?, ?, ?, ?, ?, \'exato\', NULL, ?, ?)'
        )->execute([
            $fonte->getFonteId(),
            $this->helperDeviceId($fonte),
            self::newUuid(),
            $car,
            $tsMs,
            $tsMs,
            'teclado',
            $tsMs,
        ]);
        return (int) $connection->lastInsertId();
    }

    /**
     * Counters 1 and 2 both record $car crossing the line at $tsMs. Two
     * counters agreeing is what makes a pass official. Stored directly; the
     * engine has not run yet when this returns.
     *
     * Remember the counting rule: a car's FIRST official pass is its "pass
     * zero" and completes no lap (the counting line is after the start
     * line). Its second official pass completes lap 1.
     *
     * @return int[] the ids of the two entries: [counter 1's, counter 2's]
     */
    protected function recordCountedPass(int $car, int $tsMs): array
    {
        return [
            $this->addEntry($this->fontes[0], $car, $tsMs),
            $this->addEntry($this->fontes[1], $car, $tsMs),
        ];
    }

    /**
     * Takes one of the prova's locks from a SECOND database connection and
     * returns that connection; the lock is held until the returned object
     * is destroyed (set the variable to null). This is how a test plays
     * "another request is in the middle of its work right now".
     *
     * @param string $purpose '' for the count lock, 'recalculo' for the recalculation lock
     */
    protected function holdLockFromAnotherRequest(string $purpose = ''): \PDO
    {
        $other = new \PDO(
            'mysql:host=' . getenv('PHPBB_DB_HOST') . ';dbname=baja_resultados',
            (string) getenv('PHPBB_DB_USER'),
            (string) getenv('PHPBB_DB_PASS'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $statement = $other->prepare('SELECT GET_LOCK(?, 5)');
        $statement->execute([$this->lockName($purpose)]);
        $this->assertSame(1, (int) $statement->fetchColumn(), 'The test could not take the lock it wanted to hold.');
        return $other;
    }

    /**
     * Starts a second PHP process that plays "another request, running at
     * the same time", and returns at once while it runs. See
     * Support/another-request.php for the steps it understands. The test
     * should finish with waitForAnotherRequest().
     *
     * @param array<int, array<int, mixed>> $steps
     * @return resource the running process
     */
    protected function startAnotherRequest(array $steps)
    {
        $command = [PHP_BINARY, __DIR__ . '/Support/another-request.php', (string) json_encode($steps)];
        $process = proc_open($command, [], $pipes);
        $this->assertNotFalse($process, 'The second process could not be started.');
        return $process;
    }

    /** @param resource $process */
    protected function waitForAnotherRequest($process): void
    {
        $this->assertSame(0, proc_close($process), 'The second process ended with an error.');
    }

    /** The name MySQL knows one of the prova's locks by. */
    protected function lockName(string $purpose = ''): string
    {
        return 'contagem_' . self::EVENTO_ID . '_' . self::PROVA_ID . ($purpose !== '' ? '_' . $purpose : '');
    }

    /**
     * Runs one SQL statement directly. For setting up a situation the
     * application itself would never produce in one step.
     *
     * @param array<int, mixed> $params
     */
    protected function executeSql(string $sql, array $params = []): void
    {
        Propel::getWriteConnection('resultados')->prepare($sql)->execute($params);
    }

    /**
     * The first column of the first row of a query, or null when there is no row.
     *
     * @param array<int, mixed> $params
     * @return mixed
     */
    protected function fetchValue(string $sql, array $params = [])
    {
        $statement = Propel::getWriteConnection('resultados')->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        return is_array($row) ? $row[0] : null;
    }

    /**
     * Marks every source as "synced through $tsMs": each of them has told
     * the server that it uploaded everything it recorded up to that moment.
     * Only then does the engine treat a source's silence about a car as
     * "this counter did not see it" instead of "this phone may be offline".
     */
    protected function syncAll(int $tsMs): void
    {
        foreach ($this->fontes as $fonte) {
            $this->helperDeviceId($fonte);
        }
        Propel::getWriteConnection('resultados')->prepare(
            'UPDATE contagem_dispositivo d
               JOIN contagem_fonte f ON f.fonte_id = d.fonte_id
                SET d.sincronizado_ate_ms = ?, d.visto_em_ms = ?
              WHERE f.evento_id = ? AND f.prova_id = ? AND d.revogado_em_ms IS NULL'
        )->execute([$tsMs, $tsMs, self::EVENTO_ID, self::PROVA_ID]);
    }

    /**
     * Changes some of the prova's lap counting settings, as the settings
     * page does, leaving the others as they are.
     *
     * @param array<string, mixed> $overrides setting name (as stored, e.g. 'modo_publicacao') => new value
     */
    protected function changeSettings(array $overrides): void
    {
        $this->newRequest();
        $current = $this->service->getSettings()->toArray();
        $this->service->saveSettings(Settings::fromArray(array_merge($current, $overrides)));
        $this->newRequest();
    }

    /** Runs the engine and publishes, as happens after every upload or decision. */
    protected function recompute(): void
    {
        $this->newRequest();
        $this->service->withLock(function (): void {
            $this->service->recomputeAndPublish();
        });
    }

    // ------------------------------------------------------------------
    // Helpers that play the part of a phone
    // ------------------------------------------------------------------

    /**
     * What happens when a counter opens the count page: the server creates
     * a "device" for that source and hands the browser a secret token.
     * From then on the phone proves who it is with that token alone.
     */
    /**
     * A pit-log phone's token, for a user who holds the pit log permission
     * ({evento}_{prova}_BOX). The upload endpoint checks that permission on
     * every upload, so a token for a user without it would be refused.
     */
    protected function issuePitLogToken(string $username = self::USER_PREFIX . 'box'): string
    {
        if (UserQuery::create()->findOneByUsername($username) === null) {
            $this->createUser($username, ['index', self::EVENTO_ID . '_' . self::PROVA_ID . ContagemAccess::PIT_LOG_SUFFIX]);
        }
        return DeviceTokens::issue(DeviceTokens::boxSourceFor($this->prova), $username, 'Telefone do box');
    }

    protected function issueToken(ContagemFonte $fonte, ?string $username = null): string
    {
        return DeviceTokens::issue($fonte, $username ?? self::USER_PREFIX . 'contador', 'Telefone de teste');
    }

    /**
     * One entry as a phone sends it.
     *
     * The phone works out the time of the entry itself, in the server's
     * time, from the last moment it compared clocks with the server (its
     * "anchor"). It also says how good that time is: "exato" when it had
     * an anchor, "estimado" or "desconhecido" when it did not. The server
     * stores the time as it comes and never changes it.
     *
     * @param string $timeQuality 'exato', 'estimado' or 'desconhecido'
     * @return array<string, mixed>
     */
    protected function entryPayload(string $uuid, int $car, int $tsMs, string $timeQuality = 'exato'): array
    {
        return [
            'uuid' => $uuid,
            'car' => $car,
            'ts_ms' => $tsMs,
            'time_quality' => $timeQuality,
            // What the phone's own clock said, kept for audit only.
            'ts_device_ms' => $tsMs,
            'anchor' => $timeQuality === 'desconhecido' ? null : ['server_ms' => $tsMs - 10000, 'rtt_ms' => 80, 'age_ms' => 10000],
            'typing_ms' => 800,
            'mode' => 'teclado',
        ];
    }

    /**
     * Sends an upload the way the count page does, minus the network: the
     * token goes in the Authorization header and the body is JSON.
     *
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $extra other fields of the request body (voids, queue_empty, ...)
     * @return array{status: int, body: array<string, mixed>} the HTTP status and the decoded answer
     */
    protected function upload(?string $token, array $entries = [], array $extra = []): array
    {
        $body = array_merge([
            'entries' => $entries,
            'voids' => [],
            // The phone's idea of the server's time right now.
            'now_ms' => Clock::nowMs(),
            'queue_empty' => false,
        ], $extra);

        $this->newRequest();
        return SyncHandler::handle($token === null ? null : 'Bearer ' . $token, (string) json_encode($body));
    }

    protected static function newUuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    // ------------------------------------------------------------------
    // Helpers about users and sessions
    // ------------------------------------------------------------------

    /**
     * Creates a row in the application's `user` table. This is what makes
     * a forum account "exist" for this system and says what it may do.
     *
     * @param string $username must start with "zzt_" so that tearDown() removes it
     * @param string[] $permissions permission codes, e.g. ['index', '00NE_ZZL']
     */
    protected function createUser(string $username, array $permissions): void
    {
        $user = new User();
        $user->setUsername($username);
        $user->setPermissions($permissions);
        $user->save();
    }

    /** Makes the rest of the test run as if this user were logged in. */
    protected function loginAs(string $username): void
    {
        Session::setForcedSession($username);
    }

    /** Back to "nobody is logged in". */
    protected function logout(): void
    {
        // No user has an empty name, so this looks one up, finds nobody,
        // and leaves the session empty.
        Session::setForcedSession('');
    }

    // ------------------------------------------------------------------
    // Helpers that look at the result
    // ------------------------------------------------------------------

    /**
     * The published score fields of a car (the JSON in input.dados) as an
     * array, or null when the car has no score row at all.
     *
     * @return array<string, mixed>|null
     */
    protected function publishedData(int $car): ?array
    {
        return $this->inputColumn($car, 'dados');
    }

    /**
     * The points of a car (the JSON in input.pontos), or null when the car
     * has no score row.
     *
     * @return array<string, mixed>|null
     */
    protected function publishedPoints(int $car): ?array
    {
        return $this->inputColumn($car, 'pontos');
    }

    /**
     * Runs "SELECT COUNT(*) FROM $table WHERE $where" and returns the number.
     *
     * @param array<int, mixed> $params values for the ? placeholders in $where
     */
    protected function countRows(string $table, string $where, array $params = []): int
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where
        );
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    /**
     * Writes a score field by hand, as a judge does on the entry page.
     * Creates the car's score row when it has none.
     *
     * @param mixed $value
     */
    protected function setManualField(int $car, string $field, $value): void
    {
        $dados = $this->publishedData($car) ?? [];
        $dados[$field] = $value;
        Propel::getWriteConnection('resultados')->prepare(
            'INSERT INTO input (evento_id, prova_id, equipe_id, dados) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE dados = VALUES(dados)'
        )->execute([self::EVENTO_ID, self::PROVA_ID, $car, json_encode($dados)]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * addEntry() needs a device to attribute its entries to, because every
     * entry records which device sent it. This returns one per source,
     * creating it on first use. Its token is random and thrown away: no
     * test uploads through it.
     */
    private function helperDeviceId(ContagemFonte $fonte): int
    {
        $fonteId = (int) $fonte->getFonteId();
        if (!isset($this->helperDeviceIds[$fonteId])) {
            $connection = Propel::getWriteConnection('resultados');
            $connection->prepare(
                'INSERT INTO contagem_dispositivo (fonte_id, token_hash, usuario, rotulo, criado_em_ms)
                 VALUES (?, ?, NULL, ?, ?)'
            )->execute([$fonteId, hash('sha256', random_bytes(32)), 'Dispositivo auxiliar do teste', self::RACE_START_MS]);
            $this->helperDeviceIds[$fonteId] = (int) $connection->lastInsertId();
        }
        return $this->helperDeviceIds[$fonteId];
    }

    /** @return array<string, mixed>|null */
    private function inputColumn(int $car, string $column): ?array
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT ' . $column . ' FROM input WHERE evento_id = ? AND prova_id = ? AND equipe_id = ?'
        );
        $statement->execute([self::EVENTO_ID, self::PROVA_ID, $car]);
        // fetch(), not fetchColumn(): Propel's statement wrapper turns the
        // "no row" answer of fetchColumn() into an empty string, which
        // cannot be told apart from an empty value.
        $row = $statement->fetch(\PDO::FETCH_NUM);
        if ($row === false || $row === null || $row[0] === null) {
            return null;
        }
        return (array) json_decode((string) $row[0], true);
    }
}
