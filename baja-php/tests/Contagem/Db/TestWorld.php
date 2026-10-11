<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\RedisClient;
use Baja\Model\ContagemFonte;
use Propel\Runtime\Propel;

/**
 * Builds and removes the small made-up world every database test runs in
 * (see DbTestCase for what it contains and why).
 *
 * Kept apart from DbTestCase so that the test base class is only about
 * what a test can DO, and this class only about what EXISTS before it
 * starts.
 */
final class TestWorld
{
    public const EVENTO_ID = 'ZZT1';
    public const PROVA_ID = 'ZZL';

    /** Every test user's name starts with this, and users are deleted by this prefix. */
    public const USER_PREFIX = 'zzt_';

    /** Car numbers of the three made-up teams. */
    public const CARS = [11, 22, 33];

    private static bool $databaseBooted = false;

    public static function isBooted(): bool
    {
        return self::$databaseBooted;
    }

    /** Connects to the database. Does the work once; later calls return at once. */
    public static function boot(): void
    {
        if (self::$databaseBooted) {
            return;
        }
        // The same file the web application loads: it tells Propel (the
        // database library) where the database is.
        require_once __DIR__ . '/../../../src/config.php';

        // Score formulas are evaluated by PHP itself and mention these bare
        // words; the application defines them in src/bootstrap.php.
        foreach (['Pos', 'EquipeNum', 'Equipe'] as $constant) {
            if (!defined($constant)) {
                define($constant, $constant);
            }
        }

        self::$databaseBooted = true;
    }

    /**
     * Makes Propel forget every row object it has loaded, which is what the
     * end of a web request does.
     *
     * Propel (the database library) remembers each row it loads and hands
     * the same object out again when the row is asked for a second time.
     * The application relies on that: Prova::refreshVarsAndPontos() writes
     * the prova's totals and then every score row reads them back through
     * "its" prova, which must be that very same object. So the memory has
     * to stay switched on, exactly as in production.
     *
     * But one test plays several requests in a row, and changes rows with
     * plain SQL in between. Without this, the second "request" would be
     * handed objects from the first one, with old values.
     */
    public static function forgetLoadedRows(): void
    {
        foreach (Propel::getServiceContainer()->getDatabaseMap('resultados')->getTables() as $tableMap) {
            $tableMap::clearInstancePool();
        }
    }

    /**
     * Creates the event, its three teams, the laps prova and the three
     * manual sources.
     *
     * @return ContagemFonte[] the sources: [0] Contagem 1, [1] Contagem 2, [2] Contagem 3
     */
    public static function create(): array
    {
        $connection = Propel::getWriteConnection('resultados');

        // "ativo = 0" keeps the made-up event out of every public list
        // while the test runs.
        $connection->prepare(
            'INSERT INTO evento (evento_id, titulo, nome, ano, ativo) VALUES (?, ?, ?, ?, 0)'
        )->execute([self::EVENTO_ID, 'Teste de contagem', 'Evento de teste da contagem de voltas', 1999]);

        $insertTeam = $connection->prepare(
            'INSERT INTO equipe (evento_id, equipe_id, escola, escola_curto, cidade, equipe, equipe_curto, estado, presente)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        foreach (self::CARS as $car) {
            $insertTeam->execute([
                self::EVENTO_ID, $car, 'Escola ' . $car, 'Esc ' . $car, 'Cidade', 'Equipe ' . $car, 'Eq ' . $car, 'SP',
            ]);
        }

        $params = [
            'type' => 'laps',
            'inputs' => [
                ['code' => 'VOLTAS', 'name' => 'Voltas', 'type' => 'number', 'precision' => 0],
                ['code' => 'LAST', 'name' => 'Última passagem (s)', 'type' => 'number', 'precision' => 0],
                ['code' => 'BEST', 'name' => 'Melhor volta (s)', 'type' => 'number', 'precision' => 0],
                ['code' => 'DESCLAS', 'name' => 'Desclassificado', 'type' => 'enum', 'options' => ['SIM', 'NÃO']],
            ],
            // The same formula the real Enduro uses: the leader gets 400,
            // the others in proportion to their laps, a disqualified car 0.
            'pontos' => ['ENDPTS' => "\$DESCLAS=='SIM'?0:(400 * \$VOLTAS / \$Vmax)"],
            'min_max' => ['V' => '$VOLTAS'],
        ];
        $connection->prepare(
            'INSERT INTO prova (evento_id, prova_id, nome, status, params) VALUES (?, ?, ?, 0, ?)'
        )->execute([self::EVENTO_ID, self::PROVA_ID, 'Enduro de teste', json_encode($params)]);

        $fontes = [];
        foreach ([1, 2, 3] as $number) {
            $fonte = new ContagemFonte();
            $fonte->setEventoId(self::EVENTO_ID);
            $fonte->setProvaId(self::PROVA_ID);
            $fonte->setNome('Contagem ' . $number);
            $fonte->setTipo('manual');
            $fonte->setQualidadeTempo('humana');
            $fonte->setAtiva(true);
            $fonte->setOrdem($number);
            $fonte->save();
            $fontes[] = $fonte;
        }
        return $fontes;
    }

    /**
     * Removes everything create() and the tests made. Deleting the event
     * is enough for the database side: the prova, its sources, entries,
     * laps and scores are linked to it with ON DELETE CASCADE.
     */
    public static function delete(): void
    {
        $connection = Propel::getWriteConnection('resultados');
        $connection->prepare('DELETE FROM evento WHERE evento_id = ?')->execute([self::EVENTO_ID]);
        // The backslash makes the underscore literal; a bare "_" in LIKE
        // means "any one character".
        $connection->prepare('DELETE FROM `user` WHERE username LIKE ?')->execute(['zzt\\_%']);

        // The review screens' version counter and the like live in Redis,
        // outside the database, so they are cleared separately.
        $redis = RedisClient::get();
        if ($redis !== null) {
            $prefix = 'contagem:' . self::EVENTO_ID . ':' . self::PROVA_ID . ':';
            $redis->del($prefix . 'versao', $prefix . 'publicado_em_ms', $prefix . 'impressao', $prefix . 'feed');
        }
    }
}
