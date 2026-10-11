<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemDispositivo as ChildContagemDispositivo;
use Baja\Model\ContagemDispositivoQuery as ChildContagemDispositivoQuery;
use Baja\Model\Map\ContagemDispositivoTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `contagem_dispositivo` table.
 *
 * @method     ChildContagemDispositivoQuery orderByDispositivoId($order = Criteria::ASC) Order by the dispositivo_id column
 * @method     ChildContagemDispositivoQuery orderByFonteId($order = Criteria::ASC) Order by the fonte_id column
 * @method     ChildContagemDispositivoQuery orderByTokenHash($order = Criteria::ASC) Order by the token_hash column
 * @method     ChildContagemDispositivoQuery orderByUsuario($order = Criteria::ASC) Order by the usuario column
 * @method     ChildContagemDispositivoQuery orderByRotulo($order = Criteria::ASC) Order by the rotulo column
 * @method     ChildContagemDispositivoQuery orderByCriadoEmMs($order = Criteria::ASC) Order by the criado_em_ms column
 * @method     ChildContagemDispositivoQuery orderByRevogadoEmMs($order = Criteria::ASC) Order by the revogado_em_ms column
 * @method     ChildContagemDispositivoQuery orderByVistoEmMs($order = Criteria::ASC) Order by the visto_em_ms column
 * @method     ChildContagemDispositivoQuery orderBySincronizadoAteMs($order = Criteria::ASC) Order by the sincronizado_ate_ms column
 * @method     ChildContagemDispositivoQuery orderByPrimeiroDesvioMs($order = Criteria::ASC) Order by the primeiro_desvio_ms column
 *
 * @method     ChildContagemDispositivoQuery groupByDispositivoId() Group by the dispositivo_id column
 * @method     ChildContagemDispositivoQuery groupByFonteId() Group by the fonte_id column
 * @method     ChildContagemDispositivoQuery groupByTokenHash() Group by the token_hash column
 * @method     ChildContagemDispositivoQuery groupByUsuario() Group by the usuario column
 * @method     ChildContagemDispositivoQuery groupByRotulo() Group by the rotulo column
 * @method     ChildContagemDispositivoQuery groupByCriadoEmMs() Group by the criado_em_ms column
 * @method     ChildContagemDispositivoQuery groupByRevogadoEmMs() Group by the revogado_em_ms column
 * @method     ChildContagemDispositivoQuery groupByVistoEmMs() Group by the visto_em_ms column
 * @method     ChildContagemDispositivoQuery groupBySincronizadoAteMs() Group by the sincronizado_ate_ms column
 * @method     ChildContagemDispositivoQuery groupByPrimeiroDesvioMs() Group by the primeiro_desvio_ms column
 *
 * @method     ChildContagemDispositivoQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildContagemDispositivoQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildContagemDispositivoQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildContagemDispositivoQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildContagemDispositivoQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildContagemDispositivoQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildContagemDispositivoQuery leftJoinContagemFonte($relationAlias = null) Adds a LEFT JOIN clause to the query using the ContagemFonte relation
 * @method     ChildContagemDispositivoQuery rightJoinContagemFonte($relationAlias = null) Adds a RIGHT JOIN clause to the query using the ContagemFonte relation
 * @method     ChildContagemDispositivoQuery innerJoinContagemFonte($relationAlias = null) Adds a INNER JOIN clause to the query using the ContagemFonte relation
 *
 * @method     ChildContagemDispositivoQuery joinWithContagemFonte($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the ContagemFonte relation
 *
 * @method     ChildContagemDispositivoQuery leftJoinWithContagemFonte() Adds a LEFT JOIN clause and with to the query using the ContagemFonte relation
 * @method     ChildContagemDispositivoQuery rightJoinWithContagemFonte() Adds a RIGHT JOIN clause and with to the query using the ContagemFonte relation
 * @method     ChildContagemDispositivoQuery innerJoinWithContagemFonte() Adds a INNER JOIN clause and with to the query using the ContagemFonte relation
 *
 * @method     ChildContagemDispositivoQuery leftJoinContagemPassagem($relationAlias = null) Adds a LEFT JOIN clause to the query using the ContagemPassagem relation
 * @method     ChildContagemDispositivoQuery rightJoinContagemPassagem($relationAlias = null) Adds a RIGHT JOIN clause to the query using the ContagemPassagem relation
 * @method     ChildContagemDispositivoQuery innerJoinContagemPassagem($relationAlias = null) Adds a INNER JOIN clause to the query using the ContagemPassagem relation
 *
 * @method     ChildContagemDispositivoQuery joinWithContagemPassagem($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the ContagemPassagem relation
 *
 * @method     ChildContagemDispositivoQuery leftJoinWithContagemPassagem() Adds a LEFT JOIN clause and with to the query using the ContagemPassagem relation
 * @method     ChildContagemDispositivoQuery rightJoinWithContagemPassagem() Adds a RIGHT JOIN clause and with to the query using the ContagemPassagem relation
 * @method     ChildContagemDispositivoQuery innerJoinWithContagemPassagem() Adds a INNER JOIN clause and with to the query using the ContagemPassagem relation
 *
 * @method     \Baja\Model\ContagemFonteQuery|\Baja\Model\ContagemPassagemQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildContagemDispositivo|null findOne(?ConnectionInterface $con = null) Return the first ChildContagemDispositivo matching the query
 * @method     ChildContagemDispositivo findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildContagemDispositivo matching the query, or a new ChildContagemDispositivo object populated from the query conditions when no match is found
 *
 * @method     ChildContagemDispositivo|null findOneByDispositivoId(int $dispositivo_id) Return the first ChildContagemDispositivo filtered by the dispositivo_id column
 * @method     ChildContagemDispositivo|null findOneByFonteId(int $fonte_id) Return the first ChildContagemDispositivo filtered by the fonte_id column
 * @method     ChildContagemDispositivo|null findOneByTokenHash(string $token_hash) Return the first ChildContagemDispositivo filtered by the token_hash column
 * @method     ChildContagemDispositivo|null findOneByUsuario(string $usuario) Return the first ChildContagemDispositivo filtered by the usuario column
 * @method     ChildContagemDispositivo|null findOneByRotulo(string $rotulo) Return the first ChildContagemDispositivo filtered by the rotulo column
 * @method     ChildContagemDispositivo|null findOneByCriadoEmMs(string $criado_em_ms) Return the first ChildContagemDispositivo filtered by the criado_em_ms column
 * @method     ChildContagemDispositivo|null findOneByRevogadoEmMs(string $revogado_em_ms) Return the first ChildContagemDispositivo filtered by the revogado_em_ms column
 * @method     ChildContagemDispositivo|null findOneByVistoEmMs(string $visto_em_ms) Return the first ChildContagemDispositivo filtered by the visto_em_ms column
 * @method     ChildContagemDispositivo|null findOneBySincronizadoAteMs(string $sincronizado_ate_ms) Return the first ChildContagemDispositivo filtered by the sincronizado_ate_ms column
 * @method     ChildContagemDispositivo|null findOneByPrimeiroDesvioMs(string $primeiro_desvio_ms) Return the first ChildContagemDispositivo filtered by the primeiro_desvio_ms column
 *
 * @method     ChildContagemDispositivo requirePk($key, ?ConnectionInterface $con = null) Return the ChildContagemDispositivo by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDispositivo requireOne(?ConnectionInterface $con = null) Return the first ChildContagemDispositivo matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemDispositivo requireOneByDispositivoId(int $dispositivo_id) Return the first ChildContagemDispositivo filtered by the dispositivo_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDispositivo requireOneByFonteId(int $fonte_id) Return the first ChildContagemDispositivo filtered by the fonte_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDispositivo requireOneByTokenHash(string $token_hash) Return the first ChildContagemDispositivo filtered by the token_hash column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDispositivo requireOneByUsuario(string $usuario) Return the first ChildContagemDispositivo filtered by the usuario column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDispositivo requireOneByRotulo(string $rotulo) Return the first ChildContagemDispositivo filtered by the rotulo column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDispositivo requireOneByCriadoEmMs(string $criado_em_ms) Return the first ChildContagemDispositivo filtered by the criado_em_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDispositivo requireOneByRevogadoEmMs(string $revogado_em_ms) Return the first ChildContagemDispositivo filtered by the revogado_em_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDispositivo requireOneByVistoEmMs(string $visto_em_ms) Return the first ChildContagemDispositivo filtered by the visto_em_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDispositivo requireOneBySincronizadoAteMs(string $sincronizado_ate_ms) Return the first ChildContagemDispositivo filtered by the sincronizado_ate_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDispositivo requireOneByPrimeiroDesvioMs(string $primeiro_desvio_ms) Return the first ChildContagemDispositivo filtered by the primeiro_desvio_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemDispositivo[]|Collection find(?ConnectionInterface $con = null) Return ChildContagemDispositivo objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> find(?ConnectionInterface $con = null) Return ChildContagemDispositivo objects based on current ModelCriteria
 *
 * @method     ChildContagemDispositivo[]|Collection findByDispositivoId(int|array<int> $dispositivo_id) Return ChildContagemDispositivo objects filtered by the dispositivo_id column
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> findByDispositivoId(int|array<int> $dispositivo_id) Return ChildContagemDispositivo objects filtered by the dispositivo_id column
 * @method     ChildContagemDispositivo[]|Collection findByFonteId(int|array<int> $fonte_id) Return ChildContagemDispositivo objects filtered by the fonte_id column
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> findByFonteId(int|array<int> $fonte_id) Return ChildContagemDispositivo objects filtered by the fonte_id column
 * @method     ChildContagemDispositivo[]|Collection findByTokenHash(string|array<string> $token_hash) Return ChildContagemDispositivo objects filtered by the token_hash column
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> findByTokenHash(string|array<string> $token_hash) Return ChildContagemDispositivo objects filtered by the token_hash column
 * @method     ChildContagemDispositivo[]|Collection findByUsuario(string|array<string> $usuario) Return ChildContagemDispositivo objects filtered by the usuario column
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> findByUsuario(string|array<string> $usuario) Return ChildContagemDispositivo objects filtered by the usuario column
 * @method     ChildContagemDispositivo[]|Collection findByRotulo(string|array<string> $rotulo) Return ChildContagemDispositivo objects filtered by the rotulo column
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> findByRotulo(string|array<string> $rotulo) Return ChildContagemDispositivo objects filtered by the rotulo column
 * @method     ChildContagemDispositivo[]|Collection findByCriadoEmMs(string|array<string> $criado_em_ms) Return ChildContagemDispositivo objects filtered by the criado_em_ms column
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> findByCriadoEmMs(string|array<string> $criado_em_ms) Return ChildContagemDispositivo objects filtered by the criado_em_ms column
 * @method     ChildContagemDispositivo[]|Collection findByRevogadoEmMs(string|array<string> $revogado_em_ms) Return ChildContagemDispositivo objects filtered by the revogado_em_ms column
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> findByRevogadoEmMs(string|array<string> $revogado_em_ms) Return ChildContagemDispositivo objects filtered by the revogado_em_ms column
 * @method     ChildContagemDispositivo[]|Collection findByVistoEmMs(string|array<string> $visto_em_ms) Return ChildContagemDispositivo objects filtered by the visto_em_ms column
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> findByVistoEmMs(string|array<string> $visto_em_ms) Return ChildContagemDispositivo objects filtered by the visto_em_ms column
 * @method     ChildContagemDispositivo[]|Collection findBySincronizadoAteMs(string|array<string> $sincronizado_ate_ms) Return ChildContagemDispositivo objects filtered by the sincronizado_ate_ms column
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> findBySincronizadoAteMs(string|array<string> $sincronizado_ate_ms) Return ChildContagemDispositivo objects filtered by the sincronizado_ate_ms column
 * @method     ChildContagemDispositivo[]|Collection findByPrimeiroDesvioMs(string|array<string> $primeiro_desvio_ms) Return ChildContagemDispositivo objects filtered by the primeiro_desvio_ms column
 * @psalm-method Collection&\Traversable<ChildContagemDispositivo> findByPrimeiroDesvioMs(string|array<string> $primeiro_desvio_ms) Return ChildContagemDispositivo objects filtered by the primeiro_desvio_ms column
 *
 * @method     ChildContagemDispositivo[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildContagemDispositivo> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ContagemDispositivoQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ContagemDispositivoQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\ContagemDispositivo', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildContagemDispositivoQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildContagemDispositivoQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildContagemDispositivoQuery) {
            return $criteria;
        }
        $query = new ChildContagemDispositivoQuery();
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
     * @return ChildContagemDispositivo|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ContagemDispositivoTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ContagemDispositivoTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildContagemDispositivo A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT dispositivo_id, fonte_id, token_hash, usuario, rotulo, criado_em_ms, revogado_em_ms, visto_em_ms, sincronizado_ate_ms, primeiro_desvio_ms FROM contagem_dispositivo WHERE dispositivo_id = :p0';
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
            /** @var ChildContagemDispositivo $obj */
            $obj = new ChildContagemDispositivo();
            $obj->hydrate($row);
            ContagemDispositivoTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildContagemDispositivo|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the dispositivo_id column
     *
     * Example usage:
     * <code>
     * $query->filterByDispositivoId(1234); // WHERE dispositivo_id = 1234
     * $query->filterByDispositivoId(array(12, 34)); // WHERE dispositivo_id IN (12, 34)
     * $query->filterByDispositivoId(array('min' => 12)); // WHERE dispositivo_id > 12
     * </code>
     *
     * @param mixed $dispositivoId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDispositivoId($dispositivoId = null, ?string $comparison = null)
    {
        if (is_array($dispositivoId)) {
            $useMinMax = false;
            if (isset($dispositivoId['min'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, $dispositivoId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($dispositivoId['max'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, $dispositivoId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, $dispositivoId, $comparison);

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
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_FONTE_ID, $fonteId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($fonteId['max'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_FONTE_ID, $fonteId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_FONTE_ID, $fonteId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the token_hash column
     *
     * Example usage:
     * <code>
     * $query->filterByTokenHash('fooValue');   // WHERE token_hash = 'fooValue'
     * $query->filterByTokenHash('%fooValue%', Criteria::LIKE); // WHERE token_hash LIKE '%fooValue%'
     * $query->filterByTokenHash(['foo', 'bar']); // WHERE token_hash IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $tokenHash The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByTokenHash($tokenHash = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($tokenHash)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_TOKEN_HASH, $tokenHash, $comparison);

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

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_USUARIO, $usuario, $comparison);

        return $this;
    }

    /**
     * Filter the query on the rotulo column
     *
     * Example usage:
     * <code>
     * $query->filterByRotulo('fooValue');   // WHERE rotulo = 'fooValue'
     * $query->filterByRotulo('%fooValue%', Criteria::LIKE); // WHERE rotulo LIKE '%fooValue%'
     * $query->filterByRotulo(['foo', 'bar']); // WHERE rotulo IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $rotulo The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByRotulo($rotulo = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($rotulo)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_ROTULO, $rotulo, $comparison);

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
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_CRIADO_EM_MS, $criadoEmMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($criadoEmMs['max'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_CRIADO_EM_MS, $criadoEmMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_CRIADO_EM_MS, $criadoEmMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the revogado_em_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByRevogadoEmMs(1234); // WHERE revogado_em_ms = 1234
     * $query->filterByRevogadoEmMs(array(12, 34)); // WHERE revogado_em_ms IN (12, 34)
     * $query->filterByRevogadoEmMs(array('min' => 12)); // WHERE revogado_em_ms > 12
     * </code>
     *
     * @param mixed $revogadoEmMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByRevogadoEmMs($revogadoEmMs = null, ?string $comparison = null)
    {
        if (is_array($revogadoEmMs)) {
            $useMinMax = false;
            if (isset($revogadoEmMs['min'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_REVOGADO_EM_MS, $revogadoEmMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($revogadoEmMs['max'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_REVOGADO_EM_MS, $revogadoEmMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_REVOGADO_EM_MS, $revogadoEmMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the visto_em_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByVistoEmMs(1234); // WHERE visto_em_ms = 1234
     * $query->filterByVistoEmMs(array(12, 34)); // WHERE visto_em_ms IN (12, 34)
     * $query->filterByVistoEmMs(array('min' => 12)); // WHERE visto_em_ms > 12
     * </code>
     *
     * @param mixed $vistoEmMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByVistoEmMs($vistoEmMs = null, ?string $comparison = null)
    {
        if (is_array($vistoEmMs)) {
            $useMinMax = false;
            if (isset($vistoEmMs['min'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_VISTO_EM_MS, $vistoEmMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($vistoEmMs['max'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_VISTO_EM_MS, $vistoEmMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_VISTO_EM_MS, $vistoEmMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the sincronizado_ate_ms column
     *
     * Example usage:
     * <code>
     * $query->filterBySincronizadoAteMs(1234); // WHERE sincronizado_ate_ms = 1234
     * $query->filterBySincronizadoAteMs(array(12, 34)); // WHERE sincronizado_ate_ms IN (12, 34)
     * $query->filterBySincronizadoAteMs(array('min' => 12)); // WHERE sincronizado_ate_ms > 12
     * </code>
     *
     * @param mixed $sincronizadoAteMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterBySincronizadoAteMs($sincronizadoAteMs = null, ?string $comparison = null)
    {
        if (is_array($sincronizadoAteMs)) {
            $useMinMax = false;
            if (isset($sincronizadoAteMs['min'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS, $sincronizadoAteMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($sincronizadoAteMs['max'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS, $sincronizadoAteMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_SINCRONIZADO_ATE_MS, $sincronizadoAteMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the primeiro_desvio_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByPrimeiroDesvioMs(1234); // WHERE primeiro_desvio_ms = 1234
     * $query->filterByPrimeiroDesvioMs(array(12, 34)); // WHERE primeiro_desvio_ms IN (12, 34)
     * $query->filterByPrimeiroDesvioMs(array('min' => 12)); // WHERE primeiro_desvio_ms > 12
     * </code>
     *
     * @param mixed $primeiroDesvioMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByPrimeiroDesvioMs($primeiroDesvioMs = null, ?string $comparison = null)
    {
        if (is_array($primeiroDesvioMs)) {
            $useMinMax = false;
            if (isset($primeiroDesvioMs['min'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS, $primeiroDesvioMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($primeiroDesvioMs['max'])) {
                $this->addUsingAlias(ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS, $primeiroDesvioMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDispositivoTableMap::COL_PRIMEIRO_DESVIO_MS, $primeiroDesvioMs, $comparison);

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
                ->addUsingAlias(ContagemDispositivoTableMap::COL_FONTE_ID, $contagemFonte->getFonteId(), $comparison);
        } elseif ($contagemFonte instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(ContagemDispositivoTableMap::COL_FONTE_ID, $contagemFonte->toKeyValue('PrimaryKey', 'FonteId'), $comparison);

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
     * Filter the query by a related \Baja\Model\ContagemPassagem object
     *
     * @param \Baja\Model\ContagemPassagem|ObjectCollection $contagemPassagem the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByContagemPassagem($contagemPassagem, ?string $comparison = null)
    {
        if ($contagemPassagem instanceof \Baja\Model\ContagemPassagem) {
            $this
                ->addUsingAlias(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, $contagemPassagem->getDispositivoId(), $comparison);

            return $this;
        } elseif ($contagemPassagem instanceof ObjectCollection) {
            $this
                ->useContagemPassagemQuery()
                ->filterByPrimaryKeys($contagemPassagem->getPrimaryKeys())
                ->endUse();

            return $this;
        } else {
            throw new PropelException('filterByContagemPassagem() only accepts arguments of type \Baja\Model\ContagemPassagem or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the ContagemPassagem relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinContagemPassagem(?string $relationAlias = null, ?string $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('ContagemPassagem');

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
            $this->addJoinObject($join, 'ContagemPassagem');
        }

        return $this;
    }

    /**
     * Use the ContagemPassagem relation ContagemPassagem object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\ContagemPassagemQuery A secondary query class using the current class as primary query
     */
    public function useContagemPassagemQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinContagemPassagem($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'ContagemPassagem', '\Baja\Model\ContagemPassagemQuery');
    }

    /**
     * Use the ContagemPassagem relation ContagemPassagem object
     *
     * @param callable(\Baja\Model\ContagemPassagemQuery):\Baja\Model\ContagemPassagemQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withContagemPassagemQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::INNER_JOIN
    ) {
        $relatedQuery = $this->useContagemPassagemQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to ContagemPassagem table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\ContagemPassagemQuery The inner query object of the EXISTS statement
     */
    public function useContagemPassagemExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\ContagemPassagemQuery */
        $q = $this->useExistsQuery('ContagemPassagem', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to ContagemPassagem table for a NOT EXISTS query.
     *
     * @see useContagemPassagemExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ContagemPassagemQuery The inner query object of the NOT EXISTS statement
     */
    public function useContagemPassagemNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ContagemPassagemQuery */
        $q = $this->useExistsQuery('ContagemPassagem', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to ContagemPassagem table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\ContagemPassagemQuery The inner query object of the IN statement
     */
    public function useInContagemPassagemQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\ContagemPassagemQuery */
        $q = $this->useInQuery('ContagemPassagem', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to ContagemPassagem table for a NOT IN query.
     *
     * @see useContagemPassagemInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ContagemPassagemQuery The inner query object of the NOT IN statement
     */
    public function useNotInContagemPassagemQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ContagemPassagemQuery */
        $q = $this->useInQuery('ContagemPassagem', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Exclude object from result
     *
     * @param ChildContagemDispositivo $contagemDispositivo Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($contagemDispositivo = null)
    {
        if ($contagemDispositivo) {
            $this->addUsingAlias(ContagemDispositivoTableMap::COL_DISPOSITIVO_ID, $contagemDispositivo->getDispositivoId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the contagem_dispositivo table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemDispositivoTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ContagemDispositivoTableMap::clearInstancePool();
            ContagemDispositivoTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemDispositivoTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ContagemDispositivoTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ContagemDispositivoTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ContagemDispositivoTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
