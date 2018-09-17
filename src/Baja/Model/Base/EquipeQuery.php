<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Equipe as ChildEquipe;
use Baja\Model\EquipeQuery as ChildEquipeQuery;
use Baja\Model\Map\EquipeTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the 'equipe' table.
 *
 *
 *
 * @method     ChildEquipeQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildEquipeQuery orderByEquipeId($order = Criteria::ASC) Order by the equipe_id column
 * @method     ChildEquipeQuery orderByEscola($order = Criteria::ASC) Order by the escola column
 * @method     ChildEquipeQuery orderByEquipe($order = Criteria::ASC) Order by the equipe column
 * @method     ChildEquipeQuery orderByEstado($order = Criteria::ASC) Order by the estado column
 * @method     ChildEquipeQuery orderByPresente($order = Criteria::ASC) Order by the presente column
 *
 * @method     ChildEquipeQuery groupByEventoId() Group by the evento_id column
 * @method     ChildEquipeQuery groupByEquipeId() Group by the equipe_id column
 * @method     ChildEquipeQuery groupByEscola() Group by the escola column
 * @method     ChildEquipeQuery groupByEquipe() Group by the equipe column
 * @method     ChildEquipeQuery groupByEstado() Group by the estado column
 * @method     ChildEquipeQuery groupByPresente() Group by the presente column
 *
 * @method     ChildEquipeQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildEquipeQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildEquipeQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildEquipeQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildEquipeQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildEquipeQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildEquipeQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildEquipeQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildEquipeQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildEquipeQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildEquipeQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildEquipeQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildEquipeQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     ChildEquipeQuery leftJoinInput($relationAlias = null) Adds a LEFT JOIN clause to the query using the Input relation
 * @method     ChildEquipeQuery rightJoinInput($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Input relation
 * @method     ChildEquipeQuery innerJoinInput($relationAlias = null) Adds a INNER JOIN clause to the query using the Input relation
 *
 * @method     ChildEquipeQuery joinWithInput($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Input relation
 *
 * @method     ChildEquipeQuery leftJoinWithInput() Adds a LEFT JOIN clause and with to the query using the Input relation
 * @method     ChildEquipeQuery rightJoinWithInput() Adds a RIGHT JOIN clause and with to the query using the Input relation
 * @method     ChildEquipeQuery innerJoinWithInput() Adds a INNER JOIN clause and with to the query using the Input relation
 *
 * @method     \Baja\Model\EventoQuery|\Baja\Model\InputQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildEquipe findOne(ConnectionInterface $con = null) Return the first ChildEquipe matching the query
 * @method     ChildEquipe findOneOrCreate(ConnectionInterface $con = null) Return the first ChildEquipe matching the query, or a new ChildEquipe object populated from the query conditions when no match is found
 *
 * @method     ChildEquipe findOneByEventoId(string $evento_id) Return the first ChildEquipe filtered by the evento_id column
 * @method     ChildEquipe findOneByEquipeId(int $equipe_id) Return the first ChildEquipe filtered by the equipe_id column
 * @method     ChildEquipe findOneByEscola(string $escola) Return the first ChildEquipe filtered by the escola column
 * @method     ChildEquipe findOneByEquipe(string $equipe) Return the first ChildEquipe filtered by the equipe column
 * @method     ChildEquipe findOneByEstado(string $estado) Return the first ChildEquipe filtered by the estado column
 * @method     ChildEquipe findOneByPresente(boolean $presente) Return the first ChildEquipe filtered by the presente column *

 * @method     ChildEquipe requirePk($key, ConnectionInterface $con = null) Return the ChildEquipe by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOne(ConnectionInterface $con = null) Return the first ChildEquipe matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildEquipe requireOneByEventoId(string $evento_id) Return the first ChildEquipe filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByEquipeId(int $equipe_id) Return the first ChildEquipe filtered by the equipe_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByEscola(string $escola) Return the first ChildEquipe filtered by the escola column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByEquipe(string $equipe) Return the first ChildEquipe filtered by the equipe column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByEstado(string $estado) Return the first ChildEquipe filtered by the estado column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildEquipe requireOneByPresente(boolean $presente) Return the first ChildEquipe filtered by the presente column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildEquipe[]|ObjectCollection find(ConnectionInterface $con = null) Return ChildEquipe objects based on current ModelCriteria
 * @method     ChildEquipe[]|ObjectCollection findByEventoId(string $evento_id) Return ChildEquipe objects filtered by the evento_id column
 * @method     ChildEquipe[]|ObjectCollection findByEquipeId(int $equipe_id) Return ChildEquipe objects filtered by the equipe_id column
 * @method     ChildEquipe[]|ObjectCollection findByEscola(string $escola) Return ChildEquipe objects filtered by the escola column
 * @method     ChildEquipe[]|ObjectCollection findByEquipe(string $equipe) Return ChildEquipe objects filtered by the equipe column
 * @method     ChildEquipe[]|ObjectCollection findByEstado(string $estado) Return ChildEquipe objects filtered by the estado column
 * @method     ChildEquipe[]|ObjectCollection findByPresente(boolean $presente) Return ChildEquipe objects filtered by the presente column
 * @method     ChildEquipe[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 *
 */
abstract class EquipeQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\EquipeQuery object.
     *
     * @param     string $dbName The database name
     * @param     string $modelName The phpName of a model, e.g. 'Book'
     * @param     string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Equipe', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildEquipeQuery object.
     *
     * @param     string $modelAlias The alias of a model in the query
     * @param     Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildEquipeQuery
     */
    public static function create($modelAlias = null, Criteria $criteria = null)
    {
        if ($criteria instanceof ChildEquipeQuery) {
            return $criteria;
        }
        $query = new ChildEquipeQuery();
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
     * @param array[$evento_id, $equipe_id] $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildEquipe|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(EquipeTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = EquipeTableMap::getInstanceFromPool(serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]))))) {
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
     * @return ChildEquipe A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT evento_id, equipe_id, escola, equipe, estado, presente FROM equipe WHERE evento_id = :p0 AND equipe_id = :p1';
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
            /** @var ChildEquipe $obj */
            $obj = new ChildEquipe();
            $obj->hydrate($row);
            EquipeTableMap::addInstanceToPool($obj, serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]));
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
     * @return ChildEquipe|array|mixed the result, formatted by the current formatter
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
     * @return $this|ChildEquipeQuery The current query, for fluid interface
     */
    public function filterByPrimaryKey($key)
    {
        $this->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
        $this->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $key[1], Criteria::EQUAL);

        return $this;
    }

    /**
     * Filter the query by a list of primary keys
     *
     * @param     array $keys The list of primary key to use for the query
     *
     * @return $this|ChildEquipeQuery The current query, for fluid interface
     */
    public function filterByPrimaryKeys($keys)
    {
        if (empty($keys)) {
            return $this->add(null, '1<>1', Criteria::CUSTOM);
        }
        foreach ($keys as $key) {
            $cton0 = $this->getNewCriterion(EquipeTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
            $cton1 = $this->getNewCriterion(EquipeTableMap::COL_EQUIPE_ID, $key[1], Criteria::EQUAL);
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
     * @return $this|ChildEquipeQuery The current query, for fluid interface
     */
    public function filterByEventoId($eventoId = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($eventoId)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $eventoId, $comparison);
    }

    /**
     * Filter the query on the equipe_id column
     *
     * Example usage:
     * <code>
     * $query->filterByEquipeId(1234); // WHERE equipe_id = 1234
     * $query->filterByEquipeId(array(12, 34)); // WHERE equipe_id IN (12, 34)
     * $query->filterByEquipeId(array('min' => 12)); // WHERE equipe_id > 12
     * </code>
     *
     * @param     mixed $equipeId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildEquipeQuery The current query, for fluid interface
     */
    public function filterByEquipeId($equipeId = null, $comparison = null)
    {
        if (is_array($equipeId)) {
            $useMinMax = false;
            if (isset($equipeId['min'])) {
                $this->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $equipeId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($equipeId['max'])) {
                $this->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $equipeId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $equipeId, $comparison);
    }

    /**
     * Filter the query on the escola column
     *
     * Example usage:
     * <code>
     * $query->filterByEscola('fooValue');   // WHERE escola = 'fooValue'
     * $query->filterByEscola('%fooValue%', Criteria::LIKE); // WHERE escola LIKE '%fooValue%'
     * </code>
     *
     * @param     string $escola The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildEquipeQuery The current query, for fluid interface
     */
    public function filterByEscola($escola = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($escola)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(EquipeTableMap::COL_ESCOLA, $escola, $comparison);
    }

    /**
     * Filter the query on the equipe column
     *
     * Example usage:
     * <code>
     * $query->filterByEquipe('fooValue');   // WHERE equipe = 'fooValue'
     * $query->filterByEquipe('%fooValue%', Criteria::LIKE); // WHERE equipe LIKE '%fooValue%'
     * </code>
     *
     * @param     string $equipe The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildEquipeQuery The current query, for fluid interface
     */
    public function filterByEquipe($equipe = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($equipe)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(EquipeTableMap::COL_EQUIPE, $equipe, $comparison);
    }

    /**
     * Filter the query on the estado column
     *
     * Example usage:
     * <code>
     * $query->filterByEstado('fooValue');   // WHERE estado = 'fooValue'
     * $query->filterByEstado('%fooValue%', Criteria::LIKE); // WHERE estado LIKE '%fooValue%'
     * </code>
     *
     * @param     string $estado The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildEquipeQuery The current query, for fluid interface
     */
    public function filterByEstado($estado = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($estado)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(EquipeTableMap::COL_ESTADO, $estado, $comparison);
    }

    /**
     * Filter the query on the presente column
     *
     * Example usage:
     * <code>
     * $query->filterByPresente(true); // WHERE presente = true
     * $query->filterByPresente('yes'); // WHERE presente = true
     * </code>
     *
     * @param     boolean|string $presente The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildEquipeQuery The current query, for fluid interface
     */
    public function filterByPresente($presente = null, $comparison = null)
    {
        if (is_string($presente)) {
            $presente = in_array(strtolower($presente), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
        }

        return $this->addUsingAlias(EquipeTableMap::COL_PRESENTE, $presente, $comparison);
    }

    /**
     * Filter the query by a related \Baja\Model\Evento object
     *
     * @param \Baja\Model\Evento|ObjectCollection $evento The related object(s) to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return ChildEquipeQuery The current query, for fluid interface
     */
    public function filterByEvento($evento, $comparison = null)
    {
        if ($evento instanceof \Baja\Model\Evento) {
            return $this
                ->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $evento->getEventoId(), $comparison);
        } elseif ($evento instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            return $this
                ->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $evento->toKeyValue('PrimaryKey', 'EventoId'), $comparison);
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
     * @return $this|ChildEquipeQuery The current query, for fluid interface
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
     * @return ChildEquipeQuery The current query, for fluid interface
     */
    public function filterByInput($input, $comparison = null)
    {
        if ($input instanceof \Baja\Model\Input) {
            return $this
                ->addUsingAlias(EquipeTableMap::COL_EVENTO_ID, $input->getEventoId(), $comparison)
                ->addUsingAlias(EquipeTableMap::COL_EQUIPE_ID, $input->getEquipeId(), $comparison);
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
     * @return $this|ChildEquipeQuery The current query, for fluid interface
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
     * @param   ChildEquipe $equipe Object to remove from the list of results
     *
     * @return $this|ChildEquipeQuery The current query, for fluid interface
     */
    public function prune($equipe = null)
    {
        if ($equipe) {
            $this->addCond('pruneCond0', $this->getAliasedColName(EquipeTableMap::COL_EVENTO_ID), $equipe->getEventoId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond1', $this->getAliasedColName(EquipeTableMap::COL_EQUIPE_ID), $equipe->getEquipeId(), Criteria::NOT_EQUAL);
            $this->combine(array('pruneCond0', 'pruneCond1'), Criteria::LOGICAL_OR);
        }

        return $this;
    }

    /**
     * Deletes all rows from the equipe table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(EquipeTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            EquipeTableMap::clearInstancePool();
            EquipeTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(EquipeTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(EquipeTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            EquipeTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            EquipeTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

} // EquipeQuery
