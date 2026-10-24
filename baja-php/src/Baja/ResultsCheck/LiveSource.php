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
     * The event's resultados, each with its columns twice: decoded, to check,
     * and exactly as stored, for the guard of the SQL fix.
     *
     * @return list<array{resultado: string, nome: string, colunas: ?string, inputs: list<TeamInput>}>
     */
    public function resultados(string $event): array
    {
        // Resultado::getColunas() decodes; the stored text is read here.
        $statement = Propel::getReadConnection('resultados')->prepare('SELECT resultado_id, colunas FROM resultado WHERE evento_id = :evento');
        $statement->execute([':evento' => $event]);
        $stored = $statement->fetchAll(PDO::FETCH_KEY_PAIR);

        $resultados = [];
        foreach (ResultadoQuery::create()->filterByEventoId($event)->orderByResultadoId()->find() as $resultado) {
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
     * The event's provas, each with its inputs as Prova::refreshVarsAndPontos()
     * selects them (those that have a team), in team order.
     *
     * @return list<array{prova: \Baja\Model\Prova, inputs: iterable<\Baja\Model\Input>}>
     */
    public function provas(string $event): array
    {
        $provas = [];
        foreach (ProvaQuery::create()->filterByEventoId($event)->orderByProvaId()->find() as $prova) {
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
