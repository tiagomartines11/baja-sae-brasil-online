<?php
declare(strict_types=1);

namespace Baja\Contagem\Access;

use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Rbac\Authorization;
use Baja\Rbac\Delegation\GrantRefused;
use Baja\Rbac\Legacy\LegacyCode;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;

/**
 * The two roles the lap counting's "Usuários" page hands out: lap_counter
 * (CounterLinks) and pit_logger (PitLoggers), on one prova (BAJAAPP-76).
 *
 * A race controller is usually not an administrator and holds no
 * role.grant, yet must be able to hand phones to volunteers on race
 * morning, as they could when these were the codes {EVT}_{PROVA}_CONT and
 * _BOX. So these grants go through RoleGranting::grantByAuthority with
 * contagem.control as the authority: whoever controls the prova may give
 * and take these two roles there, and nothing else. The grant ends when
 * the event's grants end by default, and never after the controller's
 * own; each one writes an audit row naming the controller.
 */
final class ContagemRoles
{
    public const COUNTER = LegacyCode::LAP_COUNTER;
    public const PIT_LOGGER = LegacyCode::PIT_LOGGER;

    /**
     * Gives $role on the prova to $user (saved first if new), on behalf of
     * $byUsername. Nothing happens when they already hold it there.
     *
     * @throws \InvalidArgumentException with a message in Portuguese, fit to show to the controller
     */
    public static function give(string $role, string $eventoId, string $provaId, User $user, string $byUsername): void
    {
        if ($user->isNew()) {
            $user->save();
        }
        $node = ScopeNode::prova($eventoId, $provaId);
        if (self::grantIdsOf($role, $node, (int) $user->getUserId()) !== []) {
            return;
        }
        $granterId = self::userId($byUsername);
        try {
            Authorization::services()->granting->grantByAuthority(Permission::CONTAGEM_CONTROL, $granterId, (int) $user->getUserId(), $role, $node);
        } catch (GrantRefused $refused) {
            throw new \InvalidArgumentException(self::explain($refused), 0, $refused);
        }
    }

    /**
     * Takes $role on the prova away from $username, on behalf of
     * $byUsername. Nothing happens when they do not hold it there.
     *
     * @throws \InvalidArgumentException with a message in Portuguese
     */
    public static function take(string $role, string $eventoId, string $provaId, string $username, string $byUsername): void
    {
        $user = UserQuery::create()->findOneByUsername($username);
        if ($user === null) {
            return;
        }
        $node = ScopeNode::prova($eventoId, $provaId);
        $grantIds = self::grantIdsOf($role, $node, (int) $user->getUserId());
        if ($grantIds === []) {
            return;
        }
        $revokerId = self::userId($byUsername);
        try {
            foreach ($grantIds as $grantId) {
                Authorization::services()->granting->revokeByAuthority(Permission::CONTAGEM_CONTROL, $role, $revokerId, $grantId);
            }
        } catch (GrantRefused $refused) {
            throw new \InvalidArgumentException(self::explain($refused), 0, $refused);
        }
    }

    /**
     * The usernames holding $role on the prova now, sorted.
     *
     * @return string[]
     */
    public static function holders(string $role, string $eventoId, string $provaId): array
    {
        $rbac = Authorization::services();
        $roleRow = $rbac->roles->byCode($role);
        if ($roleRow === null) {
            return [];
        }
        $names = [];
        foreach ($rbac->grants->activeOnNode(ScopeNode::prova($eventoId, $provaId), $rbac->clock->now(), $roleRow->id) as $grant) {
            $user = UserQuery::create()->findPk($grant->userId);
            if ($user !== null) {
                $names[(string) $user->getUsername()] = true;
            }
        }
        $names = array_keys($names);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return int[] the active grants of $role on exactly $node held by the user */
    private static function grantIdsOf(string $role, ScopeNode $node, int $userId): array
    {
        $rbac = Authorization::services();
        $roleRow = $rbac->roles->byCode($role);
        if ($roleRow === null) {
            return [];
        }
        $ids = [];
        foreach ($rbac->grants->activeOnNode($node, $rbac->clock->now(), $roleRow->id) as $grant) {
            if ($grant->userId === $userId) {
                $ids[] = $grant->id;
            }
        }
        return $ids;
    }

    private static function userId(string $username): int
    {
        $user = UserQuery::create()->findOneByUsername($username);
        if ($user === null) {
            throw new \InvalidArgumentException('Usuário "' . $username . '" não encontrado.');
        }
        return (int) $user->getUserId();
    }

    private static function explain(GrantRefused $refused): string
    {
        return match ($refused->reason) {
            GrantRefused::VALID_UNTIL_REQUIRED => 'O evento não tem data de término (data_fim). Peça a um administrador para preenchê-la em evento.php.',
            GrantRefused::VALID_UNTIL_IN_PAST => 'O seu acesso a esta prova já terminou.',
            GrantRefused::NO_ROLE_GRANT => 'Só o controle da prova pode adicionar ou remover pessoas aqui.',
            default => 'Não foi possível: ' . $refused->getMessage(),
        };
    }
}
