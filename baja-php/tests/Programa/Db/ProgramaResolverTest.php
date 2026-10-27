<?php
declare(strict_types=1);

namespace Baja\Tests\Programa\Db;

use Baja\Programa\ProgramaResolver;
use Baja\Tests\Support\WritableTestDatabase;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Programs on the real database (BAJAAPP-11): the six programs the
 * migration creates, what the tables refuse, and ProgramaResolver.
 *
 * BACKGROUND
 *
 * Every event now belongs to a program (evento.programa_id), and programs
 * form a tree (programa.parent_id: the Fórum AeroDesign is under
 * AeroDesign). The access rules of BAJAAPP-12 will ask ProgramaResolver
 * "which program is this event in?" and "what is above it?", and give or
 * refuse access by the answer. So the answers must come from what is
 * stored, never from what an event code looks like, and the tables must
 * refuse rows that would make the answer wrong or missing.
 *
 * WHAT IS TESTED
 *
 *   - the six programs exist, with the forum under AeroDesign;
 *   - the database refuses an event without a program, an event with a
 *     program that does not exist, and deleting a program something still
 *     points at;
 *   - the resolver answers from the stored program: an event coded 97BR
 *     but stored under AERO is an AERO event;
 *   - ancestors and descendants on the real tree;
 *   - answers are kept for one request only: a new resolver (the next
 *     request) sees a change.
 *
 * Uses made-up rows: event 97BR and program ZZ_TESTE, removed after each
 * test.
 *
 * HOW TO RUN
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite db --group programa
 *
 * Skipped without BAJA_TEST_DB=1 (see tests/Support/WritableTestDatabase.php).
 */
#[Group('programa')]
final class ProgramaResolverTest extends TestCase
{
    private const EVENT = '97BR';
    private const TEST_PROGRAM = 'ZZ_TESTE';

    private static ?string $skipped = null;

    private ConnectionInterface $con;

    public static function setUpBeforeClass(): void
    {
        self::$skipped = WritableTestDatabase::whyUnavailable();
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
        $this->con = WritableTestDatabase::propel();
        $this->removeFixture();
    }

    protected function tearDown(): void
    {
        if (self::$skipped === null) {
            $this->removeFixture();
        }
    }

    // --- The programs ---------------------------------------------------------

    public function testTheSixProgramsExistWithTheForumUnderAeroDesign(): void
    {
        $rows = $this->con->query('SELECT programa_id, parent_id FROM programa ORDER BY ordem')->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->assertSame([
            'BAJA' => null,
            'AERO' => null,
            'AERO_FORUM' => 'AERO',
            'FORMULA' => null,
            'ELETROQUAD' => null,
            'H2' => null,
        ], $rows);
    }

    // --- What the tables refuse -----------------------------------------------

    /** An event must say which program it is in: there is no default to fall back on. */
    public function testAnEventWithoutAProgramIsRefused(): void
    {
        $this->expectException(PDOException::class);
        $this->con->prepare('INSERT INTO evento (evento_id, titulo, ano, ativo) VALUES (?, ?, 2097, 0)')
            ->execute([self::EVENT, 'Fixture BAJAAPP-11']);
    }

    /** A program that does not exist is refused, so a typo cannot create an event nobody manages. */
    public function testAnEventInAProgramThatDoesNotExistIsRefused(): void
    {
        $this->expectException(PDOException::class);
        $this->insertEvent('NAO_EXISTE');
    }

    /** A program that still has events cannot be deleted: those events would be left without one. */
    public function testAProgramWithEventsCannotBeDeleted(): void
    {
        $this->insertProgram(self::TEST_PROGRAM, null);
        $this->insertEvent(self::TEST_PROGRAM);

        $this->expectException(PDOException::class);
        $this->con->prepare('DELETE FROM programa WHERE programa_id = ?')->execute([self::TEST_PROGRAM]);
    }

    /** A program that has programs under it cannot be deleted either. */
    public function testAProgramWithChildrenCannotBeDeleted(): void
    {
        $this->expectException(PDOException::class);
        $this->con->exec("DELETE FROM programa WHERE programa_id = 'AERO'");
    }

    // --- The resolver -----------------------------------------------------------

    public function testASeedEventIsInItsStoredProgram(): void
    {
        $this->assertSame('BAJA', (new ProgramaResolver($this->con))->programaOfEvento('26BR'));
    }

    /**
     * The point of the change: 97BR ends in BR, which used to mean Baja
     * SAE. Stored under AERO, it is an AERO event. Nothing reads the code.
     */
    public function testTheProgramComesFromTheRowNotFromTheCode(): void
    {
        $this->insertEvent('AERO');

        $this->assertSame('AERO', (new ProgramaResolver($this->con))->programaOfEvento(self::EVENT));
    }

    public function testAnEventThatDoesNotExistHasNoProgram(): void
    {
        $this->assertNull((new ProgramaResolver($this->con))->programaOfEvento('00XX'));
    }

    public function testAncestorsAndDescendantsOnTheRealTree(): void
    {
        $resolver = new ProgramaResolver($this->con);

        $this->assertSame(['AERO'], $resolver->ancestorsOf('AERO_FORUM'));
        $this->assertSame([], $resolver->ancestorsOf('AERO'));
        $this->assertSame(['AERO_FORUM'], $resolver->descendantsOf('AERO'));
        $this->assertSame([], $resolver->descendantsOf('BAJA'));
    }

    /** A program two levels down is a descendant of the top one, and has both above it. */
    public function testADeeperProgramIsFound(): void
    {
        $this->insertProgram(self::TEST_PROGRAM, 'AERO_FORUM');
        $resolver = new ProgramaResolver($this->con);

        $this->assertSame(['AERO_FORUM', 'AERO'], $resolver->ancestorsOf(self::TEST_PROGRAM));
        $this->assertSame(['AERO_FORUM', self::TEST_PROGRAM], $resolver->descendantsOf('AERO'));
    }

    /**
     * One resolver is one web request: it reads each thing once. A change
     * made meanwhile shows on the next request, which builds a new one.
     * Nothing is kept anywhere that would outlive the request.
     */
    public function testAChangeShowsOnTheNextRequest(): void
    {
        $this->insertEvent('AERO');
        $thisRequest = new ProgramaResolver($this->con);
        $this->assertSame('AERO', $thisRequest->programaOfEvento(self::EVENT));

        $this->con->prepare("UPDATE evento SET programa_id = 'BAJA' WHERE evento_id = ?")->execute([self::EVENT]);

        $this->assertSame('AERO', $thisRequest->programaOfEvento(self::EVENT), 'Within one request, the first answer stands.');
        $this->assertSame('BAJA', (new ProgramaResolver($this->con))->programaOfEvento(self::EVENT), 'The next request sees the change.');
    }

    private function insertEvent(string $programaId): void
    {
        $this->con->prepare(
            "INSERT INTO evento (evento_id, titulo, nome, ano, ativo, programa_id)
             VALUES (?, 'Fixture BAJAAPP-11', 'Evento de teste (programas)', 2097, 0, ?)"
        )->execute([self::EVENT, $programaId]);
    }

    private function insertProgram(string $programaId, ?string $parentId): void
    {
        $this->con->prepare("INSERT INTO programa (programa_id, parent_id, nome, ordem, ativo) VALUES (?, ?, 'Programa de teste', 999, 0)")
            ->execute([$programaId, $parentId]);
    }

    private function removeFixture(): void
    {
        $this->con->prepare('DELETE FROM evento WHERE evento_id = ?')->execute([self::EVENT]);
        $this->con->prepare('DELETE FROM programa WHERE programa_id = ?')->execute([self::TEST_PROGRAM]);
    }
}
