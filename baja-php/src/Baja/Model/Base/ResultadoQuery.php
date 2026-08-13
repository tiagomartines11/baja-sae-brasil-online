<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Resultado as ChildResultado;
use Baja\Model\ResultadoQuery as ChildResultadoQuery;
use Baja\Model\Map\ResultadoTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `resultado` table.
 *
 * @method     ChildResultadoQuery orderByResultadoId($order = Criteria::ASC) Order by the resultado_id column
 * @method     ChildResultadoQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildResultadoQuery orderByNome($order = Criteria::ASC) Order by the nome column
 * @method     ChildResultadoQuery orderByInputs($order = Criteria::ASC) Order by the inputs column
 * @method     ChildResultadoQuery orderByColunas($order = Criteria::ASC) Order by the colunas column
 * @method     ChildResultadoQuery orderByColunasBackup($order = Criteria::ASC) Order by the colunas_backup column
 *
 * @method     ChildResultadoQuery groupByResultadoId() Group by the resultado_id column
 * @method     ChildResultadoQuery groupByEventoId() Group by the evento_id column
 * @method     ChildResultadoQuery groupByNome() Group by the nome column
 * @method     ChildResultadoQuery groupByInputs() Group by the inputs column
 * @method     ChildResultadoQuery groupByColunas() Group by the colunas column
 * @method     ChildResultadoQuery groupByColunasBackup() Group by the colunas_backup column
 *
 * @method     ChildResultadoQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildResultadoQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildResultadoQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildResultadoQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildResultadoQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildResultadoQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildResultadoQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildResultadoQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildResultadoQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildResultadoQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildResultadoQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildResultadoQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildResultadoQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     \Baja\Model\EventoQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildResultado|null findOne(?ConnectionInterface $con = null) Return the first ChildResultado matching the query
 * @method     ChildResultado findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildResultado matching the query, or a new ChildResultado object populated from the query conditions when no match is found
 *
 * @method     ChildResultado|null findOneByResultadoId(string $resultado_id) Return the first ChildResultado filtered by the resultado_id column
 * @method     ChildResultado|null findOneByEventoId(string $evento_id) Return the first ChildResultado filtered by the evento_id column
 * @method     ChildResultado|null findOneByNome(string $nome) Return the first ChildResultado filtered by the nome column
 * @method     ChildResultado|null findOneByInputs(array $inputs) Return the first ChildResultado filtered by the inputs column
 * @method     ChildResultado|null findOneByColunas(string $colunas) Return the first ChildResultado filtered by the colunas column
 * @method     ChildResultado|null findOneByColunasBackup(string $colunas_backup) Return the first ChildResultado filtered by the colunas_backup column
 *
 * @method     ChildResultado requirePk($key, ?ConnectionInterface $con = null) Return the ChildResultado by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildResultado requireOne(?ConnectionInterface $con = null) Return the first ChildResultado matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildResultado requireOneByResultadoId(string $resultado_id) Return the first ChildResultado filtered by the resultado_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildResultado requireOneByEventoId(string $evento_id) Return the first ChildResultado filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildResultado requireOneByNome(string $nome) Return the first ChildResultado filtered by the nome column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildResultado requireOneByInputs(array $inputs) Return the first ChildResultado filtered by the inputs column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildResultado requireOneByColunas(string $colunas) Return the first ChildResultado filtered by the colunas column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildResultado requireOneByColunasBackup(string $colunas_backup) Return the first ChildResultado filtered by the colunas_backup column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildResultado[]|Collection find(?ConnectionInterface $con = null) Return ChildResultado objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildResultado> find(?ConnectionInterface $con = null) Return ChildResultado objects based on current ModelCriteria
 *
 * @method     ChildResultado[]|Collection findByResultadoId(string|array<string> $resultado_id) Return ChildResultado objects filtered by the resultado_id column
 * @psalm-method Collection&\Traversable<ChildResultado> findByResultadoId(string|array<string> $resultado_id) Return ChildResultado objects filtered by the resultado_id column
 * @method     ChildResultado[]|Collection findByEventoId(string|array<string> $evento_id) Return ChildResultado objects filtered by the evento_id column
 * @psalm-method Collection&\Traversable<ChildResultado> findByEventoId(string|array<string> $evento_id) Return ChildResultado objects filtered by the evento_id column
 * @method     ChildResultado[]|Collection findByNome(string|array<string> $nome) Return ChildResultado objects filtered by the nome column
 * @psalm-method Collection&\Traversable<ChildResultado> findByNome(string|array<string> $nome) Return ChildResultado objects filtered by the nome column
 * @method     ChildResultado[]|Collection findByInputs(array|array<array> $inputs) Return ChildResultado objects filtered by the inputs column
 * @psalm-method Collection&\Traversable<ChildResultado> findByInputs(array|array<array> $inputs) Return ChildResultado objects filtered by the inputs column
 * @method     ChildResultado[]|Collection findByColunas(string|array<string> $colunas) Return ChildResultado objects filtered by the colunas column
 * @psalm-method Collection&\Traversable<ChildResultado> findByColunas(string|array<string> $colunas) Return ChildResultado objects filtered by the colunas column
 * @method     ChildResultado[]|Collection findByColunasBackup(string|array<string> $colunas_backup) Return ChildResultado objects filtered by the colunas_backup column
 * @psalm-method Collection&\Traversable<ChildResultado> findByColunasBackup(string|array<string> $colunas_backup) Return ChildResultado objects filtered by the colunas_backup column
 *
 * @method     ChildResultado[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildResultado> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ResultadoQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ResultadoQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Resultado', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildResultadoQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildResultadoQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildResultadoQuery) {
            return $criteria;
        }
        $query = new ChildResultadoQuery();
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
     * @return ChildResultado|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ResultadoTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ResultadoTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildResultado A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT resultado_id, evento_id, nome, inputs, colunas, colunas_backup FROM resultado WHERE resultado_id = :p0';
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
            /** @var ChildResultado $obj */
            $obj = new ChildResultado();
            $obj->hydrate($row);
            ResultadoTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildResultado|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(ResultadoTableMap::COL_RESULTADO_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(ResultadoTableMap::COL_RESULTADO_ID, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the resultado_id column
     *
     * Example usage:
     * <code>
     * $query->filterByResultadoId('fooValue');   // WHERE resultado_id = 'fooValue'
     * $query->filterByResultadoId('%fooValue%', Criteria::LIKE); // WHERE resultado_id LIKE '%fooValue%'
     * $query->filterByResultadoId(['foo', 'bar']); // WHERE resultado_id IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $resultadoId The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByResultadoId($resultadoId = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($resultadoId)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ResultadoTableMap::COL_RESULTADO_ID, $resultadoId, $comparison);

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

        $this->addUsingAlias(ResultadoTableMap::COL_EVENTO_ID, $eventoId, $comparison);

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

        $this->addUsingAlias(ResultadoTableMap::COL_NOME, $nome, $comparison);

        return $this;
    }

    /**
     * Filter the query on the inputs column
     *
     * @param array $inputs The values to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByInputs($inputs = null, ?string $comparison = null)
    {
        $key = $this->getAliasedColName(ResultadoTableMap::COL_INPUTS);
        if (null === $comparison || $comparison == Criteria::CONTAINS_ALL) {
            foreach ($inputs as $value) {
                $value = '%| ' . $value . ' |%';
                if ($this->containsKey($key)) {
                    $this->addAnd($key, $value, Criteria::LIKE);
                } else {
                    $this->add($key, $value, Criteria::LIKE);
                }
            }

            return $this;
        } elseif ($comparison == Criteria::CONTAINS_SOME) {
            foreach ($inputs as $value) {
                $value = '%| ' . $value . ' |%';
                if ($this->containsKey($key)) {
                    $this->addOr($key, $value, Criteria::LIKE);
                } else {
                    $this->add($key, $value, Criteria::LIKE);
                }
            }

            return $this;
        } elseif ($comparison == Criteria::CONTAINS_NONE) {
            foreach ($inputs as $value) {
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

        $this->addUsingAlias(ResultadoTableMap::COL_INPUTS, $inputs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the inputs column
     * @param mixed $inputs The value to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::CONTAINS_ALL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByInput($inputs = null, ?string $comparison = null)
    {
        if (null === $comparison || $comparison == Criteria::CONTAINS_ALL) {
            if (is_scalar($inputs)) {
                $inputs = '%| ' . $inputs . ' |%';
                $comparison = Criteria::LIKE;
            }
        } elseif ($comparison == Criteria::CONTAINS_NONE) {
            $inputs = '%| ' . $inputs . ' |%';
            $comparison = Criteria::NOT_LIKE;
            $key = $this->getAliasedColName(ResultadoTableMap::COL_INPUTS);
            if ($this->containsKey($key)) {
                $this->addAnd($key, $inputs, $comparison);
            } else {
                $this->addAnd($key, $inputs, $comparison);
            }
            $this->addOr($key, null, Criteria::ISNULL);

            return $this;
        }

        $this->addUsingAlias(ResultadoTableMap::COL_INPUTS, $inputs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the colunas column
     *
     * Example usage:
     * <code>
     * $query->filterByColunas('fooValue');   // WHERE colunas = 'fooValue'
     * $query->filterByColunas('%fooValue%', Criteria::LIKE); // WHERE colunas LIKE '%fooValue%'
     * $query->filterByColunas(['foo', 'bar']); // WHERE colunas IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $colunas The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByColunas($colunas = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($colunas)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ResultadoTableMap::COL_COLUNAS, $colunas, $comparison);

        return $this;
    }

    /**
     * Filter the query on the colunas_backup column
     *
     * Example usage:
     * <code>
     * $query->filterByColunasBackup('fooValue');   // WHERE colunas_backup = 'fooValue'
     * $query->filterByColunasBackup('%fooValue%', Criteria::LIKE); // WHERE colunas_backup LIKE '%fooValue%'
     * $query->filterByColunasBackup(['foo', 'bar']); // WHERE colunas_backup IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $colunasBackup The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByColunasBackup($colunasBackup = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($colunasBackup)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ResultadoTableMap::COL_COLUNAS_BACKUP, $colunasBackup, $comparison);

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
                ->addUsingAlias(ResultadoTableMap::COL_EVENTO_ID, $evento->getEventoId(), $comparison);
        } elseif ($evento instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(ResultadoTableMap::COL_EVENTO_ID, $evento->toKeyValue('PrimaryKey', 'EventoId'), $comparison);

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
     * @param ChildResultado $resultado Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($resultado = null)
    {
        if ($resultado) {
            $this->addUsingAlias(ResultadoTableMap::COL_RESULTADO_ID, $resultado->getResultadoId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the resultado table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ResultadoTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ResultadoTableMap::clearInstancePool();
            ResultadoTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ResultadoTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ResultadoTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ResultadoTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ResultadoTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
