<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Timer as ChildTimer;
use Baja\Model\TimerQuery as ChildTimerQuery;
use Baja\Model\Map\TimerTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `timer` table.
 *
 * @method     ChildTimerQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildTimerQuery orderByTimerId($order = Criteria::ASC) Order by the timer_id column
 * @method     ChildTimerQuery orderByConfig($order = Criteria::ASC) Order by the config column
 * @method     ChildTimerQuery orderByConfigBackup($order = Criteria::ASC) Order by the config_backup column
 * @method     ChildTimerQuery orderByState($order = Criteria::ASC) Order by the state column
 * @method     ChildTimerQuery orderByLog($order = Criteria::ASC) Order by the log column
 * @method     ChildTimerQuery orderByAccessKey($order = Criteria::ASC) Order by the access_key column
 *
 * @method     ChildTimerQuery groupByEventoId() Group by the evento_id column
 * @method     ChildTimerQuery groupByTimerId() Group by the timer_id column
 * @method     ChildTimerQuery groupByConfig() Group by the config column
 * @method     ChildTimerQuery groupByConfigBackup() Group by the config_backup column
 * @method     ChildTimerQuery groupByState() Group by the state column
 * @method     ChildTimerQuery groupByLog() Group by the log column
 * @method     ChildTimerQuery groupByAccessKey() Group by the access_key column
 *
 * @method     ChildTimerQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildTimerQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildTimerQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildTimerQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildTimerQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildTimerQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildTimerQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildTimerQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildTimerQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildTimerQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildTimerQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildTimerQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildTimerQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     \Baja\Model\EventoQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildTimer|null findOne(?ConnectionInterface $con = null) Return the first ChildTimer matching the query
 * @method     ChildTimer findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildTimer matching the query, or a new ChildTimer object populated from the query conditions when no match is found
 *
 * @method     ChildTimer|null findOneByEventoId(string $evento_id) Return the first ChildTimer filtered by the evento_id column
 * @method     ChildTimer|null findOneByTimerId(int $timer_id) Return the first ChildTimer filtered by the timer_id column
 * @method     ChildTimer|null findOneByConfig(string $config) Return the first ChildTimer filtered by the config column
 * @method     ChildTimer|null findOneByConfigBackup(string $config_backup) Return the first ChildTimer filtered by the config_backup column
 * @method     ChildTimer|null findOneByState(string $state) Return the first ChildTimer filtered by the state column
 * @method     ChildTimer|null findOneByLog(string $log) Return the first ChildTimer filtered by the log column
 * @method     ChildTimer|null findOneByAccessKey(string $access_key) Return the first ChildTimer filtered by the access_key column
 *
 * @method     ChildTimer requirePk($key, ?ConnectionInterface $con = null) Return the ChildTimer by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildTimer requireOne(?ConnectionInterface $con = null) Return the first ChildTimer matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildTimer requireOneByEventoId(string $evento_id) Return the first ChildTimer filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildTimer requireOneByTimerId(int $timer_id) Return the first ChildTimer filtered by the timer_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildTimer requireOneByConfig(string $config) Return the first ChildTimer filtered by the config column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildTimer requireOneByConfigBackup(string $config_backup) Return the first ChildTimer filtered by the config_backup column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildTimer requireOneByState(string $state) Return the first ChildTimer filtered by the state column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildTimer requireOneByLog(string $log) Return the first ChildTimer filtered by the log column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildTimer requireOneByAccessKey(string $access_key) Return the first ChildTimer filtered by the access_key column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildTimer[]|Collection find(?ConnectionInterface $con = null) Return ChildTimer objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildTimer> find(?ConnectionInterface $con = null) Return ChildTimer objects based on current ModelCriteria
 *
 * @method     ChildTimer[]|Collection findByEventoId(string|array<string> $evento_id) Return ChildTimer objects filtered by the evento_id column
 * @psalm-method Collection&\Traversable<ChildTimer> findByEventoId(string|array<string> $evento_id) Return ChildTimer objects filtered by the evento_id column
 * @method     ChildTimer[]|Collection findByTimerId(int|array<int> $timer_id) Return ChildTimer objects filtered by the timer_id column
 * @psalm-method Collection&\Traversable<ChildTimer> findByTimerId(int|array<int> $timer_id) Return ChildTimer objects filtered by the timer_id column
 * @method     ChildTimer[]|Collection findByConfig(string|array<string> $config) Return ChildTimer objects filtered by the config column
 * @psalm-method Collection&\Traversable<ChildTimer> findByConfig(string|array<string> $config) Return ChildTimer objects filtered by the config column
 * @method     ChildTimer[]|Collection findByConfigBackup(string|array<string> $config_backup) Return ChildTimer objects filtered by the config_backup column
 * @psalm-method Collection&\Traversable<ChildTimer> findByConfigBackup(string|array<string> $config_backup) Return ChildTimer objects filtered by the config_backup column
 * @method     ChildTimer[]|Collection findByState(string|array<string> $state) Return ChildTimer objects filtered by the state column
 * @psalm-method Collection&\Traversable<ChildTimer> findByState(string|array<string> $state) Return ChildTimer objects filtered by the state column
 * @method     ChildTimer[]|Collection findByLog(string|array<string> $log) Return ChildTimer objects filtered by the log column
 * @psalm-method Collection&\Traversable<ChildTimer> findByLog(string|array<string> $log) Return ChildTimer objects filtered by the log column
 * @method     ChildTimer[]|Collection findByAccessKey(string|array<string> $access_key) Return ChildTimer objects filtered by the access_key column
 * @psalm-method Collection&\Traversable<ChildTimer> findByAccessKey(string|array<string> $access_key) Return ChildTimer objects filtered by the access_key column
 *
 * @method     ChildTimer[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildTimer> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class TimerQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\TimerQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Timer', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildTimerQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildTimerQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildTimerQuery) {
            return $criteria;
        }
        $query = new ChildTimerQuery();
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
     * @param array[$evento_id, $timer_id] $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildTimer|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(TimerTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = TimerTableMap::getInstanceFromPool(serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]))))) {
            // the object is already in the instance pool
            return $obj;
        }

        return $this->findPkSimple($key, $con);
    }

    /**
     * Find object by primary key using raw SQL to go fast.
     * Bypass doSelect() and the object formatter by using generated code.
     *
     * @param mixed $key Primary key to use for the query
     * @param ConnectionInterface $con A connection object
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return ChildTimer A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT evento_id, timer_id, config, config_backup, state, log, access_key FROM timer WHERE evento_id = :p0 AND timer_id = :p1';
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
            /** @var ChildTimer $obj */
            $obj = new ChildTimer();
            $obj->hydrate($row);
            TimerTableMap::addInstanceToPool($obj, serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]));
        }
        $stmt->closeCursor();

        return $obj;
    }

    /**
     * Find object by primary key.
     *
     * @param mixed $key Primary key to use for the query
     * @param ConnectionInterface $con A connection object
     *
     * @return ChildTimer|array|mixed the result, formatted by the current formatter
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
     * @param array $keys Primary keys to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return Collection|array|mixed the list of results, formatted by the current formatter
     */
    public function findPks($keys, ?ConnectionInterface $con = null)
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
     * @param mixed $key Primary key to use for the query
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByPrimaryKey($key)
    {
        $this->addUsingAlias(TimerTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
        $this->addUsingAlias(TimerTableMap::COL_TIMER_ID, $key[1], Criteria::EQUAL);

        return $this;
    }

    /**
     * Filter the query by a list of primary keys
     *
     * @param array|int $keys The list of primary key to use for the query
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByPrimaryKeys($keys)
    {
        if (empty($keys)) {
            $this->add(null, '1<>1', Criteria::CUSTOM);

            return $this;
        }
        foreach ($keys as $key) {
            $cton0 = $this->getNewCriterion(TimerTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
            $cton1 = $this->getNewCriterion(TimerTableMap::COL_TIMER_ID, $key[1], Criteria::EQUAL);
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
     * $query->filterByEventoId(['foo', 'bar']); // WHERE evento_id IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $eventoId The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEventoId($eventoId = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($eventoId)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(TimerTableMap::COL_EVENTO_ID, $eventoId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the timer_id column
     *
     * Example usage:
     * <code>
     * $query->filterByTimerId(1234); // WHERE timer_id = 1234
     * $query->filterByTimerId(array(12, 34)); // WHERE timer_id IN (12, 34)
     * $query->filterByTimerId(array('min' => 12)); // WHERE timer_id > 12
     * </code>
     *
     * @param mixed $timerId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByTimerId($timerId = null, ?string $comparison = null)
    {
        if (is_array($timerId)) {
            $useMinMax = false;
            if (isset($timerId['min'])) {
                $this->addUsingAlias(TimerTableMap::COL_TIMER_ID, $timerId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($timerId['max'])) {
                $this->addUsingAlias(TimerTableMap::COL_TIMER_ID, $timerId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(TimerTableMap::COL_TIMER_ID, $timerId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the config column
     *
     * Example usage:
     * <code>
     * $query->filterByConfig('fooValue');   // WHERE config = 'fooValue'
     * $query->filterByConfig('%fooValue%', Criteria::LIKE); // WHERE config LIKE '%fooValue%'
     * $query->filterByConfig(['foo', 'bar']); // WHERE config IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $config The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByConfig($config = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($config)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(TimerTableMap::COL_CONFIG, $config, $comparison);

        return $this;
    }

    /**
     * Filter the query on the config_backup column
     *
     * Example usage:
     * <code>
     * $query->filterByConfigBackup('fooValue');   // WHERE config_backup = 'fooValue'
     * $query->filterByConfigBackup('%fooValue%', Criteria::LIKE); // WHERE config_backup LIKE '%fooValue%'
     * $query->filterByConfigBackup(['foo', 'bar']); // WHERE config_backup IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $configBackup The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByConfigBackup($configBackup = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($configBackup)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(TimerTableMap::COL_CONFIG_BACKUP, $configBackup, $comparison);

        return $this;
    }

    /**
     * Filter the query on the state column
     *
     * Example usage:
     * <code>
     * $query->filterByState('fooValue');   // WHERE state = 'fooValue'
     * $query->filterByState('%fooValue%', Criteria::LIKE); // WHERE state LIKE '%fooValue%'
     * $query->filterByState(['foo', 'bar']); // WHERE state IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $state The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByState($state = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($state)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(TimerTableMap::COL_STATE, $state, $comparison);

        return $this;
    }

    /**
     * Filter the query on the log column
     *
     * Example usage:
     * <code>
     * $query->filterByLog('fooValue');   // WHERE log = 'fooValue'
     * $query->filterByLog('%fooValue%', Criteria::LIKE); // WHERE log LIKE '%fooValue%'
     * $query->filterByLog(['foo', 'bar']); // WHERE log IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $log The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByLog($log = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($log)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(TimerTableMap::COL_LOG, $log, $comparison);

        return $this;
    }

    /**
     * Filter the query on the access_key column
     *
     * Example usage:
     * <code>
     * $query->filterByAccessKey('fooValue');   // WHERE access_key = 'fooValue'
     * $query->filterByAccessKey('%fooValue%', Criteria::LIKE); // WHERE access_key LIKE '%fooValue%'
     * $query->filterByAccessKey(['foo', 'bar']); // WHERE access_key IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $accessKey The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByAccessKey($accessKey = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($accessKey)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(TimerTableMap::COL_ACCESS_KEY, $accessKey, $comparison);

        return $this;
    }

    /**
     * Filter the query by a related \Baja\Model\Evento object
     *
     * @param \Baja\Model\Evento|ObjectCollection $evento The related object(s) to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEvento($evento, ?string $comparison = null)
    {
        if ($evento instanceof \Baja\Model\Evento) {
            return $this
                ->addUsingAlias(TimerTableMap::COL_EVENTO_ID, $evento->getEventoId(), $comparison);
        } elseif ($evento instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(TimerTableMap::COL_EVENTO_ID, $evento->toKeyValue('PrimaryKey', 'EventoId'), $comparison);

            return $this;
        } else {
            throw new PropelException('filterByEvento() only accepts arguments of type \Baja\Model\Evento or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Evento relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinEvento(?string $relationAlias = null, ?string $joinType = Criteria::INNER_JOIN)
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
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
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
     * Use the Evento relation Evento object
     *
     * @param callable(\Baja\Model\EventoQuery):\Baja\Model\EventoQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withEventoQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::INNER_JOIN
    ) {
        $relatedQuery = $this->useEventoQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to Evento table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\EventoQuery The inner query object of the EXISTS statement
     */
    public function useEventoExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\EventoQuery */
        $q = $this->useExistsQuery('Evento', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to Evento table for a NOT EXISTS query.
     *
     * @see useEventoExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\EventoQuery The inner query object of the NOT EXISTS statement
     */
    public function useEventoNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\EventoQuery */
        $q = $this->useExistsQuery('Evento', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to Evento table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\EventoQuery The inner query object of the IN statement
     */
    public function useInEventoQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\EventoQuery */
        $q = $this->useInQuery('Evento', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to Evento table for a NOT IN query.
     *
     * @see useEventoInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\EventoQuery The inner query object of the NOT IN statement
     */
    public function useNotInEventoQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\EventoQuery */
        $q = $this->useInQuery('Evento', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Exclude object from result
     *
     * @param ChildTimer $timer Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($timer = null)
    {
        if ($timer) {
            $this->addCond('pruneCond0', $this->getAliasedColName(TimerTableMap::COL_EVENTO_ID), $timer->getEventoId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond1', $this->getAliasedColName(TimerTableMap::COL_TIMER_ID), $timer->getTimerId(), Criteria::NOT_EQUAL);
            $this->combine(array('pruneCond0', 'pruneCond1'), Criteria::LOGICAL_OR);
        }

        return $this;
    }

    /**
     * Deletes all rows from the timer table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(TimerTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            TimerTableMap::clearInstancePool();
            TimerTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

    /**
     * Performs a DELETE on the database based on the current ModelCriteria
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).  This includes CASCADE-related rows
     *                         if supported by native driver or if emulated using Propel.
     * @throws \Propel\Runtime\Exception\PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public function delete(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(TimerTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(TimerTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            TimerTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            TimerTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
