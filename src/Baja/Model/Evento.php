<?php

namespace Baja\Model;

use Baja\Model\Base\Evento as BaseEvento;

/**
 * Skeleton subclass for representing a row from the 'evento' table.
 *
 *
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 *
 */
class Evento extends BaseEvento
{

    public function getMenu()
    {
        return json_decode(parent::getMenu());
    }

    /**
     * @param object|array $v
     * @return $this|Evento
     */
    public function setMenu($v)
    {
        return parent::setMenu(json_encode($v));
    }
}
