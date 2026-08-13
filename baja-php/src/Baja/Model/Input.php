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
        return json_decode(parent::getDados() ?? '');
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
        return json_decode(parent::getVars() ?? '');
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
        return json_decode(parent::getPontos() ?? '');
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
            #$prova = $this->getProva();
            #$dados = $this->getDados();
            #$vars = $this->getVars();
            #$varsMinMax = $prova->getTotals();

            #$vars = array_merge((array)$dados, (array)$vars, (array)$varsMinMax);

            #$pontos = (array)$prova->getParamsPontos();
            #ksort($pontos);
            #$pts = [];
            #foreach ($pontos as $p => $formula) {
                #$pts[$p] = self::solveFormula($vars, $formula);
                #$vars[$p] = $pts[$p];
            #}
            #$this->setPontos($pts);

            #Ajuste para desclassificadas
            if (!$this->getEquipe()->isDesclassificado()) {
            
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
            }
        } catch (PropelException $e) {
            return;
        }
    }

    public static function solveFormula($vars, $formula) {
        extract((array)$vars);

        // Rede de segurança: uma fórmula malformada não deve derrubar a página
        // inteira. Em PHP 8 usar "+" para concatenar strings ('<b>'+X) é um
        // TypeError fatal (no PHP 7 a string era coagida a 0 silenciosamente).
        // Fórmulas assim devem ser corrigidas na origem (usar "." em vez de "+");
        // aqui apenas degradamos para null em vez de derrubar a página — o mesmo
        // que o "@" fazia no PHP 7 para erros não fatais.
        try {
            @eval("\$formula = $formula;");
        } catch (\Throwable $e) {
            return null;
        }

        if (is_string($formula)) {
            return $formula;
        }
        // is_nan()/is_infinite() só aceitam float; chamar com null gera deprecation.
        if (!is_int($formula) && !is_float($formula)) {
            return null;
        }
        return (is_nan($formula) || is_infinite($formula)) ? null : $formula;
    }
}
