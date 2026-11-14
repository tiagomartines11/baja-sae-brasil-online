<?php
declare(strict_types=1);

namespace Baja\Tests\Prova\Db;

use Baja\Model\InputQuery;
use Baja\Model\Map\InputTableMap;
use Baja\Model\Map\ProvaTableMap;
use Baja\Model\Map\ResultadoTableMap;
use Baja\Model\ProvaQuery;
use Baja\Model\ResultadoQuery;
use Baja\Posicoes\EventGraph;
use Baja\Posicoes\ExternalPositions;
use Baja\Posicoes\PosicoesRules;
use Baja\Posicoes\Recalculation;
use Baja\FormulaCompare\Recorder;
use Baja\FormulaCompare\Units;
use Baja\Results\ResultsBuilder;
use Baja\ResultsCheck\Command;
use Baja\ResultsCheck\LiveSource;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;
use Baja\ResultsSnapshot\Jobs\FormulasJob;
use Baja\Results\ResultsConfig;
use Baja\Results\ViewerContext;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Propel;

/**
 * Positions in prova formulas, with a real database (BAJAAPP-102).
 *
 * BACKGROUND
 *
 * A "prova" is one test of the competition (Tração, Aceleração...). Judges
 * type each team's data, and the prova's formulas turn it into points,
 * which are STORED in the database. A "resultado" is a results page: it
 * takes the points of one or more provas and ranks the teams.
 *
 * Some points depend on where a team PLACED in another resultado. The real
 * case: Compliance gives 20 points to the cars in the top 5 of both Tração
 * and Aceleração. A prova can now say so in its "posicoes":
 *
 *     "posicoes": {"P_TRA": {"resultado": "99PS_TRA"}, "P_AV": {"resultado": "99PS_AVF"}},
 *     "pontos":   {"CMPPTS": "$P_TRA !== null && $P_TRA <= 5 && $P_AV !== null && $P_AV <= 5 ? 20 : 0"}
 *
 * $P_TRA is then the team's position in that results page: 1, 2, 3... or
 * null when it has none.
 *
 * Because points are stored, Compliance has to be worked out again every
 * time a Tração or Aceleração point changes. That happens by itself, at
 * the end of Prova::refreshVarsAndPontos(), which every save calls.
 *
 * THE MADE-UP EVENT, 99PS
 *
 * Eight cars. Tração and Aceleração points (what the judges "typed"):
 *
 *   car   TRA   pos    AVF   pos    in the top 5 of both?    CMPPTS
 *    1    100    1     100    1     yes                       20
 *    2     90    3      90    2     yes                       20
 *    3     80    4      80    3     yes                       20
 *    4     50    7      60    6     no                         0
 *    5     60    5=     70    4=    yes (tied 5th in TRA)     20
 *    6     60    5=     70    4=    yes (tied 5th in TRA)     20
 *    7     95    2      50    7     only Tração                0
 *    8     40    8      40    8     no                         0
 *
 * (5= means "tied for 5th": both are 5th and nobody is 6th.)
 *
 * Three more teams: car 9 is disqualified (it has data only in EXT, below),
 * car 10 is present but has no data anywhere, car 11 is absent.
 *
 * Provas:  TRA, AVF  points straight from what was typed
 *          CMP       Compliance, as above. A DERIVED prova: it has no input
 *                    field, nobody types anything for it, and it makes its
 *                    own entry for every present team when worked out
 *          LVL       takes a position from the Compliance resultado: a
 *                    second level, to show that changes go all the way
 *                    (derived too)
 * Resultados:  99PS_TRA (TRA), 99PS_AVF (AVF), 99PS_CMP (CMP), and
 *              99PS_GER (TRA, AVF, CMP), a "Geral", to test cycles.
 *
 * HOW TO RUN
 *
 * Like the other prova tests, this WRITES a made-up event and deletes it
 * again, so it only runs with BAJA_TEST_DB=1 (never against production):
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite prova-db
 */
final class PosicoesTest extends TestCase
{
    private const EVENT = '99PS';

    /** car => [Tração points, Aceleração points] */
    private const POINTS = [
        1 => [100, 100],
        2 => [90, 90],
        3 => [80, 80],
        4 => [50, 60],
        5 => [60, 70],
        6 => [60, 70],
        7 => [95, 50],
        8 => [40, 40],
    ];

    private const COMPLIANCE = '$P_TRA !== null && $P_TRA <= 5 && $P_AV !== null && $P_AV <= 5 ? 20 : 0';

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
                (string)getenv('PHPBB_DB_USER'),
                (string)getenv('PHPBB_DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            self::$skipped = 'No database: ' . $e->getMessage();
            return;
        }
        require_once dirname(__DIR__, 3) . '/src/config.php';
    }

    /** Every test starts from the same fresh event, worked out as the judges' saves would. */
    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
        self::removeFixture();
        self::insertFixture();
        // Saving Tração works out Tração, and then, by itself, Compliance
        // and LVL. Aceleração is saved after, so Compliance sees both.
        self::prova('TRA')->refreshVarsAndPontos();
        self::prova('AVF')->refreshVarsAndPontos();
        self::prova('EXT')->refreshVarsAndPontos();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$database !== null) {
            self::removeFixture();
        }
    }

    // --- Compliance, end to end ------------------------------------------------

    /**
     * The positions are exactly those of the two results pages, ties
     * included: cars 5 and 6 are both 5th in Tração.
     */
    public function testPositionsAreThoseOfTheResultsPages(): void
    {
        $this->assertSame(
            [1 => 1, 2 => 3, 3 => 4, 4 => 7, 5 => 5, 6 => 5, 7 => 2, 8 => 8],
            self::stored('CMP', 'vars', 'P_TRA'),
        );
        $this->assertSame(
            [1 => 1, 2 => 2, 3 => 3, 4 => 6, 5 => 4, 6 => 4, 7 => 7, 8 => 8],
            self::stored('CMP', 'vars', 'P_AV'),
        );
    }

    /**
     * Top 5 of both: 20. Top 5 of only one (car 7: 2nd in Tração, 7th in
     * Aceleração): 0. Tied for 5th (cars 5 and 6): both qualify.
     */
    public function testComplianceGivesTwentyToTheTopFiveOfBoth(): void
    {
        $this->assertSame(
            [1 => 20, 2 => 20, 3 => 20, 4 => 0, 5 => 20, 6 => 20, 7 => 0, 8 => 0],
            self::stored('CMP', 'pontos', 'CMPPTS'),
        );
    }

    // --- Derived provas: no input field, no entry typed -------------------------

    /**
     * SITUATION: Compliance has no input field, so judges never see it and
     * nobody ever made an entry for it. Here every entry it had is deleted,
     * then a judge saves one Tração entry (the same value again).
     *
     * Compliance makes an entry for every PRESENT team (1 to 10) and stores
     * their points: 20 or 0. Car 10, present but with no data anywhere, has
     * no position at all, and gets 0 like anyone outside the top 5. Car 9
     * is disqualified: it gets an entry but, like in every prova, no points.
     * Car 11 is absent: no entry.
     */
    public function testADerivedProvaMakesItsOwnEntriesAfterOneTracaoSave(): void
    {
        self::execute("DELETE FROM input WHERE evento_id = ? AND prova_id IN ('CMP', 'LVL')", [self::EVENT]);
        InputTableMap::clearInstancePool();
        $this->assertSame([], self::stored('CMP', 'pontos', 'CMPPTS', everyTeam: true), 'no entry at all to start with');

        self::saveTracaoEntry(4, 50);

        $this->assertSame(
            [1 => 20, 2 => 20, 3 => 20, 4 => 0, 5 => 20, 6 => 20, 7 => 0, 8 => 0, 9 => null, 10 => 0],
            self::stored('CMP', 'pontos', 'CMPPTS', everyTeam: true),
        );
        $this->assertSame([9 => null, 10 => null], array_slice(self::stored('CMP', 'vars', 'P_TRA', everyTeam: true), 8, null, true), 'no position');
        $this->assertCount(10, self::stored('LVL', 'pontos', 'LVLPTS', everyTeam: true), 'the second level made its entries too');
    }

    // --- Automatic recalculation -----------------------------------------------

    /**
     * SITUATION: a judge corrects car 4's Tração: 85 points instead of 50.
     * Car 4 moves up to 4th, and cars 5 and 6 drop to 6th, out of the top 5.
     *
     * Only Tração is saved, as juiz/save_entry.php does it: the input,
     * then Prova::refreshVarsAndPontos(). Nobody touches Compliance, yet
     * cars 5 and 6 lose their 20 points.
     */
    public function testSavingOneTracaoEntryUpdatesCompliance(): void
    {
        self::saveTracaoEntry(4, 85);

        $this->assertSame([1 => 1, 2 => 3, 3 => 5, 4 => 4, 5 => 6, 6 => 6, 7 => 2, 8 => 8], self::stored('CMP', 'vars', 'P_TRA'));
        $this->assertSame(
            [1 => 20, 2 => 20, 3 => 20, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0],
            self::stored('CMP', 'pontos', 'CMPPTS'),
        );
    }

    /**
     * Two levels: LVL takes its points from the position in the Compliance
     * results page. Before: five cars have 20 and share 1st place, so car 5
     * is 1st. After car 4's Tração is corrected, only three cars have 20,
     * and car 5 is 4th (tied with every car on 0). Saving Tração changed
     * Compliance, and that changed LVL.
     */
    public function testRecalculationGoesThroughTwoLevels(): void
    {
        $this->assertSame(1, self::stored('LVL', 'pontos', 'LVLPTS')[5]);

        self::saveTracaoEntry(4, 85);

        // Car 10 (present, 0 points in Compliance) is tied with the 0s.
        $this->assertSame(
            [1 => 1, 2 => 1, 3 => 1, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => null, 10 => 4],
            self::stored('LVL', 'pontos', 'LVLPTS', everyTeam: true),
        );
    }

    /**
     * The ranking can also change when a resultado's config changes: here
     * Aceleração starts ranking the lowest first ("pos_order": "asc"), as
     * a judge would by saving the resultado's page (juiz/resultado.php).
     * Car 8 is now 1st in Aceleração, car 1 is 8th.
     */
    public function testSavingAResultadoUpdatesTheProvasThatReadIt(): void
    {
        $resultado = ResultadoQuery::create()->filterByEventoId(self::EVENT)->findOneByResultadoId('99PS_AVF');
        $colunas = $resultado->getColunas();
        $colunas->pos_order = 'asc';
        $resultado->setColunas($colunas);
        $resultado->save();
        Recalculation::afterResultadoChanged(self::EVENT, '99PS_AVF');

        $this->assertSame([1 => 8, 2 => 7, 3 => 6, 4 => 3, 5 => 4, 6 => 4, 7 => 2, 8 => 1], self::stored('CMP', 'vars', 'P_AV'));
    }

    /**
     * A cycle saved straight into the database, past the checks: CMP takes
     * a position from the Geral, which includes CMP. Working the points out
     * must still finish (each prova runs once), not loop forever.
     */
    public function testACycleInTheDatabaseDoesNotLoopForever(): void
    {
        self::execute(
            "UPDATE prova SET params = JSON_SET(params, '$.posicoes.P_GER', JSON_OBJECT('resultado', '99PS_GER')) WHERE evento_id = ? AND prova_id = 'CMP'",
            [self::EVENT]
        );
        ProvaTableMap::clearInstancePool();  // the test changed it behind Propel's back

        self::saveTracaoEntry(4, 85);

        // It finished, and CMP was worked out with the new Tração.
        $this->assertSame(4, self::stored('CMP', 'vars', 'P_TRA')[4]);
    }

    // --- Computing is read-only --------------------------------------------------

    /**
     * The results snapshots, the config check and the formula comparison
     * work every prova out again to see what it gives, and must never
     * write: they are pointed at copies of production, and CI runs them as
     * an account that may only read.
     *
     * SITUATION: car 4's Tração was changed in the database but not worked
     * out yet, and car 10's Compliance entry is missing. Saving would
     * rewrite Tração's points, then Compliance's and LVL's (the dependents),
     * and make car 10's entry. Here MySQL itself is told to refuse every
     * write (as the snapshot tool does), and each tool works out every
     * prova of the event. None of them fails on a refused write, and the
     * database is exactly as before.
     */
    public function testComputingAProvaReadOnlyNeverWrites(): void
    {
        self::execute("UPDATE input SET dados = JSON_OBJECT('PTS', 85) WHERE evento_id = ? AND prova_id = 'TRA' AND equipe_id = 4", [self::EVENT]);
        self::execute("DELETE FROM input WHERE evento_id = ? AND prova_id = 'CMP' AND equipe_id = 10", [self::EVENT]);
        InputTableMap::clearInstancePool();
        $before = self::fingerprint();

        ReadOnlyDatabase::enforce();
        try {
            $source = new LiveSource();
            foreach (['TRA', 'AVF', 'CMP', 'LVL', 'EXT', 'USE'] as $prova) {
                $snapshot = FormulasJob::run(self::EVENT, $prova);
                $this->assertNull($snapshot['recompute_error'], "snapshot of $prova");
                Command::checkProva($source, self::EVENT, $prova);
                Units::prova($source, new Recorder(time(), []), self::EVENT, $prova);
                ProvaTableMap::clearInstancePool();
                InputTableMap::clearInstancePool();
            }
            // It did work Tração out: car 4's new points differ from those stored.
            $this->assertSame([4], FormulasJob::run(self::EVENT, 'TRA')['differs_from_stored']['pontos']);
        } finally {
            Propel::getWriteConnection('resultados')->exec('SET SESSION transaction_read_only = OFF');
        }

        $this->assertSame($before, self::fingerprint(), 'nothing was written');
    }

    // --- Cycles are refused on save ------------------------------------------------

    /**
     * CMP's points would depend on the Geral, whose ranking adds up CMP's
     * points: they could never be worked out. The prova page refuses to
     * save it, and says why with the whole chain.
     */
    public function testACycleIsRefusedWhenSavingAProva(): void
    {
        $params = self::prova('CMP')->getParams();
        $params->posicoes->P_GER = (object)['resultado' => '99PS_GER'];

        $errors = PosicoesRules::errorsForProva(EventGraph::load(self::EVENT), 'CMP', $params);

        $this->assertSame(['Ciclo: CMP → 99PS_GER → CMP. Os pontos desta prova dependeriam deles mesmos.'], $errors);
    }

    /**
     * The same cycle, made from the other side: adding CMP to the Inputs of
     * the Tração resultado, which CMP takes a position from. The resultado
     * page refuses it. A longer chain: with LVL in its Inputs, 99PS_TRA would
     * add up LVL, which reads 99PS_CMP, which adds up CMP, which reads 99PS_TRA.
     */
    public function testACycleIsRefusedWhenSavingAResultado(): void
    {
        $graph = EventGraph::load(self::EVENT);
        $colunas = ResultadoQuery::create()->filterByEventoId(self::EVENT)->findOneByResultadoId('99PS_TRA')->getColunas();

        $this->assertSame(
            ['Ciclo: 99PS_TRA → CMP → 99PS_TRA. Os pontos de uma prova dependeriam deles mesmos.'],
            PosicoesRules::errorsForResultado($graph, '99PS_TRA', ['TRA', 'CMP'], $colunas),
        );
        $this->assertSame(
            ['Ciclo: 99PS_TRA → LVL → 99PS_CMP → CMP → 99PS_TRA. Os pontos de uma prova dependeriam deles mesmos.'],
            PosicoesRules::errorsForResultado($graph, '99PS_TRA', ['TRA', 'LVL'], $colunas),
        );
        $this->assertSame([], PosicoesRules::errorsForResultado($graph, '99PS_TRA', ['TRA', 'AVF'], $colunas), 'no cycle: saved');
    }

    /** A resultado that does not exist in the event is refused too. */
    public function testAnUnknownResultadoIsRefused(): void
    {
        $params = (object)['posicoes' => (object)['P' => (object)['resultado' => '26BR_TRA']]];

        $this->assertSame(
            ['posicoes.P: o resultado 26BR_TRA não existe neste evento.'],
            PosicoesRules::errorsForProva(EventGraph::load(self::EVENT), 'NEW', $params),
        );
    }

    // --- The referenced ranking's own rules ---------------------------------------

    /**
     * Prova EXT and its resultado 99PS_EXT have every ranking rule at once:
     *
     *   car   V    TB   OK   position
     *    9    90    -    1   1   DISQUALIFIED, and still ranked, as on the results page
     *    1    50    3    1   2   tie on V, broken by the tiebreak TB (higher first)...
     *    2    50    1    1   3   ...
     *    5    40    2    1   4   tie on V: a car with a tiebreak goes first
     *    4    40    -    1   5
     *    7    30    5    1   6   still tied after the tiebreak: share 6th
     *    8    30    5    1   6
     *    3    70    -    0   -   left out by the resultado's "filter": OK
     *    6     -    -    1   -   no value: no position
     *
     * Prova USE takes the position from it. Its $P is the "Pos" column of
     * the results page, team by team, as the judges see it.
     */
    public function testExternalPositionsFollowEveryRuleOfTheResultsPage(): void
    {
        $expected = [1 => 2, 2 => 3, 3 => null, 4 => 5, 5 => 4, 6 => null, 7 => 6, 8 => 6, 9 => 1, 10 => null];
        $this->assertSame($expected, self::stored('USE', 'vars', 'P', everyTeam: true));

        // The same, read off the results page as the builder makes it.
        $resultado = ResultadoQuery::create()->filterByEventoId(self::EVENT)->findOneByResultadoId('99PS_EXT');
        $config = ResultsConfig::fromColunas($resultado->getColunas());
        $page = [];
        foreach (ResultsBuilder::buildFor($resultado, $config, ViewerContext::premiacao())->rows as $row) {
            $page[(int)$row->team->number] = $row->cell('Pos')->raw;
        }
        foreach ($expected as $car => $position) {
            $this->assertSame($position, $page[$car] ?? null, "car $car");
        }
        $positions = ExternalPositions::ofResultado(self::EVENT, '99PS_EXT');
        ksort($positions);
        $this->assertSame(array_filter($expected), $positions, 'teams with no position are left out');
    }

    // --- Helpers ---------------------------------------------------------------

    /** Every row of the event's provas and entries, as text, to tell whether anything changed. */
    private static function fingerprint(): string
    {
        $rows = [];
        foreach (["SELECT prova_id, params, totals, modificado FROM prova WHERE evento_id = ? ORDER BY prova_id",
                  "SELECT prova_id, equipe_id, dados, vars, pontos FROM input WHERE evento_id = ? ORDER BY prova_id, equipe_id"] as $sql) {
            $statement = self::$database->prepare($sql);
            $statement->execute([self::EVENT]);
            $rows[] = $statement->fetchAll(PDO::FETCH_NUM);
        }
        return json_encode($rows);
    }

    /** What juiz/save_entry.php does: store what the judge typed, then work the prova out. */
    private static function saveTracaoEntry(int $car, int $points): void
    {
        $input = InputQuery::create()->filterByEventoId(self::EVENT)->filterByProvaId('TRA')->findOneByEquipeId($car);
        $input->setDados(['PTS' => $points]);
        $input->save();
        self::prova('TRA')->refreshVarsAndPontos();
    }

    private static function prova(string $id): \Baja\Model\Prova
    {
        return ProvaQuery::create()->filterByEventoId(self::EVENT)->findOneByProvaId($id);
    }

    /**
     * One stored value of each team in a prova, read back from the database:
     * of cars 1 to 8 (the table above), or of every team that has an entry.
     *
     * @param 'vars'|'pontos' $column
     * @return array<int, mixed> car => value
     */
    private static function stored(string $prova, string $column, string $name, bool $everyTeam = false): array
    {
        $statement = self::$database->prepare("SELECT equipe_id, $column FROM input WHERE evento_id = ? AND prova_id = ? ORDER BY equipe_id");
        $statement->execute([self::EVENT, $prova]);
        $values = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$car, $json]) {
            if ($everyTeam || isset(self::POINTS[(int)$car])) {
                $values[(int)$car] = json_decode((string)$json, true)[$name] ?? null;
            }
        }
        return $values;
    }

    // --- The made-up event ---------------------------------------------------------

    /**
     * Event 99PS: not finished and not active, so the results snapshots
     * (which only look at finished events) never see it.
     */
    private static function insertFixture(): void
    {
        self::execute(
            "INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers) VALUES (?, 'Fixture BAJAAPP-102', 'Evento de teste (posições)', 0, 2099, 0, 0, 0)",
            [self::EVENT]
        );
        // Car 9 is disqualified, car 10 present with no data, car 11 absent.
        foreach (range(1, 11) as $car) {
            self::execute(
                "INSERT INTO equipe (evento_id, equipe_id, escola, equipe, estado, desclassificado, presente) VALUES (?, ?, ?, ?, 'SP', ?, ?)",
                [self::EVENT, $car, 'ESCOLA DE TESTE ' . $car, 'EQUIPE TESTE ' . $car, $car === 9 ? 1 : 0, $car === 11 ? 0 : 1]
            );
        }

        $points = ['inputs' => [['code' => 'PTS', 'name' => 'Pontos', 'type' => 'number']]];
        self::insertProva('TRA', $points + ['pontos' => ['TRAPTS' => '$PTS']]);
        self::insertProva('AVF', $points + ['pontos' => ['AVPTS' => '$PTS']]);
        self::insertProva('CMP', [
            'posicoes' => ['P_TRA' => ['resultado' => '99PS_TRA'], 'P_AV' => ['resultado' => '99PS_AVF']],
            'pontos' => ['CMPPTS' => self::COMPLIANCE],
        ]);
        self::insertProva('LVL', ['posicoes' => ['P_CMP' => ['resultado' => '99PS_CMP']], 'pontos' => ['LVLPTS' => '$P_CMP']]);
        foreach (self::POINTS as $car => [$tracao, $aceleracao]) {
            self::insertInput('TRA', $car, ['PTS' => $tracao]);
            self::insertInput('AVF', $car, ['PTS' => $aceleracao]);
            // Nothing for CMP and LVL: they make their own entries.
        }

        self::insertResultado('99PS_TRA', ['TRA'], '$TRAPTS');
        self::insertResultado('99PS_AVF', ['AVF'], '$AVPTS');
        self::insertResultado('99PS_CMP', ['CMP'], '$CMPPTS');
        self::insertResultado('99PS_GER', ['TRA', 'AVF', 'CMP'], '$TRAPTS + $AVPTS + $CMPPTS');

        // The ranking rules at once (testExternalPositionsFollowEveryRuleOfTheResultsPage).
        self::insertProva('EXT', ['vars' => ['OK' => '$IN_OK'], 'pontos' => ['EXTPTS' => '$V']]);
        self::insertProva('USE', ['posicoes' => ['P' => ['resultado' => '99PS_EXT']], 'pontos' => ['USEPTS' => '$P']]);
        $ext = [
            1 => ['V' => 50, 'TB' => 3, 'IN_OK' => 1],
            2 => ['V' => 50, 'TB' => 1, 'IN_OK' => 1],
            3 => ['V' => 70, 'IN_OK' => 0],
            4 => ['V' => 40, 'IN_OK' => 1],
            5 => ['V' => 40, 'TB' => 2, 'IN_OK' => 1],
            6 => ['V' => null, 'IN_OK' => 1],
            7 => ['V' => 30, 'TB' => 5, 'IN_OK' => 1],
            8 => ['V' => 30, 'TB' => 5, 'IN_OK' => 1],
            9 => ['V' => 90, 'IN_OK' => 1],
        ];
        foreach ($ext as $car => $dados) {
            // Points are never worked out for a disqualified team: car 9
            // keeps those it had before it was disqualified.
            self::insertInput('EXT', $car, $dados, $car === 9 ? ['EXTPTS' => 90] : []);
        }
        self::insertResultado('99PS_EXT', ['EXT'], '$EXTPTS', ['tiebreak' => '$TB', 'filter' => 'OK']);
    }

    private static function insertProva(string $id, array $params): void
    {
        self::execute(
            "INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES (?, ?, ?, 1, ?, '{}')",
            [self::EVENT, $id, 'Prova ' . $id, json_encode($params)]
        );
    }

    private static function insertInput(string $prova, int $car, array $dados, array $pontos = []): void
    {
        self::execute(
            "INSERT INTO input (evento_id, prova_id, equipe_id, dados, vars, pontos) VALUES (?, ?, ?, ?, '{}', ?)",
            [self::EVENT, $prova, $car, json_encode((object)$dados), json_encode((object)$pontos)]
        );
    }

    /** A resultado ranked by its "Pontos" column, highest first. */
    private static function insertResultado(string $id, array $provas, string $pontos, array $more = []): void
    {
        $colunas = [
            'pos' => 'Pontos',
            'colunas' => [
                ['header' => 'Pos', 'val' => 'Pos'],
                ['header' => 'Equipe', 'val' => 'Equipe'],
                ['header' => 'Pontos', 'val' => $pontos],
            ],
        ] + $more;
        // Inputs is stored the way Propel stores a list: "| TRA | AVF |".
        self::execute(
            'INSERT INTO resultado (resultado_id, evento_id, nome, inputs, colunas) VALUES (?, ?, ?, ?, ?)',
            [$id, self::EVENT, 'Resultado ' . $id, '| ' . implode(' | ', $provas) . ' |', json_encode($colunas)]
        );
    }

    private static function removeFixture(): void
    {
        // Every other row of the fixture hangs off the event, ON DELETE CASCADE.
        self::execute('DELETE FROM evento WHERE evento_id = ?', [self::EVENT]);
        // Propel keeps the objects it loaded; forget them, they are gone.
        ProvaTableMap::clearInstancePool();
        InputTableMap::clearInstancePool();
        ResultadoTableMap::clearInstancePool();
    }

    private static function execute(string $sql, array $params): void
    {
        self::$database->prepare($sql)->execute($params);
    }
}
