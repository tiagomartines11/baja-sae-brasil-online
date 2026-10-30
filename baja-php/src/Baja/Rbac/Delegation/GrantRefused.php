<?php
declare(strict_types=1);

namespace Baja\Rbac\Delegation;

use RuntimeException;

/**
 * A grant, revoke or role edit that the rules do not allow. Nothing was
 * written: no grant, no audit row.
 *
 * $reason is one of the constants, for callers (and tests) to tell the
 * cases apart; the message is for people.
 */
final class GrantRefused extends RuntimeException
{
    /** The node does not exist (or its way up to the platform is broken). */
    public const UNKNOWN_NODE = 'unknown_node';
    public const UNKNOWN_ROLE = 'unknown_role';
    public const UNKNOWN_USER = 'unknown_user';
    public const UNKNOWN_GRANT = 'unknown_grant';
    public const ALREADY_REVOKED = 'already_revoked';

    /** The role may not be given on this type of node (a judge on a whole event). */
    public const ROLE_NOT_ALLOWED_HERE = 'role_not_allowed_here';

    /** The granter does not hold role.grant here or above. */
    public const NO_ROLE_GRANT = 'no_role_grant';

    /** The role carries a permission the granter does not hold here. */
    public const ESCALATION = 'escalation';

    /** A system role, and the granter does not hold role.manage. */
    public const SYSTEM_ROLE = 'system_role';

    /** Role definitions may only be edited by holders of role.manage. */
    public const NO_ROLE_MANAGE = 'no_role_manage';

    /** A role definition that names no node type, or a code that is not valid. */
    public const INVALID_ROLE = 'invalid_role';

    public const VALID_UNTIL_REQUIRED = 'valid_until_required';
    public const VALID_UNTIL_IN_PAST = 'valid_until_in_past';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
