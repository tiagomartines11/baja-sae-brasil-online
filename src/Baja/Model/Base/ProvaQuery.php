<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Prova as ChildProva;
use Baja\Model\ProvaQuery as ChildProvaQuery;
use Baja\Model\Map\ProvaTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the 'prova' table.
 *
 *
 *
 * @method     ChildProvaQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildProvaQuery orderByProvaId($order = Criteria::ASC) Order by the prova_id column
 * @method     ChildProvaQuery orderByNome($order = Criteria::ASC) Order by the nome column
 * @method     ChildProvaQuery orderByStatus($order = Criteria::ASC) Order by the status column
 * @method     ChildProvaQuery orderByTempo($order = Criteria::ASC) Order by the tempo column
 * @method     ChildProvaQuery orderByModificado($order = Criteria::ASC) Order by the modificado column
 * @method     ChildProvaQuery orderByParams($order = Criteria::ASC) Order by the params column
 * @method     ChildProvaQuery orderByTotals($order = Criteria::ASC) Order by the totals column
 *
 * @method     ChildProvaQuery groupByEventoId() Group by the evento_id column
 * @method     ChildProvaQuery groupByProvaId() Group by the prova_id column
 * @method     ChildProvaQuery groupByNome() Group by the nome column
 * @method     ChildProvaQuery groupByStatus() Group by the status column
 * @method     ChildProvaQuery groupByTempo() Group by the tempo column
 * @method     ChildProvaQuery groupByModificado() Group by the modificado column
 * @method     ChildProvaQuery groupByParams() Group by the params column
 * @method     ChildProvaQuery groupByTotals() Group by the totals column
 *
 * @method     ChildProvaQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildProvaQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildProvaQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildProvaQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildProvaQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildProvaQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildProvaQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildProvaQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildProvaQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildProvaQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildProvaQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildProvaQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildProvaQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     ChildProvaQuery leftJoinInput($relationAlias = null) Adds a LEFT JOIN clause to the query using the Input relation
 * @method     ChildProvaQuery rightJoinInput($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Input relation
 * @method     ChildProvaQuery innerJoinInput($relationAlias = null) Adds a INNER JOIN clause to the query using the Input relation
 *
 * @method     ChildProvaQuery joinWithInput($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Input relation
 *
 * @method     ChildProvaQuery leftJoinWithInput() Adds a LEFT JOIN clause and with to the query using the Input relation
 * @method     ChildProvaQuery rightJoinWithInput() Adds a RIGHT JOIN clause and with to the query using the Input relation
 * @method     ChildProvaQuery innerJoinWithInput() Adds a INNER JOIN clause and with to the query using the Input relation
 *
 * @method     \Baja\Model\EventoQuery|\Baja\Model\InputQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildProva findOne(ConnectionInterface $con = null) Return the first ChildProva matching the query
 * @method     ChildProva findOneOrCreate(ConnectionInterface $con = null) Return the first ChildProva matching the query, or a new ChildProva object populated from the query conditions when no match is found
 *
 * @method     ChildProva findOneByEventoId(string $evento_id) Return the first ChildProva filtered by the evento_id column
 * @method     ChildProva findOneByProvaId(string $prova_id) Return the first ChildProva filtered by the prova_id column
 * @method     ChildProva findOneByNome(string $nome) Return the first ChildProva filtered by the nome column
 * @method     ChildProva findOneByStatus(int $status) Return the first ChildProva filtered by the status column
 * @method     ChildProva findOneByTempo(int $tempo) Return the first ChildProva filtered by the tempo column
 * @method     ChildProva findOneByModificado(string $modificado) Return the first ChildProva filtered by the modificado column
 * @method     ChildProva findOneByParams(string $params) Return the first ChildProva filtered by the params column
 * @method     ChildProva findOneByTotals(string $totals) Return the first ChildProva filtered by the totals column *

 * @method     ChildProva requirePk($key, ConnectionInterface $con = null) Return the ChildProva by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildProva requireOne(ConnectionInterface $con = null) Return the first ChildProva matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildProva requireOneByEventoId(string $evento_id) Return the first ChildProva filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildProva requireOneByProvaId(string $prova_id) Return the first ChildProva filtered by the prova_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildProva requireOneByNome(string $nome) Return the first ChildProva filtered by the nome column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildProva requireOneByStatus(int $status) Return the first ChildProva filtered by the status column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildProva requireOneByTempo(int $tempo) Return the first ChildProva filtered by the tempo column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildProva requireOneByModificado(string $modificado) Return the first ChildProva filtered by the modificado column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildProva requireOneByParams(string $params) Return the first ChildProva filtered by the params column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildProva requireOneByTotals(string $totals) Return the first ChildProva filtered by the totals column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildProva[]|ObjectCollection find(ConnectionInterface $con = null) Return ChildProva objects based on current ModelCriteria
 * @method     ChildProva[]|ObjectCollection findByEventoId(string $evento_id) Return ChildProva objects filtered by the evento_id column
 * @method     ChildProva[]|ObjectCollection findByProvaId(string $prova_id) Return ChildProva objects filtered by the prova_id column
 * @method     ChildProva[]|ObjectCollection findByNome(string $nome) Return ChildProva objects filtered by the nome column
 * @method     ChildProva[]|ObjectCollection findByStatus(int $status) Return ChildProva objects filtered by the status column
 * @method     ChildProva[]|ObjectCollection findByTempo(int $tempo) Return ChildProva objects filtered by the tempo column
 * @method     ChildProva[]|ObjectCollection findByModificado(string $modificado) Return ChildProva objects filtered by the modificado column
 * @method     ChildProva[]|ObjectCollection findByParams(string $params) Return ChildProva objects filtered by the params column
 * @method     ChildProva[]|ObjectCollection findByTotals(string $totals) Return ChildProva objects filtered by the totals column
 * @method     ChildProva[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 *
 */
abstract class ProvaQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ProvaQuery object.
     *
     * @param     string $dbName The database name
     * @param     string $modelName The phpName of a model, e.g. 'Book'
     * @param     string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Prova', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildProvaQuery object.
     *
     * @param     string $modelAlias The alias of a model in the query
     * @param     Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildProvaQuery
     */
    public static function create($modelAlias = null, Criteria $criteria = null)
    {
        if ($criteria instanceof ChildProvaQuery) {
            return $criteria;
        }
        $query = new ChildProvaQuery();
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
     * @param array[$evento_id, $prova_id] $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildProva|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ProvaTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ProvaTableMap::getInstanceFromPool(serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]))))) {
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
     * @return ChildProva A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT evento_id, prova_id, nome, status, tempo, modificado, params, totals FROM prova WHERE evento_id = :p0 AND prova_id = :p1';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key[0], PDO::PARAM_STR);
            $stmt->bindValue(':p1', $key[1], PDO::PARAM_STR);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            /** @var ChildProva $obj */
            $obj = new ChildProva();
            $obj->hydrate($row);
            ProvaTableMap::addInstanceToPool($obj, serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]));
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
     * @return ChildProva|array|mixed the result, formatted by the current formatter
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
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function filterByPrimaryKey($key)
    {
        $this->addUsingAlias(ProvaTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
        $this->addUsingAlias(ProvaTableMap::COL_PROVA_ID, $key[1], Criteria::EQUAL);

        return $this;
    }

    /**
     * Filter the query by a list of primary keys
     *
     * @param     array $keys The list of primary key to use for the query
     *
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function filterByPrimaryKeys($keys)
    {
        if (empty($keys)) {
            return $this->add(null, '1<>1', Criteria::CUSTOM);
        }
        foreach ($keys as $key) {
            $cton0 = $this->getNewCriterion(ProvaTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
            $cton1 = $this->getNewCriterion(ProvaTableMap::COL_PROVA_ID, $key[1], Criteria::EQUAL);
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
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function filterByEventoId($eventoId = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($eventoId)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ProvaTableMap::COL_EVENTO_ID, $eventoId, $comparison);
    }

    /**
     * Filter the query on the prova_id column
     *
     * Example usage:
     * <code>
     * $query->filterByProvaId('fooValue');   // WHERE prova_id = 'fooValue'
     * $query->filterByProvaId('%fooValue%', Criteria::LIKE); // WHERE prova_id LIKE '%fooValue%'
     * </code>
     *
     * @param     string $provaId The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function filterByProvaId($provaId = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($provaId)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ProvaTableMap::COL_PROVA_ID, $provaId, $comparison);
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
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function filterByNome($nome = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($nome)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ProvaTableMap::COL_NOME, $nome, $comparison);
    }

    /**
     * Filter the query on the status column
     *
     * @param     mixed $status The value to use as filter
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function filterByStatus($status = null, $comparison = null)
    {
        $valueSet = ProvaTableMap::getValueSet(ProvaTableMap::COL_STATUS);
        if (is_scalar($status)) {
            if (!in_array($status, $valueSet)) {
                throw new PropelException(sprintf('Value "%s" is not accepted in this enumerated column', $status));
            }
            $status = array_search($status, $valueSet);
        } elseif (is_array($status)) {
            $convertedValues = array();
            foreach ($status as $value) {
                if (!in_array($value, $valueSet)) {
                    throw new PropelException(sprintf('Value "%s" is not accepted in this enumerated column', $value));
                }
                $convertedValues []= array_search($value, $valueSet);
            }
            $status = $convertedValues;
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ProvaTableMap::COL_STATUS, $status, $comparison);
    }

    /**
     * Filter the query on the tempo column
     *
     * Example usage:
     * <code>
     * $query->filterByTempo(1234); // WHERE tempo = 1234
     * $query->filterByTempo(array(12, 34)); // WHERE tempo IN (12, 34)
     * $query->filterByTempo(array('min' => 12)); // WHERE tempo > 12
     * </code>
     *
     * @param     mixed $tempo The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function filterByTempo($tempo = null, $comparison = null)
    {
        if (is_array($tempo)) {
            $useMinMax = false;
            if (isset($tempo['min'])) {
                $this->addUsingAlias(ProvaTableMap::COL_TEMPO, $tempo['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tempo['max'])) {
                $this->addUsingAlias(ProvaTableMap::COL_TEMPO, $tempo['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ProvaTableMap::COL_TEMPO, $tempo, $comparison);
    }

    /**
     * Filter the query on the modificado column
     *
     * Example usage:
     * <code>
     * $query->filterByModificado('2011-03-14'); // WHERE modificado = '2011-03-14'
     * $query->filterByModificado('now'); // WHERE modificado = '2011-03-14'
     * $query->filterByModificado(array('max' => 'yesterday')); // WHERE modificado > '2011-03-13'
     * </code>
     *
     * @param     mixed $modificado The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function filterByModificado($modificado = null, $comparison = null)
    {
        if (is_array($modificado)) {
            $useMinMax = false;
            if (isset($modificado['min'])) {
                $this->addUsingAlias(ProvaTableMap::COL_MODIFICADO, $modificado['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($modificado['max'])) {
                $this->addUsingAlias(ProvaTableMap::COL_MODIFICADO, $modificado['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ProvaTableMap::COL_MODIFICADO, $modificado, $comparison);
    }

    /**
     * Filter the query on the params column
     *
     * Example usage:
     * <code>
     * $query->filterByParams('fooValue');   // WHERE params = 'fooValue'
     * $query->filterByParams('%fooValue%', Criteria::LIKE); // WHERE params LIKE '%fooValue%'
     * </code>
     *
     * @param     string $params The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function filterByParams($params = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($params)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ProvaTableMap::COL_PARAMS, $params, $comparison);
    }

    /**
     * Filter the query on the totals column
     *
     * Example usage:
     * <code>
     * $query->filterByTotals('fooValue');   // WHERE totals = 'fooValue'
     * $query->filterByTotals('%fooValue%', Criteria::LIKE); // WHERE totals LIKE '%fooValue%'
     * </code>
     *
     * @param     string $totals The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function filterByTotals($totals = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($totals)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ProvaTableMap::COL_TOTALS, $totals, $comparison);
    }

    /**
     * Filter the query by a related \Baja\Model\Evento object
     *
     * @param \Baja\Model\Evento|ObjectCollection $evento The related object(s) to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return ChildProvaQuery The current query, for fluid interface
     */
    public function filterByEvento($evento, $comparison = null)
    {
        if ($evento instanceof \Baja\Model\Evento) {
            return $this
                ->addUsingAlias(ProvaTableMap::COL_EVENTO_ID, $evento->getEventoId(), $comparison);
        } elseif ($evento instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            return $this
                ->addUsingAlias(ProvaTableMap::COL_EVENTO_ID, $evento->toKeyValue('PrimaryKey', 'EventoId'), $comparison);
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
     * @return $this|ChildProvaQuery The current query, for fluid interface
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
     * Filter the query by a related \Baja\Model\Input object
     *
     * @param \Baja\Model\Input|ObjectCollection $input the related object to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildProvaQuery The current query, for fluid interface
     */
    public function filterByInput($input, $comparison = null)
    {
        if ($input instanceof \Baja\Model\Input) {
            return $this
                ->addUsingAlias(ProvaTableMap::COL_EVENTO_ID, $input->getEventoId(), $comparison)
                ->addUsingAlias(ProvaTableMap::COL_PROVA_ID, $input->getProvaId(), $comparison);
        } else {
            throw new PropelException('filterByInput() only accepts arguments of type \Baja\Model\Input');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Input relation
     *
     * @param     string $relationAlias optional alias for the relation
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function joinInput($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Input');

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
            $this->addJoinObject($join, 'Input');
        }

        return $this;
    }

    /**
     * Use the Input relation Input object
     *
     * @see useQuery()
     *
     * @param     string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\InputQuery A secondary query class using the current class as primary query
     */
    public function useInputQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinInput($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Input', '\Baja\Model\InputQuery');
    }

    /**
     * Exclude object from result
     *
     * @param   ChildProva $prova Object to remove from the list of results
     *
     * @return $this|ChildProvaQuery The current query, for fluid interface
     */
    public function prune($prova = null)
    {
        if ($prova) {
            $this->addCond('pruneCond0', $this->getAliasedColName(ProvaTableMap::COL_EVENTO_ID), $prova->getEventoId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond1', $this->getAliasedColName(ProvaTableMap::COL_PROVA_ID), $prova->getProvaId(), Criteria::NOT_EQUAL);
            $this->combine(array('pruneCond0', 'pruneCond1'), Criteria::LOGICAL_OR);
        }

        return $this;
    }

    /**
     * Deletes all rows from the prova table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ProvaTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ProvaTableMap::clearInstancePool();
            ProvaTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ProvaTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ProvaTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ProvaTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ProvaTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

} // ProvaQuery
