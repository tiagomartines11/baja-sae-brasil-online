<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\User as ChildUser;
use Baja\Model\UserQuery as ChildUserQuery;
use Baja\Model\Map\UserTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `user` table.
 *
 * @method     ChildUserQuery orderByUserId($order = Criteria::ASC) Order by the user_id column
 * @method     ChildUserQuery orderByUsername($order = Criteria::ASC) Order by the username column
 * @method     ChildUserQuery orderByPermissions($order = Criteria::ASC) Order by the permissions column
 * @method     ChildUserQuery orderByLastLogin($order = Criteria::ASC) Order by the last_login column
 *
 * @method     ChildUserQuery groupByUserId() Group by the user_id column
 * @method     ChildUserQuery groupByUsername() Group by the username column
 * @method     ChildUserQuery groupByPermissions() Group by the permissions column
 * @method     ChildUserQuery groupByLastLogin() Group by the last_login column
 *
 * @method     ChildUserQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildUserQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildUserQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildUserQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildUserQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildUserQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildUserQuery leftJoinParticipante($relationAlias = null) Adds a LEFT JOIN clause to the query using the Participante relation
 * @method     ChildUserQuery rightJoinParticipante($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Participante relation
 * @method     ChildUserQuery innerJoinParticipante($relationAlias = null) Adds a INNER JOIN clause to the query using the Participante relation
 *
 * @method     ChildUserQuery joinWithParticipante($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Participante relation
 *
 * @method     ChildUserQuery leftJoinWithParticipante() Adds a LEFT JOIN clause and with to the query using the Participante relation
 * @method     ChildUserQuery rightJoinWithParticipante() Adds a RIGHT JOIN clause and with to the query using the Participante relation
 * @method     ChildUserQuery innerJoinWithParticipante() Adds a INNER JOIN clause and with to the query using the Participante relation
 *
 * @method     ChildUserQuery leftJoinConfig($relationAlias = null) Adds a LEFT JOIN clause to the query using the Config relation
 * @method     ChildUserQuery rightJoinConfig($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Config relation
 * @method     ChildUserQuery innerJoinConfig($relationAlias = null) Adds a INNER JOIN clause to the query using the Config relation
 *
 * @method     ChildUserQuery joinWithConfig($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Config relation
 *
 * @method     ChildUserQuery leftJoinWithConfig() Adds a LEFT JOIN clause and with to the query using the Config relation
 * @method     ChildUserQuery rightJoinWithConfig() Adds a RIGHT JOIN clause and with to the query using the Config relation
 * @method     ChildUserQuery innerJoinWithConfig() Adds a INNER JOIN clause and with to the query using the Config relation
 *
 * @method     \Baja\Model\ParticipanteQuery|\Baja\Model\ConfigQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildUser|null findOne(?ConnectionInterface $con = null) Return the first ChildUser matching the query
 * @method     ChildUser findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildUser matching the query, or a new ChildUser object populated from the query conditions when no match is found
 *
 * @method     ChildUser|null findOneByUserId(int $user_id) Return the first ChildUser filtered by the user_id column
 * @method     ChildUser|null findOneByUsername(string $username) Return the first ChildUser filtered by the username column
 * @method     ChildUser|null findOneByPermissions(array $permissions) Return the first ChildUser filtered by the permissions column
 * @method     ChildUser|null findOneByLastLogin(string $last_login) Return the first ChildUser filtered by the last_login column
 *
 * @method     ChildUser requirePk($key, ?ConnectionInterface $con = null) Return the ChildUser by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildUser requireOne(?ConnectionInterface $con = null) Return the first ChildUser matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildUser requireOneByUserId(int $user_id) Return the first ChildUser filtered by the user_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildUser requireOneByUsername(string $username) Return the first ChildUser filtered by the username column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildUser requireOneByPermissions(array $permissions) Return the first ChildUser filtered by the permissions column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildUser requireOneByLastLogin(string $last_login) Return the first ChildUser filtered by the last_login column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildUser[]|Collection find(?ConnectionInterface $con = null) Return ChildUser objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildUser> find(?ConnectionInterface $con = null) Return ChildUser objects based on current ModelCriteria
 *
 * @method     ChildUser[]|Collection findByUserId(int|array<int> $user_id) Return ChildUser objects filtered by the user_id column
 * @psalm-method Collection&\Traversable<ChildUser> findByUserId(int|array<int> $user_id) Return ChildUser objects filtered by the user_id column
 * @method     ChildUser[]|Collection findByUsername(string|array<string> $username) Return ChildUser objects filtered by the username column
 * @psalm-method Collection&\Traversable<ChildUser> findByUsername(string|array<string> $username) Return ChildUser objects filtered by the username column
 * @method     ChildUser[]|Collection findByPermissions(array|array<array> $permissions) Return ChildUser objects filtered by the permissions column
 * @psalm-method Collection&\Traversable<ChildUser> findByPermissions(array|array<array> $permissions) Return ChildUser objects filtered by the permissions column
 * @method     ChildUser[]|Collection findByLastLogin(string|array<string> $last_login) Return ChildUser objects filtered by the last_login column
 * @psalm-method Collection&\Traversable<ChildUser> findByLastLogin(string|array<string> $last_login) Return ChildUser objects filtered by the last_login column
 *
 * @method     ChildUser[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildUser> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class UserQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\UserQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\User', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildUserQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildUserQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildUserQuery) {
            return $criteria;
        }
        $query = new ChildUserQuery();
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
     * @return ChildUser|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(UserTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = UserTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildUser A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT user_id, username, permissions, last_login FROM user WHERE user_id = :p0';
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
            /** @var ChildUser $obj */
            $obj = new ChildUser();
            $obj->hydrate($row);
            UserTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildUser|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(UserTableMap::COL_USER_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(UserTableMap::COL_USER_ID, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the user_id column
     *
     * Example usage:
     * <code>
     * $query->filterByUserId(1234); // WHERE user_id = 1234
     * $query->filterByUserId(array(12, 34)); // WHERE user_id IN (12, 34)
     * $query->filterByUserId(array('min' => 12)); // WHERE user_id > 12
     * </code>
     *
     * @param mixed $userId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByUserId($userId = null, ?string $comparison = null)
    {
        if (is_array($userId)) {
            $useMinMax = false;
            if (isset($userId['min'])) {
                $this->addUsingAlias(UserTableMap::COL_USER_ID, $userId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($userId['max'])) {
                $this->addUsingAlias(UserTableMap::COL_USER_ID, $userId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(UserTableMap::COL_USER_ID, $userId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the username column
     *
     * Example usage:
     * <code>
     * $query->filterByUsername('fooValue');   // WHERE username = 'fooValue'
     * $query->filterByUsername('%fooValue%', Criteria::LIKE); // WHERE username LIKE '%fooValue%'
     * $query->filterByUsername(['foo', 'bar']); // WHERE username IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $username The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByUsername($username = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($username)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(UserTableMap::COL_USERNAME, $username, $comparison);

        return $this;
    }

    /**
     * Filter the query on the permissions column
     *
     * @param array $permissions The values to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByPermissions($permissions = null, ?string $comparison = null)
    {
        $key = $this->getAliasedColName(UserTableMap::COL_PERMISSIONS);
        if (null === $comparison || $comparison == Criteria::CONTAINS_ALL) {
            foreach ($permissions as $value) {
                $value = '%| ' . $value . ' |%';
                if ($this->containsKey($key)) {
                    $this->addAnd($key, $value, Criteria::LIKE);
                } else {
                    $this->add($key, $value, Criteria::LIKE);
                }
            }

            return $this;
        } elseif ($comparison == Criteria::CONTAINS_SOME) {
            foreach ($permissions as $value) {
                $value = '%| ' . $value . ' |%';
                if ($this->containsKey($key)) {
                    $this->addOr($key, $value, Criteria::LIKE);
                } else {
                    $this->add($key, $value, Criteria::LIKE);
                }
            }

            return $this;
        } elseif ($comparison == Criteria::CONTAINS_NONE) {
            foreach ($permissions as $value) {
                $value = '%| ' . $value . ' |%';
                if ($this->containsKey($key)) {
                    $this->addAnd($key, $value, Criteria::NOT_LIKE);
                } else {
                    $this->add($key, $value, Criteria::NOT_LIKE);
                }
            }
            $this->addOr($key, null, Criteria::ISNULL);

            return $this;
        }

        $this->addUsingAlias(UserTableMap::COL_PERMISSIONS, $permissions, $comparison);

        return $this;
    }

    /**
     * Filter the query on the permissions column
     * @param mixed $permissions The value to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::CONTAINS_ALL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByPermission($permissions = null, ?string $comparison = null)
    {
        if (null === $comparison || $comparison == Criteria::CONTAINS_ALL) {
            if (is_scalar($permissions)) {
                $permissions = '%| ' . $permissions . ' |%';
                $comparison = Criteria::LIKE;
            }
        } elseif ($comparison == Criteria::CONTAINS_NONE) {
            $permissions = '%| ' . $permissions . ' |%';
            $comparison = Criteria::NOT_LIKE;
            $key = $this->getAliasedColName(UserTableMap::COL_PERMISSIONS);
            if ($this->containsKey($key)) {
                $this->addAnd($key, $permissions, $comparison);
            } else {
                $this->addAnd($key, $permissions, $comparison);
            }
            $this->addOr($key, null, Criteria::ISNULL);

            return $this;
        }

        $this->addUsingAlias(UserTableMap::COL_PERMISSIONS, $permissions, $comparison);

        return $this;
    }

    /**
     * Filter the query on the last_login column
     *
     * Example usage:
     * <code>
     * $query->filterByLastLogin('2011-03-14'); // WHERE last_login = '2011-03-14'
     * $query->filterByLastLogin('now'); // WHERE last_login = '2011-03-14'
     * $query->filterByLastLogin(array('max' => 'yesterday')); // WHERE last_login > '2011-03-13'
     * </code>
     *
     * @param mixed $lastLogin The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByLastLogin($lastLogin = null, ?string $comparison = null)
    {
        if (is_array($lastLogin)) {
            $useMinMax = false;
            if (isset($lastLogin['min'])) {
                $this->addUsingAlias(UserTableMap::COL_LAST_LOGIN, $lastLogin['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($lastLogin['max'])) {
                $this->addUsingAlias(UserTableMap::COL_LAST_LOGIN, $lastLogin['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(UserTableMap::COL_LAST_LOGIN, $lastLogin, $comparison);

        return $this;
    }

    /**
     * Filter the query by a related \Baja\Model\Participante object
     *
     * @param \Baja\Model\Participante|ObjectCollection $participante the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByParticipante($participante, ?string $comparison = null)
    {
        if ($participante instanceof \Baja\Model\Participante) {
            $this
                ->addUsingAlias(UserTableMap::COL_USER_ID, $participante->getCriadoPor(), $comparison);

            return $this;
        } elseif ($participante instanceof ObjectCollection) {
            $this
                ->useParticipanteQuery()
                ->filterByPrimaryKeys($participante->getPrimaryKeys())
                ->endUse();

            return $this;
        } else {
            throw new PropelException('filterByParticipante() only accepts arguments of type \Baja\Model\Participante or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Participante relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinParticipante(?string $relationAlias = null, ?string $joinType = Criteria::LEFT_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Participante');

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
            $this->addJoinObject($join, 'Participante');
        }

        return $this;
    }

    /**
     * Use the Participante relation Participante object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\ParticipanteQuery A secondary query class using the current class as primary query
     */
    public function useParticipanteQuery($relationAlias = null, $joinType = Criteria::LEFT_JOIN)
    {
        return $this
            ->joinParticipante($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Participante', '\Baja\Model\ParticipanteQuery');
    }

    /**
     * Use the Participante relation Participante object
     *
     * @param callable(\Baja\Model\ParticipanteQuery):\Baja\Model\ParticipanteQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withParticipanteQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::LEFT_JOIN
    ) {
        $relatedQuery = $this->useParticipanteQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to Participante table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\ParticipanteQuery The inner query object of the EXISTS statement
     */
    public function useParticipanteExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\ParticipanteQuery */
        $q = $this->useExistsQuery('Participante', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to Participante table for a NOT EXISTS query.
     *
     * @see useParticipanteExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ParticipanteQuery The inner query object of the NOT EXISTS statement
     */
    public function useParticipanteNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ParticipanteQuery */
        $q = $this->useExistsQuery('Participante', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to Participante table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\ParticipanteQuery The inner query object of the IN statement
     */
    public function useInParticipanteQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\ParticipanteQuery */
        $q = $this->useInQuery('Participante', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to Participante table for a NOT IN query.
     *
     * @see useParticipanteInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ParticipanteQuery The inner query object of the NOT IN statement
     */
    public function useNotInParticipanteQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ParticipanteQuery */
        $q = $this->useInQuery('Participante', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Filter the query by a related \Baja\Model\Config object
     *
     * @param \Baja\Model\Config|ObjectCollection $config the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByConfig($config, ?string $comparison = null)
    {
        if ($config instanceof \Baja\Model\Config) {
            $this
                ->addUsingAlias(UserTableMap::COL_USER_ID, $config->getUpdatedBy(), $comparison);

            return $this;
        } elseif ($config instanceof ObjectCollection) {
            $this
                ->useConfigQuery()
                ->filterByPrimaryKeys($config->getPrimaryKeys())
                ->endUse();

            return $this;
        } else {
            throw new PropelException('filterByConfig() only accepts arguments of type \Baja\Model\Config or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Config relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinConfig(?string $relationAlias = null, ?string $joinType = Criteria::LEFT_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Config');

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
            $this->addJoinObject($join, 'Config');
        }

        return $this;
    }

    /**
     * Use the Config relation Config object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\ConfigQuery A secondary query class using the current class as primary query
     */
    public function useConfigQuery($relationAlias = null, $joinType = Criteria::LEFT_JOIN)
    {
        return $this
            ->joinConfig($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Config', '\Baja\Model\ConfigQuery');
    }

    /**
     * Use the Config relation Config object
     *
     * @param callable(\Baja\Model\ConfigQuery):\Baja\Model\ConfigQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withConfigQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::LEFT_JOIN
    ) {
        $relatedQuery = $this->useConfigQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to Config table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\ConfigQuery The inner query object of the EXISTS statement
     */
    public function useConfigExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\ConfigQuery */
        $q = $this->useExistsQuery('Config', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to Config table for a NOT EXISTS query.
     *
     * @see useConfigExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ConfigQuery The inner query object of the NOT EXISTS statement
     */
    public function useConfigNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ConfigQuery */
        $q = $this->useExistsQuery('Config', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to Config table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\ConfigQuery The inner query object of the IN statement
     */
    public function useInConfigQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\ConfigQuery */
        $q = $this->useInQuery('Config', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to Config table for a NOT IN query.
     *
     * @see useConfigInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ConfigQuery The inner query object of the NOT IN statement
     */
    public function useNotInConfigQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ConfigQuery */
        $q = $this->useInQuery('Config', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Exclude object from result
     *
     * @param ChildUser $user Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($user = null)
    {
        if ($user) {
            $this->addUsingAlias(UserTableMap::COL_USER_ID, $user->getUserId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the user table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(UserTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            UserTableMap::clearInstancePool();
            UserTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(UserTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(UserTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            UserTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            UserTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
