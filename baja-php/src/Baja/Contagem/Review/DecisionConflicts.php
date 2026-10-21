<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Model\Prova;

/**
 * The two questions that decide whether a reviewer's click still applies:
 * did somebody already decide the same thing, and are the entries still as
 * the reviewer saw them? Each throws DecisionConflict when the answer is no.
 */
final class DecisionConflicts
{
    /**
     * Refuses a decision that already stands, naming who made it. Asked
     * before the signature: "already decided by maria" is more useful than
     * "the data changed".
     *
     * @throws DecisionConflict
     */
    public static function requireNew(Prova $prova, Decision $decision, EngineInput $input): void
    {
        if ($decision->tipo === Decision::DESFAZER) {
            $undoId = self::activeUndoOf((int) $decision->desfazDecisaoId, $input->decisions);
            if ($undoId !== null) {
                throw DecisionConflict::alreadyDecided(DecisionHistory::usernameOf($prova, $undoId));
            }
        }
        $sameDecisionId = DecisionRules::sameDecisionInForce($decision, $input);
        if ($sameDecisionId !== null) {
            throw DecisionConflict::alreadyDecided(DecisionHistory::usernameOf($prova, $sameDecisionId));
        }
    }

    /**
     * Refuses when the entries the reviewer was looking at are not as the
     * reviewer's screen had them.
     *
     * @throws DecisionConflict
     */
    public static function requireUnchanged(Prova $prova, DecisionRequest $parsed, CarsRecount $recount): void
    {
        if ($parsed->passagemIds === []) {
            // Nothing was being looked at (adding a pass, excluding a source).
            return;
        }
        $current = $recount->signatures->forEntries($parsed->passagemIds);
        if ($parsed->assinatura !== null && hash_equals($current, $parsed->assinatura)) {
            return;
        }
        $newerDecisionId = self::newestDecisionAbout($parsed->passagemIds, $recount->input->decisions, $parsed->ultimaDecisaoId);
        if ($newerDecisionId !== null) {
            throw DecisionConflict::alreadyDecided(DecisionHistory::usernameOf($prova, $newerDecisionId));
        }
        throw DecisionConflict::dataChanged();
    }

    /**
     * The undo still in force that already reversed this decision, if any.
     *
     * @param Decision[] $decisions
     */
    public static function activeUndoOf(int $decisaoId, array $decisions): ?int
    {
        foreach (ActiveDecisions::filter($decisions) as $active) {
            if ($active->tipo === Decision::DESFAZER && $active->desfazDecisaoId === $decisaoId) {
                return $active->id;
            }
        }
        return null;
    }

    /**
     * The most recent decision about any of these entries that the
     * reviewer's screen did not know yet. When there is one, the conflict is
     * "somebody decided first"; when there is none, the entries themselves
     * changed (for example a late upload joined the pass group).
     *
     * @param int[] $passagemIds
     * @param Decision[] $decisions
     */
    private static function newestDecisionAbout(array $passagemIds, array $decisions, ?int $knownUpToId): ?int
    {
        $watched = array_flip($passagemIds);
        $allById = ActiveDecisions::indexById($decisions);

        $newest = null;
        foreach ($decisions as $decision) {
            if ($knownUpToId !== null && $decision->id <= $knownUpToId) {
                continue;
            }
            $entryId = ActiveDecisions::entryIdOf($decision, $allById);
            if ($entryId === null || !isset($watched[$entryId])) {
                continue;
            }
            if ($newest === null || $decision->id > $newest) {
                $newest = $decision->id;
            }
        }
        return $newest;
    }
}
