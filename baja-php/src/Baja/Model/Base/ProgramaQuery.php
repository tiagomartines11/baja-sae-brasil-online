<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Programa as ChildPrograma;
use Baja\Model\ProgramaQuery as ChildProgramaQuery;
use Baja\Model\Map\ProgramaTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `programa` table.
 *
 * @method     ChildProgramaQuery orderByProgramaId($order = Criteria::ASC) Order by the programa_id column
 * @method     ChildProgramaQuery orderByParentId($order = Criteria::ASC) Order by the parent_id column
 * @method     ChildProgramaQuery orderByNome($order = Criteria::ASC) Order by the nome column
 * @method     ChildProgramaQuery orderByOrdem($order = Criteria::ASC) Order by the ordem column
 * @method     ChildProgramaQuery orderByAtivo($order = Criteria::ASC) Order by the ativo column
 *
 * @method     ChildProgramaQuery groupByProgramaId() Group by the programa_id column
 * @method     ChildProgramaQuery groupByParentId() Group by the parent_id column
 * @method     ChildProgramaQuery groupByNome() Group by the nome column
 * @method     ChildProgramaQuery groupByOrdem() Group by the ordem column
 * @method     ChildProgramaQuery groupByAtivo() Group by the ativo column
 *
 * @method     ChildProgramaQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildProgramaQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildProgramaQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildProgramaQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildProgramaQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildProgramaQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildProgramaQuery leftJoinParent($relationAlias = null) Adds a LEFT JOIN clause to the query using the Parent relation
 * @method     ChildProgramaQuery rightJoinParent($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Parent relation
 * @method     ChildProgramaQuery innerJoinParent($relationAlias = null) Adds a INNER JOIN clause to the query using the Parent relation
 *
 * @method     ChildProgramaQuery joinWithParent($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Parent relation
 *
 * @method     ChildProgramaQuery leftJoinWithParent() Adds a LEFT JOIN clause and with to the query using the Parent relation
 * @method     ChildProgramaQuery rightJoinWithParent() Adds a RIGHT JOIN clause and with to the query using the Parent relation
 * @method     ChildProgramaQuery innerJoinWithParent() Adds a INNER JOIN clause and with to the query using the Parent relation
 *
 * @method     ChildProgramaQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildProgramaQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildProgramaQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildProgramaQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildProgramaQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildProgramaQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildProgramaQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     ChildProgramaQuery leftJoinChild($relationAlias = null) Adds a LEFT JOIN clause to the query using the Child relation
 * @method     ChildProgramaQuery rightJoinChild($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Child relation
 * @method     ChildProgramaQuery innerJoinChild($relationAlias = null) Adds a INNER JOIN clause to the query using the Child relation
 *
 * @method     ChildProgramaQuery joinWithChild($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Child relation
 *
 * @method     ChildProgramaQuery leftJoinWithChild() Adds a LEFT JOIN clause and with to the query using the Child relation
 * @method     ChildProgramaQuery rightJoinWithChild() Adds a RIGHT JOIN clause and with to the query using the Child relation
 * @method     ChildProgramaQuery innerJoinWithChild() Adds a INNER JOIN clause and with to the query using the Child relation
 *
 * @method     \Baja\Model\ProgramaQuery|\Baja\Model\EventoQuery|\Baja\Model\ProgramaQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildPrograma|null findOne(?ConnectionInterface $con = null) Return the first ChildPrograma matching the query
 * @method     ChildPrograma findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildPrograma matching the query, or a new ChildPrograma object populated from the query conditions when no match is found
 *
 * @method     ChildPrograma|null findOneByProgramaId(string $programa_id) Return the first ChildPrograma filtered by the programa_id column
 * @method     ChildPrograma|null findOneByParentId(string $parent_id) Return the first ChildPrograma filtered by the parent_id column
 * @method     ChildPrograma|null findOneByNome(string $nome) Return the first ChildPrograma filtered by the nome column
 * @method     ChildPrograma|null findOneByOrdem(int $ordem) Return the first ChildPrograma filtered by the ordem column
 * @method     ChildPrograma|null findOneByAtivo(boolean $ativo) Return the first ChildPrograma filtered by the ativo column
 *
 * @method     ChildPrograma requirePk($key, ?ConnectionInterface $con = null) Return the ChildPrograma by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPrograma requireOne(?ConnectionInterface $con = null) Return the first ChildPrograma matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildPrograma requireOneByProgramaId(string $programa_id) Return the first ChildPrograma filtered by the programa_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPrograma requireOneByParentId(string $parent_id) Return the first ChildPrograma filtered by the parent_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPrograma requireOneByNome(string $nome) Return the first ChildPrograma filtered by the nome column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPrograma requireOneByOrdem(int $ordem) Return the first ChildPrograma filtered by the ordem column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPrograma requireOneByAtivo(boolean $ativo) Return the first ChildPrograma filtered by the ativo column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildPrograma[]|Collection find(?ConnectionInterface $con = null) Return ChildPrograma objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildPrograma> find(?ConnectionInterface $con = null) Return ChildPrograma objects based on current ModelCriteria
 *
 * @method     ChildPrograma[]|Collection findByProgramaId(string|array<string> $programa_id) Return ChildPrograma objects filtered by the programa_id column
 * @psalm-method Collection&\Traversable<ChildPrograma> findByProgramaId(string|array<string> $programa_id) Return ChildPrograma objects filtered by the programa_id column
 * @method     ChildPrograma[]|Collection findByParentId(string|array<string> $parent_id) Return ChildPrograma objects filtered by the parent_id column
 * @psalm-method Collection&\Traversable<ChildPrograma> findByParentId(string|array<string> $parent_id) Return ChildPrograma objects filtered by the parent_id column
 * @method     ChildPrograma[]|Collection findByNome(string|array<string> $nome) Return ChildPrograma objects filtered by the nome column
 * @psalm-method Collection&\Traversable<ChildPrograma> findByNome(string|array<string> $nome) Return ChildPrograma objects filtered by the nome column
 * @method     ChildPrograma[]|Collection findByOrdem(int|array<int> $ordem) Return ChildPrograma objects filtered by the ordem column
 * @psalm-method Collection&\Traversable<ChildPrograma> findByOrdem(int|array<int> $ordem) Return ChildPrograma objects filtered by the ordem column
 * @method     ChildPrograma[]|Collection findByAtivo(boolean|array<boolean> $ativo) Return ChildPrograma objects filtered by the ativo column
 * @psalm-method Collection&\Traversable<ChildPrograma> findByAtivo(boolean|array<boolean> $ativo) Return ChildPrograma objects filtered by the ativo column
 *
 * @method     ChildPrograma[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildPrograma> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ProgramaQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ProgramaQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Programa', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildProgramaQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildProgramaQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildProgramaQuery) {
            return $criteria;
        }
        $query = new ChildProgramaQuery();
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
     * @return ChildPrograma|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ProgramaTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ProgramaTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildPrograma A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT programa_id, parent_id, nome, ordem, ativo FROM programa WHERE programa_id = :p0';
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
            /** @var ChildPrograma $obj */
            $obj = new ChildPrograma();
            $obj->hydrate($row);
            ProgramaTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildPrograma|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(ProgramaTableMap::COL_PROGRAMA_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(ProgramaTableMap::COL_PROGRAMA_ID, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the programa_id column
     *
     * Example usage:
     * <code>
     * $query->filterByProgramaId('fooValue');   // WHERE programa_id = 'fooValue'
     * $query->filterByProgramaId('%fooValue%', Criteria::LIKE); // WHERE programa_id LIKE '%fooValue%'
     * $query->filterByProgramaId(['foo', 'bar']); // WHERE programa_id IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $programaId The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByProgramaId($programaId = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($programaId)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ProgramaTableMap::COL_PROGRAMA_ID, $programaId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the parent_id column
     *
     * Example usage:
     * <code>
     * $query->filterByParentId('fooValue');   // WHERE parent_id = 'fooValue'
     * $query->filterByParentId('%fooValue%', Criteria::LIKE); // WHERE parent_id LIKE '%fooValue%'
     * $query->filterByParentId(['foo', 'bar']); // WHERE parent_id IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $parentId The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByParentId($parentId = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($parentId)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ProgramaTableMap::COL_PARENT_ID, $parentId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the nome column
     *
     * Example usage:
     * <code>
     * $query->filterByNome('fooValue');   // WHERE nome = 'fooValue'
     * $query->filterByNome('%fooValue%', Criteria::LIKE); // WHERE nome LIKE '%fooValue%'
     * $query->filterByNome(['foo', 'bar']); // WHERE nome IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $nome The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByNome($nome = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($nome)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ProgramaTableMap::COL_NOME, $nome, $comparison);

        return $this;
    }

    /**
     * Filter the query on the ordem column
     *
     * Example usage:
     * <code>
     * $query->filterByOrdem(1234); // WHERE ordem = 1234
     * $query->filterByOrdem(array(12, 34)); // WHERE ordem IN (12, 34)
     * $query->filterByOrdem(array('min' => 12)); // WHERE ordem > 12
     * </code>
     *
     * @param mixed $ordem The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByOrdem($ordem = null, ?string $comparison = null)
    {
        if (is_array($ordem)) {
            $useMinMax = false;
            if (isset($ordem['min'])) {
                $this->addUsingAlias(ProgramaTableMap::COL_ORDEM, $ordem['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($ordem['max'])) {
                $this->addUsingAlias(ProgramaTableMap::COL_ORDEM, $ordem['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ProgramaTableMap::COL_ORDEM, $ordem, $comparison);

        return $this;
    }

    /**
     * Filter the query on the ativo column
     *
     * Example usage:
     * <code>
     * $query->filterByAtivo(true); // WHERE ativo = true
     * $query->filterByAtivo('yes'); // WHERE ativo = true
     * </code>
     *
     * @param bool|string $ativo The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByAtivo($ativo = null, ?string $comparison = null)
    {
        if (is_string($ativo)) {
            $ativo = in_array(strtolower($ativo), array('false', 'off', '-', 'no', 'n', '0', ''), true) ? false : true;
        }

        $this->addUsingAlias(ProgramaTableMap::COL_ATIVO, $ativo, $comparison);

        return $this;
    }

    /**
     * Filter the query by a related \Baja\Model\Programa object
     *
     * @param \Baja\Model\Programa|ObjectCollection $programa The related object(s) to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByParent($programa, ?string $comparison = null)
    {
        if ($programa instanceof \Baja\Model\Programa) {
            return $this
                ->addUsingAlias(ProgramaTableMap::COL_PARENT_ID, $programa->getProgramaId(), $comparison);
        } elseif ($programa instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(ProgramaTableMap::COL_PARENT_ID, $programa->toKeyValue('PrimaryKey', 'ProgramaId'), $comparison);

            return $this;
        } else {
            throw new PropelException('filterByParent() only accepts arguments of type \Baja\Model\Programa or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Parent relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinParent(?string $relationAlias = null, ?string $joinType = Criteria::LEFT_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Parent');

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
            $this->addJoinObject($join, 'Parent');
        }

        return $this;
    }

    /**
     * Use the Parent relation Programa object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\ProgramaQuery A secondary query class using the current class as primary query
     */
    public function useParentQuery($relationAlias = null, $joinType = Criteria::LEFT_JOIN)
    {
        return $this
            ->joinParent($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Parent', '\Baja\Model\ProgramaQuery');
    }

    /**
     * Use the Parent relation Programa object
     *
     * @param callable(\Baja\Model\ProgramaQuery):\Baja\Model\ProgramaQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withParentQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::LEFT_JOIN
    ) {
        $relatedQuery = $this->useParentQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the Parent relation to the Programa table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\ProgramaQuery The inner query object of the EXISTS statement
     */
    public function useParentExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\ProgramaQuery */
        $q = $this->useExistsQuery('Parent', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the Parent relation to the Programa table for a NOT EXISTS query.
     *
     * @see useParentExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ProgramaQuery The inner query object of the NOT EXISTS statement
     */
    public function useParentNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ProgramaQuery */
        $q = $this->useExistsQuery('Parent', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the Parent relation to the Programa table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\ProgramaQuery The inner query object of the IN statement
     */
    public function useInParentQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\ProgramaQuery */
        $q = $this->useInQuery('Parent', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the Parent relation to the Programa table for a NOT IN query.
     *
     * @see useParentInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ProgramaQuery The inner query object of the NOT IN statement
     */
    public function useNotInParentQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ProgramaQuery */
        $q = $this->useInQuery('Parent', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Filter the query by a related \Baja\Model\Evento object
     *
     * @param \Baja\Model\Evento|ObjectCollection $evento the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEvento($evento, ?string $comparison = null)
    {
        if ($evento instanceof \Baja\Model\Evento) {
            $this
                ->addUsingAlias(ProgramaTableMap::COL_PROGRAMA_ID, $evento->getProgramaId(), $comparison);

            return $this;
        } elseif ($evento instanceof ObjectCollection) {
            $this
                ->useEventoQuery()
                ->filterByPrimaryKeys($evento->getPrimaryKeys())
                ->endUse();

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
     * Filter the query by a related \Baja\Model\Programa object
     *
     * @param \Baja\Model\Programa|ObjectCollection $programa the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByChild($programa, ?string $comparison = null)
    {
        if ($programa instanceof \Baja\Model\Programa) {
            $this
                ->addUsingAlias(ProgramaTableMap::COL_PROGRAMA_ID, $programa->getParentId(), $comparison);

            return $this;
        } elseif ($programa instanceof ObjectCollection) {
            $this
                ->useChildQuery()
                ->filterByPrimaryKeys($programa->getPrimaryKeys())
                ->endUse();

            return $this;
        } else {
            throw new PropelException('filterByChild() only accepts arguments of type \Baja\Model\Programa or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Child relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinChild(?string $relationAlias = null, ?string $joinType = Criteria::LEFT_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Child');

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
            $this->addJoinObject($join, 'Child');
        }

        return $this;
    }

    /**
     * Use the Child relation Programa object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\ProgramaQuery A secondary query class using the current class as primary query
     */
    public function useChildQuery($relationAlias = null, $joinType = Criteria::LEFT_JOIN)
    {
        return $this
            ->joinChild($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Child', '\Baja\Model\ProgramaQuery');
    }

    /**
     * Use the Child relation Programa object
     *
     * @param callable(\Baja\Model\ProgramaQuery):\Baja\Model\ProgramaQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withChildQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::LEFT_JOIN
    ) {
        $relatedQuery = $this->useChildQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the Child relation to the Programa table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\ProgramaQuery The inner query object of the EXISTS statement
     */
    public function useChildExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\ProgramaQuery */
        $q = $this->useExistsQuery('Child', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the Child relation to the Programa table for a NOT EXISTS query.
     *
     * @see useChildExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ProgramaQuery The inner query object of the NOT EXISTS statement
     */
    public function useChildNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ProgramaQuery */
        $q = $this->useExistsQuery('Child', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the Child relation to the Programa table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\ProgramaQuery The inner query object of the IN statement
     */
    public function useInChildQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\ProgramaQuery */
        $q = $this->useInQuery('Child', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the Child relation to the Programa table for a NOT IN query.
     *
     * @see useChildInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ProgramaQuery The inner query object of the NOT IN statement
     */
    public function useNotInChildQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ProgramaQuery */
        $q = $this->useInQuery('Child', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Exclude object from result
     *
     * @param ChildPrograma $programa Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($programa = null)
    {
        if ($programa) {
            $this->addUsingAlias(ProgramaTableMap::COL_PROGRAMA_ID, $programa->getProgramaId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the programa table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ProgramaTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ProgramaTableMap::clearInstancePool();
            ProgramaTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ProgramaTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ProgramaTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ProgramaTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ProgramaTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
