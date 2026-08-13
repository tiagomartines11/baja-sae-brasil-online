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
    /**
     * Permission codes that are global sentinels rather than real access grants.
     * 'index' must exist for a user to reach juiz/index.php, but on its own it
     * grants no meaningful access.
     */
    const SENTINEL_PERMISSIONS = ['index'];

    /**
     * Replace only the permissions that fall within a given scope, preserving
     * every permission outside it. Use this from any editor that only exposes a
     * subset of a user's permissions (e.g. the provas of a single event) so that
     * saving it never wipes permissions the editor can't see.
     *
     * @param string[] $scopeCodes   every permission code this editor controls
     * @param string[] $checkedCodes the codes within the scope that should be granted
     * @return $this
     */
    public function setScopedPermissions(array $scopeCodes, array $checkedCodes)
    {
        $kept = array_filter(
            $this->getPermissions(),
            fn($p) => !in_array($p, $scopeCodes, true)
        );
        $granted = array_intersect($scopeCodes, $checkedCodes);
        $merged = array_values(array_unique(array_merge(self::SENTINEL_PERMISSIONS, $kept, $granted)));
        $this->setPermissions($merged);

        return $this;
    }

    /**
     * True when the user holds no permission beyond the global sentinels, i.e.
     * they can no longer do anything meaningful and the row can be removed.
     *
     * @return bool
     */
    public function hasNoMeaningfulPermissions(): bool
    {
        return count(array_diff($this->getPermissions(), self::SENTINEL_PERMISSIONS)) === 0;
    }
}
