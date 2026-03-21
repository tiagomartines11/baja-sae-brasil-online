<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Log as ChildLog;
use Baja\Model\LogQuery as ChildLogQuery;
use Baja\Model\Map\LogTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the 'log' table.
 *
 *
 *
 * @method     ChildLogQuery orderById($order = Criteria::ASC) Order by the id column
 * @method     ChildLogQuery orderByUser($order = Criteria::ASC) Order by the user column
 * @method     ChildLogQuery orderByPagina($order = Criteria::ASC) Order by the pagina column
 * @method     ChildLogQuery orderByEquipe($order = Criteria::ASC) Order by the equipe column
 * @method     ChildLogQuery orderByDados($order = Criteria::ASC) Order by the dados column
 * @method     ChildLogQuery orderByData($order = Criteria::ASC) Order by the data column
 *
 * @method     ChildLogQuery groupById() Group by the id column
 * @method     ChildLogQuery groupByUser() Group by the user column
 * @method     ChildLogQuery groupByPagina() Group by the pagina column
 * @method     ChildLogQuery groupByEquipe() Group by the equipe column
 * @method     ChildLogQuery groupByDados() Group by the dados column
 * @method     ChildLogQuery groupByData() Group by the data column
 *
 * @method     ChildLogQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildLogQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildLogQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildLogQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildLogQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildLogQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildLog findOne(ConnectionInterface $con = null) Return the first ChildLog matching the query
 * @method     ChildLog findOneOrCreate(ConnectionInterface $con = null) Return the first ChildLog matching the query, or a new ChildLog object populated from the query conditions when no match is found
 *
 * @method     ChildLog findOneById(string $id) Return the first ChildLog filtered by the id column
 * @method     ChildLog findOneByUser(string $user) Return the first ChildLog filtered by the user column
 * @method     ChildLog findOneByPagina(string $pagina) Return the first ChildLog filtered by the pagina column
 * @method     ChildLog findOneByEquipe(int $equipe) Return the first ChildLog filtered by the equipe column
 * @method     ChildLog findOneByDados(string $dados) Return the first ChildLog filtered by the dados column
 * @method     ChildLog findOneByData(string $data) Return the first ChildLog filtered by the data column *

 * @method     ChildLog requirePk($key, ConnectionInterface $con = null) Return the ChildLog by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildLog requireOne(ConnectionInterface $con = null) Return the first ChildLog matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildLog requireOneById(string $id) Return the first ChildLog filtered by the id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildLog requireOneByUser(string $user) Return the first ChildLog filtered by the user column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildLog requireOneByPagina(string $pagina) Return the first ChildLog filtered by the pagina column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildLog requireOneByEquipe(int $equipe) Return the first ChildLog filtered by the equipe column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildLog requireOneByDados(string $dados) Return the first ChildLog filtered by the dados column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildLog requireOneByData(string $data) Return the first ChildLog filtered by the data column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildLog[]|ObjectCollection find(ConnectionInterface $con = null) Return ChildLog objects based on current ModelCriteria
 * @method     ChildLog[]|ObjectCollection findById(string $id) Return ChildLog objects filtered by the id column
 * @method     ChildLog[]|ObjectCollection findByUser(string $user) Return ChildLog objects filtered by the user column
 * @method     ChildLog[]|ObjectCollection findByPagina(string $pagina) Return ChildLog objects filtered by the pagina column
 * @method     ChildLog[]|ObjectCollection findByEquipe(int $equipe) Return ChildLog objects filtered by the equipe column
 * @method     ChildLog[]|ObjectCollection findByDados(string $dados) Return ChildLog objects filtered by the dados column
 * @method     ChildLog[]|ObjectCollection findByData(string $data) Return ChildLog objects filtered by the data column
 * @method     ChildLog[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 *
 */
abstract class LogQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\LogQuery object.
     *
     * @param     string $dbName The database name
     * @param     string $modelName The phpName of a model, e.g. 'Book'
     * @param     string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Log', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildLogQuery object.
     *
     * @param     string $modelAlias The alias of a model in the query
     * @param     Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildLogQuery
     */
    public static function create($modelAlias = null, Criteria $criteria = null)
    {
        if ($criteria instanceof ChildLogQuery) {
            return $criteria;
        }
        $query = new ChildLogQuery();
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
     * $obj  = $c->findPk(12, $con);
     * </code>
     *
     * @param mixed $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildLog|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(LogTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = LogTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildLog A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT id, user, pagina, equipe, dados, data FROM log WHERE id = :p0';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            /** @var ChildLog $obj */
            $obj = new ChildLog();
            $obj->hydrate($row);
            LogTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildLog|array|mixed the result, formatted by the current formatter
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
     * $objs = $c->findPks(array(12, 56, 832), $con);
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
     * @return $this|ChildLogQuery The current query, for fluid interface
     */
    public function filterByPrimaryKey($key)
    {

        return $this->addUsingAlias(LogTableMap::COL_ID, $key, Criteria::EQUAL);
    }

    /**
     * Filter the query by a list of primary keys
     *
     * @param     array $keys The list of primary key to use for the query
     *
     * @return $this|ChildLogQuery The current query, for fluid interface
     */
    public function filterByPrimaryKeys($keys)
    {

        return $this->addUsingAlias(LogTableMap::COL_ID, $keys, Criteria::IN);
    }

    /**
     * Filter the query on the id column
     *
     * Example usage:
     * <code>
     * $query->filterById(1234); // WHERE id = 1234
     * $query->filterById(array(12, 34)); // WHERE id IN (12, 34)
     * $query->filterById(array('min' => 12)); // WHERE id > 12
     * </code>
     *
     * @param     mixed $id The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildLogQuery The current query, for fluid interface
     */
    public function filterById($id = null, $comparison = null)
    {
        if (is_array($id)) {
            $useMinMax = false;
            if (isset($id['min'])) {
                $this->addUsingAlias(LogTableMap::COL_ID, $id['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($id['max'])) {
                $this->addUsingAlias(LogTableMap::COL_ID, $id['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(LogTableMap::COL_ID, $id, $comparison);
    }

    /**
     * Filter the query on the user column
     *
     * Example usage:
     * <code>
     * $query->filterByUser('fooValue');   // WHERE user = 'fooValue'
     * $query->filterByUser('%fooValue%', Criteria::LIKE); // WHERE user LIKE '%fooValue%'
     * </code>
     *
     * @param     string $user The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildLogQuery The current query, for fluid interface
     */
    public function filterByUser($user = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($user)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(LogTableMap::COL_USER, $user, $comparison);
    }

    /**
     * Filter the query on the pagina column
     *
     * Example usage:
     * <code>
     * $query->filterByPagina('fooValue');   // WHERE pagina = 'fooValue'
     * $query->filterByPagina('%fooValue%', Criteria::LIKE); // WHERE pagina LIKE '%fooValue%'
     * </code>
     *
     * @param     string $pagina The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildLogQuery The current query, for fluid interface
     */
    public function filterByPagina($pagina = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($pagina)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(LogTableMap::COL_PAGINA, $pagina, $comparison);
    }

    /**
     * Filter the query on the equipe column
     *
     * Example usage:
     * <code>
     * $query->filterByEquipe(1234); // WHERE equipe = 1234
     * $query->filterByEquipe(array(12, 34)); // WHERE equipe IN (12, 34)
     * $query->filterByEquipe(array('min' => 12)); // WHERE equipe > 12
     * </code>
     *
     * @param     mixed $equipe The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildLogQuery The current query, for fluid interface
     */
    public function filterByEquipe($equipe = null, $comparison = null)
    {
        if (is_array($equipe)) {
            $useMinMax = false;
            if (isset($equipe['min'])) {
                $this->addUsingAlias(LogTableMap::COL_EQUIPE, $equipe['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($equipe['max'])) {
                $this->addUsingAlias(LogTableMap::COL_EQUIPE, $equipe['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(LogTableMap::COL_EQUIPE, $equipe, $comparison);
    }

    /**
     * Filter the query on the dados column
     *
     * Example usage:
     * <code>
     * $query->filterByDados('fooValue');   // WHERE dados = 'fooValue'
     * $query->filterByDados('%fooValue%', Criteria::LIKE); // WHERE dados LIKE '%fooValue%'
     * </code>
     *
     * @param     string $dados The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildLogQuery The current query, for fluid interface
     */
    public function filterByDados($dados = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($dados)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(LogTableMap::COL_DADOS, $dados, $comparison);
    }

    /**
     * Filter the query on the data column
     *
     * Example usage:
     * <code>
     * $query->filterByData('2011-03-14'); // WHERE data = '2011-03-14'
     * $query->filterByData('now'); // WHERE data = '2011-03-14'
     * $query->filterByData(array('max' => 'yesterday')); // WHERE data > '2011-03-13'
     * </code>
     *
     * @param     mixed $data The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildLogQuery The current query, for fluid interface
     */
    public function filterByData($data = null, $comparison = null)
    {
        if (is_array($data)) {
            $useMinMax = false;
            if (isset($data['min'])) {
                $this->addUsingAlias(LogTableMap::COL_DATA, $data['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($data['max'])) {
                $this->addUsingAlias(LogTableMap::COL_DATA, $data['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(LogTableMap::COL_DATA, $data, $comparison);
    }

    /**
     * Exclude object from result
     *
     * @param   ChildLog $log Object to remove from the list of results
     *
     * @return $this|ChildLogQuery The current query, for fluid interface
     */
    public function prune($log = null)
    {
        if ($log) {
            $this->addUsingAlias(LogTableMap::COL_ID, $log->getId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the log table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(LogTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            LogTableMap::clearInstancePool();
            LogTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(LogTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(LogTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            LogTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            LogTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

} // LogQuery
