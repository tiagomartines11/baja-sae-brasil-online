<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\RaceTimeline;

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
                self::accept($input, $result, $flag->sugestao);
                $accepted++;
            }
            if ($accepted === 0) {
                return $result;
            }
        }
        throw new \LogicException('Accepting suggestions kept producing new suggestions for ten rounds.');
    }

    /**
     * Stores a suggestion the way the review screen does. Most are one
     * decision. Three are not:
     *
     * - "reatribuir_grupo" is one "reatribuir" per entry named;
     * - "descartar_passagem" is one "anular" per entry of the pass group
     *   the named entry is in;
     * - "marcar_saida" records an exit from the pit lane or the fueling
     *   station, attributed to the reviewer (an inferred exit).
     *
     * @param array<string, mixed> $suggestion
     */
    public static function accept(EngineInput $input, EngineResult $result, array $suggestion): void
    {
        $nextId = self::nextDecisionId($input);
        switch ($suggestion['tipo']) {
            case 'reatribuir_grupo':
                foreach ($suggestion['passagem_ids'] as $offset => $entryId) {
                    $input->decisions[] = Decision::reatribuir($nextId + $offset, $entryId, $suggestion['equipe_numero']);
                }
                return;
            case 'descartar_passagem':
                $group = $result->groupOfEntry($suggestion['passagem_id']);
                foreach ($group === null ? [] : $group->entryIds() as $offset => $entryId) {
                    $input->decisions[] = Decision::anular($nextId + $offset, $entryId);
                }
                return;
            case 'marcar_saida':
                $highestId = 0;
                foreach ($input->ocorrencias as $ocorrencia) {
                    $highestId = max($highestId, $ocorrencia->id);
                }
                $exit = new Ocorrencia(
                    $highestId + 1,
                    $suggestion['area'] === RaceTimeline::AREA_BOX ? Ocorrencia::BOX_SAIDA : Ocorrencia::ABASTECIMENTO_SAIDA,
                    $suggestion['ts_ms'],
                    $suggestion['equipe_numero']
                );
                $exit->inferida = true;
                $input->ocorrencias[] = $exit;
                return;
        }
        $decision = new Decision($nextId, $suggestion['tipo']);
        $decision->passagemId = $suggestion['passagem_id'] ?? null;
        $decision->carro = $suggestion['equipe_numero'] ?? null;
        $input->decisions[] = $decision;
    }

    /**
     * Accepts every suggestion, then settles what is left by what the
     * video shows (see settleWithTheVideo), and repeats until nothing
     * changes.
     */
    public static function settleEverythingWithTheVideo(SyntheticRace $race, EngineInput $input): EngineResult
    {
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $result = self::acceptEverySuggestion($input);
            $settled = 0;
            foreach ($result->openFlags() as $flag) {
                foreach (self::settleWithTheVideo($race, $input, $result, $flag) as $decision) {
                    $input->decisions[] = $decision;
                    $settled++;
                }
            }
            if ($settled === 0) {
                return $result;
            }
        }
        throw new \LogicException('The video reviewer kept finding lone entries for ten rounds.');
    }

    /** Flags the video settles entry by entry: each entry is what really crossed, another car, or nothing. */
    private const SETTLED_ENTRY_BY_ENTRY = [
        Flag::SOLITARIA,
        Flag::TROCA_PROVAVEL,
        Flag::DISPERSAO_ALTA,
        Flag::PASSAGEM_PROXIMA_DEMAIS,
        Flag::VOLTA_CURTA,
        Flag::NO_BOX,
        Flag::NO_ABASTECIMENTO,
    ];

    /**
     * What the reviewer with the video does about one flag that has no
     * suggestion:
     *
     * - a lone entry, a swap the engine could not decide, a held pass, two
     *   passes too close together: every entry of the flag is confirmed,
     *   reassigned or voided, by what really crossed when it was typed;
     * - an estimated time: the video shows the pass where the group is, so
     *   "está correto" (confirm);
     * - an unknown time: the pass was counted from the other counters'
     *   entries, so the stray entry is voided;
     * - a car that is not classified: it stays not classified, so its
     *   entries are discarded (voided), which is the only way to clear the
     *   flag without counting the pass.
     *
     * @return Decision[]
     */
    private static function settleWithTheVideo(SyntheticRace $race, EngineInput $input, EngineResult $result, Flag $flag): array
    {
        $isLoneEntry = count($flag->passagemIds) === 1;
        $nextId = self::nextDecisionId($input);
        if (in_array($flag->tipo, self::SETTLED_ENTRY_BY_ENTRY, true) || ($flag->tipo === Flag::TEMPO_ESTIMADO && $isLoneEntry)) {
            $decisions = [];
            foreach ($flag->passagemIds as $offset => $entryId) {
                $decisions[] = self::whatTheVideoShows($race, $result, $entryId, $nextId + $offset);
            }
            return $decisions;
        }
        if ($flag->tipo === Flag::TEMPO_ESTIMADO) {
            return [Decision::confirmar($nextId, $flag->passagemIds[0])];
        }
        if ($flag->tipo === Flag::TEMPO_DESCONHECIDO) {
            return [Decision::anular($nextId, $flag->passagemIds[0])];
        }
        if ($flag->tipo === Flag::CARRO_NAO_CLASSIFICADO) {
            $voids = [];
            foreach ($flag->passagemIds as $offset => $entryId) {
                $voids[] = Decision::anular($nextId + $offset, $entryId);
            }
            return $voids;
        }
        return [];
    }

    private static function whatTheVideoShows(SyntheticRace $race, EngineResult $result, int $entryId, int $decisionId): Decision
    {
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
