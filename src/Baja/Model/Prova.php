<?php

namespace Baja\Model;

use Baja\Model\Base\Prova as BaseProva;
use Baja\Model\JSON\Field;
use DateTime;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Propel;

/**
 * Skeleton subclass for representing a row from the 'prova' table.
 *
 *
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 *
 */
class Prova extends BaseProva
{

    /**
     * @return object
     */
    public function getParams()
    {
        return json_decode(parent::getParams());
    }

    /**
     * @param object $v
     * @return $this|Prova
     */
    public function setParams($v)
    {
        return parent::setParams(json_encode($v));
    }

    /**
     * @return object
     */
    public function getTotals()
    {
        return json_decode(parent::getTotals());
    }

    /**
     * @param string $v
     * @return $this|Prova
     */
    public function setTotals($v)
    {
        return parent::setTotals($v !== null ? json_encode($v) : null);
    }

    public function getParamsVars() {
        return $this->getParams()->vars;
    }

    public function getParamsMinMax() {
        return $this->getParams()->min_max;
    }

    /**
     * @return Field[]
     */
    public function getParamsInputs() {
        $ret = [];
        foreach ((array)$this->getParams()->inputs as $i){
            $f = new Field($i->code, $i->name, $i->pass, $i->xor);
            if ($i->type == "enum") {
                $f->setEnum($i->options, $i->multiple);
            } else if ($i->type == "time") {
                $f->setTime();
            } else {
                $f->setNumber($i->precision, $i->negative);
            }
            $ret[]= $f;
        }
        return $ret;
    }

    public function getParamsPontos() {
        return $this->getParams()->pontos;
    }

    public function refreshVarsAndPontos() {
        $inputs = InputQuery::create()->filterByEventoId($this->getEventoId())->findByProvaId($this->getProvaId());
        foreach ($inputs as $i) {
            $i->updateVars();
            $i->save();
        }
        $this->setModificado(new DateTime("now"));
        $this->updateTotals();
        foreach ($inputs as $i) {
            $i->updatePontos();
            $i->save();
        }
    }

    public function getFullCode() {
        return $this->getEventoId().'_'.$this->getProvaId();
    }

    public function updateTotals()
    {
        $varsQuery = [];
        foreach ((array)$this->getParamsMinMax() as $var => $formula) {
            $varsQuery[] = "'{$var}max', MAX(IF(vars->>'$.$var'='null', NULL, vars->'$.$var')), '{$var}min', MIN(IF(vars->>'$.$var'='null', NULL, vars->'$.$var'))";
        }

        $con = Propel::getWriteConnection("resultados");
        $sql = "SELECT JSON_OBJECT(" . implode(",", $varsQuery) . ") as v FROM input WHERE prova_id = :id AND evento_id = :ev";
        $stmt = $con->prepare($sql);
        $stmt->execute(array(':id' => $this->getProvaId(), ':ev' => $this->getEventoId()));
        $varsMinMax = json_decode($stmt->fetch()[0]);
        foreach ($varsMinMax as &$v) {
            if (is_numeric($v)) $v = doubleval($v);
            else if ($v === "null") $v = null;
        }

        $this->setTotals($varsMinMax);
        $this->save();
    }
}
