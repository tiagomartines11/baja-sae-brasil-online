<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\EngineInputLoader;
use Baja\Model\Prova;
use InvalidArgumentException;
use PDO;
use Propel\Runtime\Propel;

/**
 * The exits a reviewer marked ("Marcar saída do box / do abastecimento"),
 * as race control lists, corrects and cancels them.
 *
 * A car is logged into the pit lane, nobody logs it out, and then two
 * counters see it cross the line. The reviewer says "it left": an exit is
 * stored one second before that pass. It is an ordinary race event to the
 * engine, and it is two rows in the database:
 *
 *   - the exit itself, in contagem_ocorrencia, marked origem = "revisor";
 *   - a decision of type "marcar_saida" (car, time, and the area as its
 *     reason), which is what the history shows and what "Desfazer" undoes.
 *
 * Race control works on the same pair, so that the two never tell
 * different stories: cancelling here is exactly "Desfazer" in the history,
 * and correcting the time is an undo plus a new mark at the new time (the
 * way an open exclusion gets its end).
 */
final class InferredExits
{
    public const AREA_LABELS = [
        DecisionRequest::AREA_BOX => 'box',
        DecisionRequest::AREA_ABASTECIMENTO => 'abastecimento',
    ];

    /**
     * The exits marked by reviewers that still stand, newest first.
     *
     * @return array<int, array{decisao_id: int, area: string, carro: int, ts_ms: int, usuario: ?string, criado_em_ms: int}>
     */
    public static function inForce(Prova $prova): array
    {
        $active = DecisionHistory::activeIdsFromDatabase($prova);
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT decisao_id, equipe_numero, ts_ms, motivo, usuario, criado_em_ms
               FROM contagem_decisao
              WHERE evento_id = ? AND prova_id = ? AND tipo = ?
              ORDER BY decisao_id DESC'
        );
        $statement->execute([$prova->getEventoId(), $prova->getProvaId(), DecisionRequest::MARCAR_SAIDA]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isComplete = isset(self::AREA_LABELS[(string) $row['motivo']]) && $row['equipe_numero'] !== null && $row['ts_ms'] !== null;
            if (!isset($active[(int) $row['decisao_id']]) || !$isComplete) {
                continue;
            }
            $rows[] = [
                'decisao_id' => (int) $row['decisao_id'],
                'area' => (string) $row['motivo'],
                'carro' => (int) $row['equipe_numero'],
                'ts_ms' => (int) $row['ts_ms'],
                'usuario' => $row['usuario'] === null ? null : (string) $row['usuario'],
                'criado_em_ms' => (int) $row['criado_em_ms'],
            ];
        }
        return $rows;
    }

    /**
     * "Cancelar" on a marked exit: the same as "Desfazer" in the history.
     * The car is logged inside again, and its passes are flagged again.
     *
     * @param array<string, mixed> $post
     */
    public static function cancel(ContagemService $service, array $post, string $username): void
    {
        $service->withLock(function () use ($service, $post, $username): void {
            $exit = self::requireInForce($service->getProva(), $post['decisao_id'] ?? null);
            Propel::getWriteConnection('resultados')->transaction(function () use ($service, $exit, $username): void {
                $service->storeDecision(Decision::desfazer(0, $exit['decisao_id']), $username, 'Cancelada no controle de corrida');
                $service->cancelInferredExit($exit['decisao_id'], $username);
            });
            $service->recomputeAndPublish();
        });
    }

    /**
     * "Corrigir" on a marked exit: the mark is undone and made again at the
     * new time, in one step.
     *
     * The new time must still close the stay: the car has to be logged
     * inside at that moment once the old exit is out of the way. That is
     * checked BEFORE anything is written, on the events as they would be.
     *
     * @param array<string, mixed> $post
     */
    public static function correct(ContagemService $service, array $post, string $username): void
    {
        $newTsMs = RaceMaintenance::parseTime($post['horario'] ?? '');
        if ($newTsMs === null) {
            throw new InvalidArgumentException('Informe o novo horário.');
        }
        if ($newTsMs > Clock::nowMs()) {
            throw new InvalidArgumentException('O horário da saída está no futuro.');
        }
        $service->withLock(function () use ($service, $post, $newTsMs, $username): void {
            $prova = $service->getProva();
            $exit = self::requireInForce($prova, $post['decisao_id'] ?? null);
            if (!self::wouldCloseAStay($prova, $exit, $newTsMs)) {
                throw new InvalidArgumentException(
                    'Em ' . Pagina::formatClock($newTsMs) . ' o carro ' . $exit['carro'] . ' não consta como dentro do '
                        . self::AREA_LABELS[$exit['area']] . ': a saída precisa ficar depois da entrada dele e antes de qualquer outro registro dessa parada.'
                );
            }
            Propel::getWriteConnection('resultados')->transaction(function () use ($service, $exit, $newTsMs, $username): void {
                $service->storeDecision(Decision::desfazer(0, $exit['decisao_id']), $username, 'Horário corrigido no controle de corrida');
                $service->cancelInferredExit($exit['decisao_id'], $username);

                $service->recordInferredExit($exit['area'], $exit['carro'], $newTsMs, $username);
                $mark = new Decision(0, DecisionRequest::MARCAR_SAIDA);
                $mark->carro = $exit['carro'];
                $mark->tsMs = $newTsMs;
                $service->storeDecision($mark, $username, $exit['area']);
            });
            $service->recomputeAndPublish();
        });
    }

    /**
     * A marked exit of this prova that still stands. An id from a form is
     * never trusted on its own, and a page drawn before somebody else undid
     * the mark must not undo it a second time.
     *
     * @param mixed $postedId
     * @return array{decisao_id: int, area: string, carro: int, ts_ms: int, usuario: ?string, criado_em_ms: int}
     */
    private static function requireInForce(Prova $prova, $postedId): array
    {
        $decisaoId = filter_var($postedId, FILTER_VALIDATE_INT);
        foreach (self::inForce($prova) as $exit) {
            if ($exit['decisao_id'] === $decisaoId) {
                return $exit;
            }
        }
        throw new InvalidArgumentException('Esta saída já foi corrigida ou cancelada. Recarregue a página.');
    }

    /**
     * Would the car be logged inside the area at $newTsMs if the exit in
     * force today were not there?
     *
     * @param array{area: string, carro: int, ts_ms: int} $exit
     */
    private static function wouldCloseAStay(Prova $prova, array $exit, int $newTsMs): bool
    {
        $exitType = $exit['area'] === DecisionRequest::AREA_BOX ? Ocorrencia::BOX_SAIDA : Ocorrencia::ABASTECIMENTO_SAIDA;
        $ocorrencias = (new EngineInputLoader($prova))->loadOcorrencias();

        $inForce = [];
        foreach (RaceTimeline::rowsInForce($ocorrencias) as $ocorrencia) {
            $inForce[$ocorrencia->id] = true;
        }
        foreach ($ocorrencias as $ocorrencia) {
            $isTheExit = isset($inForce[$ocorrencia->id]) && $ocorrencia->tipo === $exitType
                && $ocorrencia->carro === $exit['carro'] && $ocorrencia->tsMs === $exit['ts_ms'] && $ocorrencia->inferida;
            if ($isTheExit) {
                // The same events with that exit cancelled.
                $ocorrencias[] = new Ocorrencia(PHP_INT_MAX, Ocorrencia::CANCELAMENTO, $ocorrencia->tsMs, null, $ocorrencia->id);
                break;
            }
        }
        $timeline = RaceTimeline::fromOcorrencias($ocorrencias);
        return $exit['area'] === DecisionRequest::AREA_BOX
            ? $timeline->isInPitAt($exit['carro'], $newTsMs)
            : $timeline->isFuelingAt($exit['carro'], $newTsMs);
    }
}
