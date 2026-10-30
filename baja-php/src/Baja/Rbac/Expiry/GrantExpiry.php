<?php
declare(strict_types=1);

namespace Baja\Rbac\Expiry;

use Baja\Rbac\Clock;
use Baja\Rbac\Delegation\GrantRefused;
use Baja\Rbac\Grant\GrantRepository;
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
 *     valid_until = 23:59:59 on (evento.data_fim + DAYS)
 *
 * DAYS is the config key rbac.dias_apos_evento (Baja\SysConfig), 7 when
 * it is not set.
 *
 * An event without data_fim gives no default: the grant is REFUSED unless
 * the granter gives valid_until themselves. Guessing either way is worse:
 * too short and judges are locked out during the event; no end at all and
 * access to the event never goes away.
 *
 * A grant never outlives the granter's own right to grant there (the end
 * of their covering grant, worked out by RoleGranting):
 *
 *   - a valid_until the granter asks for that is later is REFUSED, with a
 *     message saying until when they may grant. It is not shortened
 *     behind their back;
 *   - the default end is brought forward to it when it would be later, and
 *     a grant with no default end (on a program) ends then too. Nobody
 *     asked for those dates, so using the latest one allowed is not a
 *     surprise.
 *
 * A valid_until must be in the future (a grant that would be born expired
 * is refused, not silently made). Grants above an event (program,
 * platform) have no default end.
 */
final class GrantExpiry
{
    public const CONFIG_KEY = 'rbac.dias_apos_evento';
    public const DEFAULT_DAYS_AFTER_EVENT = 7;

    /**
     * @param Closure(string): ?string $eventLastDay evento_id => evento.data_fim ('Y-m-d'), null when not set
     */
    public function __construct(
        private Closure $eventLastDay,
        private Clock $clock,
        private int $daysAfterEvent = self::DEFAULT_DAYS_AFTER_EVENT,
    ) {
    }

    public static function forDatabase(ConnectionInterface $con, Clock $clock): self
    {
        $eventLastDay = function (string $eventoId) use ($con): ?string {
            $statement = $con->prepare('SELECT data_fim FROM evento WHERE evento_id = ?');
            $statement->execute([$eventoId]);
            $dataFim = $statement->fetchAll(PDO::FETCH_COLUMN)[0] ?? null;
            return $dataFim === null ? null : (string) $dataFim;
        };
        return new self($eventLastDay, $clock, self::configuredDays());
    }

    /**
     * The valid_until to store for a new grant on $node.
     *
     * @param DateTimeImmutable|null $requested what the granter asked for; null for the default
     * @param DateTimeImmutable|null $granterRightEnds when the granter's own right to grant on $node ends; null when it never does
     * @throws GrantRefused VALID_UNTIL_REQUIRED, VALID_UNTIL_AFTER_GRANTER or VALID_UNTIL_IN_PAST
     */
    public function validUntilFor(ScopeNode $node, ?DateTimeImmutable $requested, ?DateTimeImmutable $granterRightEnds): ?DateTimeImmutable
    {
        if ($requested !== null) {
            if ($granterRightEnds !== null && $requested > $granterRightEnds) {
                throw new GrantRefused(
                    GrantRefused::VALID_UNTIL_AFTER_GRANTER,
                    'The granter may only give roles here until ' . GrantRepository::format($granterRightEnds)
                    . ', so this grant cannot end later (asked for ' . GrantRepository::format($requested) . ').'
                );
            }
            $validUntil = $requested;
        } else {
            $validUntil = $this->defaultEnd($node);
            if ($granterRightEnds !== null && ($validUntil === null || $validUntil > $granterRightEnds)) {
                $validUntil = $granterRightEnds;
            }
        }

        if ($validUntil !== null && $validUntil <= $this->clock->now()) {
            throw new GrantRefused(GrantRefused::VALID_UNTIL_IN_PAST, 'The grant would end before it starts (valid_until is not in the future).');
        }
        return $validUntil;
    }

    /** DAYS after the event's last day, for a node inside an event; null above one. */
    private function defaultEnd(ScopeNode $node): ?DateTimeImmutable
    {
        if (!$node->type->isInsideAnEvent()) {
            return null;
        }
        $dataFim = ($this->eventLastDay)((string) $node->eventoId());
        $lastDay = $dataFim === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $dataFim);
        if ($lastDay === false) {
            throw new GrantRefused(
                GrantRefused::VALID_UNTIL_REQUIRED,
                "Event {$node->eventoId()} has no end date (data_fim), so this grant needs an explicit end (valid_until)."
            );
        }
        return $lastDay->modify("+{$this->daysAfterEvent} days")->setTime(23, 59, 59);
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
