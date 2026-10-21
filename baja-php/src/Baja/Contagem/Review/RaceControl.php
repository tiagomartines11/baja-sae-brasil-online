<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Model\ContagemOcorrencia;
use Baja\Model\Prova;
use InvalidArgumentException;

/**
 * The actions of the race control page: start, red flag, restart, finish,
 * correcting, cancelling or restoring any of them, and the maintenance
 * buttons (those are in RaceMaintenance).
 *
 * Race events are append-only like everything else (see RaceEvents), so
 * every one of these actions can be undone: a wrong time is corrected, a
 * button pressed by mistake is cancelled, a cancellation made by mistake is
 * restored.
 *
 * Each action is checked and stored while holding the count lock. Two
 * controllers pressing buttons at the same moment are thereby handled one
 * after the other, and the second is checked against what the first did.
 */
final class RaceControl
{
    /** Race events the page can record, with the label shown for each. */
    public const EVENT_LABELS = RaceEvents::LABELS;

    /** Form field: "I was asked whether to replace the start / end the race, and said yes". */
    public const CAMPO_CONFIRMAR = 'confirmar';
    /** Form field: "I was told how many entries fall before the new start, and said yes". */
    public const CAMPO_CONFIRMAR_REGISTROS = 'confirmar_registros';
    /** Form field: "I was told this leaves the race with no start, and said yes". */
    public const CAMPO_CONFIRMAR_SEM_LARGADA = 'confirmar_sem_largada';
    public const CAMPO_CONFIRMAR_PREDEFINICAO = StartRules::CAMPO_CONFIRMAR_PREDEFINICAO;
    public const CAMPO_INSTANTE = StartRules::CAMPO_INSTANTE;

    /** Confirmation texts, keyed by the codes handle() returns. */
    public const MESSAGES = [
        Ocorrencia::LARGADA => 'Largada registrada.',
        Ocorrencia::BANDEIRA_VERMELHA => 'Bandeira vermelha registrada.',
        Ocorrencia::RELARGADA => 'Relargada registrada.',
        Ocorrencia::ENCERRAMENTO => 'Encerramento registrado.',
        'corrigida' => 'Horário corrigido.',
        'cancelada' => 'Ocorrência cancelada.',
        'restaurada' => 'Ocorrência restaurada: voltou a valer.',
        'saida_corrigida' => 'Horário da saída corrigido.',
        'saida_confirmada' => 'Saída confirmada: passou a constar no registro, marcada por você.',
        'saida_cancelada' => 'Saída cancelada: no registro o carro volta a constar como dentro da área. Se as contagens mostram o carro dando voltas, o sistema volta a presumir a saída (veja "Saídas presumidas").',
        'republicado' => 'Todos os valores foram republicados.',
        'reconstruido' => 'As voltas foram reconstruídas.',
        'zerado' => 'A corrida foi zerada. Os dados apagados ficaram guardados no arquivo.',
    ];

    /**
     * Runs one posted action.
     *
     * @param array<string, mixed> $post
     * @param bool $isAdmin whether the user holds the `admin` permission (needed to wipe a race that was started)
     * @return string code of the confirmation message to show (see MESSAGES)
     * @throws ConfirmationNeeded when the person must be asked first; nothing was stored
     * @throws InvalidArgumentException with a message in Portuguese
     * @throws \Baja\Contagem\Service\LockBusy when the count is busy for too long
     */
    public static function handle(ContagemService $service, array $post, string $username, bool $isAdmin = false): string
    {
        $action = is_string($post['acao'] ?? null) ? $post['acao'] : '';

        switch ($action) {
            case 'largar_agora':
                $clickMs = StartRules::clickInstant($post) ?? Clock::nowMs();
                try {
                    self::recordEvent($service, Ocorrencia::LARGADA, $clickMs, $username, null, $post, true);
                } catch (ConfirmationNeeded $question) {
                    $question->campos[self::CAMPO_INSTANTE] = (string) $clickMs;
                    throw $question;
                }
                return Ocorrencia::LARGADA;
            case 'registrar':
                $tipo = is_string($post['tipo'] ?? null) ? $post['tipo'] : '';
                if (!isset(self::EVENT_LABELS[$tipo])) {
                    throw new InvalidArgumentException('Tipo de ocorrência desconhecido.');
                }
                $tsMs = RaceMaintenance::parseTime($post['horario'] ?? '') ?? Clock::nowMs();
                self::recordEvent($service, $tipo, $tsMs, $username, null, $post);
                return $tipo;
            case 'corrigir':
                self::correctEvent($service, $post, $username);
                return 'corrigida';
            case 'cancelar':
                self::cancelEvent($service, $post, $username);
                return 'cancelada';
            case 'restaurar':
                self::restoreEvent($service, $post, $username);
                return 'restaurada';
            case 'corrigir_saida':
                InferredExits::correct($service, $post, $username);
                return 'saida_corrigida';
            case 'confirmar_saida':
                InferredExits::confirmAssumed($service, $post, $username);
                return 'saida_confirmada';
            case 'cancelar_saida':
                InferredExits::cancel($service, $post, $username);
                return 'saida_cancelada';
            default:
                return RaceMaintenance::handle($service, $action, $post, $username, $isAdmin);
        }
    }

    /**
     * @return ContagemOcorrencia[] race-control events and their cancellations, oldest first
     */
    public static function raceEvents(Prova $prova): array
    {
        return RaceEvents::load($prova);
    }

    /**
     * @param ContagemOcorrencia[] $rows
     */
    public static function timelineOf(array $rows): RaceTimeline
    {
        return RaceEvents::timelineOf($rows);
    }

    public static function hasEverStarted(Prova $prova): bool
    {
        return RaceMaintenance::hasEverStarted($prova);
    }

    /** @param mixed $value */
    public static function parseTime($value): ?int
    {
        return RaceMaintenance::parseTime($value);
    }

    /**
     * Stores a race event, after checking it against the events in force.
     *
     * @param int|null $substituiId the row this one corrects, if any
     * @param array<string, mixed> $post the form, for the confirmation fields
     * @param bool $isLiveClick "Largar agora": the time is the click itself, not a time somebody typed
     */
    private static function recordEvent(
        ContagemService $service,
        string $tipo,
        int $tsMs,
        string $username,
        ?int $substituiId,
        array $post,
        bool $isLiveClick = false
    ): void {
        $service->withLock(function () use ($service, $tipo, $tsMs, $username, $substituiId, $post, $isLiveClick): void {
            $ocorrencias = RaceEvents::toOcorrencias(RaceEvents::load($service->getProva()));
            $startMs = RaceEventRules::startMs($ocorrencias);
            $isCorrection = $substituiId !== null;
            $confirmed = self::said($post, self::CAMPO_CONFIRMAR);
            $newEvent = new Ocorrencia(PHP_INT_MAX, $tipo, $tsMs, null, $substituiId);

            if ($tipo !== Ocorrencia::LARGADA && $startMs === null) {
                throw new InvalidArgumentException('A corrida ainda não largou.');
            }
            if ($tipo !== Ocorrencia::LARGADA && $tsMs < $startMs) {
                throw new InvalidArgumentException('O horário informado é anterior à largada.');
            }
            if ($tipo === Ocorrencia::ENCERRAMENTO && !$isCorrection && !$confirmed) {
                throw new InvalidArgumentException('Confirme o encerramento da corrida.');
            }
            if ($tipo === Ocorrencia::LARGADA) {
                StartRules::checkStart($service, $startMs, $tsMs, $isCorrection, $post);
            }
            self::requireConsistentWith($ocorrencias, $newEvent);
            // The very first "Largar agora" asks nothing: every entry typed
            // so far is before it by definition (counters warming up), and a
            // question would stand between the controller and the start.
            $isFirstLiveStart = $isLiveClick && $startMs === null;
            if (!$isFirstLiveStart) {
                StartRules::requireMoveConfirmed($service->getProva(), $ocorrencias, $newEvent, $post);
            }

            $service->recordOcorrencia($tipo, $tsMs, null, $username, $substituiId);
        });
    }

    /**
     * Stores a new row of the same type that replaces a mistaken one.
     *
     * @param array<string, mixed> $post
     */
    private static function correctEvent(ContagemService $service, array $post, string $username): void
    {
        $tsMs = RaceMaintenance::parseTime($post['horario'] ?? '');
        if ($tsMs === null) {
            throw new InvalidArgumentException('Informe o novo horário.');
        }
        // The lock is taken here already (recordEvent takes it again, which
        // is allowed), so nobody can cancel the row between the check and
        // the correction.
        $service->withLock(function () use ($service, $post, $tsMs, $username): void {
            $original = self::requireEvent($service->getProva(), $post['ocorrencia_id'] ?? null, [RaceEvents::EM_VIGOR]);
            self::recordEvent($service, $original->tipo, $tsMs, $username, $original->id, $post);
        });
    }

    /**
     * Stores a "cancelamento" row pointing at an event: the event stops
     * counting, as if the button had never been pressed. When the event was
     * itself a correction, the row it had corrected counts again.
     *
     * @param array<string, mixed> $post
     */
    private static function cancelEvent(ContagemService $service, array $post, string $username): void
    {
        $service->withLock(function () use ($service, $post, $username): void {
            $prova = $service->getProva();
            $target = self::requireEvent($prova, $post['ocorrencia_id'] ?? null, [RaceEvents::EM_VIGOR, RaceEvents::SUPERADA]);
            $ocorrencias = RaceEvents::toOcorrencias(RaceEvents::load($prova));
            $cancellation = new Ocorrencia(PHP_INT_MAX, Ocorrencia::CANCELAMENTO, $target->tsMs, null, $target->id);
            self::requireConsistentWith($ocorrencias, $cancellation);

            $leavesNoStart = RaceEventRules::cancellingLeavesNoStart($ocorrencias, $target);
            if ($leavesNoStart && !self::said($post, self::CAMPO_CONFIRMAR_SEM_LARGADA)) {
                throw new ConfirmationNeeded(
                    'Esta é a única largada da corrida. Cancelá-la deixa a corrida SEM LARGADA: a contagem de todos os carros '
                        . 'volta a zero, os resultados publicados são zerados e o relógio da prova para. Nenhum registro é apagado, '
                        . 'e a largada pode ser restaurada depois. Se a intenção é mudar o horário, use "Corrigir".',
                    self::CAMPO_CONFIRMAR_SEM_LARGADA,
                    'Cancelar a única largada'
                );
            }

            // Cancelling a correction or a second start brings an older
            // start back, which may be later than the one in force.
            StartRules::requireMoveConfirmed($prova, $ocorrencias, $cancellation, $post);

            // The cancellation carries the time of what it cancels, so that
            // it sorts next to it wherever events are listed by time.
            $service->recordOcorrencia(Ocorrencia::CANCELAMENTO, $target->tsMs, null, $username, $target->id);
        });
    }

    /**
     * "Restaurar" on a cancelled row: cancels the cancellation, so the
     * event counts again exactly as it was.
     *
     * @param array<string, mixed> $post
     */
    private static function restoreEvent(ContagemService $service, array $post, string $username): void
    {
        $service->withLock(function () use ($service, $post, $username): void {
            $prova = $service->getProva();
            $target = self::requireEvent($prova, $post['ocorrencia_id'] ?? null, [RaceEvents::CANCELADA]);
            $ocorrencias = RaceEvents::toOcorrencias(RaceEvents::load($prova));
            $cancellation = RaceEvents::cancellationOf($ocorrencias, $target->id);
            if ($cancellation === null) {
                throw new InvalidArgumentException('Esta ocorrência não está cancelada. Recarregue a página.');
            }
            $restoration = new Ocorrencia(PHP_INT_MAX, Ocorrencia::CANCELAMENTO, $target->tsMs, null, $cancellation->id);
            self::requireConsistentWith($ocorrencias, $restoration);
            StartRules::requireMoveConfirmed($prova, $ocorrencias, $restoration, $post);
            $service->recordOcorrencia(Ocorrencia::CANCELAMENTO, $target->tsMs, null, $username, $cancellation->id);
        });
    }

    /**
     * A race event of this prova in one of the given situations. An id from
     * a form is never trusted on its own, and a row that was already
     * corrected or cancelled must not be corrected or cancelled again: the
     * page that offered the button was out of date.
     *
     * @param mixed $postedId
     * @param string[] $allowedSituations constants of RaceEvents
     */
    private static function requireEvent(Prova $prova, $postedId, array $allowedSituations): Ocorrencia
    {
        $ocorrenciaId = filter_var($postedId, FILTER_VALIDATE_INT);
        $ocorrencias = RaceEvents::toOcorrencias(RaceEvents::load($prova));
        $situations = RaceEvents::situations($ocorrencias);

        foreach ($ocorrencias as $ocorrencia) {
            if ($ocorrencia->id !== $ocorrenciaId || $ocorrencia->tipo === Ocorrencia::CANCELAMENTO) {
                continue;
            }
            if (!in_array($situations[$ocorrencia->id], $allowedSituations, true)) {
                throw new InvalidArgumentException('Esta ocorrência já foi corrigida, cancelada ou substituída. Recarregue a página.');
            }
            return $ocorrencia;
        }
        throw new InvalidArgumentException('Ocorrência não encontrada nesta prova.');
    }

    /**
     * @param Ocorrencia[] $ocorrencias the events stored so far
     */
    private static function requireConsistentWith(array $ocorrencias, Ocorrencia $newEvent): void
    {
        $ocorrencias[] = $newEvent;
        $problem = RaceEventRules::inconsistency($ocorrencias);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
    }

    /** @param array<string, mixed> $post */
    private static function said(array $post, string $field): bool
    {
        return ($post[$field] ?? '') === '1';
    }
}
