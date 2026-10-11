<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Engine;

/**
 * Two reviewers for synthetic races.
 *
 * - The one who TRUSTS THE ENGINE presses "aceitar sugestão" on every flag
 *   that has a suggestion, and touches nothing else.
 * - The one who WATCHES THE VIDEO deals with the flags that have no
 *   suggestion: for an entry that only one counter has, the video shows
 *   whether that car really crossed (confirm it), another car did (reassign
 *   it), or nothing did (void it). The generator knows what the video shows.
 *
 * Both add their decisions to the input they are given, as a real reviewer
 * adds rows to contagem_decisao.
 */
final class SyntheticReviewer
{
    private const MAX_ROUNDS = 10;

    /**
     * Accepts every suggestion, looks again, and repeats until the queue has
     * no more suggestions.
     */
    public static function acceptEverySuggestion(EngineInput $input): EngineResult
    {
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $result = Engine::run($input);
            $accepted = 0;
            foreach ($result->openFlags() as $flag) {
                if ($flag->sugestao === null) {
                    continue;
                }
                $decision = new Decision(self::nextDecisionId($input), $flag->sugestao['tipo']);
                $decision->passagemId = $flag->sugestao['passagem_id'] ?? null;
                $decision->carro = $flag->sugestao['equipe_numero'] ?? null;
                $input->decisions[] = $decision;
                $accepted++;
            }
            if ($accepted === 0) {
                return $result;
            }
        }
        throw new \LogicException('Accepting suggestions kept producing new suggestions for ten rounds.');
    }

    /**
     * Accepts every suggestion, then settles each remaining lone entry by
     * what the video shows, and repeats until nothing changes.
     */
    public static function settleEverythingWithTheVideo(SyntheticRace $race, EngineInput $input): EngineResult
    {
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $result = self::acceptEverySuggestion($input);
            $settled = 0;
            foreach ($result->openFlags() as $flag) {
                if ($flag->tipo !== Flag::SOLITARIA || count($flag->passagemIds) !== 1) {
                    continue;
                }
                $input->decisions[] = self::whatTheVideoShows($race, $input, $result, $flag->passagemIds[0]);
                $settled++;
            }
            if ($settled === 0) {
                return $result;
            }
        }
        throw new \LogicException('The video reviewer kept finding lone entries for ten rounds.');
    }

    private static function whatTheVideoShows(SyntheticRace $race, EngineInput $input, EngineResult $result, int $entryId): Decision
    {
        $decisionId = self::nextDecisionId($input);
        $realCar = $race->realCarBehindEntry($entryId);
        if ($realCar === null) {
            return Decision::anular($decisionId, $entryId);
        }
        if ($realCar !== $result->effectiveEntries[$entryId]->carro) {
            return Decision::reatribuir($decisionId, $entryId, $realCar);
        }
        return Decision::confirmar($decisionId, $entryId);
    }

    private static function nextDecisionId(EngineInput $input): int
    {
        $highest = 0;
        foreach ($input->decisions as $decision) {
            $highest = max($highest, $decision->id);
        }
        return $highest + 1;
    }
}
