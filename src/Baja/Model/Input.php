<?php

namespace Baja\Model;

use Baja\Model\Base\Input as BaseInput;
use Propel\Runtime\Exception\PropelException;
use Throwable;

/**
 * Skeleton subclass for representing a row from the 'input' table.
 *
 *
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 *
 */
class Input extends BaseInput
{
    /**
     * @return object
     */
    public function getDados()
    {
        return json_decode(parent::getDados());
    }

    /**
     * @param object|array $v
     * @return $this|Input
     */
    public function setDados($v)
    {
        return parent::setDados(json_encode($v));
    }

    /**
     * @return object
     */
    public function getVars()
    {
        return json_decode(parent::getVars());
    }

    /**
     * @param object|array $v
     * @return $this|Input
     */
    public function setPontos($v)
    {
        return parent::setPontos(json_encode($v));
    }

    /**
     * @return object
     */
    public function getPontos()
    {
        return json_decode(parent::getPontos());
    }

    /**
     * @param object|array $v
     * @return $this|Input
     */
    public function setVars($v)
    {
        return parent::setVars(json_encode($v));
    }


    public function updateVars() {
        $vars = [];
        foreach ((array)$this->getProva()->getParamsVars() as $v=>$formula) {
            $vars[$v] = self::solveFormula($this->getDados(), $formula);
        }
        foreach ((array)$this->getProva()->getParamsMinMax() as $v=>$formula) {
            $vars[$v] = self::solveFormula(array_merge((array)$this->getDados(), $vars), $formula);
        }
        $this->setVars($vars);
    }

    public function updatePontos() {
        try {
            $prova = $this->getProva();
            $dados = $this->getDados();
            $vars = $this->getVars();
            $varsMinMax = $prova->getTotals();

            $vars = array_merge((array)$dados, (array)$vars, (array)$varsMinMax);

            $pontos = (array)$prova->getParamsPontos();
            ksort($pontos);
            $pts = [];
            foreach ($pontos as $p => $formula) {
                $pts[$p] = self::solveFormula($vars, $formula);
                $vars[$p] = $pts[$p];
            }
            $this->setPontos($pts);
        } catch (PropelException $e) {
            return;
        }
    }

    public static function solveFormula($vars, $formula) {
        extract((array)$vars);
        @eval("\$formula = $formula;");
        return (!is_string($formula) && (is_bool($formula) || is_nan($formula) || is_infinite($formula))) ? null : ($formula === false ? null : $formula);
    }
}
