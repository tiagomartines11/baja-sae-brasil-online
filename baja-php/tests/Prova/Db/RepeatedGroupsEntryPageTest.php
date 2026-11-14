<?php
declare(strict_types=1);

namespace Baja\Tests\Prova\Db;

use Baja\Tests\Prova\Db\Support\JuizPages;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The judge's entry page for a prova with a repeated group (BAJAAPP-103),
 * run for real: the page a judge opens, and the save.
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
 * n+1 is locked until attempt n is complete. These tests run the real page
 * files against a real database and check what a browser would get: the
 * HTML, the status code, and where the browser is sent after a save. The
 * blocks' logic itself is tested without a database in
 * tests/Unit/Juiz/RepeatedGroupsEntryTest.
 *
 * HOW THE PAGES RUN
 *
 * Each request runs the page in a PHP process of its own, as php-fpm does,
 * with the judge logged in and the form's security token working, but no
 * web server and no forum (Support/JuizPages and run-juiz-page.php). So
 * the tests run in CI, which has a database but no web server.
 *
 * WHAT EVERY TEST STARTS WITH
 *
 * A made-up event 03SE (it never existed) with the prova TRG, team 7, whose
 * stored data says it is present and ran attempt 1 in 4.512 s, and a judge
 * account "zz103_juiz" allowed to score 03SE_TRG. The fields are marked
 * "sensitive", so a save sends no push notification from a test. All of it
 * is removed afterwards.
 *
 * HOW TO RUN
 *
 * It writes, so only with BAJA_TEST_DB=1:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite prova-db
 *
 * It connects as PHPBB_DB_USER, or as BAJA_TEST_DB_USER / BAJA_TEST_DB_PASS
 * when set (CI sets them, because its usual account may only read).
 */
final class RepeatedGroupsEntryPageTest extends TestCase
{
    use JuizPages;

    private const EVENT = '03SE';
    private const PROVA = 'TRG';
    private const TEAM = 7;
    private const JUDGE = 'zz103_juiz';
    private const ENTRY = ['p' => 'TRG', 't' => '7'];

    private static ?PDO $database = null;

    private static ?string $skipped = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if (getenv('BAJA_TEST_DB') !== '1' || $host === false || $host === '') {
            self::$skipped = 'Writes a test event: set BAJA_TEST_DB=1 and the database variables (see the class comment).';
            return;
        }
        // The pages (through Propel) connect as PHPBB_DB_USER. Point them at
        // the test account too, when one is given; the page processes inherit it.
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
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
        self::removeFixture();  // left over from a run that was interrupted
        self::insertFixture();
    }

    protected function tearDown(): void
    {
        if (self::$database !== null && self::$skipped === null) {
            self::removeFixture();
        }
    }

    /**
     * The page: one block per attempt, six of them, in order, each with its
     * title, its two fields named after it, and its own buttons. Its state
     * at a glance: attempt 1 is filled, 2 is open, 3 to 6 are locked.
     */
    public function testThePageShowsOneBlockPerAttempt(): void
    {
        $page = $this->openEntryPage();

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
        $third = self::block($this->openEntryPage()['body'], 'repeticao-1-3');

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
        $token = $this->formTokenIn($this->openEntryPage()['body']);

        $saved = $this->save(['_csrf' => $token, 'submit' => 'repeticao-1-2', 'PRESENTE' => 'SIM', 'T_1' => '4.512', 'D_2' => '12.25']);

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
        $body = $this->openEntryPage()['body'];
        $this->assertMatchesRegularExpression('/id="repeticao-1-2"[^>]*data-estado="preenchida"/', $body);
        $this->assertMatchesRegularExpression('/id="repeticao-1-3"[^>]*data-estado="vazia"/', $body);
    }

    /** The old buttons still do what they did: plain Salvar goes back to the list of teams. */
    public function testThePlainSalvarStillGoesToTheList(): void
    {
        $saved = $this->save(['_csrf' => $this->formTokenIn($this->openEntryPage()['body']), 'submit' => 'Salvar', 'PRESENTE' => 'SIM', 'T_1' => '4.4']);

        $this->assertSame(302, $saved['status']);
        $this->assertSame('dashboard.php?p=TRG', $saved['location']);
        $this->assertEquals(4.4, $this->storedDados()['T_1']);
    }

    /**
     * A value for a locked attempt (4, while 2 and 3 are empty) did not
     * come from the page. Nothing is saved; the page comes back with the
     * reason, and the stored data is as it was.
     */
    public function testAValueForALockedAttemptIsRefused(): void
    {
        $before = $this->storedDados();

        $refused = $this->save(['_csrf' => $this->formTokenIn($this->openEntryPage()['body']), 'submit' => 'repeticao-1-4', 'PRESENTE' => 'SIM', 'T_1' => '4.512', 'T_4' => '4.1']);

        $this->assertSame(422, $refused['status']);
        $this->assertStringContainsString('Nada foi salvo', $refused['body']);
        $this->assertStringContainsString('&quot;Tentativa 4&quot; está bloqueada até &quot;Tentativa 3&quot; estar completa', $refused['body']);
        $this->assertSame($before, $this->storedDados());
    }

    /**
     * Without the form's security token nothing is saved (BAJAAPP-41): this
     * shows the token the tests send is really checked, not waved through.
     */
    public function testASaveWithoutTheFormTokenIsRefused(): void
    {
        $before = $this->storedDados();

        $refused = $this->save(['submit' => 'repeticao-1-2', 'PRESENTE' => 'SIM', 'T_1' => '4.512', 'D_2' => '12.25']);

        $this->assertSame(403, $refused['status']);
        $this->assertSame($before, $this->storedDados());
    }

    // ------------------------------------------------------------------

    /** @return array{status: int, location: string, body: string} */
    private function openEntryPage(): array
    {
        return $this->openPage(self::EVENT, 'entry.php', self::JUDGE, self::ENTRY);
    }

    /** @return array{status: int, location: string, body: string} */
    private function save(array $fields): array
    {
        return $this->submitForm(self::EVENT, 'save_entry.php', self::JUDGE, self::ENTRY, $fields);
    }

    /** One block's HTML, from its opening to the next block. */
    private static function block(string $body, string $anchor): string
    {
        $start = strpos($body, 'id="' . $anchor . '"');
        $end = strpos($body, '</tbody>', (int)$start);
        return substr($body, (int)$start, (int)$end - (int)$start);
    }

    /** @return array<string, mixed> the team's stored dados */
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
                ['code' => 'PRESENTE', 'name' => 'Presente', 'type' => 'enum', 'options' => ['SIM', 'NÃO'], 'sensitive' => true],
                ['repetir' => 6, 'nome' => 'Tentativa', 'inputs' => [
                    ['code' => 'T', 'name' => 'Tempo [s]', 'type' => 'number', 'precision' => 3, 'xor' => 'A', 'sensitive' => true],
                    ['code' => 'D', 'name' => 'Distância [m]', 'type' => 'number', 'precision' => 2, 'xor' => 'A', 'sensitive' => true],
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
        // As Propel stores a permission list.
        self::execute("INSERT INTO user (username, permissions) VALUES (?, '| index | 03SE_TRG |')", [self::JUDGE]);
    }

    private static function removeFixture(): void
    {
        self::execute('DELETE FROM user WHERE username = ?', [self::JUDGE]);
        // Every other row of the fixture hangs off the event, ON DELETE CASCADE.
        self::execute('DELETE FROM evento WHERE evento_id = ?', [self::EVENT]);
    }

    private static function execute(string $sql, array $params): void
    {
        self::$database->prepare($sql)->execute($params);
    }
}
