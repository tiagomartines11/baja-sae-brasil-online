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
        return json_decode(parent::getParams() ?? '');
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
        return json_decode(parent::getTotals() ?? '');
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
        return $this->getParams()->vars ?? null;
    }

    public function getParamsMinMax() {
        return $this->getParams()->min_max ?? null;
    }

    /**
     * @return Field[]
     */
    public function getParamsInputs() {
        $ret = [];
        foreach ((array)($this->getParams()->inputs ?? []) as $i){
            $f = new Field($i->code, $i->name, $i->pass ?? null, $i->xor ?? null, $i->sensitive ?? null);
            if ($i->type == "enum") {
                $f->setEnum($i->options, $i->multiple ?? null);
            } else if ($i->type == "enum-assoc") {
                $f->setEnumAssoc($i->options, $i->multiple ?? null);
            } else if ($i->type == "time") {
                $f->setTime();
            } else {
                $f->setNumber($i->precision ?? null, $i->negative ?? null);
            }
            $ret[]= $f;
        }
        return $ret;
    }

    public function getParamsPontos() {
        return $this->getParams()->pontos ?? null;
    }

    public function refreshVarsAndPontos() {
        #$inputs = InputQuery::create()->filterByEventoId($this->getEventoId())->findByProvaId($this->getProvaId());
        #Ajuste para desclassificadas
        $inputs = InputQuery::create()->filterByEventoId($this->getEventoId())->joinEquipe()->findByProvaId($this->getProvaId());
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

    public function getType() {
        return !empty($this->getParams()->type) ? $this->getParams()->type : 'normal';
    }

    public function getFullCode() {
        return $this->getEventoId().'_'.$this->getProvaId();
    }

    /**
     * Works out the prova's totals and saves them. The "pontos" formulas of
     * every team can then use them. For each variable X in "min_max", over
     * the event's teams that are not disqualified:
     *
     *   $Xmax    the highest X, leaving out null and 9999
     *   $Xmin    the lowest X, leaving out null and 0
     *   $Xcom    0.5 * (1 - (teams whose X is not 9999) / (teams whose X is not null))
     *   $Xcount  how many teams have a value
     *   $Xsum    the sum of the values (0 when no team has one)
     *   $Xavg    the average of the values (null when no team has one)
     *
     * For the last three (BAJAAPP-65), "a value" is any X that is not null
     * and not the 9999 "no valid result" marker, the same ones $Xmax looks
     * at; 0 is a value. A min_max formula that gives null for the teams that
     * don't qualify makes them conditional: with "FIN": "$T < 9999 ? 1 : null",
     * $FINcount is the number of finishers and $FINsum a SUMIF.
     */
    public function updateTotals()
    {
        $varsQuery = [];
        foreach ((array)$this->getParamsMinMax() as $var => $formula) {
            // The team's X, or NULL when it has no value (null, or 9999).
            $value = "IF(vars->>'$.$var'='null' OR vars->>'$.$var'='9999', NULL, vars->'$.$var')";
            $varsQuery[] = "
            '{$var}max', MAX($value),
            '{$var}min', MIN(IF(vars->>'$.$var'='null' OR vars->>'$.$var'='0', NULL, vars->'$.$var')),
            '{$var}com', (0.5 * (1 - SUM(IF(vars->>'$.$var'='9999', NULL, 1))/SUM(IF(vars->>'$.$var'='null', NULL, 1)))),
            '{$var}count', COUNT($value),
            '{$var}sum', COALESCE(SUM($value), 0),
            '{$var}avg', AVG($value)
            ";
        }

        $con = Propel::getWriteConnection("resultados");
        #$sql = "SELECT JSON_OBJECT(" . implode(",", $varsQuery) . ") as v FROM input WHERE prova_id = :id AND evento_id = :ev";
        #Ajuste para desclassificadas
        $sql = "SELECT JSON_OBJECT(" . implode(",", $varsQuery) . ") as v FROM input INNER JOIN equipe ON equipe.equipe_id=input.equipe_id AND equipe.evento_id=input.evento_id WHERE equipe.desclassificado=0 AND prova_id = :id AND input.evento_id = :ev";
        $stmt = $con->prepare($sql);
        $stmt->execute(array(':id' => $this->getProvaId(), ':ev' => $this->getEventoId()));
        $varsMinMax = json_decode($stmt->fetch()[0] ?? '') ?? (object)[];
        foreach ($varsMinMax as &$v) {
            if (is_numeric($v)) $v = doubleval($v);
            else if ($v === "null") $v = null;
        }

        $this->setTotals($varsMinMax);
        $this->save();
    }
}
