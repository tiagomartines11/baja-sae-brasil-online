<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Model\ContagemOcorrencia;
use Baja\Model\ContagemOcorrenciaQuery;
use Baja\Model\Prova;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The actions of the race control page: start, red flag, restart, finish,
 * correcting or cancelling any of them, and the maintenance buttons.
 *
 * Race events are append-only like everything else (see RaceEvents), so
 * every one of these actions can be undone: a wrong time is corrected, a
 * button pressed by mistake is cancelled.
 *
 * Each action is checked and stored while holding the count lock. Two
 * controllers pressing buttons at the same moment are thereby handled one
 * after the other, and the second is checked against what the first did.
 */
final class RaceControl
{
    /** Race events the page can record, with the label shown for each. */
    public const EVENT_LABELS = RaceEvents::LABELS;

    /** A typed time further than this from now is taken to be a typo. */
    private const MAX_DISTANCE_FROM_NOW_MS = 7 * 24 * 3600 * 1000;

    /** Confirmation texts, keyed by the codes handle() returns. */
    public const MESSAGES = [
        Ocorrencia::LARGADA => 'Largada registrada.',
        Ocorrencia::BANDEIRA_VERMELHA => 'Bandeira vermelha registrada.',
        Ocorrencia::RELARGADA => 'Relargada registrada.',
        Ocorrencia::ENCERRAMENTO => 'Encerramento registrado.',
        'corrigida' => 'Horário corrigido.',
        'cancelada' => 'Ocorrência cancelada.',
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
     * @throws InvalidArgumentException with a message in Portuguese
     * @throws \Baja\Contagem\Service\LockBusy when the count is busy for too long
     */
    public static function handle(ContagemService $service, array $post, string $username, bool $isAdmin = false): string
    {
        $action = is_string($post['acao'] ?? null) ? $post['acao'] : '';
        $confirmed = ($post['confirmar'] ?? '') === '1';

        switch ($action) {
            case 'largar_agora':
                self::recordEvent($service, Ocorrencia::LARGADA, Clock::nowMs(), $username, null, $confirmed);
                return Ocorrencia::LARGADA;
            case 'registrar':
                $tipo = is_string($post['tipo'] ?? null) ? $post['tipo'] : '';
                if (!isset(self::EVENT_LABELS[$tipo])) {
                    throw new InvalidArgumentException('Tipo de ocorrência desconhecido.');
                }
                $tsMs = self::parseTime($post['horario'] ?? '') ?? Clock::nowMs();
                self::recordEvent($service, $tipo, $tsMs, $username, null, $confirmed);
                return $tipo;
            case 'corrigir':
                self::correctEvent($service, $post, $username);
                return 'corrigida';
            case 'cancelar':
                self::cancelEvent($service, $post, $username);
                return 'cancelada';
            // These three take the count lock themselves.
            case 'republicar':
                $service->republishAll();
                return 'republicado';
            case 'reconstruir':
                $service->rebuildLaps();
                return 'reconstruido';
            case 'zerar':
                self::resetRace($service, $post, $username, $isAdmin);
                return 'zerado';
            default:
                throw new InvalidArgumentException('Ação desconhecida.');
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

    /**
     * Has this race ever been started, even if the start was cancelled
     * later? From then on it may be a real race, and wiping it needs an
     * administrator.
     */
    public static function hasEverStarted(Prova $prova): bool
    {
        return ContagemOcorrenciaQuery::create()
            ->filterByEventoId($prova->getEventoId())
            ->filterByProvaId($prova->getProvaId())
            ->filterByTipo(Ocorrencia::LARGADA)
            ->count() > 0;
    }

    /**
     * Reads the value of an <input type="datetime-local">, in the site's
     * time zone.
     *
     * @param mixed $value
     * @return int|null milliseconds since the epoch; null when the field was left empty
     * @throws InvalidArgumentException when it is filled but unreadable or absurd
     */
    public static function parseTime($value): ?int
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }
        // Browsers leave the seconds out when they are zero.
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        if ($parsed === false) {
            throw new InvalidArgumentException('Horário inválido.');
        }
        $tsMs = $parsed->getTimestamp() * 1000;
        if (abs($tsMs - Clock::nowMs()) > self::MAX_DISTANCE_FROM_NOW_MS) {
            throw new InvalidArgumentException('Horário muito distante de agora. Confira a data.');
        }
        return $tsMs;
    }

    /**
     * Stores a race event, after checking it against the events in force.
     *
     * @param int|null $substituiId the row this one corrects, if any
     * @param bool $confirmed the person was asked "are you sure?" and said yes
     */
    private static function recordEvent(
        ContagemService $service,
        string $tipo,
        int $tsMs,
        string $username,
        ?int $substituiId,
        bool $confirmed
    ): void {
        $service->withLock(function () use ($service, $tipo, $tsMs, $username, $substituiId, $confirmed): void {
            $ocorrencias = RaceEvents::toOcorrencias(RaceEvents::load($service->getProva()));
            $timeline = RaceTimeline::fromOcorrencias($ocorrencias);
            $isCorrection = $substituiId !== null;

            if ($tipo !== Ocorrencia::LARGADA && $timeline->largadaMs === null) {
                throw new InvalidArgumentException('A corrida ainda não largou.');
            }
            if ($tipo !== Ocorrencia::LARGADA && $tsMs < $timeline->largadaMs) {
                throw new InvalidArgumentException('O horário informado é anterior à largada.');
            }
            if ($tipo === Ocorrencia::LARGADA && !$isCorrection && $timeline->largadaMs !== null && !$confirmed) {
                // The page asks before sending. This catches the page that
                // was opened before somebody else started the race, and the
                // second click of a double click.
                throw new InvalidArgumentException(
                    'A corrida já tem largada (' . Pagina::formatClock($timeline->largadaMs) . '). Confirme para substituir o horário.'
                );
            }
            if ($tipo === Ocorrencia::ENCERRAMENTO && !$isCorrection && !$confirmed) {
                throw new InvalidArgumentException('Confirme o encerramento da corrida.');
            }
            self::requireConsistentWith($ocorrencias, new Ocorrencia(PHP_INT_MAX, $tipo, $tsMs, null, $substituiId));

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
        $tsMs = self::parseTime($post['horario'] ?? '');
        if ($tsMs === null) {
            throw new InvalidArgumentException('Informe o novo horário.');
        }
        // The lock is taken here already (recordEvent takes it again, which
        // is allowed), so nobody can cancel the row between the check and
        // the correction.
        $service->withLock(function () use ($service, $post, $tsMs, $username): void {
            $original = self::requireEvent($service->getProva(), $post['ocorrencia_id'] ?? null, [RaceEvents::EM_VIGOR]);
            self::recordEvent($service, $original->tipo, $tsMs, $username, $original->id, true);
        });
    }

    /**
     * Stores a "cancelamento" row pointing at an event: the event stops
     * counting, as if the button had never been pressed.
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

            // The cancellation carries the time of what it cancels, so that
            // it sorts next to it wherever events are listed by time.
            $service->recordOcorrencia(Ocorrencia::CANCELAMENTO, $target->tsMs, null, $username, $target->id);
        });
    }

    /**
     * A race event of this prova in one of the given situations. An id from
     * a form is never trusted on its own, and a row that was already
     * corrected or cancelled must not be corrected or cancelled again: the
     * page that offered the button was out of date.
     *
     * @param mixed $postedId
     * @param string[] $allowedSituations RaceEvents::EM_VIGOR and/or RaceEvents::SUPERADA
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
        $problem = RaceEvents::inconsistency($ocorrencias);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function resetRace(ContagemService $service, array $post, string $username, bool $isAdmin): void
    {
        $prova = $service->getProva();
        if (!$isAdmin && self::hasEverStarted($prova)) {
            // A controller may clear a rehearsal that never left the grid.
            // A race that was started may be THE race, and one person's
            // mistake must not be enough to wipe it.
            throw new InvalidArgumentException(
                'Esta corrida já teve largada: só um administrador pode zerá-la.'
            );
        }
        $typed = is_string($post['confirmacao'] ?? null) ? trim($post['confirmacao']) : '';
        // Typing the prova id is the guard: nobody deletes a race by a stray click.
        if ($typed !== $prova->getProvaId()) {
            throw new InvalidArgumentException('Confirmação incorreta: digite o código da prova exatamente como mostrado.');
        }
        $service->resetRace($username);
    }
}
