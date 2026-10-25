<?php
declare(strict_types=1);

namespace Baja\FormulaCompare;

use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\Prova;
use Baja\Results\Column;
use Baja\Results\EventStatus;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\ViewerContext;
use Baja\ResultsCheck\LiveSource;

/**
 * Runs the formulas of one prova or one resultado the way the site runs
 * them, with the Recorder in Input::solveFormula(), so that every formula
 * is compared in its real place, for every team, with the variables it
 * really gets (the earlier columns, the prova's totals...). Nothing is
 * saved; the database is read-only anyway.
 *
 *   prova      Input::updateVars() and updatePontos() for each team, as
 *              Prova::refreshVarsAndPontos() does, with the totals as
 *              stored (as ResultsSnapshot\Jobs\FormulasJob does); and, for
 *              a tournament prova, the seeding formulas, as
 *              juiz/torneio.php runs them
 *   resultado  ResultsBuilder::build() for the whole resultado, as the
 *              public sees a finished event, and again with spoilers on
 *              when a column has a "val_spoilers"
 *   alone      each of a list of formulas once, with no variables
 */
final class Units
{
    /** @return array{teams: int, formulas: array<string, array>} */
    public static function prova(LiveSource $source, Recorder $recorder, string $event, string $id): array
    {
        $prova = $source->provas($event, $id)[0] ?? throw new \RuntimeException('No prova ' . $id . ' in ' . $event);
        $teams = 0;
        self::with($recorder, function () use ($prova, &$teams): void {
            foreach ($prova['inputs'] as $input) {
                /** @var Input $input */
                $teams++;
                $input->updateVars();
                $input->updatePontos();
            }
            self::seeding($prova['prova']);
        });
        return ['teams' => $teams, 'formulas' => $recorder->formulas()];
    }

    /** @return array{teams: int, formulas: array<string, array>} */
    public static function resultado(LiveSource $source, Recorder $recorder, string $event, string $id): array
    {
        $resultado = $source->resultados($event, $id)[0] ?? throw new \RuntimeException('No resultado ' . $id . ' in ' . $event);
        $colunas = json_decode($resultado['colunas'] ?? '');
        if (!is_object($colunas) || ($colunas->type ?? null) === 'tournament') {
            return ['teams' => 0, 'formulas' => []];
        }
        $config = ResultsConfig::fromColunas($colunas);
        $hasSpoilers = array_filter($config->columns, fn(Column $column) => (bool)$column->valSpoilers) !== [];
        $teams = count(array_unique(array_map(fn($input) => $input->teamId, $resultado['inputs'])));
        self::with($recorder, function () use ($config, $resultado, $hasSpoilers): void {
            ResultsBuilder::build($config, $resultado['inputs'], ViewerContext::publicView(new EventStatus(spoilers: false, finished: true)));
            if ($hasSpoilers) {
                ResultsBuilder::build($config, $resultado['inputs'], ViewerContext::publicView(new EventStatus(spoilers: true, finished: false)));
            }
        });
        return ['teams' => $teams, 'formulas' => $recorder->formulas()];
    }

    /**
     * Each formula once, with no variables: for those no team runs.
     *
     * @param list<string> $serialized the formulas, each serialize()d (one may be a number or a list)
     * @return array{teams: int, formulas: array<string, array>}
     */
    public static function alone(Recorder $recorder, array $serialized): array
    {
        self::with($recorder, function () use ($serialized): void {
            foreach ($serialized as $formula) {
                Input::solveFormula([], unserialize($formula, ['allowed_classes' => [\stdClass::class]]));
            }
        });
        return ['teams' => 0, 'formulas' => $recorder->formulas()];
    }

    /**
     * A tournament's seeding formulas ("seeding.able.formula", and each
     * "seeding.classification[].formula" but "random"), for every team,
     * with the variables juiz/torneio.php gives them.
     */
    private static function seeding(Prova $prova): void
    {
        $seeding = $prova->getParams()->seeding ?? null;
        if (!is_object($seeding)) {
            return;
        }
        if (isset($seeding->able->formula)) {
            foreach (self::seedingVariables($prova, $seeding->able->inputs ?? null) as $variables) {
                Input::solveFormula($variables, $seeding->able->formula);
            }
        }
        foreach ((array)($seeding->classification ?? []) as $classification) {
            if (!is_object($classification) || !isset($classification->formula) || $classification->formula == 'random') {
                continue;
            }
            foreach (self::seedingVariables($prova, $classification->inputs ?? null) as $variables) {
                Input::solveFormula($variables, $classification->formula);
            }
        }
    }

    /**
     * juiz/torneio.php's variables for each team: NUM and EQUIPE, then the
     * dados, vars and pontos of the given provas' inputs, later ones winning.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private static function seedingVariables(Prova $prova, mixed $provas): array
    {
        $variables = [];
        $inputs = InputQuery::create()->filterByEventoId($prova->getEventoId())->filterByProvaId($provas)
            ->leftJoinEquipe()->withColumn('Equipe.EquipeId')->withColumn('Equipe.Equipe')
            ->orderByEquipeId()->find();
        foreach ($inputs as $input) {
            $team = $input->getEquipeId();
            $variables[$team] ??= ['NUM' => $input->getVirtualColumn('EquipeEquipeId'), 'EQUIPE' => $input->getVirtualColumn('EquipeEquipe')];
            $variables[$team] = array_merge($variables[$team], (array)$input->getDados(), (array)$input->getVars(), (array)$input->getPontos());
        }
        return $variables;
    }

    private static function with(Recorder $recorder, callable $work): void
    {
        Input::setFormulaEngine($recorder);
        // The formulas print nothing; anything they do print is not ours.
        ob_start();
        try {
            $work();
        } finally {
            ob_end_clean();
            Input::setFormulaEngine(null);
        }
    }
}
