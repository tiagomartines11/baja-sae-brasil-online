<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Source;
use InvalidArgumentException;

/**
 * Turns a request that has the right shape (see DecisionRequest) into the
 * decisions to store, checking it against the race as it is now.
 *
 * Everything in a request comes from a browser, so nothing is trusted: an
 * entry id must belong to this prova, a car must be one of the cars at the
 * event, a source must be one of this race's counters.
 *
 * ENTRY IDS BELOW ZERO. A pass added by a reviewer is not a row of
 * contagem_passagem; on the timeline it carries the id "minus the id of the
 * decision that added it". Voiding such a pass therefore means undoing that
 * decision, and that is what a request to void entry -N is turned into.
 */
final class DecisionResolver
{
    /**
     * One of the engine's own decision types.
     *
     * @throws InvalidArgumentException with a message in Portuguese, safe to show
     */
    public static function toDecision(DecisionRequest $request, EngineInput $input): Decision
    {
        // Id 0: the real id is assigned by the database when it is stored.
        switch ($request->tipo) {
            case Decision::ANULAR:
                return self::voidOf(self::entryId($request, $input, true));
            case Decision::CONFIRMAR:
                return Decision::confirmar(0, self::entryId($request, $input, true));
            case Decision::REATRIBUIR:
                return Decision::reatribuir(0, self::entryId($request, $input, false), self::presentCar($request, $input));
            case Decision::ADICIONAR:
                return Decision::adicionar(0, self::classifiedCar($request, $input), (int) $request->number('ts_ms'));
            case Decision::EXCLUIR_FONTE:
                return Decision::excluirFonte(0, self::countingSource($request, $input), (int) $request->number('ts_ms'), $request->number('ts_fim_ms'));
            case Decision::DESFAZER:
                return self::undo($request, $input);
            default:
                throw new InvalidArgumentException('Tipo de decisão desconhecido.');
        }
    }

    /**
     * "Descartar esta passagem": one void for every entry of the pass group
     * the named entry is in, so that a pass that did not happen goes away in
     * one step instead of one click per counter (with the car's count wrong
     * in between).
     *
     * @return Decision[]
     */
    public static function discardGroup(DecisionRequest $request, CarsRecount $recount): array
    {
        $entryId = self::entryId($request, $recount->input, true);
        $group = $recount->groupOf($entryId);
        if ($group === null) {
            throw new InvalidArgumentException('Este registro não faz parte de nenhuma passagem (já foi anulado ou está fora de uso).');
        }
        $decisions = [];
        foreach ($group->entryIds() as $id) {
            $decisions[] = self::voidOf($id);
        }
        return $decisions;
    }

    /**
     * "Mover os registros para o carro Y": two counters typed the same
     * wrong number, so their entries form a pass of a car that was not
     * there. One reassignment per entry, stored together: with only one of
     * them moved, both cars would be wrong in between.
     *
     * @return Decision[]
     */
    public static function moveGroup(DecisionRequest $request, EngineInput $input): array
    {
        $car = self::presentCar($request, $input);
        $stored = [];
        foreach ($input->entries as $entry) {
            $stored[$entry->id] = true;
        }
        $decisions = [];
        foreach ($request->grupoIds as $entryId) {
            if (!isset($stored[$entryId])) {
                throw new InvalidArgumentException('Esta passagem não pertence a esta prova.');
            }
            $decisions[] = Decision::reatribuir(0, $entryId, $car);
        }
        return $decisions;
    }

    /**
     * "Marcar saída do box / do abastecimento": the car and the moment of
     * the exit a reviewer infers from a pass at the line.
     *
     * The log must still say the car is INSIDE at that moment. When it does
     * not, somebody was faster (the person at the pit lane logged the exit
     * late, or another reviewer marked it): a second exit would be an
     * unpaired event, so the request is answered as a conflict and the
     * reviewer gets the screen as it is now.
     *
     * @return array{area: string, carro: int, ts_ms: int}
     * @throws DecisionConflict when the car is no longer logged inside
     */
    public static function inferredExit(DecisionRequest $request, CarsRecount $recount): array
    {
        $car = self::presentCar($request, $recount->input);
        $tsMs = (int) $request->number('ts_ms');
        if ($tsMs > $recount->input->nowMs) {
            throw new InvalidArgumentException('O horário da saída está no futuro.');
        }
        // Asked of the LOG, not of the recount's timeline: the engine may
        // already have ASSUMED this very exit (the car was lapping), and its
        // timeline then ends the stay there. "Confirmar saída" stores the
        // exit the engine assumed, so what matters is whether the log
        // itself still has the car inside.
        if (!InferredExits::logHasCarInside($recount->input->ocorrencias, (string) $request->area, $car, $tsMs)) {
            throw DecisionConflict::dataChanged();
        }
        return ['area' => (string) $request->area, 'carro' => $car, 'ts_ms' => $tsMs];
    }

    /**
     * "Encerrar exclusão": the open-ended exclusion that is to get an end.
     *
     * @return Decision the exclusion still in force
     */
    public static function openExclusion(DecisionRequest $request, EngineInput $input, int $nowMs): Decision
    {
        $target = ActiveDecisions::indexById($input->decisions)[(int) $request->number('desfaz_decisao_id')] ?? null;
        if ($target === null || $target->tipo !== Decision::EXCLUIR_FONTE) {
            throw new InvalidArgumentException('Esta exclusão não pertence a esta prova.');
        }
        if ($target->tsFimMs !== null) {
            throw new InvalidArgumentException('Esta exclusão já tem fim. Para mudá-lo, desfaça a exclusão e registre de novo.');
        }
        $until = (int) $request->number('ts_fim_ms');
        if ($until <= (int) $target->tsMs) {
            throw new InvalidArgumentException('O fim da exclusão deve ser depois do início dela.');
        }
        if ($until > $nowMs) {
            // An end in the future would let the source back in by itself,
            // at a moment nobody is watching.
            throw new InvalidArgumentException('O fim da exclusão está no futuro. Informe o tempo de prova (h:mm:ss desde a largada) em que a fonte voltou a contar.');
        }
        return $target;
    }

    /** Voiding an added pass is undoing the decision that added it. */
    private static function voidOf(int $entryId): Decision
    {
        return $entryId < 0 ? Decision::desfazer(0, -$entryId) : Decision::anular(0, $entryId);
    }

    private static function countingSource(DecisionRequest $request, EngineInput $input): int
    {
        $fonteId = (int) $request->number('fonte_id');
        foreach ($input->sources as $source) {
            if ($source->id === $fonteId
                && in_array($source->tipo, [Source::TIPO_MANUAL, Source::TIPO_AUTOMATICA], true)) {
                return $fonteId;
            }
        }
        throw new InvalidArgumentException('Esta fonte não pertence a esta prova.');
    }

    private static function undo(DecisionRequest $request, EngineInput $input): Decision
    {
        $targetId = (int) $request->number('desfaz_decisao_id');
        $target = ActiveDecisions::indexById($input->decisions)[$targetId] ?? null;
        if ($target === null) {
            throw new InvalidArgumentException('Esta decisão não pertence a esta prova.');
        }
        if ($target->tipo === Decision::DESFAZER) {
            // Undoing an undo would leave a chain nobody can read in the
            // history. Making the original decision again says the same thing.
            throw new InvalidArgumentException('Não é possível desfazer um "desfazer". Registre a decisão novamente.');
        }
        return Decision::desfazer(0, $targetId);
    }

    /**
     * The entry a decision is about: a stored entry of this prova, or (when
     * allowed) a pass a reviewer added, named by its id below zero.
     *
     * The input holds the entries of the cars the watched entries count
     * for, and the decided entry is one of the watched ones; an id that is
     * not found there is not an entry of this prova.
     */
    private static function entryId(DecisionRequest $request, EngineInput $input, bool $allowAddedPass): int
    {
        $id = (int) $request->number('passagem_id');

        if ($id < 0) {
            if (!$allowAddedPass) {
                throw new InvalidArgumentException('Uma passagem adicionada pelo revisor não pode ser trocada de carro: remova-a e adicione no carro certo.');
            }
            $addition = ActiveDecisions::filter($input->decisions)[-$id] ?? null;
            if ($addition === null || $addition->tipo !== Decision::ADICIONAR) {
                throw new InvalidArgumentException('Esta passagem adicionada não existe mais.');
            }
            return $id;
        }

        foreach ($input->entries as $entry) {
            if ($entry->id === $id) {
                return $id;
            }
        }
        throw new InvalidArgumentException('Esta passagem não pertence a esta prova.');
    }

    /**
     * A car present at the event, classified for this prova or not: an
     * entry typed with the wrong number may really be of a car that is not
     * classified, and the reviewer must be able to say so. (The engine then
     * flags the pass as "carro não classificado" instead of counting it.)
     */
    private static function presentCar(DecisionRequest $request, EngineInput $input): int
    {
        $car = (int) $request->number('equipe_numero');
        if (!in_array($car, $input->validCars, true) && !in_array($car, $input->unclassifiedCars, true)) {
            throw new InvalidArgumentException('O carro ' . $car . ' não está presente neste evento.');
        }
        return $car;
    }

    /** A pass can only be added for a car that takes part in the race. */
    private static function classifiedCar(DecisionRequest $request, EngineInput $input): int
    {
        $car = self::presentCar($request, $input);
        if (!in_array($car, $input->validCars, true)) {
            throw new InvalidArgumentException(
                'O carro ' . $car . ' não está classificado para esta prova. Marque-o como apto antes de adicionar uma passagem.'
            );
        }
        return $car;
    }
}
