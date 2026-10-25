<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Baja\Model\EventoQuery;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\ResultadoQuery;
use Baja\Results\TeamInput;
use PDO;
use Propel\Runtime\Propel;

/**
 * Reads what the check needs from the app's database. Only reads: the
 * script switches the connection to read-only before this is used
 * (ResultsSnapshot\Capture\ReadOnlyDatabase), and nothing here saves.
 */
final class LiveSource
{
    /**
     * Every event's code, or those of $only that exist.
     *
     * @param list<string>|null $only
     * @return list<string>
     */
    public function events(?array $only): array
    {
        $query = EventoQuery::create()->orderByEventoId();
        if ($only !== null) {
            $query->filterByEventoId($only);
        }
        $events = [];
        foreach ($query->find() as $evento) {
            $events[] = (string)$evento->getEventoId();
        }
        return $events;
    }

    /**
     * What the command needs to list the event's work, without loading any
     * team's data: each resultado's columns as stored, each prova's params.
     * Enough to start a child per resultado and prova (IsolatedCheck), and
     * to find a formula in them when a child dies.
     *
     * @return array{resultados: list<array{resultado: string, nome: string, colunas: ?string}>,
     *               provas: list<array{prova: string, code: string, nome: string, params: ?string}>}
     *         code: the prova's id within the event ("APR"), prova: with the event ("18SU_APR")
     */
    public function outline(string $event): array
    {
        $connection = Propel::getReadConnection('resultados');
        $statement = $connection->prepare('SELECT resultado_id AS resultado, nome, colunas FROM resultado WHERE evento_id = :evento ORDER BY resultado_id');
        $statement->execute([':evento' => $event]);
        $resultados = $statement->fetchAll(PDO::FETCH_ASSOC);

        $statement = $connection->prepare("SELECT CONCAT(evento_id, '_', prova_id) AS prova, prova_id AS code, nome, params FROM prova WHERE evento_id = :evento ORDER BY prova_id");
        $statement->execute([':evento' => $event]);
        return ['resultados' => $resultados, 'provas' => $statement->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * The event's resultados (or only the one with id $only), each with its
     * columns twice: decoded, to check, and exactly as stored, for the guard
     * of the SQL fix.
     *
     * @return list<array{resultado: string, nome: string, colunas: ?string, inputs: list<TeamInput>}>
     */
    public function resultados(string $event, ?string $only = null): array
    {
        // Resultado::getColunas() decodes; the stored text is read here.
        $statement = Propel::getReadConnection('resultados')->prepare('SELECT resultado_id, colunas FROM resultado WHERE evento_id = :evento');
        $statement->execute([':evento' => $event]);
        $stored = $statement->fetchAll(PDO::FETCH_KEY_PAIR);

        $query = ResultadoQuery::create()->filterByEventoId($event)->orderByResultadoId();
        if ($only !== null) {
            $query->filterByResultadoId($only);
        }
        $resultados = [];
        foreach ($query->find() as $resultado) {
            $id = (string)$resultado->getResultadoId();
            $resultados[] = [
                'resultado' => $id,
                'nome' => (string)$resultado->getNome(),
                'colunas' => $stored[$id] ?? null,
                'inputs' => TeamInput::forResultado($resultado),
            ];
        }
        return $resultados;
    }

    /**
     * The event's provas (or only the one with id $only, e.g. "APR"), each
     * with its inputs as Prova::refreshVarsAndPontos() selects them (those
     * that have a team), in team order.
     *
     * @return list<array{prova: \Baja\Model\Prova, inputs: iterable<\Baja\Model\Input>}>
     */
    public function provas(string $event, ?string $only = null): array
    {
        $query = ProvaQuery::create()->filterByEventoId($event)->orderByProvaId();
        if ($only !== null) {
            $query->filterByProvaId($only);
        }
        $provas = [];
        foreach ($query->find() as $prova) {
            $inputs = InputQuery::create()
                ->filterByEventoId($event)
                ->joinWithEquipe()
                ->filterByProvaId($prova->getProvaId())
                ->orderByEquipeId()
                ->find();
            $provas[] = ['prova' => $prova, 'inputs' => $inputs];
        }
        return $provas;
    }
}
