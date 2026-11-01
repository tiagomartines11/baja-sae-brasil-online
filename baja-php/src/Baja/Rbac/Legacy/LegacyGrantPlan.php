<?php
declare(strict_types=1);

namespace Baja\Rbac\Legacy;

use Baja\Rbac\Expiry\GrantExpiry;
use Baja\Rbac\Grant\GrantRepository;
use Baja\Rbac\Scope\ScopeNode;
use DateTimeImmutable;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * The one-off move of every legacy code in user.permissions to grants
 * (BAJAAPP-76): what PropelMigration_1793110000 writes, worked out first
 * and printed, so that nothing is dropped silently.
 *
 * Each code goes through LegacyCode::map(). Each grant it gives is written
 * with granted_by NULL (the migration, as for the admins in phase 2) and
 * one role_audit row, and ends:
 *
 *   - on an event, a prova or a queue: 23:59:59 on evento.data_fim + the
 *     configured days (rbac.dias_apos_evento, 7 by default), the same end
 *     GrantExpiry gives a grant made by hand;
 *   - with no end, when the event has no data_fim. Those events are listed
 *     (noEndDate) for a person to set the date on juiz/evento.php and then
 *     shorten the grants;
 *   - with no end on a program or the platform.
 *
 * A grant whose end is already past (an event that ended long ago) is
 * still written, so the record of who had what stays, and is listed
 * (expired). It gives nothing.
 *
 * A grant the user already holds, active, with the same role on the same
 * node (the platform admins from phase 2) is not written again.
 *
 * user.permissions is only READ here. It stays as it was, for one release,
 * as the way back (docs/rbac.md, "Rolling back").
 */
final class LegacyGrantPlan
{
    public const SOURCE = 'PropelMigration_1793110000: legacy code';

    /** @var array<int, array{user_id: int, username: string, code: string, role: string, node: ScopeNode, valid_until: ?DateTimeImmutable}> */
    public array $grants = [];

    /** @var array<int, array{user_id: int, username: string, code: string, reason: string}> */
    public array $unmapped = [];

    /** @var array<int, array{user_id: int, username: string, code: string, reason: string}> */
    public array $kept = [];

    /** @var array<int, array{user_id: int, username: string, code: string}> */
    public array $dropped = [];

    /** @var array<int, array{user_id: int, username: string, code: string, role: string, node: string}> */
    public array $alreadyHeld = [];

    /** @var array<string, string[]> evento_id => the codes whose grants got no end, "username: code" */
    public array $noEndDate = [];

    /** @var array<int, array{username: string, code: string, valid_until: string}> */
    public array $expired = [];

    private function __construct()
    {
    }

    public static function build(ConnectionInterface $con, DateTimeImmutable $now, ?int $daysAfterEvent = null, ?string $usernameLike = null): self
    {
        $plan = new self();
        $world = new DatabaseLegacyWorld($con);
        $days = $daysAfterEvent ?? GrantExpiry::configuredDays();

        $lastDay = [];
        foreach ($con->query('SELECT evento_id, data_fim FROM evento')->fetchAll(PDO::FETCH_NUM) as [$evento, $dataFim]) {
            $lastDay[(string) $evento] = $dataFim === null ? null : (string) $dataFim;
        }

        $held = [];
        $active = $con->prepare('SELECT g.user_id, r.code, g.scope_type, g.scope_id FROM user_role g JOIN role r ON r.role_id = g.role_id
                                  WHERE g.revoked_at IS NULL AND (g.valid_until IS NULL OR g.valid_until > ?)');
        $active->execute([GrantRepository::format($now)]);
        foreach ($active->fetchAll(PDO::FETCH_NUM) as [$userId, $role, $type, $id]) {
            $held[$userId . "\0" . $role . "\0" . $type . ':' . $id] = true;
        }

        $sql = 'SELECT user_id, username, permissions FROM user';
        $params = [];
        if ($usernameLike !== null) {
            // Tests: only their own made-up users.
            $sql .= ' WHERE username LIKE ?';
            $params[] = $usernameLike;
        }
        $users = $con->prepare($sql . ' ORDER BY user_id');
        $users->execute($params);

        foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int) $row['user_id'];
            $username = (string) $row['username'];
            $planned = [];
            foreach (self::codes($row['permissions']) as $code) {
                $mapping = LegacyCode::map($code, $world);
                $who = ['user_id' => $userId, 'username' => $username, 'code' => $code];
                switch ($mapping->outcome) {
                    case LegacyMapping::UNMAPPED:
                        $plan->unmapped[] = $who + ['reason' => $mapping->reason];
                        continue 2;
                    case LegacyMapping::KEPT:
                        $plan->kept[] = $who + ['reason' => $mapping->reason];
                        continue 2;
                    case LegacyMapping::DROPPED:
                        $plan->dropped[] = $who;
                        continue 2;
                }
                foreach ($mapping->grants as [$role, $node]) {
                    $key = $userId . "\0" . $role . "\0" . $node->key();
                    if (isset($held[$key])) {
                        $plan->alreadyHeld[] = $who + ['role' => $role, 'node' => $node->key()];
                        continue;
                    }
                    if (isset($planned[$key])) {
                        continue;
                    }
                    $planned[$key] = true;

                    $validUntil = null;
                    $evento = $node->eventoId();
                    if ($evento !== null) {
                        $dataFim = $lastDay[$evento] ?? null;
                        $day = $dataFim === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $dataFim);
                        if ($day === false) {
                            $plan->noEndDate[$evento][] = "$username: $code";
                        } else {
                            $validUntil = GrantExpiry::endAfterEvent($day, $days);
                            if ($validUntil <= $now) {
                                $plan->expired[] = ['username' => $username, 'code' => $code, 'valid_until' => GrantRepository::format($validUntil)];
                            }
                        }
                    }
                    $plan->grants[] = $who + ['role' => $role, 'node' => $node, 'valid_until' => $validUntil];
                }
            }
        }
        ksort($plan->noEndDate);
        return $plan;
    }

    /**
     * The statements that write the grants: for each, the user_role row and
     * its role_audit row (LAST_INSERT_ID() is the grant just written).
     * Values are quoted with $quote ($con->quote(...)).
     *
     * @param callable(string): string $quote
     * @return string[]
     */
    public function statements(callable $quote, DateTimeImmutable $now): array
    {
        $q = fn(?string $v) => $v === null ? 'NULL' : $quote($v);
        $grantedAt = $q(GrantRepository::format($now));
        $statements = [];
        foreach ($this->grants as $g) {
            $validUntil = $g['valid_until'] === null ? null : GrantRepository::format($g['valid_until']);
            $statements[] = 'INSERT INTO `user_role` (`user_id`, `role_id`, `scope_type`, `scope_id`, `granted_by`, `granted_at`, `valid_until`) '
                . 'SELECT ' . (int) $g['user_id'] . ', `role_id`, ' . $q($g['node']->type->value) . ', ' . $q($g['node']->id) . ', NULL, ' . $grantedAt . ', ' . $q($validUntil)
                . ' FROM `role` WHERE `code` = ' . $q($g['role']);
            $statements[] = 'INSERT INTO `role_audit` (`occurred_at`, `actor_user_id`, `action`, `grant_id`, `role_id`, `target_user_id`, `scope_type`, `scope_id`, `details`) '
                . 'SELECT ' . $grantedAt . ', NULL, \'grant\', LAST_INSERT_ID(), `role_id`, ' . (int) $g['user_id'] . ', ' . $q($g['node']->type->value) . ', ' . $q($g['node']->id)
                . ', JSON_OBJECT(\'role\', ' . $q($g['role']) . ', \'valid_until\', ' . $q($validUntil) . ', \'source\', ' . $q(self::SOURCE) . ', \'legacy_code\', ' . $q($g['code']) . ')'
                . ' FROM `role` WHERE `code` = ' . $q($g['role']);
        }
        return $statements;
    }

    /** The report the migration prints. */
    public function report(): string
    {
        $lines = ['BAJAAPP-76: ' . count($this->grants) . ' grant(s) from the legacy codes in user.permissions.'];
        if ($this->alreadyHeld !== []) {
            $lines[] = '  ' . count($this->alreadyHeld) . ' already held (same role, same node, active), not written again.';
        }
        if ($this->dropped !== []) {
            $lines[] = '  ' . count($this->dropped) . " 'index' code(s) dropped: the start page now follows any grant in the event.";
        }
        if ($this->kept !== []) {
            $lines[] = '  ' . count($this->kept) . " team queue code(s) KEPT in user.permissions (still read there, until the teams package).";
        }
        if ($this->unmapped !== []) {
            $lines[] = 'UNMAPPED: these ' . count($this->unmapped) . ' code(s) give nothing now. Check them; grant a role by hand where one is still needed:';
            foreach ($this->unmapped as $u) {
                $lines[] = "    {$u['username']}  {$u['code']}  ({$u['reason']})";
            }
        }
        if ($this->noEndDate !== []) {
            $lines[] = 'NO END DATE: these events have no data_fim, so their grants were given with NO END. Set the dates on juiz/evento.php, then shorten the grants:';
            foreach ($this->noEndDate as $evento => $who) {
                $lines[] = "    $evento  " . implode(', ', $who);
            }
        }
        if ($this->expired !== []) {
            $lines[] = 'ALREADY ENDED: ' . count($this->expired) . ' grant(s) were written with an end in the past (their event ended); they give nothing:';
            foreach ($this->expired as $e) {
                $lines[] = "    {$e['username']}  {$e['code']}  until {$e['valid_until']}";
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * The codes in a user.permissions value, as Propel's ARRAY column reads
     * it ("| a | b |"), without blanks or repeats.
     *
     * @return string[]
     */
    public static function codes(?string $stored): array
    {
        if ($stored === null || strlen($stored) < 4) {
            return [];
        }
        $inner = substr($stored, 2, -2);
        $codes = $inner === '' ? [] : explode(' | ', $inner);
        return array_values(array_unique(array_filter(array_map('trim', $codes), fn($c) => $c !== '')));
    }
}
