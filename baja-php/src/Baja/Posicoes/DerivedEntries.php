<?php
declare(strict_types=1);

namespace Baja\Posicoes;

use Baja\Model\EquipeQuery;
use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\Prova;
use Baja\Repeat\InputGroups;

/**
 * Entries for a DERIVED prova (BAJAAPP-102): one with "posicoes" and no
 * "inputs", such as Compliance, whose points come only from where the teams
 * placed elsewhere.
 *
 * A prova stores points only for the teams that have an entry (an "input"
 * row) in it, and a judge's first save is what makes one. A derived prova
 * has nothing to type, so the judge screen does not show it, and no entry
 * would ever be made. So, when it is worked out
 * (Prova::refreshVarsAndPontos()), it makes its own: an empty entry for
 * every team present at the event, the same teams the judge screen lists
 * for a normal prova. From then on its points are stored like any other
 * prova's.
 *
 * Nothing is ever removed: a team marked absent later keeps its entry, as
 * it would in a normal prova.
 *
 * Only refreshVarsAndPontos() calls this, never the read-only tools (config
 * check, results snapshots, formula comparison), which only compute.
 */
final class DerivedEntries
{
    /**
     * Whether the prova's points come only from its "posicoes": it has some,
     * and no input field. The fields are counted after the repeated input
     * groups are written out (BAJAAPP-103, Baja\Repeat\InputGroups), so a
     * prova whose only "inputs" are a {"repetir": 6, ...} group has fields
     * (T_1 ... T_6) and is a normal prova, not a derived one.
     */
    public static function isDerived(Prova $prova): bool
    {
        $params = $prova->getParams();
        if (PositionSpec::allOf($params) === []) {
            return false;
        }
        $inputs = is_object($params) ? ($params->inputs ?? []) : [];
        return InputGroups::expand($inputs)->inputs === [];
    }

    /** For a derived prova, saves an empty entry for every present team that has none. */
    public static function create(Prova $prova): void
    {
        if (!self::isDerived($prova)) {
            return;
        }
        $have = [];
        foreach (InputQuery::create()->filterByEventoId($prova->getEventoId())->findByProvaId($prova->getProvaId()) as $input) {
            $have[$input->getEquipeId()] = true;
        }
        $teams = EquipeQuery::create()->filterByPresente(true)->findByEventoId($prova->getEventoId());
        foreach ($teams as $team) {
            if (isset($have[$team->getEquipeId()])) {
                continue;
            }
            $input = new Input();
            $input->setEventoId($prova->getEventoId());
            $input->setProvaId($prova->getProvaId());
            $input->setEquipeId($team->getEquipeId());
            $input->setDados((object)[]);
            $input->save();
        }
    }
}
