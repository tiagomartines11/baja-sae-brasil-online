<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Fila as ChildFila;
use Baja\Model\FilaQuery as ChildFilaQuery;
use Baja\Model\Map\FilaTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the 'fila' table.
 *
 *
 *
 * @method     ChildFilaQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildFilaQuery orderByFilaId($order = Criteria::ASC) Order by the fila_id column
 * @method     ChildFilaQuery orderByNome($order = Criteria::ASC) Order by the nome column
 * @method     ChildFilaQuery orderByStatus($order = Criteria::ASC) Order by the status column
 * @method     ChildFilaQuery orderByPermiteTroca($order = Criteria::ASC) Order by the permite_troca column
 * @method     ChildFilaQuery orderByPermiteMultiplas($order = Criteria::ASC) Order by the permite_multiplas column
 * @method     ChildFilaQuery orderByPermiteChamadaEspera($order = Criteria::ASC) Order by the permite_chamada_espera column
 * @method     ChildFilaQuery orderByTempoEspera($order = Criteria::ASC) Order by the tempo_espera column
 * @method     ChildFilaQuery orderByAberturaProgramada($order = Criteria::ASC) Order by the abertura_programada column
 * @method     ChildFilaQuery orderByFechamentoProgramado($order = Criteria::ASC) Order by the fechamento_programado column
 *
 * @method     ChildFilaQuery groupByEventoId() Group by the evento_id column
 * @method     ChildFilaQuery groupByFilaId() Group by the fila_id column
 * @method     ChildFilaQuery groupByNome() Group by the nome column
 * @method     ChildFilaQuery groupByStatus() Group by the status column
 * @method     ChildFilaQuery groupByPermiteTroca() Group by the permite_troca column
 * @method     ChildFilaQuery groupByPermiteMultiplas() Group by the permite_multiplas column
 * @method     ChildFilaQuery groupByPermiteChamadaEspera() Group by the permite_chamada_espera column
 * @method     ChildFilaQuery groupByTempoEspera() Group by the tempo_espera column
 * @method     ChildFilaQuery groupByAberturaProgramada() Group by the abertura_programada column
 * @method     ChildFilaQuery groupByFechamentoProgramado() Group by the fechamento_programado column
 *
 * @method     ChildFilaQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildFilaQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildFilaQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildFilaQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildFilaQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildFilaQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildFilaQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildFilaQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildFilaQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildFilaQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildFilaQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildFilaQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildFilaQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     \Baja\Model\EventoQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildFila findOne(ConnectionInterface $con = null) Return the first ChildFila matching the query
 * @method     ChildFila findOneOrCreate(ConnectionInterface $con = null) Return the first ChildFila matching the query, or a new ChildFila object populated from the query conditions when no match is found
 *
 * @method     ChildFila findOneByEventoId(string $evento_id) Return the first ChildFila filtered by the evento_id column
 * @method     ChildFila findOneByFilaId(int $fila_id) Return the first ChildFila filtered by the fila_id column
 * @method     ChildFila findOneByNome(string $nome) Return the first ChildFila filtered by the nome column
 * @method     ChildFila findOneByStatus(int $status) Return the first ChildFila filtered by the status column
 * @method     ChildFila findOneByPermiteTroca(boolean $permite_troca) Return the first ChildFila filtered by the permite_troca column
 * @method     ChildFila findOneByPermiteMultiplas(boolean $permite_multiplas) Return the first ChildFila filtered by the permite_multiplas column
 * @method     ChildFila findOneByPermiteChamadaEspera(boolean $permite_chamada_espera) Return the first ChildFila filtered by the permite_chamada_espera column
 * @method     ChildFila findOneByTempoEspera(int $tempo_espera) Return the first ChildFila filtered by the tempo_espera column
 * @method     ChildFila findOneByAberturaProgramada(string $abertura_programada) Return the first ChildFila filtered by the abertura_programada column
 * @method     ChildFila findOneByFechamentoProgramado(string $fechamento_programado) Return the first ChildFila filtered by the fechamento_programado column *

 * @method     ChildFila requirePk($key, ConnectionInterface $con = null) Return the ChildFila by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildFila requireOne(ConnectionInterface $con = null) Return the first ChildFila matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildFila requireOneByEventoId(string $evento_id) Return the first ChildFila filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildFila requireOneByFilaId(int $fila_id) Return the first ChildFila filtered by the fila_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildFila requireOneByNome(string $nome) Return the first ChildFila filtered by the nome column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildFila requireOneByStatus(int $status) Return the first ChildFila filtered by the status column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildFila requireOneByPermiteTroca(boolean $permite_troca) Return the first ChildFila filtered by the permite_troca column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildFila requireOneByPermiteMultiplas(boolean $permite_multiplas) Return the first ChildFila filtered by the permite_multiplas column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildFila requireOneByPermiteChamadaEspera(boolean $permite_chamada_espera) Return the first ChildFila filtered by the permite_chamada_espera column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildFila requireOneByTempoEspera(int $tempo_espera) Return the first ChildFila filtered by the tempo_espera column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildFila requireOneByAberturaProgramada(string $abertura_programada) Return the first ChildFila filtered by the abertura_programada column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildFila requireOneByFechamentoProgramado(string $fechamento_programado) Return the first ChildFila filtered by the fechamento_programado column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildFila[]|ObjectCollection find(ConnectionInterface $con = null) Return ChildFila objects based on current ModelCriteria
 * @method     ChildFila[]|ObjectCollection findByEventoId(string $evento_id) Return ChildFila objects filtered by the evento_id column
 * @method     ChildFila[]|ObjectCollection findByFilaId(int $fila_id) Return ChildFila objects filtered by the fila_id column
 * @method     ChildFila[]|ObjectCollection findByNome(string $nome) Return ChildFila objects filtered by the nome column
 * @method     ChildFila[]|ObjectCollection findByStatus(int $status) Return ChildFila objects filtered by the status column
 * @method     ChildFila[]|ObjectCollection findByPermiteTroca(boolean $permite_troca) Return ChildFila objects filtered by the permite_troca column
 * @method     ChildFila[]|ObjectCollection findByPermiteMultiplas(boolean $permite_multiplas) Return ChildFila objects filtered by the permite_multiplas column
 * @method     ChildFila[]|ObjectCollection findByPermiteChamadaEspera(boolean $permite_chamada_espera) Return ChildFila objects filtered by the permite_chamada_espera column
 * @method     ChildFila[]|ObjectCollection findByTempoEspera(int $tempo_espera) Return ChildFila objects filtered by the tempo_espera column
 * @method     ChildFila[]|ObjectCollection findByAberturaProgramada(string $abertura_programada) Return ChildFila objects filtered by the abertura_programada column
 * @method     ChildFila[]|ObjectCollection findByFechamentoProgramado(string $fechamento_programado) Return ChildFila objects filtered by the fechamento_programado column
 * @method     ChildFila[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 *
 */
abstract class FilaQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\FilaQuery object.
     *
     * @param     string $dbName The database name
     * @param     string $modelName The phpName of a model, e.g. 'Book'
     * @param     string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Fila', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildFilaQuery object.
     *
     * @param     string $modelAlias The alias of a model in the query
     * @param     Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildFilaQuery
     */
    public static function create($modelAlias = null, Criteria $criteria = null)
    {
        if ($criteria instanceof ChildFilaQuery) {
            return $criteria;
        }
        $query = new ChildFilaQuery();
        if (null !== $modelAlias) {
            $query->setModelAlias($modelAlias);
        }
        if ($criteria instanceof Criteria) {
            $query->mergeWith($criteria);
        }

        return $query;
    }

    /**
     * Find object by primary key.
     * Propel uses the instance pool to skip the database if the object exists.
     * Go fast if the query is untouched.
     *
     * <code>
     * $obj = $c->findPk(array(12, 34), $con);
     * </code>
     *
     * @param array[$evento_id, $fila_id] $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildFila|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(FilaTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = FilaTableMap::getInstanceFromPool(serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]))))) {
            // the object is already in the instance pool
            return $obj;
        }

        return $this->findPkSimple($key, $con);
    }

    /**
     * Find object by primary key using raw SQL to go fast.
     * Bypass doSelect() and the object formatter by using generated code.
     *
     * @param     mixed $key Primary key to use for the query
     * @param     ConnectionInterface $con A connection object
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return ChildFila A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT evento_id, fila_id, nome, status, permite_troca, permite_multiplas, permite_chamada_espera, tempo_espera, abertura_programada, fechamento_programado FROM fila WHERE evento_id = :p0 AND fila_id = :p1';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key[0], PDO::PARAM_STR);
            $stmt->bindValue(':p1', $key[1], PDO::PARAM_INT);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            /** @var ChildFila $obj */
            $obj = new ChildFila();
            $obj->hydrate($row);
            FilaTableMap::addInstanceToPool($obj, serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]));
        }
        $stmt->closeCursor();

        return $obj;
    }

    /**
     * Find object by primary key.
     *
     * @param     mixed $key Primary key to use for the query
     * @param     ConnectionInterface $con A connection object
     *
     * @return ChildFila|array|mixed the result, formatted by the current formatter
     */
    protected function findPkComplex($key, ConnectionInterface $con)
    {
        // As the query uses a PK condition, no limit(1) is necessary.
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $dataFetcher = $criteria
            ->filterByPrimaryKey($key)
            ->doSelect($con);

        return $criteria->getFormatter()->init($criteria)->formatOne($dataFetcher);
    }

    /**
     * Find objects by primary key
     * <code>
     * $objs = $c->findPks(array(array(12, 56), array(832, 123), array(123, 456)), $con);
     * </code>
     * @param     array $keys Primary keys to use for the query
     * @param     ConnectionInterface $con an optional connection object
     *
     * @return ObjectCollection|array|mixed the list of results, formatted by the current formatter
     */
    public function findPks($keys, ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getReadConnection($this->getDbName());
        }
        $this->basePreSelect($con);
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $dataFetcher = $criteria
            ->filterByPrimaryKeys($keys)
            ->doSelect($con);

        return $criteria->getFormatter()->init($criteria)->format($dataFetcher);
    }

    /**
     * Filter the query by primary key
     *
     * @param     mixed $key Primary key to use for the query
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByPrimaryKey($key)
    {
        $this->addUsingAlias(FilaTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
        $this->addUsingAlias(FilaTableMap::COL_FILA_ID, $key[1], Criteria::EQUAL);

        return $this;
    }

    /**
     * Filter the query by a list of primary keys
     *
     * @param     array $keys The list of primary key to use for the query
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByPrimaryKeys($keys)
    {
        if (empty($keys)) {
            return $this->add(null, '1<>1', Criteria::CUSTOM);
        }
        foreach ($keys as $key) {
            $cton0 = $this->getNewCriterion(FilaTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
            $cton1 = $this->getNewCriterion(FilaTableMap::COL_FILA_ID, $key[1], Criteria::EQUAL);
            $cton0->addAnd($cton1);
            $this->addOr($cton0);
        }

        return $this;
    }

    /**
     * Filter the query on the evento_id column
     *
     * Example usage:
     * <code>
     * $query->filterByEventoId('fooValue');   // WHERE evento_id = 'fooValue'
     * $query->filterByEventoId('%fooValue%', Criteria::LIKE); // WHERE evento_id LIKE '%fooValue%'
     * </code>
     *
     * @param     string $eventoId The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByEventoId($eventoId = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($eventoId)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(FilaTableMap::COL_EVENTO_ID, $eventoId, $comparison);
    }

    /**
     * Filter the query on the fila_id column
     *
     * Example usage:
     * <code>
     * $query->filterByFilaId(1234); // WHERE fila_id = 1234
     * $query->filterByFilaId(array(12, 34)); // WHERE fila_id IN (12, 34)
     * $query->filterByFilaId(array('min' => 12)); // WHERE fila_id > 12
     * </code>
     *
     * @param     mixed $filaId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByFilaId($filaId = null, $comparison = null)
    {
        if (is_array($filaId)) {
            $useMinMax = false;
            if (isset($filaId['min'])) {
                $this->addUsingAlias(FilaTableMap::COL_FILA_ID, $filaId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($filaId['max'])) {
                $this->addUsingAlias(FilaTableMap::COL_FILA_ID, $filaId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(FilaTableMap::COL_FILA_ID, $filaId, $comparison);
    }

    /**
     * Filter the query on the nome column
     *
     * Example usage:
     * <code>
     * $query->filterByNome('fooValue');   // WHERE nome = 'fooValue'
     * $query->filterByNome('%fooValue%', Criteria::LIKE); // WHERE nome LIKE '%fooValue%'
     * </code>
     *
     * @param     string $nome The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByNome($nome = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($nome)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(FilaTableMap::COL_NOME, $nome, $comparison);
    }

    /**
     * Filter the query on the status column
     *
     * Example usage:
     * <code>
     * $query->filterByStatus(1234); // WHERE status = 1234
     * $query->filterByStatus(array(12, 34)); // WHERE status IN (12, 34)
     * $query->filterByStatus(array('min' => 12)); // WHERE status > 12
     * </code>
     *
     * @param     mixed $status The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByStatus($status = null, $comparison = null)
    {
        if (is_array($status)) {
            $useMinMax = false;
            if (isset($status['min'])) {
                $this->addUsingAlias(FilaTableMap::COL_STATUS, $status['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($status['max'])) {
                $this->addUsingAlias(FilaTableMap::COL_STATUS, $status['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(FilaTableMap::COL_STATUS, $status, $comparison);
    }

    /**
     * Filter the query on the permite_troca column
     *
     * Example usage:
     * <code>
     * $query->filterByPermiteTroca(true); // WHERE permite_troca = true
     * $query->filterByPermiteTroca('yes'); // WHERE permite_troca = true
     * </code>
     *
     * @param     boolean|string $permiteTroca The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByPermiteTroca($permiteTroca = null, $comparison = null)
    {
        if (is_string($permiteTroca)) {
            $permiteTroca = in_array(strtolower($permiteTroca), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
        }

        return $this->addUsingAlias(FilaTableMap::COL_PERMITE_TROCA, $permiteTroca, $comparison);
    }

    /**
     * Filter the query on the permite_multiplas column
     *
     * Example usage:
     * <code>
     * $query->filterByPermiteMultiplas(true); // WHERE permite_multiplas = true
     * $query->filterByPermiteMultiplas('yes'); // WHERE permite_multiplas = true
     * </code>
     *
     * @param     boolean|string $permiteMultiplas The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByPermiteMultiplas($permiteMultiplas = null, $comparison = null)
    {
        if (is_string($permiteMultiplas)) {
            $permiteMultiplas = in_array(strtolower($permiteMultiplas), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
        }

        return $this->addUsingAlias(FilaTableMap::COL_PERMITE_MULTIPLAS, $permiteMultiplas, $comparison);
    }

    /**
     * Filter the query on the permite_chamada_espera column
     *
     * Example usage:
     * <code>
     * $query->filterByPermiteChamadaEspera(true); // WHERE permite_chamada_espera = true
     * $query->filterByPermiteChamadaEspera('yes'); // WHERE permite_chamada_espera = true
     * </code>
     *
     * @param     boolean|string $permiteChamadaEspera The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByPermiteChamadaEspera($permiteChamadaEspera = null, $comparison = null)
    {
        if (is_string($permiteChamadaEspera)) {
            $permiteChamadaEspera = in_array(strtolower($permiteChamadaEspera), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
        }

        return $this->addUsingAlias(FilaTableMap::COL_PERMITE_CHAMADA_ESPERA, $permiteChamadaEspera, $comparison);
    }

    /**
     * Filter the query on the tempo_espera column
     *
     * Example usage:
     * <code>
     * $query->filterByTempoEspera(1234); // WHERE tempo_espera = 1234
     * $query->filterByTempoEspera(array(12, 34)); // WHERE tempo_espera IN (12, 34)
     * $query->filterByTempoEspera(array('min' => 12)); // WHERE tempo_espera > 12
     * </code>
     *
     * @param     mixed $tempoEspera The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByTempoEspera($tempoEspera = null, $comparison = null)
    {
        if (is_array($tempoEspera)) {
            $useMinMax = false;
            if (isset($tempoEspera['min'])) {
                $this->addUsingAlias(FilaTableMap::COL_TEMPO_ESPERA, $tempoEspera['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tempoEspera['max'])) {
                $this->addUsingAlias(FilaTableMap::COL_TEMPO_ESPERA, $tempoEspera['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(FilaTableMap::COL_TEMPO_ESPERA, $tempoEspera, $comparison);
    }

    /**
     * Filter the query on the abertura_programada column
     *
     * Example usage:
     * <code>
     * $query->filterByAberturaProgramada('2011-03-14'); // WHERE abertura_programada = '2011-03-14'
     * $query->filterByAberturaProgramada('now'); // WHERE abertura_programada = '2011-03-14'
     * $query->filterByAberturaProgramada(array('max' => 'yesterday')); // WHERE abertura_programada > '2011-03-13'
     * </code>
     *
     * @param     mixed $aberturaProgramada The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByAberturaProgramada($aberturaProgramada = null, $comparison = null)
    {
        if (is_array($aberturaProgramada)) {
            $useMinMax = false;
            if (isset($aberturaProgramada['min'])) {
                $this->addUsingAlias(FilaTableMap::COL_ABERTURA_PROGRAMADA, $aberturaProgramada['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($aberturaProgramada['max'])) {
                $this->addUsingAlias(FilaTableMap::COL_ABERTURA_PROGRAMADA, $aberturaProgramada['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(FilaTableMap::COL_ABERTURA_PROGRAMADA, $aberturaProgramada, $comparison);
    }

    /**
     * Filter the query on the fechamento_programado column
     *
     * Example usage:
     * <code>
     * $query->filterByFechamentoProgramado('2011-03-14'); // WHERE fechamento_programado = '2011-03-14'
     * $query->filterByFechamentoProgramado('now'); // WHERE fechamento_programado = '2011-03-14'
     * $query->filterByFechamentoProgramado(array('max' => 'yesterday')); // WHERE fechamento_programado > '2011-03-13'
     * </code>
     *
     * @param     mixed $fechamentoProgramado The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function filterByFechamentoProgramado($fechamentoProgramado = null, $comparison = null)
    {
        if (is_array($fechamentoProgramado)) {
            $useMinMax = false;
            if (isset($fechamentoProgramado['min'])) {
                $this->addUsingAlias(FilaTableMap::COL_FECHAMENTO_PROGRAMADO, $fechamentoProgramado['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($fechamentoProgramado['max'])) {
                $this->addUsingAlias(FilaTableMap::COL_FECHAMENTO_PROGRAMADO, $fechamentoProgramado['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(FilaTableMap::COL_FECHAMENTO_PROGRAMADO, $fechamentoProgramado, $comparison);
    }

    /**
     * Filter the query by a related \Baja\Model\Evento object
     *
     * @param \Baja\Model\Evento|ObjectCollection $evento The related object(s) to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return ChildFilaQuery The current query, for fluid interface
     */
    public function filterByEvento($evento, $comparison = null)
    {
        if ($evento instanceof \Baja\Model\Evento) {
            return $this
                ->addUsingAlias(FilaTableMap::COL_EVENTO_ID, $evento->getEventoId(), $comparison);
        } elseif ($evento instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            return $this
                ->addUsingAlias(FilaTableMap::COL_EVENTO_ID, $evento->toKeyValue('PrimaryKey', 'EventoId'), $comparison);
        } else {
            throw new PropelException('filterByEvento() only accepts arguments of type \Baja\Model\Evento or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Evento relation
     *
     * @param     string $relationAlias optional alias for the relation
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function joinEvento($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Evento');

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        $join->setRelationMap($relationMap, $this->useAliasInSQL ? $this->getModelAlias() : null, $relationAlias);
        if ($previousJoin = $this->getPreviousJoin()) {
            $join->setPreviousJoin($previousJoin);
        }

        // add the ModelJoin to the current object
        if ($relationAlias) {
            $this->addAlias($relationAlias, $relationMap->getRightTable()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, 'Evento');
        }

        return $this;
    }

    /**
     * Use the Evento relation Evento object
     *
     * @see useQuery()
     *
     * @param     string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\EventoQuery A secondary query class using the current class as primary query
     */
    public function useEventoQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinEvento($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Evento', '\Baja\Model\EventoQuery');
    }

    /**
     * Exclude object from result
     *
     * @param   ChildFila $fila Object to remove from the list of results
     *
     * @return $this|ChildFilaQuery The current query, for fluid interface
     */
    public function prune($fila = null)
    {
        if ($fila) {
            $this->addCond('pruneCond0', $this->getAliasedColName(FilaTableMap::COL_EVENTO_ID), $fila->getEventoId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond1', $this->getAliasedColName(FilaTableMap::COL_FILA_ID), $fila->getFilaId(), Criteria::NOT_EQUAL);
            $this->combine(array('pruneCond0', 'pruneCond1'), Criteria::LOGICAL_OR);
        }

        return $this;
    }

    /**
     * Deletes all rows from the fila table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(FilaTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            FilaTableMap::clearInstancePool();
            FilaTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

    /**
     * Performs a DELETE on the database based on the current ModelCriteria
     *
     * @param ConnectionInterface $con the connection to use
     * @return int             The number of affected rows (if supported by underlying database driver).  This includes CASCADE-related rows
     *                         if supported by native driver or if emulated using Propel.
     * @throws PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public function delete(ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(FilaTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(FilaTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            FilaTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            FilaTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

} // FilaQuery
