<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemVolta as ChildContagemVolta;
use Baja\Model\ContagemVoltaQuery as ChildContagemVoltaQuery;
use Baja\Model\Map\ContagemVoltaTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `contagem_volta` table.
 *
 * @method     ChildContagemVoltaQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildContagemVoltaQuery orderByProvaId($order = Criteria::ASC) Order by the prova_id column
 * @method     ChildContagemVoltaQuery orderByEquipeNumero($order = Criteria::ASC) Order by the equipe_numero column
 * @method     ChildContagemVoltaQuery orderByNumeroVolta($order = Criteria::ASC) Order by the numero_volta column
 * @method     ChildContagemVoltaQuery orderByTsMs($order = Criteria::ASC) Order by the ts_ms column
 * @method     ChildContagemVoltaQuery orderByDispersaoMs($order = Criteria::ASC) Order by the dispersao_ms column
 * @method     ChildContagemVoltaQuery orderByFontes($order = Criteria::ASC) Order by the fontes column
 * @method     ChildContagemVoltaQuery orderByTempoVoltaMs($order = Criteria::ASC) Order by the tempo_volta_ms column
 * @method     ChildContagemVoltaQuery orderByElegivelMelhorVolta($order = Criteria::ASC) Order by the elegivel_melhor_volta column
 * @method     ChildContagemVoltaQuery orderByEstado($order = Criteria::ASC) Order by the estado column
 *
 * @method     ChildContagemVoltaQuery groupByEventoId() Group by the evento_id column
 * @method     ChildContagemVoltaQuery groupByProvaId() Group by the prova_id column
 * @method     ChildContagemVoltaQuery groupByEquipeNumero() Group by the equipe_numero column
 * @method     ChildContagemVoltaQuery groupByNumeroVolta() Group by the numero_volta column
 * @method     ChildContagemVoltaQuery groupByTsMs() Group by the ts_ms column
 * @method     ChildContagemVoltaQuery groupByDispersaoMs() Group by the dispersao_ms column
 * @method     ChildContagemVoltaQuery groupByFontes() Group by the fontes column
 * @method     ChildContagemVoltaQuery groupByTempoVoltaMs() Group by the tempo_volta_ms column
 * @method     ChildContagemVoltaQuery groupByElegivelMelhorVolta() Group by the elegivel_melhor_volta column
 * @method     ChildContagemVoltaQuery groupByEstado() Group by the estado column
 *
 * @method     ChildContagemVoltaQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildContagemVoltaQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildContagemVoltaQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildContagemVoltaQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildContagemVoltaQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildContagemVoltaQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildContagemVoltaQuery leftJoinProva($relationAlias = null) Adds a LEFT JOIN clause to the query using the Prova relation
 * @method     ChildContagemVoltaQuery rightJoinProva($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Prova relation
 * @method     ChildContagemVoltaQuery innerJoinProva($relationAlias = null) Adds a INNER JOIN clause to the query using the Prova relation
 *
 * @method     ChildContagemVoltaQuery joinWithProva($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Prova relation
 *
 * @method     ChildContagemVoltaQuery leftJoinWithProva() Adds a LEFT JOIN clause and with to the query using the Prova relation
 * @method     ChildContagemVoltaQuery rightJoinWithProva() Adds a RIGHT JOIN clause and with to the query using the Prova relation
 * @method     ChildContagemVoltaQuery innerJoinWithProva() Adds a INNER JOIN clause and with to the query using the Prova relation
 *
 * @method     \Baja\Model\ProvaQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildContagemVolta|null findOne(?ConnectionInterface $con = null) Return the first ChildContagemVolta matching the query
 * @method     ChildContagemVolta findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildContagemVolta matching the query, or a new ChildContagemVolta object populated from the query conditions when no match is found
 *
 * @method     ChildContagemVolta|null findOneByEventoId(string $evento_id) Return the first ChildContagemVolta filtered by the evento_id column
 * @method     ChildContagemVolta|null findOneByProvaId(string $prova_id) Return the first ChildContagemVolta filtered by the prova_id column
 * @method     ChildContagemVolta|null findOneByEquipeNumero(int $equipe_numero) Return the first ChildContagemVolta filtered by the equipe_numero column
 * @method     ChildContagemVolta|null findOneByNumeroVolta(int $numero_volta) Return the first ChildContagemVolta filtered by the numero_volta column
 * @method     ChildContagemVolta|null findOneByTsMs(string $ts_ms) Return the first ChildContagemVolta filtered by the ts_ms column
 * @method     ChildContagemVolta|null findOneByDispersaoMs(int $dispersao_ms) Return the first ChildContagemVolta filtered by the dispersao_ms column
 * @method     ChildContagemVolta|null findOneByFontes(int $fontes) Return the first ChildContagemVolta filtered by the fontes column
 * @method     ChildContagemVolta|null findOneByTempoVoltaMs(string $tempo_volta_ms) Return the first ChildContagemVolta filtered by the tempo_volta_ms column
 * @method     ChildContagemVolta|null findOneByElegivelMelhorVolta(boolean $elegivel_melhor_volta) Return the first ChildContagemVolta filtered by the elegivel_melhor_volta column
 * @method     ChildContagemVolta|null findOneByEstado(string $estado) Return the first ChildContagemVolta filtered by the estado column
 *
 * @method     ChildContagemVolta requirePk($key, ?ConnectionInterface $con = null) Return the ChildContagemVolta by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemVolta requireOne(?ConnectionInterface $con = null) Return the first ChildContagemVolta matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemVolta requireOneByEventoId(string $evento_id) Return the first ChildContagemVolta filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemVolta requireOneByProvaId(string $prova_id) Return the first ChildContagemVolta filtered by the prova_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemVolta requireOneByEquipeNumero(int $equipe_numero) Return the first ChildContagemVolta filtered by the equipe_numero column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemVolta requireOneByNumeroVolta(int $numero_volta) Return the first ChildContagemVolta filtered by the numero_volta column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemVolta requireOneByTsMs(string $ts_ms) Return the first ChildContagemVolta filtered by the ts_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemVolta requireOneByDispersaoMs(int $dispersao_ms) Return the first ChildContagemVolta filtered by the dispersao_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemVolta requireOneByFontes(int $fontes) Return the first ChildContagemVolta filtered by the fontes column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemVolta requireOneByTempoVoltaMs(string $tempo_volta_ms) Return the first ChildContagemVolta filtered by the tempo_volta_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemVolta requireOneByElegivelMelhorVolta(boolean $elegivel_melhor_volta) Return the first ChildContagemVolta filtered by the elegivel_melhor_volta column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemVolta requireOneByEstado(string $estado) Return the first ChildContagemVolta filtered by the estado column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemVolta[]|Collection find(?ConnectionInterface $con = null) Return ChildContagemVolta objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildContagemVolta> find(?ConnectionInterface $con = null) Return ChildContagemVolta objects based on current ModelCriteria
 *
 * @method     ChildContagemVolta[]|Collection findByEventoId(string|array<string> $evento_id) Return ChildContagemVolta objects filtered by the evento_id column
 * @psalm-method Collection&\Traversable<ChildContagemVolta> findByEventoId(string|array<string> $evento_id) Return ChildContagemVolta objects filtered by the evento_id column
 * @method     ChildContagemVolta[]|Collection findByProvaId(string|array<string> $prova_id) Return ChildContagemVolta objects filtered by the prova_id column
 * @psalm-method Collection&\Traversable<ChildContagemVolta> findByProvaId(string|array<string> $prova_id) Return ChildContagemVolta objects filtered by the prova_id column
 * @method     ChildContagemVolta[]|Collection findByEquipeNumero(int|array<int> $equipe_numero) Return ChildContagemVolta objects filtered by the equipe_numero column
 * @psalm-method Collection&\Traversable<ChildContagemVolta> findByEquipeNumero(int|array<int> $equipe_numero) Return ChildContagemVolta objects filtered by the equipe_numero column
 * @method     ChildContagemVolta[]|Collection findByNumeroVolta(int|array<int> $numero_volta) Return ChildContagemVolta objects filtered by the numero_volta column
 * @psalm-method Collection&\Traversable<ChildContagemVolta> findByNumeroVolta(int|array<int> $numero_volta) Return ChildContagemVolta objects filtered by the numero_volta column
 * @method     ChildContagemVolta[]|Collection findByTsMs(string|array<string> $ts_ms) Return ChildContagemVolta objects filtered by the ts_ms column
 * @psalm-method Collection&\Traversable<ChildContagemVolta> findByTsMs(string|array<string> $ts_ms) Return ChildContagemVolta objects filtered by the ts_ms column
 * @method     ChildContagemVolta[]|Collection findByDispersaoMs(int|array<int> $dispersao_ms) Return ChildContagemVolta objects filtered by the dispersao_ms column
 * @psalm-method Collection&\Traversable<ChildContagemVolta> findByDispersaoMs(int|array<int> $dispersao_ms) Return ChildContagemVolta objects filtered by the dispersao_ms column
 * @method     ChildContagemVolta[]|Collection findByFontes(int|array<int> $fontes) Return ChildContagemVolta objects filtered by the fontes column
 * @psalm-method Collection&\Traversable<ChildContagemVolta> findByFontes(int|array<int> $fontes) Return ChildContagemVolta objects filtered by the fontes column
 * @method     ChildContagemVolta[]|Collection findByTempoVoltaMs(string|array<string> $tempo_volta_ms) Return ChildContagemVolta objects filtered by the tempo_volta_ms column
 * @psalm-method Collection&\Traversable<ChildContagemVolta> findByTempoVoltaMs(string|array<string> $tempo_volta_ms) Return ChildContagemVolta objects filtered by the tempo_volta_ms column
 * @method     ChildContagemVolta[]|Collection findByElegivelMelhorVolta(boolean|array<boolean> $elegivel_melhor_volta) Return ChildContagemVolta objects filtered by the elegivel_melhor_volta column
 * @psalm-method Collection&\Traversable<ChildContagemVolta> findByElegivelMelhorVolta(boolean|array<boolean> $elegivel_melhor_volta) Return ChildContagemVolta objects filtered by the elegivel_melhor_volta column
 * @method     ChildContagemVolta[]|Collection findByEstado(string|array<string> $estado) Return ChildContagemVolta objects filtered by the estado column
 * @psalm-method Collection&\Traversable<ChildContagemVolta> findByEstado(string|array<string> $estado) Return ChildContagemVolta objects filtered by the estado column
 *
 * @method     ChildContagemVolta[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildContagemVolta> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ContagemVoltaQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ContagemVoltaQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\ContagemVolta', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildContagemVoltaQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildContagemVoltaQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildContagemVoltaQuery) {
            return $criteria;
        }
        $query = new ChildContagemVoltaQuery();
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
     * $obj = $c->findPk(array(12, 34, 56, 78), $con);
     * </code>
     *
     * @param array[$evento_id, $prova_id, $equipe_numero, $numero_volta] $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildContagemVolta|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ContagemVoltaTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ContagemVoltaTableMap::getInstanceFromPool(serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1]), (null === $key[2] || is_scalar($key[2]) || is_callable([$key[2], '__toString']) ? (string) $key[2] : $key[2]), (null === $key[3] || is_scalar($key[3]) || is_callable([$key[3], '__toString']) ? (string) $key[3] : $key[3])]))))) {
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
     * @return ChildContagemVolta A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT evento_id, prova_id, equipe_numero, numero_volta, ts_ms, dispersao_ms, fontes, tempo_volta_ms, elegivel_melhor_volta, estado FROM contagem_volta WHERE evento_id = :p0 AND prova_id = :p1 AND equipe_numero = :p2 AND numero_volta = :p3';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key[0], PDO::PARAM_STR);
            $stmt->bindValue(':p1', $key[1], PDO::PARAM_STR);
            $stmt->bindValue(':p2', $key[2], PDO::PARAM_INT);
            $stmt->bindValue(':p3', $key[3], PDO::PARAM_INT);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            /** @var ChildContagemVolta $obj */
            $obj = new ChildContagemVolta();
            $obj->hydrate($row);
            ContagemVoltaTableMap::addInstanceToPool($obj, serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1]), (null === $key[2] || is_scalar($key[2]) || is_callable([$key[2], '__toString']) ? (string) $key[2] : $key[2]), (null === $key[3] || is_scalar($key[3]) || is_callable([$key[3], '__toString']) ? (string) $key[3] : $key[3])]));
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
     * @return ChildContagemVolta|array|mixed the result, formatted by the current formatter
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
        $this->addUsingAlias(ContagemVoltaTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
        $this->addUsingAlias(ContagemVoltaTableMap::COL_PROVA_ID, $key[1], Criteria::EQUAL);
        $this->addUsingAlias(ContagemVoltaTableMap::COL_EQUIPE_NUMERO, $key[2], Criteria::EQUAL);
        $this->addUsingAlias(ContagemVoltaTableMap::COL_NUMERO_VOLTA, $key[3], Criteria::EQUAL);

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
            $cton0 = $this->getNewCriterion(ContagemVoltaTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
            $cton1 = $this->getNewCriterion(ContagemVoltaTableMap::COL_PROVA_ID, $key[1], Criteria::EQUAL);
            $cton0->addAnd($cton1);
            $cton2 = $this->getNewCriterion(ContagemVoltaTableMap::COL_EQUIPE_NUMERO, $key[2], Criteria::EQUAL);
            $cton0->addAnd($cton2);
            $cton3 = $this->getNewCriterion(ContagemVoltaTableMap::COL_NUMERO_VOLTA, $key[3], Criteria::EQUAL);
            $cton0->addAnd($cton3);
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

        $this->addUsingAlias(ContagemVoltaTableMap::COL_EVENTO_ID, $eventoId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the prova_id column
     *
     * Example usage:
     * <code>
     * $query->filterByProvaId('fooValue');   // WHERE prova_id = 'fooValue'
     * $query->filterByProvaId('%fooValue%', Criteria::LIKE); // WHERE prova_id LIKE '%fooValue%'
     * $query->filterByProvaId(['foo', 'bar']); // WHERE prova_id IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $provaId The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByProvaId($provaId = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($provaId)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemVoltaTableMap::COL_PROVA_ID, $provaId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the equipe_numero column
     *
     * Example usage:
     * <code>
     * $query->filterByEquipeNumero(1234); // WHERE equipe_numero = 1234
     * $query->filterByEquipeNumero(array(12, 34)); // WHERE equipe_numero IN (12, 34)
     * $query->filterByEquipeNumero(array('min' => 12)); // WHERE equipe_numero > 12
     * </code>
     *
     * @param mixed $equipeNumero The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEquipeNumero($equipeNumero = null, ?string $comparison = null)
    {
        if (is_array($equipeNumero)) {
            $useMinMax = false;
            if (isset($equipeNumero['min'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_EQUIPE_NUMERO, $equipeNumero['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($equipeNumero['max'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_EQUIPE_NUMERO, $equipeNumero['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemVoltaTableMap::COL_EQUIPE_NUMERO, $equipeNumero, $comparison);

        return $this;
    }

    /**
     * Filter the query on the numero_volta column
     *
     * Example usage:
     * <code>
     * $query->filterByNumeroVolta(1234); // WHERE numero_volta = 1234
     * $query->filterByNumeroVolta(array(12, 34)); // WHERE numero_volta IN (12, 34)
     * $query->filterByNumeroVolta(array('min' => 12)); // WHERE numero_volta > 12
     * </code>
     *
     * @param mixed $numeroVolta The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByNumeroVolta($numeroVolta = null, ?string $comparison = null)
    {
        if (is_array($numeroVolta)) {
            $useMinMax = false;
            if (isset($numeroVolta['min'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_NUMERO_VOLTA, $numeroVolta['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($numeroVolta['max'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_NUMERO_VOLTA, $numeroVolta['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemVoltaTableMap::COL_NUMERO_VOLTA, $numeroVolta, $comparison);

        return $this;
    }

    /**
     * Filter the query on the ts_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByTsMs(1234); // WHERE ts_ms = 1234
     * $query->filterByTsMs(array(12, 34)); // WHERE ts_ms IN (12, 34)
     * $query->filterByTsMs(array('min' => 12)); // WHERE ts_ms > 12
     * </code>
     *
     * @param mixed $tsMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByTsMs($tsMs = null, ?string $comparison = null)
    {
        if (is_array($tsMs)) {
            $useMinMax = false;
            if (isset($tsMs['min'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_TS_MS, $tsMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tsMs['max'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_TS_MS, $tsMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemVoltaTableMap::COL_TS_MS, $tsMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the dispersao_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByDispersaoMs(1234); // WHERE dispersao_ms = 1234
     * $query->filterByDispersaoMs(array(12, 34)); // WHERE dispersao_ms IN (12, 34)
     * $query->filterByDispersaoMs(array('min' => 12)); // WHERE dispersao_ms > 12
     * </code>
     *
     * @param mixed $dispersaoMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDispersaoMs($dispersaoMs = null, ?string $comparison = null)
    {
        if (is_array($dispersaoMs)) {
            $useMinMax = false;
            if (isset($dispersaoMs['min'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_DISPERSAO_MS, $dispersaoMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($dispersaoMs['max'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_DISPERSAO_MS, $dispersaoMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemVoltaTableMap::COL_DISPERSAO_MS, $dispersaoMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the fontes column
     *
     * Example usage:
     * <code>
     * $query->filterByFontes(1234); // WHERE fontes = 1234
     * $query->filterByFontes(array(12, 34)); // WHERE fontes IN (12, 34)
     * $query->filterByFontes(array('min' => 12)); // WHERE fontes > 12
     * </code>
     *
     * @param mixed $fontes The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByFontes($fontes = null, ?string $comparison = null)
    {
        if (is_array($fontes)) {
            $useMinMax = false;
            if (isset($fontes['min'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_FONTES, $fontes['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($fontes['max'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_FONTES, $fontes['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemVoltaTableMap::COL_FONTES, $fontes, $comparison);

        return $this;
    }

    /**
     * Filter the query on the tempo_volta_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByTempoVoltaMs(1234); // WHERE tempo_volta_ms = 1234
     * $query->filterByTempoVoltaMs(array(12, 34)); // WHERE tempo_volta_ms IN (12, 34)
     * $query->filterByTempoVoltaMs(array('min' => 12)); // WHERE tempo_volta_ms > 12
     * </code>
     *
     * @param mixed $tempoVoltaMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByTempoVoltaMs($tempoVoltaMs = null, ?string $comparison = null)
    {
        if (is_array($tempoVoltaMs)) {
            $useMinMax = false;
            if (isset($tempoVoltaMs['min'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_TEMPO_VOLTA_MS, $tempoVoltaMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tempoVoltaMs['max'])) {
                $this->addUsingAlias(ContagemVoltaTableMap::COL_TEMPO_VOLTA_MS, $tempoVoltaMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemVoltaTableMap::COL_TEMPO_VOLTA_MS, $tempoVoltaMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the elegivel_melhor_volta column
     *
     * Example usage:
     * <code>
     * $query->filterByElegivelMelhorVolta(true); // WHERE elegivel_melhor_volta = true
     * $query->filterByElegivelMelhorVolta('yes'); // WHERE elegivel_melhor_volta = true
     * </code>
     *
     * @param bool|string $elegivelMelhorVolta The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByElegivelMelhorVolta($elegivelMelhorVolta = null, ?string $comparison = null)
    {
        if (is_string($elegivelMelhorVolta)) {
            $elegivelMelhorVolta = in_array(strtolower($elegivelMelhorVolta), array('false', 'off', '-', 'no', 'n', '0', ''), true) ? false : true;
        }

        $this->addUsingAlias(ContagemVoltaTableMap::COL_ELEGIVEL_MELHOR_VOLTA, $elegivelMelhorVolta, $comparison);

        return $this;
    }

    /**
     * Filter the query on the estado column
     *
     * Example usage:
     * <code>
     * $query->filterByEstado('fooValue');   // WHERE estado = 'fooValue'
     * $query->filterByEstado('%fooValue%', Criteria::LIKE); // WHERE estado LIKE '%fooValue%'
     * $query->filterByEstado(['foo', 'bar']); // WHERE estado IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $estado The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByEstado($estado = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($estado)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemVoltaTableMap::COL_ESTADO, $estado, $comparison);

        return $this;
    }

    /**
     * Filter the query by a related \Baja\Model\Prova object
     *
     * @param \Baja\Model\Prova $prova The related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByProva($prova, ?string $comparison = null)
    {
        if ($prova instanceof \Baja\Model\Prova) {
            return $this
                ->addUsingAlias(ContagemVoltaTableMap::COL_EVENTO_ID, $prova->getEventoId(), $comparison)
                ->addUsingAlias(ContagemVoltaTableMap::COL_PROVA_ID, $prova->getProvaId(), $comparison);
        } else {
            throw new PropelException('filterByProva() only accepts arguments of type \Baja\Model\Prova');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Prova relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinProva(?string $relationAlias = null, ?string $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Prova');

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
            $this->addJoinObject($join, 'Prova');
        }

        return $this;
    }

    /**
     * Use the Prova relation Prova object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\ProvaQuery A secondary query class using the current class as primary query
     */
    public function useProvaQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinProva($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Prova', '\Baja\Model\ProvaQuery');
    }

    /**
     * Use the Prova relation Prova object
     *
     * @param callable(\Baja\Model\ProvaQuery):\Baja\Model\ProvaQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withProvaQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::INNER_JOIN
    ) {
        $relatedQuery = $this->useProvaQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to Prova table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\ProvaQuery The inner query object of the EXISTS statement
     */
    public function useProvaExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\ProvaQuery */
        $q = $this->useExistsQuery('Prova', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to Prova table for a NOT EXISTS query.
     *
     * @see useProvaExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ProvaQuery The inner query object of the NOT EXISTS statement
     */
    public function useProvaNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ProvaQuery */
        $q = $this->useExistsQuery('Prova', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to Prova table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\ProvaQuery The inner query object of the IN statement
     */
    public function useInProvaQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\ProvaQuery */
        $q = $this->useInQuery('Prova', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to Prova table for a NOT IN query.
     *
     * @see useProvaInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ProvaQuery The inner query object of the NOT IN statement
     */
    public function useNotInProvaQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ProvaQuery */
        $q = $this->useInQuery('Prova', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Exclude object from result
     *
     * @param ChildContagemVolta $contagemVolta Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($contagemVolta = null)
    {
        if ($contagemVolta) {
            $this->addCond('pruneCond0', $this->getAliasedColName(ContagemVoltaTableMap::COL_EVENTO_ID), $contagemVolta->getEventoId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond1', $this->getAliasedColName(ContagemVoltaTableMap::COL_PROVA_ID), $contagemVolta->getProvaId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond2', $this->getAliasedColName(ContagemVoltaTableMap::COL_EQUIPE_NUMERO), $contagemVolta->getEquipeNumero(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond3', $this->getAliasedColName(ContagemVoltaTableMap::COL_NUMERO_VOLTA), $contagemVolta->getNumeroVolta(), Criteria::NOT_EQUAL);
            $this->combine(array('pruneCond0', 'pruneCond1', 'pruneCond2', 'pruneCond3'), Criteria::LOGICAL_OR);
        }

        return $this;
    }

    /**
     * Deletes all rows from the contagem_volta table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemVoltaTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ContagemVoltaTableMap::clearInstancePool();
            ContagemVoltaTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemVoltaTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ContagemVoltaTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ContagemVoltaTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ContagemVoltaTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
