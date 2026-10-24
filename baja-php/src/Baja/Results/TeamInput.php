<?php
declare(strict_types=1);

namespace Baja\Results;

use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\Resultado;

/**
 * One team's input for one prova (a row of the "input" table, joined with
 * the team), which is everything the builder reads from the database.
 *
 *   dados   what the judges typed. Either the variables of one run, or
 *           {"entries": [run, run, ...]} for a prova with several runs.
 *   vars    variables the prova's formulas worked out from dados
 *   pontos  the points the prova's formulas gave
 *
 * dados, vars and pontos are the decoded JSON, as Input's getters return it.
 * The tests build these by hand; the pages get them from forResultado().
 */
final class TeamInput
{
    public function __construct(
        public readonly mixed $teamId,
        public readonly mixed $number,
        public readonly mixed $name,
        public readonly mixed $school,
        public readonly mixed $state,
        public readonly mixed $disqualified,
        public readonly mixed $dados,
        public readonly mixed $vars = null,
        public readonly mixed $pontos = null,
    ) {
    }

    /**
     * The inputs of every prova the resultado is made of ("inputs"), in the
     * order the database returns them.
     *
     * @return list<self>
     */
    public static function forResultado(Resultado $resultado): array
    {
        $inputs = InputQuery::create()
            ->filterByEventoId($resultado->getEventoId())
            ->filterByProvaId($resultado->getInputs())
            ->leftJoinEquipe()
            ->withColumn('Equipe.Escola')
            ->withColumn('Equipe.EquipeId')
            ->withColumn('Equipe.Equipe')
            ->withColumn('Equipe.Estado')
            ->withColumn('Equipe.Desclassificado')
            ->find();

        $teamInputs = [];
        foreach ($inputs as $input) {
            $teamInputs[] = self::fromInput($input);
        }
        return $teamInputs;
    }

    private static function fromInput(Input $input): self
    {
        return new self(
            $input->getEquipeId(),
            $input->getEquipeEquipeId(),
            $input->getEquipeEquipe(),
            $input->getEquipeEscola(),
            $input->getEquipeEstado(),
            $input->getEquipeDesclassificado(),
            $input->getDados(),
            $input->getVars(),
            $input->getPontos(),
        );
    }
}
