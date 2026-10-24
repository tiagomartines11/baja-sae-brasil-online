<?php
declare(strict_types=1);

namespace Baja\Results;

/**
 * Who is looking at a results table, and so whether spoilers are hidden
 * from them. This is the only place that decides it.
 *
 * During an event the organisers can turn spoilers on: columns that have a
 * "val_spoilers" then show that instead of their real value (for example
 * "?" instead of the points), until the event is finished.
 *
 *   publicView()    the public results page (resultados/prova.php) and the
 *                   results API. Spoilers are hidden whenever the event has
 *                   them on and is not finished. Nobody gets past them here,
 *                   whoever they are: the page is cached publicly.
 *
 *   authorized()    the judges' copy of the page (resultados/prova_juiz.php),
 *                   for a forum user. Same rule, except that a user named in
 *                   the resultado's "authorized_users", or a developer, sees
 *                   the real values.
 *
 *   premiacao()     the awards screens (Baja\Premiacao\Resolver). They have
 *                   never hidden spoilers; they only exist for the organisers.
 */
final class ViewerContext
{
    private const PUBLIC = 'public';
    private const AUTHORIZED = 'authorized';
    private const PREMIACAO = 'premiacao';

    private function __construct(
        private readonly string $kind,
        private readonly ?EventStatus $event,
        private readonly ?string $username = null,
        private readonly bool $developer = false,
    ) {
    }

    public static function publicView(EventStatus $event): self
    {
        return new self(self::PUBLIC, $event);
    }

    /**
     * @param ?string $username  the forum user name ($user->data["username"])
     * @param bool    $developer bootstrap.php's $_DEV_MODE
     */
    public static function authorized(EventStatus $event, ?string $username, bool $developer): self
    {
        return new self(self::AUTHORIZED, $event, $username, $developer);
    }

    public static function premiacao(): self
    {
        return new self(self::PREMIACAO, null);
    }

    /**
     * Whether columns with a "val_spoilers" show that instead of their value.
     *
     * @param array<mixed> $authorizedUsers the resultado's "authorized_users"
     */
    public function hidesSpoilers(array $authorizedUsers): bool
    {
        if ($this->kind === self::PREMIACAO) {
            return false;
        }
        $eventHidesThem = $this->event->spoilers && !$this->event->finished;
        if (!$eventHidesThem || $this->kind === self::PUBLIC) {
            return $eventHidesThem;
        }
        // The loose in_array() is what the judge page always did.
        return !$this->developer && !in_array($this->username, $authorizedUsers);
    }
}
