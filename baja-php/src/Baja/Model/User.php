<?php

namespace Baja\Model;

use Baja\Model\Base\User as BaseUser;

/**
 * Skeleton subclass for representing a row from the 'user' table.
 *
 *
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 *
 */
class User extends BaseUser
{
    // What a user may do is not in this row any more: it is their RBAC
    // grants (user_role, docs/rbac.md, BAJAAPP-76). The `permissions`
    // column is frozen for one release as the way back, and is still
    // read for one thing only: a team's own queue code,
    // {EVT}_FILA_{n}_{team} (Baja\Fila\Fila), which is not a role.
}
