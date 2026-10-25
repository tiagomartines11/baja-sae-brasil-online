<?php
declare(strict_types=1);

namespace Baja\Tests\Prova\Db;

use Baja\Model\ProvaQuery;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Count, sum and average in a prova's totals (BAJAAPP-65).
 *
 * BACKGROUND
 *
 * A prova's "pontos" formulas can compare a team with all the others. For
 * that, every time a judge saves an entry, Prova::updateTotals() looks at
 * every variable X listed in the prova's "min_max", over the event's teams
 * that are not disqualified, and stores a few numbers about it in the
 * prova's "totals". The formulas then read them as $Xmax, $Xmin...
 *
 * There used to be three: $Xmax, $Xmin and $Xcom. This adds three more:
 *
 *   $Xcount  how many teams have a value
 *   $Xsum    the sum of the values
 *   $Xavg    their average (null when no team has a value)
 *
 * "A value" is anything except null and 9999, which the provas use to say
 * "no valid result". 0 is a real value and counts.
 *
 * WHAT IS TESTED
 *
 * A made-up event, 99TS, with one prova and seven teams. The prova is
 * saved the way a judge's save does it (Prova::refreshVarsAndPontos()),
 * then its totals are read back from the database. Each test below checks
 * one thing about them. (The database stores a whole number without its
 * ".0", so 4.0 is read back as 4: that is why the tests expect 4, not 4.0.)
 *
 * The seven teams, and what the judges typed for each:
 *
 *   car   T       TEMPO   notes
 *    1    10      95.5
 *    2    0       9999    T = 0 is a value; TEMPO 9999: did not finish
 *    3    -4      101.25  a negative value is a value too
 *    4    9999    88.25   T = 9999: no valid result
 *    5    (null)  9999    T empty: no value
 *    6    7.5     120
 *    7    1000    80      DISQUALIFIED: left out of every total
 *
 * The prova's "min_max" (the variables the totals are about):
 *
 *   T      "$T"                              the edge cases above
 *   TEMPO  "$TEMPO"                          a time
 *   FIN    "$TEMPO < 9999 ? 1 : null"        1 for a finisher, else null
 *   TFIN   "$TEMPO < 9999 ? $TEMPO : null"   the time, for finishers only
 *   NADA   "null"                            no team ever has a value
 *
 * FIN and TFIN show how to count or add up only SOME teams: the formula
 * gives null for the others, and null is not a value.
 *
 * HOW TO RUN
 *
 * This test WRITES: it inserts 99TS before and deletes it after. So, like
 * the other database tests, it runs only when BAJA_TEST_DB=1 and is skipped
 * otherwise. Never set that against production. In the dev container:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite prova-db
 *
 * It connects as PHPBB_DB_USER, or as BAJA_TEST_DB_USER / BAJA_TEST_DB_PASS
 * when set (CI sets them, because its usual account may only read).
 */
final class ProvaTotalsTest extends TestCase
{
    private const EVENT = '99TS';
    private const PROVA = 'EST';

    /** car => [what the judges typed, disqualified?] */
    private const TEAMS = [
        1 => [['T' => 10, 'TEMPO' => 95.5], false],
        2 => [['T' => 0, 'TEMPO' => 9999], false],
        3 => [['T' => -4, 'TEMPO' => 101.25], false],
        4 => [['T' => 9999, 'TEMPO' => 88.25], false],
        5 => [['T' => null, 'TEMPO' => 9999], false],
        6 => [['T' => 7.5, 'TEMPO' => 120], false],
        7 => [['T' => 1000, 'TEMPO' => 80], true],
    ];

    private const MIN_MAX = [
        'T' => '$T',
        'TEMPO' => '$TEMPO',
        'FIN' => '$TEMPO < 9999 ? 1 : null',
        'TFIN' => '$TEMPO < 9999 ? $TEMPO : null',
        'NADA' => 'null',
    ];

    /** A "pontos" formula that uses one of the new totals, to show the formulas can read them. */
    private const PONTOS = ['FINALISTAS' => '$FINcount'];

    private static ?PDO $database = null;

    private static ?string $skipped = null;

    /** @var array<string, mixed> the prova's totals, as saved */
    private static array $totals = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if (getenv('BAJA_TEST_DB') !== '1' || $host === false || $host === '') {
            self::$skipped = 'Writes a test event: set BAJA_TEST_DB=1 and the database variables (see the class comment).';
            return;
        }
        // The app (through Propel) connects as PHPBB_DB_USER. Point it at the
        // test account too, when one is given, before Propel is set up.
        if (getenv('BAJA_TEST_DB_USER')) {
            putenv('PHPBB_DB_USER=' . getenv('BAJA_TEST_DB_USER'));
            putenv('PHPBB_DB_PASS=' . getenv('BAJA_TEST_DB_PASS'));
        }
        try {
            self::$database = new PDO(
                'mysql:host=' . $host . ';dbname=baja_resultados;charset=utf8mb4',
                (string)getenv('PHPBB_DB_USER'),
                (string)getenv('PHPBB_DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            self::$skipped = 'No database: ' . $e->getMessage();
            return;
        }
        require_once dirname(__DIR__, 3) . '/src/config.php';

        self::removeFixture();  // left over from a run that was interrupted
        self::insertFixture();

        // What a judge's save does: every team's vars, then the totals, then the points.
        ProvaQuery::create()->filterByEventoId(self::EVENT)->findOneByProvaId(self::PROVA)->refreshVarsAndPontos();

        self::$totals = json_decode(self::selectOne('SELECT totals FROM prova WHERE evento_id = ? AND prova_id = ?', [self::EVENT, self::PROVA]), true);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$database !== null) {
            self::removeFixture();
        }
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
    }

    // --- The three new totals ----------------------------------------------

    /**
     * $Tcount: cars 1, 2, 3 and 6 have a value. Car 2's 0 counts. Car 4
     * (9999) and car 5 (empty) do not. Car 7 is disqualified.
     */
    public function testCountLeavesOutNullAnd9999ButCounts0(): void
    {
        $this->assertSame(4, self::$totals['Tcount']);
    }

    /** $Tsum: 10 + 0 + (-4) + 7.5. A negative value is added like any other. */
    public function testSumAddsTheValuesIncludingNegativeOnes(): void
    {
        $this->assertSame(13.5, self::$totals['Tsum']);
    }

    /** $Tavg: the sum divided by the count, 13.5 / 4. */
    public function testAverageIsTheSumDividedByTheCount(): void
    {
        $this->assertSame(3.375, self::$totals['Tavg']);
    }

    /**
     * When no team has a value: the count is 0, the sum is 0, and the
     * average is null, since there is nothing to divide.
     */
    public function testNoValueAtAll(): void
    {
        $this->assertSame(0, self::$totals['NADAcount']);
        $this->assertSame(0, self::$totals['NADAsum']);
        $this->assertNull(self::$totals['NADAavg']);
    }

    /**
     * Car 7 is disqualified, so its T of 1000 and TEMPO of 80 count nowhere.
     * With it, $Tsum would be 1013.5 and $TEMPOcount 5.
     */
    public function testDisqualifiedTeamIsLeftOut(): void
    {
        $this->assertSame(13.5, self::$totals['Tsum']);
        $this->assertSame(4, self::$totals['TEMPOcount'], 'cars 1, 3, 4 and 6; 2 and 5 have 9999');
    }

    // --- Counting or adding up only some teams ------------------------------

    /**
     * FIN is 1 for a team that finished (TEMPO is not 9999) and null for the
     * others. So $FINcount is the number of finishers: cars 1, 3, 4 and 6.
     */
    public function testCountingTheFinishers(): void
    {
        $this->assertSame(4, self::$totals['FINcount']);
    }

    /**
     * TFIN is the time of a finisher, null for the others. So $TFINsum adds
     * up only the finishers' times (a SUMIF), 95.5 + 101.25 + 88.25 + 120,
     * and $TFINavg is their average time.
     */
    public function testAddingUpOnlyTheFinishers(): void
    {
        $this->assertSame(405, self::$totals['TFINsum']);
        $this->assertSame(101.25, self::$totals['TFINavg']);
    }

    /**
     * The "pontos" formulas see the new totals. Here FINALISTAS is
     * "$FINcount" for every team. (Disqualified teams get no points at all:
     * that has not changed.)
     */
    public function testPontosFormulasCanUseTheNewTotals(): void
    {
        $pontos = json_decode(self::selectOne(
            'SELECT pontos FROM input WHERE evento_id = ? AND prova_id = ? AND equipe_id = 1',
            [self::EVENT, self::PROVA]
        ), true);

        $this->assertSame(['FINALISTAS' => 4], $pontos);
    }

    // --- What was there before ----------------------------------------------

    /**
     * The three old totals are still there and still work as before: $Tmax
     * leaves out 9999 (and the disqualified car 7's 1000), $Tmin leaves out
     * 0. Every variable gets the same six totals, and nothing else is added.
     */
    public function testOldTotalsAreUnchanged(): void
    {
        $this->assertSame(10, self::$totals['Tmax']);
        $this->assertSame(-4, self::$totals['Tmin']);

        $expectedKeys = [];
        foreach (array_keys(self::MIN_MAX) as $var) {
            foreach (['max', 'min', 'com', 'count', 'sum', 'avg'] as $total) {
                $expectedKeys[] = $var . $total;
            }
        }
        // Sorted, because MySQL stores a JSON object's keys in its own order.
        $actualKeys = array_keys(self::$totals);
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);
    }

    // --- The made-up event ---------------------------------------------------

    /**
     * Event 99TS: not finished and not active, so the results snapshots
     * (which only look at finished events) never see it.
     */
    private static function insertFixture(): void
    {
        self::execute(
            "INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers) VALUES (?, 'Fixture BAJAAPP-65', 'Evento de teste (totais)', 0, 2099, 0, 0, 0)",
            [self::EVENT]
        );
        self::execute(
            "INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES (?, ?, 'Estatísticas', 1, ?, '{}')",
            [self::EVENT, self::PROVA, json_encode([
                'inputs' => [
                    ['code' => 'T', 'name' => 'T', 'type' => 'number', 'negative' => true],
                    ['code' => 'TEMPO', 'name' => 'Tempo', 'type' => 'number'],
                ],
                'min_max' => self::MIN_MAX,
                'pontos' => self::PONTOS,
            ])]
        );
        foreach (self::TEAMS as $car => [$dados, $disqualified]) {
            self::execute(
                "INSERT INTO equipe (evento_id, equipe_id, escola, equipe, estado, desclassificado) VALUES (?, ?, ?, ?, 'SP', ?)",
                [self::EVENT, $car, 'ESCOLA DE TESTE ' . $car, 'EQUIPE TESTE ' . $car, $disqualified ? 1 : 0]
            );
            self::execute(
                "INSERT INTO input (evento_id, prova_id, equipe_id, dados, vars, pontos) VALUES (?, ?, ?, ?, '{}', '{}')",
                [self::EVENT, self::PROVA, $car, json_encode($dados)]
            );
        }
    }

    private static function removeFixture(): void
    {
        // Every other row of the fixture hangs off the event, ON DELETE CASCADE.
        self::execute('DELETE FROM evento WHERE evento_id = ?', [self::EVENT]);
    }

    private static function execute(string $sql, array $params): void
    {
        self::$database->prepare($sql)->execute($params);
    }

    private static function selectOne(string $sql, array $params): string
    {
        $statement = self::$database->prepare($sql);
        $statement->execute($params);
        return (string)$statement->fetchColumn();
    }
}
