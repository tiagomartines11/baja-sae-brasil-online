<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Service\ContagemService;
use Baja\Model\Prova;
use InvalidArgumentException;

/**
 * Stores a reviewer's decision, unless what they were looking at changed
 * between the moment their screen was drawn and the moment they clicked.
 *
 * Two reviewers work on the same queue at the same time. Without this check
 * the second click on the same flag would silently stack a second correction
 * on top of the first.
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
     * The decision is checked against a full run made here, inside the
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
        return $service->withLock(function () use ($service, $request, $username): array {
            $input = $service->loadInput();
            $result = Engine::run($input);

            try {
                $parsed = DecisionRequest::parse($request, $input);
            } catch (InvalidArgumentException $invalid) {
                return self::badRequest($invalid->getMessage());
            }
            $invalidReason = DecisionRules::invalidReason($parsed->decision, $result->timeline, $input->nowMs);
            if ($invalidReason !== null) {
                return self::badRequest($invalidReason);
            }

            $conflict = self::findConflict($service->getProva(), $parsed, $input, $result);
            if ($conflict !== null) {
                // The reviewer's screen was behind. Store a snapshot of the
                // race as it is now, so the answer (and the next poll) shows
                // what made the decision impossible.
                $service->recomputeAndPublish();
                $conflict['erro'] = 'conflito';
                $conflict['estado'] = self::freshState($service, $username);
                return ['status' => 409, 'body' => $conflict];
            }

            $decisaoId = $service->storeDecision($parsed->decision, $username, $parsed->motivo);
            $service->recomputeAndPublish();

            return [
                'status' => 200,
                'body' => ['ok' => true, 'decisao_id' => $decisaoId, 'estado' => self::freshState($service, $username)],
            ];
        });
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

    /**
     * @return array{motivo: string, por: ?string, mensagem: string}|null null when the decision may go ahead
     */
    private static function findConflict(Prova $prova, DecisionRequest $parsed, EngineInput $input, EngineResult $result): ?array
    {
        $decision = $parsed->decision;

        if ($decision->tipo === Decision::DESFAZER) {
            $undoId = self::activeUndoOf((int) $decision->desfazDecisaoId, $input->decisions);
            if ($undoId !== null) {
                return self::alreadyDecided(DecisionHistory::usernameOf($prova, $undoId));
            }
        }

        // Before the signature: this refusal names who made the decision
        // that already stands, which is more useful than "the data changed".
        $sameDecisionId = DecisionRules::sameDecisionInForce($decision, $input);
        if ($sameDecisionId !== null) {
            return self::alreadyDecided(DecisionHistory::usernameOf($prova, $sameDecisionId));
        }

        if ($parsed->passagemIds === []) {
            // Nothing was being looked at (adding a pass, excluding a source).
            return null;
        }

        $current = self::signature($result, $parsed->passagemIds, $input->decisions);
        if ($parsed->assinatura !== null && hash_equals($current, $parsed->assinatura)) {
            return null;
        }

        $newerDecisionId = self::newestDecisionAbout($parsed->passagemIds, $input->decisions, $parsed->ultimaDecisaoId);
        if ($newerDecisionId !== null) {
            return self::alreadyDecided(DecisionHistory::usernameOf($prova, $newerDecisionId));
        }

        return [
            'motivo' => self::MOTIVO_DADOS_MUDARAM,
            'por' => null,
            'mensagem' => 'Os dados mudaram desde que você abriu este item. Confira de novo antes de decidir.',
        ];
    }

    /** @return array{motivo: string, por: ?string, mensagem: string} */
    private static function alreadyDecided(?string $username): array
    {
        return [
            'motivo' => self::MOTIVO_JA_DECIDIDO,
            'por' => $username,
            'mensagem' => 'Já decidido por ' . ($username ?? 'outra pessoa') . '.',
        ];
    }

    /**
     * The undo still in force that already reversed this decision, if any.
     *
     * @param Decision[] $decisions
     */
    private static function activeUndoOf(int $decisaoId, array $decisions): ?int
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
