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
 * and the maintenance buttons.
 *
 * Race events are append-only like everything else: changing the start time
 * stores a new "largada" and the latest one wins.
 */
final class RaceControl
{
    /** Race events the page can record, with the label shown for each. */
    public const EVENT_LABELS = [
        Ocorrencia::LARGADA => 'Largada',
        Ocorrencia::BANDEIRA_VERMELHA => 'Bandeira vermelha',
        Ocorrencia::RELARGADA => 'Relargada',
        Ocorrencia::ENCERRAMENTO => 'Encerramento',
    ];

    /** A typed time further than this from now is taken to be a typo. */
    private const MAX_DISTANCE_FROM_NOW_MS = 7 * 24 * 3600 * 1000;

    /**
     * Runs one posted action.
     *
     * @param array<string, mixed> $post
     * @return string code of the confirmation message to show (see MESSAGES)
     * @throws InvalidArgumentException with a message in Portuguese
     */
    public static function handle(ContagemService $service, array $post, string $username): string
    {
        $action = is_string($post['acao'] ?? null) ? $post['acao'] : '';

        switch ($action) {
            case 'largar_agora':
                self::recordEvent($service, Ocorrencia::LARGADA, Clock::nowMs(), $username, null);
                return 'largada';
            case 'registrar':
                $tipo = is_string($post['tipo'] ?? null) ? $post['tipo'] : '';
                if (!isset(self::EVENT_LABELS[$tipo])) {
                    throw new InvalidArgumentException('Tipo de ocorrência desconhecido.');
                }
                $tsMs = self::parseTime($post['horario'] ?? '') ?? Clock::nowMs();
                self::recordEvent($service, $tipo, $tsMs, $username, null);
                return $tipo;
            case 'corrigir':
                self::correctEvent($service, $post, $username);
                return 'corrigida';
            // These three take the per-prova lock themselves.
            case 'republicar':
                $service->republishAll();
                return 'republicado';
            case 'reconstruir':
                $service->rebuildLaps();
                return 'reconstruido';
            case 'zerar':
                self::resetRace($service, $post);
                return 'zerado';
            default:
                throw new InvalidArgumentException('Ação desconhecida.');
        }
    }

    /** Confirmation texts, keyed by the codes handle() returns. */
    public const MESSAGES = [
        Ocorrencia::LARGADA => 'Largada registrada.',
        Ocorrencia::BANDEIRA_VERMELHA => 'Bandeira vermelha registrada.',
        Ocorrencia::RELARGADA => 'Relargada registrada.',
        Ocorrencia::ENCERRAMENTO => 'Encerramento registrado.',
        'corrigida' => 'Horário corrigido.',
        'republicado' => 'Todos os valores foram republicados.',
        'reconstruido' => 'As voltas foram reconstruídas.',
        'zerado' => 'A corrida foi zerada.',
    ];

    /**
     * @return ContagemOcorrencia[] race-control events (not pit events), oldest first
     */
    public static function raceEvents(Prova $prova): array
    {
        $rows = ContagemOcorrenciaQuery::create()
            ->filterByEventoId($prova->getEventoId())
            ->filterByProvaId($prova->getProvaId())
            ->filterByTipo(array_keys(self::EVENT_LABELS))
            ->orderByOcorrenciaId()
            ->find();
        return iterator_to_array($rows, false);
    }

    /**
     * @param ContagemOcorrencia[] $rows
     */
    public static function timelineOf(array $rows): RaceTimeline
    {
        $ocorrencias = [];
        foreach ($rows as $row) {
            $substitui = $row->getSubstituiId();
            $ocorrencias[] = new Ocorrencia(
                (int) $row->getOcorrenciaId(),
                (string) $row->getTipo(),
                (int) $row->getTsMs(),
                null,
                $substitui === null ? null : (int) $substitui
            );
        }
        return RaceTimeline::fromOcorrencias($ocorrencias);
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

    private static function recordEvent(ContagemService $service, string $tipo, int $tsMs, string $username, ?int $substituiId): void
    {
        $prova = $service->getProva();
        $timeline = self::timelineOf(self::raceEvents($prova));
        if ($tipo !== Ocorrencia::LARGADA && $timeline->largadaMs === null) {
            throw new InvalidArgumentException('A corrida ainda não largou.');
        }
        if ($tipo !== Ocorrencia::LARGADA && $tsMs < $timeline->largadaMs) {
            throw new InvalidArgumentException('O horário informado é anterior à largada.');
        }

        // recordOcorrencia() stores the event, sets prova.tempo for a start
        // and reruns the engine and the publisher; it only asks for the lock.
        $service->withLock(function () use ($service, $tipo, $tsMs, $username, $substituiId): void {
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
        $prova = $service->getProva();
        $ocorrenciaId = filter_var($post['ocorrencia_id'] ?? null, FILTER_VALIDATE_INT);
        $original = null;
        foreach (self::raceEvents($prova) as $row) {
            if ((int) $row->getOcorrenciaId() === $ocorrenciaId) {
                $original = $row;
            }
        }
        if ($original === null) {
            throw new InvalidArgumentException('Ocorrência não encontrada nesta prova.');
        }
        $tsMs = self::parseTime($post['horario'] ?? '');
        if ($tsMs === null) {
            throw new InvalidArgumentException('Informe o novo horário.');
        }
        self::recordEvent($service, (string) $original->getTipo(), $tsMs, $username, (int) $original->getOcorrenciaId());
    }

    /** @param array<string, mixed> $post */
    private static function resetRace(ContagemService $service, array $post): void
    {
        $typed = is_string($post['confirmacao'] ?? null) ? trim($post['confirmacao']) : '';
        // Typing the prova id is the guard: nobody deletes a race by a stray click.
        if ($typed !== $service->getProva()->getProvaId()) {
            throw new InvalidArgumentException('Confirmação incorreta: digite o código da prova exatamente como mostrado.');
        }
        $service->resetRace();
    }
}
