<?php
declare(strict_types=1);

namespace Baja\Tests\Prova\Db;

use Baja\Tests\Contagem\Db\HttpBrowser;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The judge's entry page for a prova with a repeated group (BAJAAPP-103),
 * through the REAL web server: the page a judge opens, and the save.
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * A judge scores one team at a time on juiz/entry.php and presses Salvar;
 * juiz/save_entry.php checks and stores what was sent. Here the prova is
 * Tração with six attempts, written as one repeated group:
 *
 *     PRESENTE, then six times "Tentativa": T_n (time) or D_n (distance)
 *
 * The page shows each attempt as a block with its own Salvar, Anterior,
 * Próxima and a row of numbered buttons to jump to any attempt. Attempt
 * n+1 is locked until attempt n is complete. These tests check that the
 * page really comes out that way and that saving behaves, which only a
 * request through the web server shows (permissions, the form's security
 * token, where the browser is sent afterwards). The blocks' logic itself
 * is tested without a server in tests/Unit/Juiz/RepeatedGroupsEntryTest.
 *
 * WHAT EVERY TEST STARTS WITH
 *
 * A made-up event 03SE (an id the web server routes, /03SE/...; it never
 * existed) with the prova TRG and team 7, whose stored data says it is
 * present and ran attempt 1 in 4.512 s. The seeded judge "juiz1" gets the
 * permission for 03SE_TRG for the length of the test. All of it is removed
 * afterwards.
 *
 * HOW TO RUN
 *
 * Needs the dev stack running, and writes, so only with BAJA_TEST_DB=1:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite prova-db
 */
final class RepeatedGroupsEntryPageTest extends TestCase
{
    use HttpBrowser {
        tearDown as private tearDownHttpBrowser;
    }

    private const EVENT = '03SE';
    private const PROVA = 'TRG';
    private const TEAM = 7;
    private const ENTRY = '/03SE/entry.php?p=TRG&t=7';
    private const SAVE = '/03SE/save_entry.php?p=TRG&t=7';

    private static ?PDO $database = null;

    private static ?string $skipped = null;

    /** @var array<string, string> the judge's cookies, after logging in */
    private array $cookies = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if (getenv('BAJA_TEST_DB') !== '1' || $host === false || $host === '') {
            self::$skipped = 'Writes a test event: set BAJA_TEST_DB=1 and the database variables (see the class comment).';
            return;
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

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
        self::removeFixture();  // left over from a run that was interrupted
        self::insertFixture();
        $this->grant(self::CONTROLLER, self::EVENT . '_' . self::PROVA);
        $this->cookies = $this->loginAndGetCookies(self::CONTROLLER);
    }

    protected function tearDown(): void
    {
        if (self::$database !== null && self::$skipped === null) {
            self::removeFixture();
        }
        // HttpBrowser's own tearDown takes the permission back.
        $this->tearDownHttpBrowser();
    }

    /**
     * The page: one block per attempt, six of them, in order, each with its
     * title, its two fields named after it, and its own buttons. Its state
     * at a glance: attempt 1 is filled, 2 is open, 3 to 6 are locked.
     */
    public function testThePageShowsOneBlockPerAttempt(): void
    {
        $page = $this->get(self::ENTRY, $this->cookies);

        $this->assertSame(200, $page['status']);
        preg_match_all('/<tbody class="repeticao" id="([^"]+)"[^>]*data-estado="([^"]+)"/', $page['body'], $blocks, PREG_SET_ORDER);
        $this->assertSame([
            ['repeticao-1-1', 'preenchida'],
            ['repeticao-1-2', 'vazia'],
            ['repeticao-1-3', 'bloqueada'],
            ['repeticao-1-4', 'bloqueada'],
            ['repeticao-1-5', 'bloqueada'],
            ['repeticao-1-6', 'bloqueada'],
        ], array_map(fn(array $match) => [$match[1], $match[2]], $blocks));

        $this->assertStringContainsString('<label for="T_3">Tempo [s] (Tentativa 3)</label>', $page['body']);
        $this->assertStringContainsString('<label for="D_6">Distância [m] (Tentativa 6)</label>', $page['body']);
        $this->assertMatchesRegularExpression('/<input[^>]+data-group="4" data-xor="A_3"[^>]+name="T_3"/', $page['body']);
        // Every block has its Salvar, and the page loads the script that keeps the states up to date.
        $this->assertSame(6, substr_count($page['body'], 'class="salvar-bloco"'));
        $this->assertMatchesRegularExpression('#<script type="module" src="/js/repeticoes\.js\?v=\d+"></script>#', $page['body']);
        // The field outside the group is drawn as before, outside any block.
        $this->assertMatchesRegularExpression('/<select[^>]+data-group="1" data-xor="X"[^>]+name="PRESENTE"/', $page['body']);
        $this->assertLessThan(strpos($page['body'], 'id="repeticao-1-1"'), strpos($page['body'], 'name="PRESENTE"'));
    }

    /** The navigation of attempt 3: back to 2, on to 4, and a numbered button for each attempt. */
    public function testTheBlockButtonsLeadToTheRightAttempts(): void
    {
        $body = $this->get(self::ENTRY, $this->cookies)['body'];
        $third = self::block($body, 'repeticao-1-3');

        $this->assertStringContainsString('<button type="submit" name="submit" value="repeticao-1-3" class="salvar-bloco" disabled>Salvar</button>', $third);
        $this->assertStringContainsString('href="#repeticao-1-2">◀ Anterior</a>', $third);
        $this->assertStringContainsString('href="#repeticao-1-4">Próxima ▶</a>', $third);
        for ($n = 1; $n <= 6; $n++) {
            $this->assertStringContainsString('href="#repeticao-1-' . $n . '" data-alvo="repeticao-1-' . $n . '"', $third);
        }
    }

    /**
     * SALVAR OF A BLOCK. The judge fills attempt 2 and presses that block's
     * Salvar: it is saved, and the browser is sent back to the same page, at
     * attempt 2 (the "#repeticao-1-2" at the end of the address), not to the
     * list of teams. The data is stored under exactly the keys a
     * hand-written config uses: T_1, D_1, ... D_6.
     */
    public function testSalvarOfABlockSavesAndComesBackToThatBlock(): void
    {
        $token = $this->tokenOfThePage();

        $saved = $this->post(['_csrf' => $token, 'submit' => 'repeticao-1-2', 'PRESENTE' => 'SIM', 'T_1' => '4.512', 'D_2' => '12.25']);

        $this->assertSame(302, $saved['status']);
        $this->assertSame('entry.php?p=TRG&t=7#repeticao-1-2', $saved['location']);
        $dados = $this->storedDados();
        $keys = array_keys($dados);
        sort($keys);  // MySQL keeps a JSON object's keys in an order of its own
        $this->assertSame(['D_1', 'D_2', 'D_3', 'D_4', 'D_5', 'D_6', 'PRESENTE', 'T_1', 'T_2', 'T_3', 'T_4', 'T_5', 'T_6'], $keys);
        $this->assertEquals(12.25, $dados['D_2']);
        $this->assertEquals(4.512, $dados['T_1']);
        $this->assertNull($dados['T_2']);

        // The page now shows attempt 2 filled and attempt 3 open.
        $body = $this->get(self::ENTRY, $this->cookies)['body'];
        $this->assertMatchesRegularExpression('/id="repeticao-1-2"[^>]*data-estado="preenchida"/', $body);
        $this->assertMatchesRegularExpression('/id="repeticao-1-3"[^>]*data-estado="vazia"/', $body);
    }

    /** The old buttons still do what they did: plain Salvar goes back to the list of teams. */
    public function testThePlainSalvarStillGoesToTheList(): void
    {
        $saved = $this->post(['_csrf' => $this->tokenOfThePage(), 'submit' => 'Salvar', 'PRESENTE' => 'SIM', 'T_1' => '4.4']);

        $this->assertSame(302, $saved['status']);
        $this->assertSame('dashboard.php?p=TRG', $saved['location']);
    }

    /**
     * A value for a locked attempt (4, while 2 and 3 are empty) did not
     * come from the page. Nothing is saved; the page comes back with the
     * reason, and the stored data is as it was.
     */
    public function testAValueForALockedAttemptIsRefused(): void
    {
        $before = $this->storedDados();

        $refused = $this->post(['_csrf' => $this->tokenOfThePage(), 'submit' => 'repeticao-1-4', 'PRESENTE' => 'SIM', 'T_1' => '4.512', 'T_4' => '4.1']);

        $this->assertSame(422, $refused['status']);
        $this->assertStringContainsString('Nada foi salvo', $refused['body']);
        $this->assertStringContainsString('&quot;Tentativa 4&quot; está bloqueada até &quot;Tentativa 3&quot; estar completa', $refused['body']);
        $this->assertSame($before, $this->storedDados());
    }

    // ------------------------------------------------------------------

    private function tokenOfThePage(): string
    {
        $body = $this->get(self::ENTRY, $this->cookies)['body'];
        $this->assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]+)"/', $body, $found), 'the page has no form token');
        return $found[1];
    }

    private function post(array $fields): array
    {
        return $this->request('POST', self::SAVE, $this->cookies, http_build_query($fields), self::JUIZ_SITE, ['Content-Type: application/x-www-form-urlencoded']);
    }

    /** One block's HTML, from its opening to the next block. */
    private static function block(string $body, string $anchor): string
    {
        $start = strpos($body, 'id="' . $anchor . '"');
        $end = strpos($body, '</tbody>', (int)$start);
        return substr($body, (int)$start, (int)$end - (int)$start);
    }

    /** @return array<string, mixed> the team's stored dados, in the stored order */
    private function storedDados(): array
    {
        $statement = self::$database->prepare('SELECT dados FROM input WHERE evento_id = ? AND prova_id = ? AND equipe_id = ?');
        $statement->execute([self::EVENT, self::PROVA, self::TEAM]);
        return json_decode((string)$statement->fetchColumn(), true);
    }

    private static function insertFixture(): void
    {
        self::execute(
            "INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers) VALUES (?, 'Fixture BAJAAPP-103', 'Evento de teste (tela do juiz)', 0, 2003, 0, 0, 0)",
            [self::EVENT]
        );
        self::execute(
            "INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES (?, ?, 'Tração', 1, ?, '{}')",
            [self::EVENT, self::PROVA, json_encode(['inputs' => [
                ['code' => 'PRESENTE', 'name' => 'Presente', 'type' => 'enum', 'options' => ['SIM', 'NÃO']],
                ['repetir' => 6, 'nome' => 'Tentativa', 'inputs' => [
                    ['code' => 'T', 'name' => 'Tempo [s]', 'type' => 'number', 'precision' => 3, 'xor' => 'A'],
                    ['code' => 'D', 'name' => 'Distância [m]', 'type' => 'number', 'precision' => 2, 'xor' => 'A'],
                ]],
            ], 'min_max' => ['T' => 'MENOR($T_lista)'], 'pontos' => ['TRAPTS' => '$T ? ROUND(50 * $Tmin / $T, 3) : 0']])]
        );
        self::execute(
            "INSERT INTO equipe (evento_id, equipe_id, escola, equipe, estado, desclassificado) VALUES (?, ?, 'ESCOLA DE TESTE', 'EQUIPE TESTE', 'SP', 0)",
            [self::EVENT, self::TEAM]
        );
        self::execute(
            "INSERT INTO input (evento_id, prova_id, equipe_id, dados, vars, pontos) VALUES (?, ?, ?, ?, '{}', '{}')",
            [self::EVENT, self::PROVA, self::TEAM, json_encode(['PRESENTE' => 'SIM', 'T_1' => 4.512])]
        );
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
}
