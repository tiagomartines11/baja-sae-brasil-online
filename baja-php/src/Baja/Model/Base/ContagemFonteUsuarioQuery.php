<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemFonteUsuario as ChildContagemFonteUsuario;
use Baja\Model\ContagemFonteUsuarioQuery as ChildContagemFonteUsuarioQuery;
use Baja\Model\Map\ContagemFonteUsuarioTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `contagem_fonte_usuario` table.
 *
 * @method     ChildContagemFonteUsuarioQuery orderByFonteId($order = Criteria::ASC) Order by the fonte_id column
 * @method     ChildContagemFonteUsuarioQuery orderByUsuario($order = Criteria::ASC) Order by the usuario column
 * @method     ChildContagemFonteUsuarioQuery orderByAdicionadoPor($order = Criteria::ASC) Order by the adicionado_por column
 * @method     ChildContagemFonteUsuarioQuery orderByCriadoEmMs($order = Criteria::ASC) Order by the criado_em_ms column
 *
 * @method     ChildContagemFonteUsuarioQuery groupByFonteId() Group by the fonte_id column
 * @method     ChildContagemFonteUsuarioQuery groupByUsuario() Group by the usuario column
 * @method     ChildContagemFonteUsuarioQuery groupByAdicionadoPor() Group by the adicionado_por column
 * @method     ChildContagemFonteUsuarioQuery groupByCriadoEmMs() Group by the criado_em_ms column
 *
 * @method     ChildContagemFonteUsuarioQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildContagemFonteUsuarioQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildContagemFonteUsuarioQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildContagemFonteUsuarioQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildContagemFonteUsuarioQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildContagemFonteUsuarioQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildContagemFonteUsuarioQuery leftJoinContagemFonte($relationAlias = null) Adds a LEFT JOIN clause to the query using the ContagemFonte relation
 * @method     ChildContagemFonteUsuarioQuery rightJoinContagemFonte($relationAlias = null) Adds a RIGHT JOIN clause to the query using the ContagemFonte relation
 * @method     ChildContagemFonteUsuarioQuery innerJoinContagemFonte($relationAlias = null) Adds a INNER JOIN clause to the query using the ContagemFonte relation
 *
 * @method     ChildContagemFonteUsuarioQuery joinWithContagemFonte($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the ContagemFonte relation
 *
 * @method     ChildContagemFonteUsuarioQuery leftJoinWithContagemFonte() Adds a LEFT JOIN clause and with to the query using the ContagemFonte relation
 * @method     ChildContagemFonteUsuarioQuery rightJoinWithContagemFonte() Adds a RIGHT JOIN clause and with to the query using the ContagemFonte relation
 * @method     ChildContagemFonteUsuarioQuery innerJoinWithContagemFonte() Adds a INNER JOIN clause and with to the query using the ContagemFonte relation
 *
 * @method     \Baja\Model\ContagemFonteQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildContagemFonteUsuario|null findOne(?ConnectionInterface $con = null) Return the first ChildContagemFonteUsuario matching the query
 * @method     ChildContagemFonteUsuario findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildContagemFonteUsuario matching the query, or a new ChildContagemFonteUsuario object populated from the query conditions when no match is found
 *
 * @method     ChildContagemFonteUsuario|null findOneByFonteId(int $fonte_id) Return the first ChildContagemFonteUsuario filtered by the fonte_id column
 * @method     ChildContagemFonteUsuario|null findOneByUsuario(string $usuario) Return the first ChildContagemFonteUsuario filtered by the usuario column
 * @method     ChildContagemFonteUsuario|null findOneByAdicionadoPor(string $adicionado_por) Return the first ChildContagemFonteUsuario filtered by the adicionado_por column
 * @method     ChildContagemFonteUsuario|null findOneByCriadoEmMs(string $criado_em_ms) Return the first ChildContagemFonteUsuario filtered by the criado_em_ms column
 *
 * @method     ChildContagemFonteUsuario requirePk($key, ?ConnectionInterface $con = null) Return the ChildContagemFonteUsuario by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonteUsuario requireOne(?ConnectionInterface $con = null) Return the first ChildContagemFonteUsuario matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemFonteUsuario requireOneByFonteId(int $fonte_id) Return the first ChildContagemFonteUsuario filtered by the fonte_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonteUsuario requireOneByUsuario(string $usuario) Return the first ChildContagemFonteUsuario filtered by the usuario column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonteUsuario requireOneByAdicionadoPor(string $adicionado_por) Return the first ChildContagemFonteUsuario filtered by the adicionado_por column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonteUsuario requireOneByCriadoEmMs(string $criado_em_ms) Return the first ChildContagemFonteUsuario filtered by the criado_em_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemFonteUsuario[]|Collection find(?ConnectionInterface $con = null) Return ChildContagemFonteUsuario objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildContagemFonteUsuario> find(?ConnectionInterface $con = null) Return ChildContagemFonteUsuario objects based on current ModelCriteria
 *
 * @method     ChildContagemFonteUsuario[]|Collection findByFonteId(int|array<int> $fonte_id) Return ChildContagemFonteUsuario objects filtered by the fonte_id column
 * @psalm-method Collection&\Traversable<ChildContagemFonteUsuario> findByFonteId(int|array<int> $fonte_id) Return ChildContagemFonteUsuario objects filtered by the fonte_id column
 * @method     ChildContagemFonteUsuario[]|Collection findByUsuario(string|array<string> $usuario) Return ChildContagemFonteUsuario objects filtered by the usuario column
 * @psalm-method Collection&\Traversable<ChildContagemFonteUsuario> findByUsuario(string|array<string> $usuario) Return ChildContagemFonteUsuario objects filtered by the usuario column
 * @method     ChildContagemFonteUsuario[]|Collection findByAdicionadoPor(string|array<string> $adicionado_por) Return ChildContagemFonteUsuario objects filtered by the adicionado_por column
 * @psalm-method Collection&\Traversable<ChildContagemFonteUsuario> findByAdicionadoPor(string|array<string> $adicionado_por) Return ChildContagemFonteUsuario objects filtered by the adicionado_por column
 * @method     ChildContagemFonteUsuario[]|Collection findByCriadoEmMs(string|array<string> $criado_em_ms) Return ChildContagemFonteUsuario objects filtered by the criado_em_ms column
 * @psalm-method Collection&\Traversable<ChildContagemFonteUsuario> findByCriadoEmMs(string|array<string> $criado_em_ms) Return ChildContagemFonteUsuario objects filtered by the criado_em_ms column
 *
 * @method     ChildContagemFonteUsuario[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildContagemFonteUsuario> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ContagemFonteUsuarioQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ContagemFonteUsuarioQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\ContagemFonteUsuario', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildContagemFonteUsuarioQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildContagemFonteUsuarioQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildContagemFonteUsuarioQuery) {
            return $criteria;
        }
        $query = new ChildContagemFonteUsuarioQuery();
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
     * @param array[$fonte_id, $usuario] $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildContagemFonteUsuario|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ContagemFonteUsuarioTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ContagemFonteUsuarioTableMap::getInstanceFromPool(serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]))))) {
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
     * @return ChildContagemFonteUsuario A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT fonte_id, usuario, adicionado_por, criado_em_ms FROM contagem_fonte_usuario WHERE fonte_id = :p0 AND usuario = :p1';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key[0], PDO::PARAM_INT);
            $stmt->bindValue(':p1', $key[1], PDO::PARAM_STR);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            /** @var ChildContagemFonteUsuario $obj */
            $obj = new ChildContagemFonteUsuario();
            $obj->hydrate($row);
            ContagemFonteUsuarioTableMap::addInstanceToPool($obj, serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]));
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
     * @return ChildContagemFonteUsuario|array|mixed the result, formatted by the current formatter
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
        $this->addUsingAlias(ContagemFonteUsuarioTableMap::COL_FONTE_ID, $key[0], Criteria::EQUAL);
        $this->addUsingAlias(ContagemFonteUsuarioTableMap::COL_USUARIO, $key[1], Criteria::EQUAL);

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
            $cton0 = $this->getNewCriterion(ContagemFonteUsuarioTableMap::COL_FONTE_ID, $key[0], Criteria::EQUAL);
            $cton1 = $this->getNewCriterion(ContagemFonteUsuarioTableMap::COL_USUARIO, $key[1], Criteria::EQUAL);
            $cton0->addAnd($cton1);
            $this->addOr($cton0);
        }

        return $this;
    }

    /**
     * Filter the query on the fonte_id column
     *
     * Example usage:
     * <code>
     * $query->filterByFonteId(1234); // WHERE fonte_id = 1234
     * $query->filterByFonteId(array(12, 34)); // WHERE fonte_id IN (12, 34)
     * $query->filterByFonteId(array('min' => 12)); // WHERE fonte_id > 12
     * </code>
     *
     * @see       filterByContagemFonte()
     *
     * @param mixed $fonteId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByFonteId($fonteId = null, ?string $comparison = null)
    {
        if (is_array($fonteId)) {
            $useMinMax = false;
            if (isset($fonteId['min'])) {
                $this->addUsingAlias(ContagemFonteUsuarioTableMap::COL_FONTE_ID, $fonteId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($fonteId['max'])) {
                $this->addUsingAlias(ContagemFonteUsuarioTableMap::COL_FONTE_ID, $fonteId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemFonteUsuarioTableMap::COL_FONTE_ID, $fonteId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the usuario column
     *
     * Example usage:
     * <code>
     * $query->filterByUsuario('fooValue');   // WHERE usuario = 'fooValue'
     * $query->filterByUsuario('%fooValue%', Criteria::LIKE); // WHERE usuario LIKE '%fooValue%'
     * $query->filterByUsuario(['foo', 'bar']); // WHERE usuario IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $usuario The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByUsuario($usuario = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($usuario)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemFonteUsuarioTableMap::COL_USUARIO, $usuario, $comparison);

        return $this;
    }

    /**
     * Filter the query on the adicionado_por column
     *
     * Example usage:
     * <code>
     * $query->filterByAdicionadoPor('fooValue');   // WHERE adicionado_por = 'fooValue'
     * $query->filterByAdicionadoPor('%fooValue%', Criteria::LIKE); // WHERE adicionado_por LIKE '%fooValue%'
     * $query->filterByAdicionadoPor(['foo', 'bar']); // WHERE adicionado_por IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $adicionadoPor The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByAdicionadoPor($adicionadoPor = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($adicionadoPor)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemFonteUsuarioTableMap::COL_ADICIONADO_POR, $adicionadoPor, $comparison);

        return $this;
    }

    /**
     * Filter the query on the criado_em_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByCriadoEmMs(1234); // WHERE criado_em_ms = 1234
     * $query->filterByCriadoEmMs(array(12, 34)); // WHERE criado_em_ms IN (12, 34)
     * $query->filterByCriadoEmMs(array('min' => 12)); // WHERE criado_em_ms > 12
     * </code>
     *
     * @param mixed $criadoEmMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByCriadoEmMs($criadoEmMs = null, ?string $comparison = null)
    {
        if (is_array($criadoEmMs)) {
            $useMinMax = false;
            if (isset($criadoEmMs['min'])) {
                $this->addUsingAlias(ContagemFonteUsuarioTableMap::COL_CRIADO_EM_MS, $criadoEmMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($criadoEmMs['max'])) {
                $this->addUsingAlias(ContagemFonteUsuarioTableMap::COL_CRIADO_EM_MS, $criadoEmMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemFonteUsuarioTableMap::COL_CRIADO_EM_MS, $criadoEmMs, $comparison);

        return $this;
    }

    /**
     * Filter the query by a related \Baja\Model\ContagemFonte object
     *
     * @param \Baja\Model\ContagemFonte|ObjectCollection $contagemFonte The related object(s) to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByContagemFonte($contagemFonte, ?string $comparison = null)
    {
        if ($contagemFonte instanceof \Baja\Model\ContagemFonte) {
            return $this
                ->addUsingAlias(ContagemFonteUsuarioTableMap::COL_FONTE_ID, $contagemFonte->getFonteId(), $comparison);
        } elseif ($contagemFonte instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(ContagemFonteUsuarioTableMap::COL_FONTE_ID, $contagemFonte->toKeyValue('PrimaryKey', 'FonteId'), $comparison);

            return $this;
        } else {
            throw new PropelException('filterByContagemFonte() only accepts arguments of type \Baja\Model\ContagemFonte or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the ContagemFonte relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinContagemFonte(?string $relationAlias = null, ?string $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('ContagemFonte');

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
            $this->addJoinObject($join, 'ContagemFonte');
        }

        return $this;
    }

    /**
     * Use the ContagemFonte relation ContagemFonte object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\ContagemFonteQuery A secondary query class using the current class as primary query
     */
    public function useContagemFonteQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinContagemFonte($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'ContagemFonte', '\Baja\Model\ContagemFonteQuery');
    }

    /**
     * Use the ContagemFonte relation ContagemFonte object
     *
     * @param callable(\Baja\Model\ContagemFonteQuery):\Baja\Model\ContagemFonteQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withContagemFonteQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::INNER_JOIN
    ) {
        $relatedQuery = $this->useContagemFonteQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to ContagemFonte table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\ContagemFonteQuery The inner query object of the EXISTS statement
     */
    public function useContagemFonteExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\ContagemFonteQuery */
        $q = $this->useExistsQuery('ContagemFonte', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to ContagemFonte table for a NOT EXISTS query.
     *
     * @see useContagemFonteExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ContagemFonteQuery The inner query object of the NOT EXISTS statement
     */
    public function useContagemFonteNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ContagemFonteQuery */
        $q = $this->useExistsQuery('ContagemFonte', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to ContagemFonte table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\ContagemFonteQuery The inner query object of the IN statement
     */
    public function useInContagemFonteQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\ContagemFonteQuery */
        $q = $this->useInQuery('ContagemFonte', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to ContagemFonte table for a NOT IN query.
     *
     * @see useContagemFonteInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ContagemFonteQuery The inner query object of the NOT IN statement
     */
    public function useNotInContagemFonteQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ContagemFonteQuery */
        $q = $this->useInQuery('ContagemFonte', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Exclude object from result
     *
     * @param ChildContagemFonteUsuario $contagemFonteUsuario Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($contagemFonteUsuario = null)
    {
        if ($contagemFonteUsuario) {
            $this->addCond('pruneCond0', $this->getAliasedColName(ContagemFonteUsuarioTableMap::COL_FONTE_ID), $contagemFonteUsuario->getFonteId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond1', $this->getAliasedColName(ContagemFonteUsuarioTableMap::COL_USUARIO), $contagemFonteUsuario->getUsuario(), Criteria::NOT_EQUAL);
            $this->combine(array('pruneCond0', 'pruneCond1'), Criteria::LOGICAL_OR);
        }

        return $this;
    }

    /**
     * Deletes all rows from the contagem_fonte_usuario table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemFonteUsuarioTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ContagemFonteUsuarioTableMap::clearInstancePool();
            ContagemFonteUsuarioTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemFonteUsuarioTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ContagemFonteUsuarioTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ContagemFonteUsuarioTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ContagemFonteUsuarioTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
