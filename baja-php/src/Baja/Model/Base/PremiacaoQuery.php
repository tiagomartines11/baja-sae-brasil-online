<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Premiacao as ChildPremiacao;
use Baja\Model\PremiacaoQuery as ChildPremiacaoQuery;
use Baja\Model\Map\PremiacaoTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `premiacao` table.
 *
 * @method     ChildPremiacaoQuery orderByPremiacaoId($order = Criteria::ASC) Order by the premiacao_id column
 * @method     ChildPremiacaoQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildPremiacaoQuery orderByNome($order = Criteria::ASC) Order by the nome column
 * @method     ChildPremiacaoQuery orderByStatus($order = Criteria::ASC) Order by the status column
 * @method     ChildPremiacaoQuery orderByModificado($order = Criteria::ASC) Order by the modificado column
 * @method     ChildPremiacaoQuery orderByCategorias($order = Criteria::ASC) Order by the categorias column
 * @method     ChildPremiacaoQuery orderByCategoriasBackup($order = Criteria::ASC) Order by the categorias_backup column
 *
 * @method     ChildPremiacaoQuery groupByPremiacaoId() Group by the premiacao_id column
 * @method     ChildPremiacaoQuery groupByEventoId() Group by the evento_id column
 * @method     ChildPremiacaoQuery groupByNome() Group by the nome column
 * @method     ChildPremiacaoQuery groupByStatus() Group by the status column
 * @method     ChildPremiacaoQuery groupByModificado() Group by the modificado column
 * @method     ChildPremiacaoQuery groupByCategorias() Group by the categorias column
 * @method     ChildPremiacaoQuery groupByCategoriasBackup() Group by the categorias_backup column
 *
 * @method     ChildPremiacaoQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildPremiacaoQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildPremiacaoQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildPremiacaoQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildPremiacaoQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildPremiacaoQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildPremiacaoQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildPremiacaoQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildPremiacaoQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildPremiacaoQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildPremiacaoQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildPremiacaoQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildPremiacaoQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     \Baja\Model\EventoQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildPremiacao|null findOne(?ConnectionInterface $con = null) Return the first ChildPremiacao matching the query
 * @method     ChildPremiacao findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildPremiacao matching the query, or a new ChildPremiacao object populated from the query conditions when no match is found
 *
 * @method     ChildPremiacao|null findOneByPremiacaoId(string $premiacao_id) Return the first ChildPremiacao filtered by the premiacao_id column
 * @method     ChildPremiacao|null findOneByEventoId(string $evento_id) Return the first ChildPremiacao filtered by the evento_id column
 * @method     ChildPremiacao|null findOneByNome(string $nome) Return the first ChildPremiacao filtered by the nome column
 * @method     ChildPremiacao|null findOneByStatus(boolean $status) Return the first ChildPremiacao filtered by the status column
 * @method     ChildPremiacao|null findOneByModificado(string $modificado) Return the first ChildPremiacao filtered by the modificado column
 * @method     ChildPremiacao|null findOneByCategorias(string $categorias) Return the first ChildPremiacao filtered by the categorias column
 * @method     ChildPremiacao|null findOneByCategoriasBackup(string $categorias_backup) Return the first ChildPremiacao filtered by the categorias_backup column
 *
 * @method     ChildPremiacao requirePk($key, ?ConnectionInterface $con = null) Return the ChildPremiacao by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPremiacao requireOne(?ConnectionInterface $con = null) Return the first ChildPremiacao matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildPremiacao requireOneByPremiacaoId(string $premiacao_id) Return the first ChildPremiacao filtered by the premiacao_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPremiacao requireOneByEventoId(string $evento_id) Return the first ChildPremiacao filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPremiacao requireOneByNome(string $nome) Return the first ChildPremiacao filtered by the nome column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPremiacao requireOneByStatus(boolean $status) Return the first ChildPremiacao filtered by the status column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPremiacao requireOneByModificado(string $modificado) Return the first ChildPremiacao filtered by the modificado column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPremiacao requireOneByCategorias(string $categorias) Return the first ChildPremiacao filtered by the categorias column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildPremiacao requireOneByCategoriasBackup(string $categorias_backup) Return the first ChildPremiacao filtered by the categorias_backup column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildPremiacao[]|Collection find(?ConnectionInterface $con = null) Return ChildPremiacao objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildPremiacao> find(?ConnectionInterface $con = null) Return ChildPremiacao objects based on current ModelCriteria
 *
 * @method     ChildPremiacao[]|Collection findByPremiacaoId(string|array<string> $premiacao_id) Return ChildPremiacao objects filtered by the premiacao_id column
 * @psalm-method Collection&\Traversable<ChildPremiacao> findByPremiacaoId(string|array<string> $premiacao_id) Return ChildPremiacao objects filtered by the premiacao_id column
 * @method     ChildPremiacao[]|Collection findByEventoId(string|array<string> $evento_id) Return ChildPremiacao objects filtered by the evento_id column
 * @psalm-method Collection&\Traversable<ChildPremiacao> findByEventoId(string|array<string> $evento_id) Return ChildPremiacao objects filtered by the evento_id column
 * @method     ChildPremiacao[]|Collection findByNome(string|array<string> $nome) Return ChildPremiacao objects filtered by the nome column
 * @psalm-method Collection&\Traversable<ChildPremiacao> findByNome(string|array<string> $nome) Return ChildPremiacao objects filtered by the nome column
 * @method     ChildPremiacao[]|Collection findByStatus(boolean|array<boolean> $status) Return ChildPremiacao objects filtered by the status column
 * @psalm-method Collection&\Traversable<ChildPremiacao> findByStatus(boolean|array<boolean> $status) Return ChildPremiacao objects filtered by the status column
 * @method     ChildPremiacao[]|Collection findByModificado(string|array<string> $modificado) Return ChildPremiacao objects filtered by the modificado column
 * @psalm-method Collection&\Traversable<ChildPremiacao> findByModificado(string|array<string> $modificado) Return ChildPremiacao objects filtered by the modificado column
 * @method     ChildPremiacao[]|Collection findByCategorias(string|array<string> $categorias) Return ChildPremiacao objects filtered by the categorias column
 * @psalm-method Collection&\Traversable<ChildPremiacao> findByCategorias(string|array<string> $categorias) Return ChildPremiacao objects filtered by the categorias column
 * @method     ChildPremiacao[]|Collection findByCategoriasBackup(string|array<string> $categorias_backup) Return ChildPremiacao objects filtered by the categorias_backup column
 * @psalm-method Collection&\Traversable<ChildPremiacao> findByCategoriasBackup(string|array<string> $categorias_backup) Return ChildPremiacao objects filtered by the categorias_backup column
 *
 * @method     ChildPremiacao[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildPremiacao> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class PremiacaoQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\PremiacaoQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Premiacao', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildPremiacaoQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildPremiacaoQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildPremiacaoQuery) {
            return $criteria;
        }
        $query = new ChildPremiacaoQuery();
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
     * @return ChildPremiacao|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(PremiacaoTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = PremiacaoTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildPremiacao A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT premiacao_id, evento_id, nome, status, modificado, categorias, categorias_backup FROM premiacao WHERE premiacao_id = :p0';
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
            /** @var ChildPremiacao $obj */
            $obj = new ChildPremiacao();
            $obj->hydrate($row);
            PremiacaoTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildPremiacao|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(PremiacaoTableMap::COL_PREMIACAO_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(PremiacaoTableMap::COL_PREMIACAO_ID, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the premiacao_id column
     *
     * Example usage:
     * <code>
     * $query->filterByPremiacaoId('fooValue');   // WHERE premiacao_id = 'fooValue'
     * $query->filterByPremiacaoId('%fooValue%', Criteria::LIKE); // WHERE premiacao_id LIKE '%fooValue%'
     * $query->filterByPremiacaoId(['foo', 'bar']); // WHERE premiacao_id IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $premiacaoId The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByPremiacaoId($premiacaoId = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($premiacaoId)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(PremiacaoTableMap::COL_PREMIACAO_ID, $premiacaoId, $comparison);

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

        $this->addUsingAlias(PremiacaoTableMap::COL_EVENTO_ID, $eventoId, $comparison);

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

        $this->addUsingAlias(PremiacaoTableMap::COL_NOME, $nome, $comparison);

        return $this;
    }

    /**
     * Filter the query on the status column
     *
     * Example usage:
     * <code>
     * $query->filterByStatus(true); // WHERE status = true
     * $query->filterByStatus('yes'); // WHERE status = true
     * </code>
     *
     * @param bool|string $status The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByStatus($status = null, ?string $comparison = null)
    {
        if (is_string($status)) {
            $status = in_array(strtolower($status), array('false', 'off', '-', 'no', 'n', '0', ''), true) ? false : true;
        }

        $this->addUsingAlias(PremiacaoTableMap::COL_STATUS, $status, $comparison);

        return $this;
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
     * @param mixed $modificado The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByModificado($modificado = null, ?string $comparison = null)
    {
        if (is_array($modificado)) {
            $useMinMax = false;
            if (isset($modificado['min'])) {
                $this->addUsingAlias(PremiacaoTableMap::COL_MODIFICADO, $modificado['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($modificado['max'])) {
                $this->addUsingAlias(PremiacaoTableMap::COL_MODIFICADO, $modificado['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(PremiacaoTableMap::COL_MODIFICADO, $modificado, $comparison);

        return $this;
    }

    /**
     * Filter the query on the categorias column
     *
     * Example usage:
     * <code>
     * $query->filterByCategorias('fooValue');   // WHERE categorias = 'fooValue'
     * $query->filterByCategorias('%fooValue%', Criteria::LIKE); // WHERE categorias LIKE '%fooValue%'
     * $query->filterByCategorias(['foo', 'bar']); // WHERE categorias IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $categorias The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByCategorias($categorias = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($categorias)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(PremiacaoTableMap::COL_CATEGORIAS, $categorias, $comparison);

        return $this;
    }

    /**
     * Filter the query on the categorias_backup column
     *
     * Example usage:
     * <code>
     * $query->filterByCategoriasBackup('fooValue');   // WHERE categorias_backup = 'fooValue'
     * $query->filterByCategoriasBackup('%fooValue%', Criteria::LIKE); // WHERE categorias_backup LIKE '%fooValue%'
     * $query->filterByCategoriasBackup(['foo', 'bar']); // WHERE categorias_backup IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $categoriasBackup The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByCategoriasBackup($categoriasBackup = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($categoriasBackup)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(PremiacaoTableMap::COL_CATEGORIAS_BACKUP, $categoriasBackup, $comparison);

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
                ->addUsingAlias(PremiacaoTableMap::COL_EVENTO_ID, $evento->getEventoId(), $comparison);
        } elseif ($evento instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(PremiacaoTableMap::COL_EVENTO_ID, $evento->toKeyValue('PrimaryKey', 'EventoId'), $comparison);

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
     * @param ChildPremiacao $premiacao Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($premiacao = null)
    {
        if ($premiacao) {
            $this->addUsingAlias(PremiacaoTableMap::COL_PREMIACAO_ID, $premiacao->getPremiacaoId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the premiacao table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(PremiacaoTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            PremiacaoTableMap::clearInstancePool();
            PremiacaoTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(PremiacaoTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(PremiacaoTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            PremiacaoTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            PremiacaoTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
