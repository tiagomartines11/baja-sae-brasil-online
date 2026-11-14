<?php
declare(strict_types=1);

namespace Baja\Tests\Prova\Db;

use Baja\Tests\Contagem\Db\HttpBrowser;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * An organiser saving a config with repeated groups (BAJAAPP-103), through
 * the REAL admin pages: juiz/prova.php and juiz/resultado.php.
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * An organiser types a prova's config (JSON) in juiz/prova.php and presses
 * "Atualizar". A repeated group in it ({"repetir": 6, "inputs": [...]}) can
 * be wrong in ways the JSON itself does not show: "repetir" of 0 or "6"
 * (text), a group with no fields, a group inside a group, a code that
 * clashes with another once the group is written out. Such a config is NOT
 * saved: the page shows what is wrong and gives the text back to fix. A
 * correct one is saved as typed (the group stays a group in the config).
 * The same goes for a resultado's repeated column in juiz/resultado.php.
 *
 * The rules themselves are tested in tests/Unit/Repeat; this checks that
 * the pages apply them.
 *
 * WHAT EVERY TEST STARTS WITH
 *
 * A made-up event 03SE with an empty prova TRG; the seeded admin account
 * "superadmin" logs in. Removed afterwards.
 *
 * HOW TO RUN
 *
 * Needs the dev stack running, and writes, so only with BAJA_TEST_DB=1:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite prova-db
 */
final class RepeatedGroupsConfigSaveTest extends TestCase
{
    use HttpBrowser;

    private const EVENT = '03SE';
    private const PROVA_PAGE = '/03SE/prova.php?id=TRG';
    private const NEW_RESULTADO_PAGE = '/03SE/resultado.php?novo=true';
    private const RESULTADO = '03SE_TRR';

    private const GOOD_INPUTS = '{"inputs": [{"code": "PRESENTE", "name": "Presente", "type": "enum", "options": ["SIM", "NÃO"]},
        {"repetir": 6, "nome": "Tentativa", "inputs": [{"code": "T", "name": "Tempo [s]", "type": "number", "precision": 3, "xor": "A"},
                                                       {"code": "D", "name": "Distância [m]", "type": "number", "precision": 2, "xor": "A"}]}]}';

    private static ?PDO $database = null;

    private static ?string $skipped = null;

    /** @var array<string, string> */
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
        self::removeFixture();
        self::execute("INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers) VALUES (?, 'Fixture BAJAAPP-103', 'Teste', 0, 2003, 0, 0, 0)", [self::EVENT]);
        self::execute("INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES (?, 'TRG', 'Tração', 1, '{}', '{}')", [self::EVENT]);
        $this->cookies = $this->loginAndGetCookies('superadmin');
    }

    protected function tearDown(): void
    {
        if (self::$database !== null && self::$skipped === null) {
            self::removeFixture();
        }
    }

    /** A correct group is saved, exactly as typed: the config keeps the group, not twelve fields. */
    public function testACorrectGroupIsSavedAsTyped(): void
    {
        $saved = $this->saveProva(self::GOOD_INPUTS);

        $this->assertSame(302, $saved['status'], 'saved, and sent back to the page');
        $params = json_decode(self::selectOne("SELECT params FROM prova WHERE evento_id = ? AND prova_id = 'TRG'", [self::EVENT]), true);
        $this->assertSame(6, $params['inputs'][1]['repetir']);
        $this->assertCount(2, $params['inputs']);
    }

    /**
     * A wrong group is not saved. The page answers with the reasons, one
     * per problem, and the text as it was typed, so the fix is one edit
     * away. The stored config is unchanged.
     */
    public function testAWrongGroupIsNotSaved(): void
    {
        $wrong = '{"inputs": [{"code": "T_2", "name": "Solto"}, {"repetir": 0, "nome": "Tentativa", "inputs": [{"code": "T", "name": "T"}]},
                              {"repetir": 3, "nome": "Volta", "inputs": [{"code": "T", "name": "T"}]}]}';

        $refused = $this->saveProva($wrong);

        $this->assertSame(200, $refused['status']);
        $this->assertStringContainsString('Parâmetros não salvos: há problemas nos grupos repetidos', $refused['body']);
        $this->assertStringContainsString('Grupo &quot;Tentativa&quot;: &quot;repetir&quot; precisa ser um número inteiro de 1 a 20 (recebeu 0).', $refused['body']);
        $this->assertStringContainsString('O código T_2 aparece mais de uma vez', $refused['body']);
        $this->assertStringContainsString(htmlspecialchars('{"code": "T_2", "name": "Solto"}'), $refused['body'], 'the text comes back as typed');
        $this->assertSame('{}', self::selectOne("SELECT params FROM prova WHERE evento_id = ? AND prova_id = 'TRG'", [self::EVENT]));
    }

    /** A resultado with a correct repeated column is created; a wrong one is not, and says why. */
    public function testARepeatedColumnIsCheckedOnSave(): void
    {
        $wrong = $this->saveNewResultado('{"colunas": [{"header": "Tentativas", "repetir": 6, "val": "$T_1"}]}');
        $this->assertSame(200, $wrong['status']);
        $this->assertStringContainsString('Colunas não salvas: há problemas nas colunas repetidas', $wrong['body']);
        $this->assertStringContainsString('precisa usar {n}', $wrong['body']);
        $this->assertSame('0', self::selectOne('SELECT COUNT(*) FROM resultado WHERE resultado_id = ?', [self::RESULTADO]), 'not created');

        $good = $this->saveNewResultado('{"colunas": [{"header": "Tentativas", "repetir": 6, "val": "$T_{n} ? ROUND($T_{n}, 3) : \'-\'", "sort": "duasmenor"}]}');
        $this->assertSame(302, $good['status']);
        $this->assertSame('1', self::selectOne('SELECT COUNT(*) FROM resultado WHERE resultado_id = ?', [self::RESULTADO]));
    }

    // ------------------------------------------------------------------

    private function saveProva(string $params): array
    {
        $page = $this->get(self::PROVA_PAGE, $this->cookies);
        $this->assertSame(200, $page['status'], 'superadmin opens the prova page');
        return $this->postForm(self::PROVA_PAGE, ['_csrf' => $this->tokenIn($page['body']), 'act' => 'Atualizar', 'nome' => 'Tração', 'params' => $params]);
    }

    private function saveNewResultado(string $colunas): array
    {
        $page = $this->get(self::NEW_RESULTADO_PAGE, $this->cookies);
        return $this->postForm(self::NEW_RESULTADO_PAGE, [
            '_csrf' => $this->tokenIn($page['body']), 'act' => 'Salvar', 'resultado_id' => self::RESULTADO, 'nome' => 'Tração', 'inputs' => 'TRG', 'colunas' => $colunas,
        ]);
    }

    private function postForm(string $path, array $fields): array
    {
        return $this->request('POST', $path, $this->cookies, http_build_query($fields), self::JUIZ_SITE, ['Content-Type: application/x-www-form-urlencoded']);
    }

    private function tokenIn(string $html): string
    {
        $this->assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $found), 'the page has no form token');
        return $found[1];
    }

    private static function removeFixture(): void
    {
        self::execute('DELETE FROM resultado WHERE resultado_id = ?', [self::RESULTADO]);
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
