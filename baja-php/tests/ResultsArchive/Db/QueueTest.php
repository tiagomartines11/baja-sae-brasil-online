<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsArchive\Db;

use Baja\Model\EventoQuery;
use Baja\ResultsArchive\Queue;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The event archive queue (BAJAAPP-67).
 *
 * BACKGROUND
 *
 * When an event is over, an admin opens juiz/evento.php and ticks
 * "Finalizado?". From then on its results are final, and they must be
 * frozen in the backups so that what was published can always be shown
 * later. Doing that takes minutes (every results page of the event is run
 * and the output encrypted and uploaded), far too long for a web request.
 *
 * So the page only writes a note: one row in the table
 * evento_arquivamento saying "this event is waiting to be archived"
 * (estado = 'pendente'). Every night the backup job asks for those rows,
 * archives each event, and marks its row 'arquivado' with the folder the
 * archive went to. That job is baja-infra/scripts/backup-run.sh; it reaches
 * the table through scripts/results-archive.php, which calls the same
 * Baja\ResultsArchive\Queue methods these tests call.
 *
 * WHAT IS TESTED
 *
 * The page saves the event through Queue::saveEvento(), exactly as these
 * tests do, so they check what ticking and unticking the box does:
 *
 *   - ticking it records exactly one pending archive;
 *   - saving again with the box still ticked records nothing more;
 *   - unticking it before the night cancels the pending archive;
 *   - ticking it again records a new one (and keeps the old row);
 *   - the nightly job sees the pending one, and once it reports the
 *     archive done, the request is no longer pending (it was "consumed");
 *   - a failed attempt leaves the request pending, to be tried again;
 *   - a request whose event is no longer finished, or was deleted, is
 *     cancelled by the job instead of archived;
 *   - if the queue's table is missing (the migration was not run), the
 *     event is STILL saved: the admin is never locked out of editing
 *     events because of the archive queue. The error is logged and the
 *     page is told, so it can warn the admin.
 *
 * All on a made-up event, 99AR, that the tests create and delete.
 *
 * HOW TO RUN
 *
 * These tests WRITE (the event and its queue rows), so like the other
 * database tests they only run when BAJA_TEST_DB=1, and are skipped
 * otherwise. Never set that against production. In the dev container,
 * from baja-infra/:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite results-archive-db
 *
 * The table must exist: a fresh dev database has it (mysql/init/07), an
 * older one needs `./propel migration:migrate` (see docs/dev-workflow.md).
 * It connects as PHPBB_DB_USER, or as BAJA_TEST_DB_USER / BAJA_TEST_DB_PASS
 * when set (CI sets them, because its usual account may only read).
 */
final class QueueTest extends TestCase
{
    private const EVENT = '99AR';

    /** Where the "table missing" test puts the real table while it runs. */
    private const HIDDEN_TABLE = 'evento_arquivamento_hidden_by_test';

    private static ?PDO $database = null;

    private static ?string $skipped = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if (getenv('BAJA_TEST_DB') !== '1' || $host === false || $host === '') {
            self::$skipped = 'Writes a test event: set BAJA_TEST_DB=1 and the database variables (see the class comment).';
            return;
        }
        if (getenv('BAJA_TEST_DB_USER')) {
            putenv('PHPBB_DB_USER=' . getenv('BAJA_TEST_DB_USER'));
            putenv('PHPBB_DB_PASS=' . getenv('BAJA_TEST_DB_PASS'));
        }
        try {
            self::$database = new PDO(
                'mysql:host=' . $host . ';dbname=baja_resultados;charset=utf8mb4',
                (string) getenv('PHPBB_DB_USER'),
                (string) getenv('PHPBB_DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            self::$skipped = 'No database: ' . $e->getMessage();
            return;
        }
        require_once dirname(__DIR__, 3) . '/src/config.php';
        // Propel keeps every object it has loaded and hands the same one
        // back. These tests change the event behind Propel's back (SQL in
        // setUp, the "deleted" and "unticked elsewhere" cases), so each
        // load must read the database, as the next page request would.
        \Propel\Runtime\Propel::disableInstancePooling();
        // A run killed during the "table missing" test leaves the table
        // under its hiding name. Put it back before anything else.
        self::restoreHiddenTable();
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
        // Every test starts from the same place: event 99AR exists, is not
        // finished, and has no archive requests.
        self::removeFixture();
        self::$database->prepare(
            "INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers)
             VALUES (?, 'Fixture BAJAAPP-67', 'Evento de teste (arquivamento)', 0, 2099, 0, 0, 0)"
        )->execute([self::EVENT]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$database !== null) {
            self::removeFixture();
        }
    }

    // --- What ticking and unticking "Finalizado?" does --------------------

    /** Ticking the box once: one row, pending, saying who asked and when. */
    public function testMarkingFinalizadoRecordsExactlyOnePendingArchive(): void
    {
        $this->saveFinalizado(true, 1_000);

        $rows = $this->requests();
        $this->assertCount(1, $rows);
        $this->assertSame(Queue::PENDENTE, $rows[0]['estado']);
        $this->assertSame('admin-de-teste', $rows[0]['pedido_por']);
        $this->assertSame(1_000, (int) $rows[0]['pedido_em_ms']);
        // And the event itself was saved as finished.
        $this->assertSame(1, (int) self::selectOne('SELECT finalizado FROM evento WHERE evento_id = ?', [self::EVENT]));
    }

    /**
     * The admin saves the page again (to fix a typo in the venue, say) with
     * the box still ticked. Nothing changed about "finished", so nothing new
     * is asked for.
     */
    public function testSavingAgainWhileFinalizadoRecordsNothingMore(): void
    {
        $this->saveFinalizado(true, 1_000);
        $this->saveFinalizado(true, 2_000);

        $this->assertCount(1, $this->requests());
    }

    /**
     * Saving a change unrelated to "finished", on an event that is not
     * finished, asks for nothing either.
     */
    public function testSavingAnUnfinishedEventRecordsNothing(): void
    {
        $this->saveFinalizado(false, 1_000);

        $this->assertCount(0, $this->requests());
    }

    /** Unticking before the night: the results are not final after all. */
    public function testUnmarkingBeforeTheNightCancelsThePendingArchive(): void
    {
        $this->saveFinalizado(true, 1_000);
        $this->saveFinalizado(false, 2_000);

        $rows = $this->requests();
        $this->assertCount(1, $rows);
        $this->assertSame(Queue::CANCELADO, $rows[0]['estado']);
        $this->assertSame([], $this->queue()->pending(3_000));
    }

    /**
     * Ticked, archived, unticked (to correct a result), ticked again: a
     * second archive is asked for, and the record of the first is kept.
     */
    public function testRemarkingAfterAnArchiveAsksForANewOneAndKeepsTheOld(): void
    {
        $this->saveFinalizado(true, 1_000);
        $first = $this->queue()->pending(1_500)[0]['arquivamento_id'];
        $this->queue()->markArchived($first, 'events/99BR/20990101T040000Z', 2_000);

        $this->saveFinalizado(false, 3_000);
        $this->saveFinalizado(true, 4_000);

        $rows = $this->requests();
        $this->assertCount(2, $rows);
        $this->assertSame([Queue::ARQUIVADO, Queue::PENDENTE], array_column($rows, 'estado'));
        $this->assertSame('events/99BR/20990101T040000Z', $rows[0]['pasta']);
    }

    // --- The archive queue must never block saving an event ----------------

    /**
     * The deploy went out without its migration, so evento_arquivamento
     * does not exist. The admin ticks "Finalizado?" and saves.
     *
     * The event must be saved anyway (finalizado = 1 in the database).
     * What failed is only the archive request, and that is reported in
     * two ways: the error is logged, and saveEvento() returns false, which
     * is what makes juiz/evento.php show the admin a warning that the
     * archive was not queued.
     *
     * To make the table "missing", the test renames it for the length of
     * this one test and always renames it back, even if an assertion fails.
     */
    public function testTheEventIsStillSavedWhenTheQueueTableIsMissing(): void
    {
        $logged = [];
        $evento = EventoQuery::create()->findOneByEventoId(self::EVENT);
        $evento->setFinalizado(true);

        self::$database->exec('RENAME TABLE evento_arquivamento TO ' . self::HIDDEN_TABLE);
        try {
            $queued = Queue::saveEvento(
                $evento,
                false,
                'admin-de-teste',
                1_000,
                // Instead of the real log, collect what would be logged.
                function (string $message, array $context) use (&$logged): void {
                    $logged[] = ['message' => $message, 'context' => $context];
                }
            );
        } finally {
            self::restoreHiddenTable();
        }

        $this->assertFalse($queued, 'saveEvento() must say the archive was not queued, so the page can warn');
        $this->assertSame(
            1,
            (int) self::selectOne('SELECT finalizado FROM evento WHERE evento_id = ?', [self::EVENT]),
            'the event itself must be saved even though the queue failed'
        );
        $this->assertCount(1, $logged, 'the failure is logged exactly once');
        $this->assertSame(self::EVENT, $logged[0]['context']['evento_id']);
        $this->assertStringContainsString('evento_arquivamento', $logged[0]['context']['error']);
        // And, with the table back, nothing was queued: there was nowhere to put it.
        $this->assertCount(0, $this->requests());
    }

    // --- What the nightly job sees ----------------------------------------

    /**
     * The job asks for the pending requests, archives the event, and
     * reports it done. After that the request is no longer pending: the
     * job consumed it, and the next night has nothing to do.
     */
    public function testTheNightlyJobConsumesThePendingArchive(): void
    {
        $this->saveFinalizado(true, 1_000);

        $pending = $this->queue()->pending(2_000);
        $this->assertCount(1, $pending);
        $this->assertSame(self::EVENT, $pending[0]['evento_id']);

        $this->queue()->markArchived($pending[0]['arquivamento_id'], 'events/99BR/20990101T040000Z', 3_000);

        $this->assertSame([], $this->queue()->pending(4_000));
        $row = $this->requests()[0];
        $this->assertSame(Queue::ARQUIVADO, $row['estado']);
        $this->assertSame(3_000, (int) $row['concluido_em_ms']);
    }

    /** Reporting the same request done twice is a bug in the caller, and is refused. */
    public function testARequestCannotBeConsumedTwice(): void
    {
        $this->saveFinalizado(true, 1_000);
        $id = $this->queue()->pending(2_000)[0]['arquivamento_id'];
        $this->queue()->markArchived($id, 'events/99BR/20990101T040000Z', 3_000);

        $this->expectException(\RuntimeException::class);
        $this->queue()->markArchived($id, 'events/99BR/20990102T040000Z', 4_000);
    }

    /** A failed attempt (Drive down, say) leaves it pending, counted, with the reason. */
    public function testAFailedAttemptStaysPendingForTheNextNight(): void
    {
        $this->saveFinalizado(true, 1_000);
        $id = $this->queue()->pending(2_000)[0]['arquivamento_id'];

        $this->queue()->markFailed($id, 'archive-event.sh failed: cannot list gdrive:');

        $pending = $this->queue()->pending(3_000);
        $this->assertCount(1, $pending);
        $this->assertSame(1, $pending[0]['tentativas']);
        $this->assertSame('archive-event.sh failed: cannot list gdrive:', $this->requests()[0]['ultimo_erro']);
    }

    /**
     * Somebody unticks the box straight in the database (or another page
     * does) after the request was made. The job must not freeze results
     * that are not final: it cancels the request instead.
     */
    public function testTheJobCancelsARequestWhoseEventIsNoLongerFinished(): void
    {
        $this->saveFinalizado(true, 1_000);
        self::$database->prepare('UPDATE evento SET finalizado = 0 WHERE evento_id = ?')->execute([self::EVENT]);

        $this->assertSame([], $this->queue()->pending(2_000));
        $this->assertSame(Queue::CANCELADO, $this->requests()[0]['estado']);
    }

    /** The event was deleted before the night: nothing to archive, and the row stays as a record. */
    public function testTheJobCancelsARequestWhoseEventWasDeleted(): void
    {
        $this->saveFinalizado(true, 1_000);
        self::$database->prepare('DELETE FROM evento WHERE evento_id = ?')->execute([self::EVENT]);

        $this->assertSame([], $this->queue()->pending(2_000));
        $this->assertSame('evento apagado antes do arquivamento', $this->requests()[0]['ultimo_erro']);
    }

    /** Only an events/<EVT>/<timestamp> folder can be recorded, never an arbitrary path. */
    public function testOnlyAnEventArchiveFolderCanBeRecorded(): void
    {
        $this->saveFinalizado(true, 1_000);
        $id = $this->queue()->pending(2_000)[0]['arquivamento_id'];

        $this->expectException(\RuntimeException::class);
        $this->queue()->markArchived($id, 'daily/20990101T040000Z', 3_000);
    }

    // --- Helpers --------------------------------------------------------------

    /**
     * What juiz/evento.php does when the admin presses "Atualizar": load
     * the event, remember whether it was finished, apply the form, save
     * through the queue.
     */
    private function saveFinalizado(bool $finalizado, int $nowMs): void
    {
        $evento = EventoQuery::create()->findOneByEventoId(self::EVENT);
        $eraFinalizado = (bool) $evento->getFinalizado();
        $evento->setFinalizado($finalizado);
        $this->assertTrue(
            Queue::saveEvento($evento, $eraFinalizado, 'admin-de-teste', $nowMs),
            'with the table present, the queue step must succeed'
        );
    }

    private function queue(): Queue
    {
        return Queue::forApp();
    }

    /** @return list<array<string, mixed>> the event's rows, oldest first */
    private function requests(): array
    {
        $statement = self::$database->prepare(
            'SELECT * FROM evento_arquivamento WHERE evento_id = ? ORDER BY arquivamento_id'
        );
        $statement->execute([self::EVENT]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function restoreHiddenTable(): void
    {
        $statement = self::$database->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([self::HIDDEN_TABLE]);
        if ((int) $statement->fetchColumn() > 0) {
            self::$database->exec('RENAME TABLE ' . self::HIDDEN_TABLE . ' TO evento_arquivamento');
        }
    }

    private static function removeFixture(): void
    {
        self::$database->prepare('DELETE FROM evento_arquivamento WHERE evento_id = ?')->execute([self::EVENT]);
        self::$database->prepare('DELETE FROM evento WHERE evento_id = ?')->execute([self::EVENT]);
    }

    private static function selectOne(string $sql, array $params): string
    {
        $statement = self::$database->prepare($sql);
        $statement->execute($params);
        return (string) $statement->fetchColumn();
    }
}
