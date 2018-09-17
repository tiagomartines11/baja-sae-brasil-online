<?php

namespace Baja\Model;

use Baja\Model\Base\Resultado as BaseResultado;

/**
 * Skeleton subclass for representing a row from the 'resultado' table.
 *
 *
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 *
 */
class Resultado extends BaseResultado
{
    /**
     * @return object
     */
    public function getColunas()
    {
        return json_decode(parent::getColunas());
    }

    /**
     * @param string $v
     * @return $this|Prova
     */
    public function setColunas($v)
    {
        return parent::setColunas(json_encode($v));
    }

    public function getIsTrophy()
    {
        return true;
    }
}
