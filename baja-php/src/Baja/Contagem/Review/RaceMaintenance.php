<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Model\ContagemOcorrenciaQuery;
use Baja\Model\Prova;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The part of the race control page that is not about race events:
 * "Republicar tudo", "Reconstruir", "Zerar corrida", and reading the time
 * fields of its forms.
 */
final class RaceMaintenance
{
    /** A typed time further than this from now is taken to be a typo. */
    private const MAX_DISTANCE_FROM_NOW_MS = 7 * 24 * 3600 * 1000;

    /**
     * @param array<string, mixed> $post
     * @return string code of the confirmation message (see RaceControl::MESSAGES)
     */
    public static function handle(ContagemService $service, string $action, array $post, string $username, bool $isAdmin): string
    {
        // All three take the count lock themselves.
        switch ($action) {
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
