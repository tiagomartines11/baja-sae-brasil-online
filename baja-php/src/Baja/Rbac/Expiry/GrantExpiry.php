<?php
declare(strict_types=1);

namespace Baja\Rbac\Expiry;

use Baja\Rbac\Clock;
use Baja\Rbac\Delegation\GrantRefused;
use Baja\Rbac\Scope\ScopeNode;
use Baja\SysConfig;
use Closure;
use DateTimeImmutable;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;
use Throwable;

/**
 * When a new grant ends (user_role.valid_until).
 *
 * A grant on an event, or on one of its provas or queues, is for that
 * event: judges and queue managers should not keep access to an event
 * months after it ended. When the granter does not say, it ends a number
 * of days after the event's last day:
 *
 *     valid_until = 23:59:59 on (last day + DAYS)
 *
 * DAYS is the config key rbac.dias_apos_evento (Baja\SysConfig), 7 when
 * it is not set.
 *
 * The last day is read from evento.data, which is free text
 * (EventDateParser). When it cannot be read, the grant is REFUSED unless
 * the granter gives valid_until themselves. Guessing either way is worse:
 * too short and judges are locked out during the event; no end at all and
 * access to the event never goes away.
 *
 * A given valid_until must be in the future, and so must the default one
 * (a grant that would be born expired is refused, not silently made).
 * Grants above an event (program, platform) have no default end.
 */
final class GrantExpiry
{
    public const CONFIG_KEY = 'rbac.dias_apos_evento';
    public const DEFAULT_DAYS_AFTER_EVENT = 7;

    /**
     * @param Closure(string): ?string $eventDateText evento_id => evento.data
     */
    public function __construct(
        private Closure $eventDateText,
        private Clock $clock,
        private int $daysAfterEvent = self::DEFAULT_DAYS_AFTER_EVENT,
    ) {
    }

    public static function forDatabase(ConnectionInterface $con, Clock $clock): self
    {
        $eventDateText = function (string $eventoId) use ($con): ?string {
            $statement = $con->prepare('SELECT data FROM evento WHERE evento_id = ?');
            $statement->execute([$eventoId]);
            $data = $statement->fetchAll(PDO::FETCH_COLUMN)[0] ?? null;
            return $data === null ? null : (string) $data;
        };
        return new self($eventDateText, $clock, self::configuredDays());
    }

    /**
     * The valid_until to store for a new grant on $node.
     *
     * @throws GrantRefused VALID_UNTIL_REQUIRED or VALID_UNTIL_IN_PAST
     */
    public function validUntilFor(ScopeNode $node, ?DateTimeImmutable $requested): ?DateTimeImmutable
    {
        $now = $this->clock->now();
        $validUntil = $requested;

        if ($validUntil === null && $node->type->isInsideAnEvent()) {
            $lastDay = EventDateParser::lastDay(($this->eventDateText)((string) $node->eventoId()));
            if ($lastDay === null) {
                throw new GrantRefused(
                    GrantRefused::VALID_UNTIL_REQUIRED,
                    "The date of event {$node->eventoId()} cannot be read, so this grant needs an explicit end (valid_until)."
                );
            }
            $validUntil = $lastDay->modify("+{$this->daysAfterEvent} days")->setTime(23, 59, 59);
        }

        if ($validUntil !== null && $validUntil <= $now) {
            throw new GrantRefused(GrantRefused::VALID_UNTIL_IN_PAST, 'The grant would end before it starts (valid_until is not in the future).');
        }
        return $validUntil;
    }

    private static function configuredDays(): int
    {
        try {
            $days = SysConfig::get(self::CONFIG_KEY, self::DEFAULT_DAYS_AFTER_EVENT);
        } catch (Throwable $e) {
            // No config table (it is created by a migration of its own): the default.
            return self::DEFAULT_DAYS_AFTER_EVENT;
        }
        return is_int($days) && $days >= 0 ? $days : self::DEFAULT_DAYS_AFTER_EVENT;
    }
}
