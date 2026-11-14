<?php
declare(strict_types=1);

namespace Baja\Tests\Prova\Db;

use Baja\Model\ProvaQuery;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Repeated input groups give the same points as the fields written by
 * hand (BAJAAPP-103).
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * A prova's config lists the fields a judge fills in ("inputs") and the
 * formulas that turn them into points. Tração allows six attempts, each a
 * time T or, for a car that did not finish, a distance D. Until now the
 * config had to list twelve fields, T_1, D_1 ... T_6, D_6. Now it can list
 * the two once, in a group repeated six times, and the site writes the
 * twelve out by itself (Baja\Repeat\InputGroups).
 *
 * The promise is that nothing else changes: the data stored per team, the
 * formulas and the points are the same either way. This test checks that
 * on a real database, through the code a judge's save runs.
 *
 * WHAT IS TESTED
 *
 * A made-up event, 98RG, with three provas that only differ in how they
 * are written, and the same data for five teams in each:
 *
 *   TRH  the twelve fields written by hand, formulas with array_diff()
 *   TRG  the same fields as one repeated group, the SAME formulas
 *   TRL  the repeated group, and formulas written with the new helpers,
 *        MENOR($T_lista) and MAIOR($D_lista), instead of array_diff()
 *
 * For each prova the points are worked out as a judge's save does
 * (Prova::refreshVarsAndPontos(): every team's vars, then the totals over
 * all teams, then every team's points), then read back from the database
 * and compared. TRH and TRG must agree on everything, TRL on the points.
 *
 * The teams, and what the judges typed:
 *
 *   car  attempts
 *    1   T_1 4.5, T_2 4.2                     two times
 *    2   D_1 12.5, T_2 5.1                    a distance, then a time
 *    3   D_1 10, D_2 14.25                    two distances
 *    4   (nothing; PRESENTE = NÃO)            no attempt at all
 *    5   T_1 ... T_6 = 4.9 4.8 4.7 4.6 4.55 4.65   all six attempts
 *
 * Car 4 is the case that used to need care: with no attempt, PHP's min()
 * of an empty list is an error. The array_diff() formulas guard against it
 * with count(...) > 0; MENOR() of an empty list is simply null.
 *
 * HOW TO RUN
 *
 * This test WRITES: it inserts 98RG before and deletes it after, so like
 * the other database tests it runs only with BAJA_TEST_DB=1:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite prova-db
 *
 * It connects as PHPBB_DB_USER, or as BAJA_TEST_DB_USER / BAJA_TEST_DB_PASS
 * when set (CI sets them).
 */
final class RepeatedGroupsPointsTest extends TestCase
{
    private const EVENT = '98RG';

    /** car => what the judges typed */
    private const TEAMS = [
        1 => ['PRESENTE' => 'SIM', 'T_1' => 4.5, 'T_2' => 4.2],
        2 => ['PRESENTE' => 'SIM', 'D_1' => 12.5, 'T_2' => 5.1],
        3 => ['PRESENTE' => 'SIM', 'D_1' => 10, 'D_2' => 14.25],
        4 => ['PRESENTE' => 'NÃO'],
        5 => ['PRESENTE' => 'SIM', 'T_1' => 4.9, 'T_2' => 4.8, 'T_3' => 4.7, 'T_4' => 4.6, 'T_5' => 4.55, 'T_6' => 4.65],
    ];

    private const PRESENTE = ['code' => 'PRESENTE', 'name' => 'Presente', 'type' => 'enum', 'options' => ['SIM', 'NÃO']];

    /** The best time and the longest distance, the old way: array_diff() drops the empty attempts. */
    private const MIN_MAX_BY_HAND = [
        'T' => 'count(array_diff([$T_1, $T_2, $T_3, $T_4, $T_5, $T_6], [null])) > 0 ? min(array_diff([$T_1, $T_2, $T_3, $T_4, $T_5, $T_6], [null])) : null',
        'D' => 'count(array_diff([$D_1, $D_2, $D_3, $D_4, $D_5, $D_6], [null])) > 0 ? max(array_diff([$D_1, $D_2, $D_3, $D_4, $D_5, $D_6], [null])) : null',
    ];

    /** The same with the helpers of BAJAAPP-103. */
    private const MIN_MAX_WITH_LISTS = [
        'T' => 'MENOR($T_lista)',
        'D' => 'MAIOR($D_lista)',
    ];

    /**
     * Points: a car with a time scores 50 x best time / its time; a car
     * with only distances scores up to 25 by distance; nothing scores 0.
     * TENTATIVAS shows the attempts are counted the same way too.
     */
    private const PONTOS = [
        'TENTATIVAS' => 'count(array_diff([$T_1, $D_1, $T_2, $D_2, $T_3, $D_3, $T_4, $D_4, $T_5, $D_5, $T_6, $D_6], [null]))',
        'TRAPTS' => '$T !== null ? ROUND(50 * $Tmin / $T, 3) : ($D !== null ? ROUND(25 * $D / $Dmax, 3) : 0)',
    ];

    private static ?PDO $database = null;

    private static ?string $skipped = null;

    /** @var array<string, array<int, array{vars: mixed, pontos: mixed}>> prova => car => what was saved */
    private static array $saved = [];

    /** @var array<string, mixed> prova => its totals */
    private static array $totals = [];

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

        foreach (['TRH', 'TRG', 'TRL'] as $prova) {
            // What a judge's save does: every team's vars, then the totals, then the points.
            ProvaQuery::create()->filterByEventoId(self::EVENT)->findOneByProvaId($prova)->refreshVarsAndPontos();
            foreach (array_keys(self::TEAMS) as $car) {
                self::$saved[$prova][$car] = [
                    'vars' => json_decode(self::selectOne('SELECT vars FROM input WHERE evento_id = ? AND prova_id = ? AND equipe_id = ?', [self::EVENT, $prova, $car]), true),
                    'pontos' => json_decode(self::selectOne('SELECT pontos FROM input WHERE evento_id = ? AND prova_id = ? AND equipe_id = ?', [self::EVENT, $prova, $car]), true),
                ];
            }
            self::$totals[$prova] = json_decode(self::selectOne('SELECT totals FROM prova WHERE evento_id = ? AND prova_id = ?', [self::EVENT, $prova]), true);
        }
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

    /**
     * THE PROMISE: the hand-written prova and the repeated group give the
     * same vars, the same totals and the same points, team by team.
     */
    public function testHandWrittenAndRepeatedGroupGiveTheSameResults(): void
    {
        $this->assertSame(self::$saved['TRH'], self::$saved['TRG']);
        $this->assertSame(self::$totals['TRH'], self::$totals['TRG']);
    }

    /** MENOR($T_lista) and MAIOR($D_lista) give the same points as the array_diff() formulas. */
    public function testTheListHelpersGiveTheSamePoints(): void
    {
        foreach (array_keys(self::TEAMS) as $car) {
            $this->assertSame(self::$saved['TRH'][$car]['pontos'], self::$saved['TRL'][$car]['pontos'], 'car ' . $car);
            $this->assertSame(self::$saved['TRH'][$car]['vars'], self::$saved['TRL'][$car]['vars'], 'car ' . $car);
        }
    }

    /**
     * And the points are the ones expected, so the three do not just agree
     * on being wrong. Best time of all is car 1's 4.2.
     *
     *   car 1: 50 x 4.2 / 4.2 = 50
     *   car 2: 50 x 4.2 / 5.1 = 41.176
     *   car 3: no time, best distance 14.25 of a longest 14.25: 25
     *   car 4: nothing: 0, and no error for an empty list
     *   car 5: best of six 4.55: 50 x 4.2 / 4.55 = 46.154
     */
    public function testThePointsAreTheExpectedOnes(): void
    {
        $points = array_map(fn(array $team) => $team['pontos']['TRAPTS'], self::$saved['TRL']);

        $this->assertEquals([1 => 50, 2 => 41.176, 3 => 25, 4 => 0, 5 => 46.154], $points);
        $this->assertSame([1 => 2, 2 => 2, 3 => 2, 4 => 0, 5 => 6], array_map(fn(array $team) => $team['pontos']['TENTATIVAS'], self::$saved['TRL']));
        $this->assertNull(self::$saved['TRL'][4]['vars']['T'], 'MENOR() of no attempt is null');
    }

    // --- The made-up event ---------------------------------------------------

    /** Event 98RG: not finished and not active, so the results snapshots never see it. */
    private static function insertFixture(): void
    {
        self::execute(
            "INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers) VALUES (?, 'Fixture BAJAAPP-103', 'Evento de teste (grupos repetidos)', 0, 2098, 0, 0, 0)",
            [self::EVENT]
        );
        $group = ['repetir' => 6, 'nome' => 'Tentativa', 'inputs' => [
            ['code' => 'T', 'name' => 'Tempo [s]', 'type' => 'number', 'precision' => 3, 'xor' => 'A'],
            ['code' => 'D', 'name' => 'Distância [m]', 'type' => 'number', 'precision' => 2, 'xor' => 'A'],
        ]];
        $byHand = [self::PRESENTE];
        for ($n = 1; $n <= 6; $n++) {
            $byHand[] = ['code' => 'T_' . $n, 'name' => 'Tempo [s] (Tentativa ' . $n . ')', 'type' => 'number', 'precision' => 3, 'xor' => 'A_' . $n, 'pass' => $n + 1];
            $byHand[] = ['code' => 'D_' . $n, 'name' => 'Distância [m] (Tentativa ' . $n . ')', 'type' => 'number', 'precision' => 2, 'xor' => 'A_' . $n, 'pass' => $n + 1];
        }
        $provas = [
            'TRH' => ['inputs' => $byHand, 'min_max' => self::MIN_MAX_BY_HAND, 'pontos' => self::PONTOS],
            'TRG' => ['inputs' => [self::PRESENTE, $group], 'min_max' => self::MIN_MAX_BY_HAND, 'pontos' => self::PONTOS],
            'TRL' => ['inputs' => [self::PRESENTE, $group], 'min_max' => self::MIN_MAX_WITH_LISTS, 'pontos' => self::PONTOS],
        ];
        foreach ($provas as $prova => $params) {
            self::execute(
                "INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES (?, ?, 'Tração', 1, ?, '{}')",
                [self::EVENT, $prova, json_encode($params)]
            );
        }
        foreach (self::TEAMS as $car => $dados) {
            self::execute(
                "INSERT INTO equipe (evento_id, equipe_id, escola, equipe, estado, desclassificado) VALUES (?, ?, ?, ?, 'SP', 0)",
                [self::EVENT, $car, 'ESCOLA DE TESTE ' . $car, 'EQUIPE TESTE ' . $car]
            );
            foreach (array_keys($provas) as $prova) {
                self::execute(
                    "INSERT INTO input (evento_id, prova_id, equipe_id, dados, vars, pontos) VALUES (?, ?, ?, ?, '{}', '{}')",
                    [self::EVENT, $prova, $car, json_encode($dados)]
                );
            }
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
