<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Service\ContagemService;
use InvalidArgumentException;
use Propel\Runtime\Propel;

/**
 * Stores a reviewer's decision, unless what they were looking at changed
 * between the moment their screen was drawn and the moment they clicked.
 *
 * Two reviewers work on the same queue at the same time. Without this check
 * the second click on the same flag would silently stack a second correction
 * on top of the first.
 *
 * WHAT IT COSTS. A decision is the one thing a reviewer waits for, and it
 * holds the count lock, so every phone's upload waits behind it:
 *
 *   1. The request is read and refused for a wrong shape BEFORE the lock is
 *      taken and before anything is loaded (DecisionRequest).
 *   2. Under the lock it is checked against a quick recount of the cars the
 *      reviewer was looking at (CarsRecount), not against a whole-race run.
 *   3. The whole engine then runs exactly once, to publish the result and
 *      to make the state sent back with the answer.
 */
final class DecisionValidator
{
    public const MOTIVO_JA_DECIDIDO = 'ja_decidido';
    public const MOTIVO_DADOS_MUDARAM = 'dados_mudaram';

    /**
     * Signature of a set of entries: sha1 over, for each id in ascending
     * order, the entry's effective car, whether it is still in use, the
     * sorted ids of its pass group and the ids of the decisions in force
     * about it.
     *
     * @param int[] $passagemIds
     * @param Decision[] $decisions every decision of the prova
     */
    public static function signature(EngineResult $result, array $passagemIds, array $decisions): string
    {
        return SignatureCalculator::forResult($result, $decisions)->forEntries($passagemIds);
    }

    /**
     * Validates and stores a decision, all under the count lock so that of
     * two simultaneous requests one always sees the other's decision.
     *
     * The decision is checked against a recount made here, inside the
     * lock, and never against the stored snapshot: the snapshot may be a
     * few seconds old, and those seconds are exactly when the other
     * reviewer clicked.
     *
     * @param array<string, mixed> $request decoded JSON body, see DecisionRequest
     * @return array{status: int, body: array<string, mixed>}
     *         200 with the new state, 409 with the fresh state, or 400
     * @throws \Baja\Contagem\Service\LockBusy when the lock is not obtained in time
     */
    public static function decide(ContagemService $service, array $request, string $username): array
    {
        try {
            $parsed = DecisionRequest::parse($request);
        } catch (InvalidArgumentException $invalid) {
            return self::badRequest($invalid->getMessage());
        }

        return $service->withLock(function () use ($service, $parsed, $username): array {
            $recount = CarsRecount::loadForEntries($service, $parsed->passagemIds);
            try {
                $work = self::plan($service, $parsed, $recount, $username);
            } catch (InvalidArgumentException $invalid) {
                return self::badRequest($invalid->getMessage());
            } catch (DecisionConflict $conflict) {
                // The reviewer's screen was behind. Store a snapshot of the
                // race as it is now, so the answer (and the next poll) shows
                // what made the decision impossible.
                $service->recomputeAndPublish();
                return ['status' => 409, 'body' => $conflict->body + ['estado' => self::freshState($service, $username)]];
            }

            // All the rows of one decision are stored together or not at all.
            try {
                $ids = Propel::getWriteConnection('resultados')->transaction($work);
            } catch (InvalidArgumentException $refused) {
                // Refused while storing (an inferred exit that race control
                // already took out, for example). The transaction undid
                // whatever had been written.
                return self::badRequest($refused->getMessage());
            }
            $service->recomputeAndPublish();

            return [
                'status' => 200,
                'body' => ['ok' => true, 'decisao_id' => end($ids), 'decisao_ids' => $ids, 'estado' => self::freshState($service, $username)],
            ];
        });
    }

    /**
     * Checks the request against the race and returns the work of storing
     * it: a function that returns the ids of the decisions it stored.
     *
     * @return callable(): int[]
     * @throws InvalidArgumentException when the request makes no sense for this race
     * @throws DecisionConflict when it made sense on the reviewer's screen but no longer does
     */
    private static function plan(ContagemService $service, DecisionRequest $parsed, CarsRecount $recount, string $username): callable
    {
        $input = $recount->input;
        $prova = $service->getProva();
        $motivo = $parsed->motivo;

        if ($parsed->tipo === DecisionRequest::ENCERRAR_EXCLUSAO) {
            $exclusion = DecisionResolver::openExclusion($parsed, $input, $input->nowMs);
            $undoId = DecisionConflicts::activeUndoOf($exclusion->id, $input->decisions);
            if ($undoId !== null) {
                throw DecisionConflict::alreadyDecided(DecisionHistory::usernameOf($prova, $undoId));
            }
            $until = (int) $parsed->number('ts_fim_ms');
            return fn(): array => [$service->closeOpenExclusion($exclusion->id, $until, $username, $motivo)];
        }

        if ($parsed->tipo === DecisionRequest::MARCAR_SAIDA) {
            $exit = DecisionResolver::inferredExit($parsed, $recount);
            DecisionConflicts::requireUnchanged($prova, $parsed, $recount);
            return function () use ($service, $exit, $username): array {
                // Two rows: the exit itself, an ordinary race event that
                // race control lists and the engine pairs with the entry;
                // and a decision, so that the history shows who marked it
                // and "Desfazer" there takes the exit back.
                $service->recordInferredExit($exit['area'], $exit['carro'], $exit['ts_ms'], $username);
                $decision = new Decision(0, DecisionRequest::MARCAR_SAIDA);
                $decision->carro = $exit['carro'];
                $decision->tsMs = $exit['ts_ms'];
                return [$service->storeDecision($decision, $username, $exit['area'])];
            };
        }

        // Set when the decision undoes a "marcar saída": the exit goes with it.
        $inferredExitToCancel = null;

        if ($parsed->tipo === DecisionRequest::REATRIBUIR_GRUPO) {
            $decisions = DecisionResolver::moveGroup($parsed, $input);
            foreach ($decisions as $decision) {
                DecisionConflicts::requireNew($prova, $decision, $input);
            }
            DecisionConflicts::requireUnchanged($prova, $parsed, $recount);
        } elseif ($parsed->tipo === DecisionRequest::DESCARTAR_PASSAGEM) {
            // Asked first: when the pass is already gone because somebody
            // else discarded it, "já decidido por maria" (and a refreshed
            // screen) is the useful answer, not "this is not a pass".
            DecisionConflicts::requireUnchanged($prova, $parsed, $recount);
            $decisions = DecisionResolver::discardGroup($parsed, $recount);
        } else {
            $decision = DecisionResolver::toDecision($parsed, $input);
            if ($decision->tipo === Decision::REATRIBUIR) {
                // "Move it to the car it already counts for" says nothing.
                // Stored, it would sit in the history as a correction, and
                // the flag the reviewer meant to settle would stay open.
                $entry = $recount->survivingEntry((int) $decision->passagemId);
                if ($entry !== null && $entry->carro === $decision->carro) {
                    throw new InvalidArgumentException('Este registro já é do carro ' . $decision->carro . '. Informe o carro que realmente passou.');
                }
            }
            $invalidReason = DecisionRules::invalidReason($decision, $recount->timeline, $input->nowMs);
            if ($invalidReason !== null) {
                throw new InvalidArgumentException($invalidReason);
            }
            DecisionConflicts::requireNew($prova, $decision, $input);
            DecisionConflicts::requireUnchanged($prova, $parsed, $recount);
            $decisions = [$decision];

            if ($decision->tipo === Decision::DESFAZER) {
                $undone = ActiveDecisions::indexById($input->decisions)[(int) $decision->desfazDecisaoId] ?? null;
                if ($undone !== null && $undone->tipo === DecisionRequest::MARCAR_SAIDA) {
                    $inferredExitToCancel = $undone->id;
                }
            }
        }

        return function () use ($service, $decisions, $username, $motivo, $inferredExitToCancel): array {
            $ids = [];
            foreach ($decisions as $decision) {
                $ids[] = $service->storeDecision($decision, $username, $motivo);
            }
            if ($inferredExitToCancel !== null) {
                $service->cancelInferredExit($inferredExitToCancel, $username);
            }
            return $ids;
        };
    }

    /** The state after the recomputeAndPublish() just made by this request. */
    private static function freshState(ContagemService $service, string $username): array
    {
        return StateSnapshot::live($service, $service->lastStoredSnapshot() ?? $service->snapshot(), $username);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function badRequest(string $message): array
    {
        return ['status' => 400, 'body' => ['erro' => 'pedido_invalido', 'mensagem' => $message]];
    }
}
