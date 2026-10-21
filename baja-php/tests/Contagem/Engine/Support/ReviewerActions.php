<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;

/**
 * The decisions a reviewer (or a counter, for their own entries) can make,
 * written the way a test tells the story: `$race->void($entry)`,
 * `$race->accept($flag)`, `$race->undo($decision)`.
 *
 * Every method stores a new decision and returns its id, so that a test can
 * undo it later.
 */
trait ReviewerActions
{
    /** @var Decision[] */
    private array $decisions = [];

    private int $nextDecisionId = 1;

    public function void(int $entryId, string $origem = Decision::ORIGEM_REVISOR): int
    {
        return $this->decide(Decision::anular($this->nextDecisionId, $entryId, $origem));
    }

    public function reassign(int $entryId, int $car): int
    {
        return $this->decide(Decision::reatribuir($this->nextDecisionId, $entryId, $car));
    }

    /** "Count this pass / keep both / it is fine as it is." */
    public function confirm(int $entryId): int
    {
        return $this->decide(Decision::confirmar($this->nextDecisionId, $entryId));
    }

    /**
     * "Usar o tempo deste registro": count the pass of $entryId, timed by
     * that entry alone. $lateEntryId is the same crossing typed late by
     * another counter, in a pass group of its own; it joins the pass.
     */
    public function useTimeOf(int $entryId, ?int $lateEntryId = null): int
    {
        return $this->decide(Decision::usarTempo($this->nextDecisionId, $entryId, $lateEntryId));
    }

    public function addPass(int $car, string $at): int
    {
        return $this->decide(Decision::adicionar($this->nextDecisionId, $car, RaceScenario::ms($at)));
    }

    /**
     * Ignores a counter between two moments. A null $from means "since
     * always"; a null $to means "until further notice".
     */
    public function excludeSource(int $source, ?string $from, ?string $to): int
    {
        return $this->decide(Decision::excluirFonte(
            $this->nextDecisionId,
            $source,
            $from === null ? null : RaceScenario::ms($from),
            $to === null ? null : RaceScenario::ms($to)
        ));
    }

    /** Reverses an earlier decision (the "desfazer" button of the history panel). */
    public function undo(int $decisionId): int
    {
        return $this->decide(Decision::desfazer($this->nextDecisionId, $decisionId));
    }

    /**
     * The reviewer presses "aceitar sugestão" on a flag. Returns the id of
     * the (last) decision stored, or of the exit recorded for "marcar saída".
     *
     * Most suggestions are one decision. "reatribuir_grupo" moves several
     * entries, "descartar_passagem" voids every entry of the flag's pass,
     * and "marcar_saida" records an exit from the pit lane or the fueling
     * station; the review screen stores each of those in one step too.
     */
    public function accept(Flag $flag): int
    {
        if ($flag->sugestao === null) {
            throw new \LogicException('The flag ' . $flag->id . ' has no suggestion to accept.');
        }
        switch ($flag->sugestao['tipo']) {
            case 'reatribuir_grupo':
                $last = 0;
                foreach ($flag->sugestao['passagem_ids'] as $entryId) {
                    $last = $this->reassign($entryId, $flag->sugestao['equipe_numero']);
                }
                return $last;
            case 'descartar_passagem':
                $last = 0;
                foreach ($flag->passagemIds as $entryId) {
                    $last = $this->void($entryId);
                }
                return $last;
            case 'marcar_saida':
                return $this->inferredExit($flag->sugestao['area'], $flag->sugestao['equipe_numero'], $flag->sugestao['ts_ms']);
        }
        $decision = new Decision($this->nextDecisionId, $flag->sugestao['tipo']);
        $decision->passagemId = $flag->sugestao['passagem_id'] ?? null;
        $decision->carro = $flag->sugestao['equipe_numero'] ?? null;
        return $this->decide($decision);
    }

    private function decide(Decision $decision): int
    {
        $this->decisions[] = $decision;
        $this->nextDecisionId++;
        return $decision->id;
    }
}
