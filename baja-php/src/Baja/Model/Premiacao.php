<?php

namespace Baja\Model;

use Baja\Model\Base\Premiacao as BasePremiacao;

/**
 * Skeleton subclass for representing a row from the 'premiacao' table.
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 *
 */
class Premiacao extends BasePremiacao
{
    /**
     * @return object|null
     */
    public function getCategorias()
    {
        return json_decode(parent::getCategorias());
    }

    /**
     * @param object|array|null $v
     * @return $this|Premiacao
     */
    public function setCategorias($v)
    {
        return parent::setCategorias($v !== null ? json_encode($v) : null);
    }
}
