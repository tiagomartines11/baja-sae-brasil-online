<?php

namespace Baja\Model;

use Baja\Formula\FormulaEngine;
use Baja\Formula\FormulaEngines;
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

    /**
     * Told about every Throwable a formula raises, just before solveFormula()
     * turns it into null. Null by default, and the pages never set it, so for
     * them nothing changes. scripts/check-results-configs.php sets it to list
     * the broken formulas (see Baja\ResultsCheck\FormulaErrors).
     *
     * @var null|callable(Throwable, mixed): void  the error, and the formula as given
     */
    private static $formulaErrorListener = null;

    /** Sets (or, with null, removes) the listener above. */
    public static function setFormulaErrorListener(?callable $listener): void
    {
        self::$formulaErrorListener = $listener;
    }

    /**
     * The engine that works the formulas out (Baja\Formula\FormulaEngines,
     * from the FORMULA_ENGINE setting), made on first use.
     */
    private static ?FormulaEngine $formulaEngine = null;

    /**
     * Sets the engine (or, with null, goes back to the configured one).
     * For the corpus comparison and the tests; the pages never call it.
     */
    public static function setFormulaEngine(?FormulaEngine $engine): void
    {
        self::$formulaEngine = $engine;
    }

    public static function solveFormula($vars, $formula) {
        // Rede de segurança: uma fórmula malformada não deve derrubar a página
        // inteira. Em PHP 8 usar "+" para concatenar strings ('<b>'+X) é um
        // TypeError fatal (no PHP 7 a string era coagida a 0 silenciosamente).
        // Fórmulas assim devem ser corrigidas na origem (usar "." em vez de "+");
        // aqui apenas degradamos para null em vez de derrubar a página — o mesmo
        // que o "@" fazia no PHP 7 para erros não fatais.
        try {
            self::$formulaEngine ??= FormulaEngines::configured();
            return self::$formulaEngine->evaluate($vars, $formula);
        } catch (Throwable $e) {
            if (self::$formulaErrorListener !== null) {
                (self::$formulaErrorListener)($e, $formula);
            }
            return null;
        }
    }
}
