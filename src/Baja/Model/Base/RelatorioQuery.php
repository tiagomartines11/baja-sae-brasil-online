<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Relatorio as ChildRelatorio;
use Baja\Model\RelatorioQuery as ChildRelatorioQuery;
use Baja\Model\Map\RelatorioTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the 'relatorio' table.
 *
 *
 *
 * @method     ChildRelatorioQuery orderByUserId($order = Criteria::ASC) Order by the user_id column
 * @method     ChildRelatorioQuery orderByEquipes($order = Criteria::ASC) Order by the equipes column
 * @method     ChildRelatorioQuery orderByNotas($order = Criteria::ASC) Order by the notas column
 *
 * @method     ChildRelatorioQuery groupByUserId() Group by the user_id column
 * @method     ChildRelatorioQuery groupByEquipes() Group by the equipes column
 * @method     ChildRelatorioQuery groupByNotas() Group by the notas column
 *
 * @method     ChildRelatorioQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildRelatorioQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildRelatorioQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildRelatorioQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildRelatorioQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildRelatorioQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildRelatorio findOne(ConnectionInterface $con = null) Return the first ChildRelatorio matching the query
 * @method     ChildRelatorio findOneOrCreate(ConnectionInterface $con = null) Return the first ChildRelatorio matching the query, or a new ChildRelatorio object populated from the query conditions when no match is found
 *
 * @method     ChildRelatorio findOneByUserId(string $user_id) Return the first ChildRelatorio filtered by the user_id column
 * @method     ChildRelatorio findOneByEquipes(string $equipes) Return the first ChildRelatorio filtered by the equipes column
 * @method     ChildRelatorio findOneByNotas(string $notas) Return the first ChildRelatorio filtered by the notas column *

 * @method     ChildRelatorio requirePk($key, ConnectionInterface $con = null) Return the ChildRelatorio by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildRelatorio requireOne(ConnectionInterface $con = null) Return the first ChildRelatorio matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildRelatorio requireOneByUserId(string $user_id) Return the first ChildRelatorio filtered by the user_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildRelatorio requireOneByEquipes(string $equipes) Return the first ChildRelatorio filtered by the equipes column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildRelatorio requireOneByNotas(string $notas) Return the first ChildRelatorio filtered by the notas column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildRelatorio[]|ObjectCollection find(ConnectionInterface $con = null) Return ChildRelatorio objects based on current ModelCriteria
 * @method     ChildRelatorio[]|ObjectCollection findByUserId(string $user_id) Return ChildRelatorio objects filtered by the user_id column
 * @method     ChildRelatorio[]|ObjectCollection findByEquipes(string $equipes) Return ChildRelatorio objects filtered by the equipes column
 * @method     ChildRelatorio[]|ObjectCollection findByNotas(string $notas) Return ChildRelatorio objects filtered by the notas column
 * @method     ChildRelatorio[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 *
 */
abstract class RelatorioQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\RelatorioQuery object.
     *
     * @param     string $dbName The database name
     * @param     string $modelName The phpName of a model, e.g. 'Book'
     * @param     string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Relatorio', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildRelatorioQuery object.
     *
     * @param     string $modelAlias The alias of a model in the query
     * @param     Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildRelatorioQuery
     */
    public static function create($modelAlias = null, Criteria $criteria = null)
    {
        if ($criteria instanceof ChildRelatorioQuery) {
            return $criteria;
        }
        $query = new ChildRelatorioQuery();
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
     * @return ChildRelatorio|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(RelatorioTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = RelatorioTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildRelatorio A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT user_id, equipes, notas FROM relatorio WHERE user_id = :p0';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key, PDO::PARAM_STR);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            /** @var ChildRelatorio $obj */
            $obj = new ChildRelatorio();
            $obj->hydrate($row);
            RelatorioTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildRelatorio|array|mixed the result, formatted by the current formatter
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
     * @return $this|ChildRelatorioQuery The current query, for fluid interface
     */
    public function filterByPrimaryKey($key)
    {

        return $this->addUsingAlias(RelatorioTableMap::COL_USER_ID, $key, Criteria::EQUAL);
    }

    /**
     * Filter the query by a list of primary keys
     *
     * @param     array $keys The list of primary key to use for the query
     *
     * @return $this|ChildRelatorioQuery The current query, for fluid interface
     */
    public function filterByPrimaryKeys($keys)
    {

        return $this->addUsingAlias(RelatorioTableMap::COL_USER_ID, $keys, Criteria::IN);
    }

    /**
     * Filter the query on the user_id column
     *
     * Example usage:
     * <code>
     * $query->filterByUserId('fooValue');   // WHERE user_id = 'fooValue'
     * $query->filterByUserId('%fooValue%', Criteria::LIKE); // WHERE user_id LIKE '%fooValue%'
     * </code>
     *
     * @param     string $userId The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildRelatorioQuery The current query, for fluid interface
     */
    public function filterByUserId($userId = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($userId)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(RelatorioTableMap::COL_USER_ID, $userId, $comparison);
    }

    /**
     * Filter the query on the equipes column
     *
     * Example usage:
     * <code>
     * $query->filterByEquipes('fooValue');   // WHERE equipes = 'fooValue'
     * $query->filterByEquipes('%fooValue%', Criteria::LIKE); // WHERE equipes LIKE '%fooValue%'
     * </code>
     *
     * @param     string $equipes The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildRelatorioQuery The current query, for fluid interface
     */
    public function filterByEquipes($equipes = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($equipes)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(RelatorioTableMap::COL_EQUIPES, $equipes, $comparison);
    }

    /**
     * Filter the query on the notas column
     *
     * Example usage:
     * <code>
     * $query->filterByNotas('fooValue');   // WHERE notas = 'fooValue'
     * $query->filterByNotas('%fooValue%', Criteria::LIKE); // WHERE notas LIKE '%fooValue%'
     * </code>
     *
     * @param     string $notas The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildRelatorioQuery The current query, for fluid interface
     */
    public function filterByNotas($notas = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($notas)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(RelatorioTableMap::COL_NOTAS, $notas, $comparison);
    }

    /**
     * Exclude object from result
     *
     * @param   ChildRelatorio $relatorio Object to remove from the list of results
     *
     * @return $this|ChildRelatorioQuery The current query, for fluid interface
     */
    public function prune($relatorio = null)
    {
        if ($relatorio) {
            $this->addUsingAlias(RelatorioTableMap::COL_USER_ID, $relatorio->getUserId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the relatorio table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(RelatorioTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            RelatorioTableMap::clearInstancePool();
            RelatorioTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(RelatorioTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(RelatorioTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            RelatorioTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            RelatorioTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

} // RelatorioQuery
