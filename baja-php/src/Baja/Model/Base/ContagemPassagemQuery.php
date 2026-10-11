<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemPassagem as ChildContagemPassagem;
use Baja\Model\ContagemPassagemQuery as ChildContagemPassagemQuery;
use Baja\Model\Map\ContagemPassagemTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `contagem_passagem` table.
 *
 * @method     ChildContagemPassagemQuery orderByPassagemId($order = Criteria::ASC) Order by the passagem_id column
 * @method     ChildContagemPassagemQuery orderByFonteId($order = Criteria::ASC) Order by the fonte_id column
 * @method     ChildContagemPassagemQuery orderByDispositivoId($order = Criteria::ASC) Order by the dispositivo_id column
 * @method     ChildContagemPassagemQuery orderByUuidCliente($order = Criteria::ASC) Order by the uuid_cliente column
 * @method     ChildContagemPassagemQuery orderByEquipeNumero($order = Criteria::ASC) Order by the equipe_numero column
 * @method     ChildContagemPassagemQuery orderByTsDispositivoMs($order = Criteria::ASC) Order by the ts_dispositivo_ms column
 * @method     ChildContagemPassagemQuery orderByDesvioRelogioMs($order = Criteria::ASC) Order by the desvio_relogio_ms column
 * @method     ChildContagemPassagemQuery orderByTsMs($order = Criteria::ASC) Order by the ts_ms column
 * @method     ChildContagemPassagemQuery orderByDigitacaoMs($order = Criteria::ASC) Order by the digitacao_ms column
 * @method     ChildContagemPassagemQuery orderByModoEntrada($order = Criteria::ASC) Order by the modo_entrada column
 * @method     ChildContagemPassagemQuery orderByRecebidoEmMs($order = Criteria::ASC) Order by the recebido_em_ms column
 *
 * @method     ChildContagemPassagemQuery groupByPassagemId() Group by the passagem_id column
 * @method     ChildContagemPassagemQuery groupByFonteId() Group by the fonte_id column
 * @method     ChildContagemPassagemQuery groupByDispositivoId() Group by the dispositivo_id column
 * @method     ChildContagemPassagemQuery groupByUuidCliente() Group by the uuid_cliente column
 * @method     ChildContagemPassagemQuery groupByEquipeNumero() Group by the equipe_numero column
 * @method     ChildContagemPassagemQuery groupByTsDispositivoMs() Group by the ts_dispositivo_ms column
 * @method     ChildContagemPassagemQuery groupByDesvioRelogioMs() Group by the desvio_relogio_ms column
 * @method     ChildContagemPassagemQuery groupByTsMs() Group by the ts_ms column
 * @method     ChildContagemPassagemQuery groupByDigitacaoMs() Group by the digitacao_ms column
 * @method     ChildContagemPassagemQuery groupByModoEntrada() Group by the modo_entrada column
 * @method     ChildContagemPassagemQuery groupByRecebidoEmMs() Group by the recebido_em_ms column
 *
 * @method     ChildContagemPassagemQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildContagemPassagemQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildContagemPassagemQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildContagemPassagemQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildContagemPassagemQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildContagemPassagemQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildContagemPassagemQuery leftJoinContagemFonte($relationAlias = null) Adds a LEFT JOIN clause to the query using the ContagemFonte relation
 * @method     ChildContagemPassagemQuery rightJoinContagemFonte($relationAlias = null) Adds a RIGHT JOIN clause to the query using the ContagemFonte relation
 * @method     ChildContagemPassagemQuery innerJoinContagemFonte($relationAlias = null) Adds a INNER JOIN clause to the query using the ContagemFonte relation
 *
 * @method     ChildContagemPassagemQuery joinWithContagemFonte($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the ContagemFonte relation
 *
 * @method     ChildContagemPassagemQuery leftJoinWithContagemFonte() Adds a LEFT JOIN clause and with to the query using the ContagemFonte relation
 * @method     ChildContagemPassagemQuery rightJoinWithContagemFonte() Adds a RIGHT JOIN clause and with to the query using the ContagemFonte relation
 * @method     ChildContagemPassagemQuery innerJoinWithContagemFonte() Adds a INNER JOIN clause and with to the query using the ContagemFonte relation
 *
 * @method     ChildContagemPassagemQuery leftJoinContagemDispositivo($relationAlias = null) Adds a LEFT JOIN clause to the query using the ContagemDispositivo relation
 * @method     ChildContagemPassagemQuery rightJoinContagemDispositivo($relationAlias = null) Adds a RIGHT JOIN clause to the query using the ContagemDispositivo relation
 * @method     ChildContagemPassagemQuery innerJoinContagemDispositivo($relationAlias = null) Adds a INNER JOIN clause to the query using the ContagemDispositivo relation
 *
 * @method     ChildContagemPassagemQuery joinWithContagemDispositivo($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the ContagemDispositivo relation
 *
 * @method     ChildContagemPassagemQuery leftJoinWithContagemDispositivo() Adds a LEFT JOIN clause and with to the query using the ContagemDispositivo relation
 * @method     ChildContagemPassagemQuery rightJoinWithContagemDispositivo() Adds a RIGHT JOIN clause and with to the query using the ContagemDispositivo relation
 * @method     ChildContagemPassagemQuery innerJoinWithContagemDispositivo() Adds a INNER JOIN clause and with to the query using the ContagemDispositivo relation
 *
 * @method     \Baja\Model\ContagemFonteQuery|\Baja\Model\ContagemDispositivoQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildContagemPassagem|null findOne(?ConnectionInterface $con = null) Return the first ChildContagemPassagem matching the query
 * @method     ChildContagemPassagem findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildContagemPassagem matching the query, or a new ChildContagemPassagem object populated from the query conditions when no match is found
 *
 * @method     ChildContagemPassagem|null findOneByPassagemId(string $passagem_id) Return the first ChildContagemPassagem filtered by the passagem_id column
 * @method     ChildContagemPassagem|null findOneByFonteId(int $fonte_id) Return the first ChildContagemPassagem filtered by the fonte_id column
 * @method     ChildContagemPassagem|null findOneByDispositivoId(int $dispositivo_id) Return the first ChildContagemPassagem filtered by the dispositivo_id column
 * @method     ChildContagemPassagem|null findOneByUuidCliente(string $uuid_cliente) Return the first ChildContagemPassagem filtered by the uuid_cliente column
 * @method     ChildContagemPassagem|null findOneByEquipeNumero(int $equipe_numero) Return the first ChildContagemPassagem filtered by the equipe_numero column
 * @method     ChildContagemPassagem|null findOneByTsDispositivoMs(string $ts_dispositivo_ms) Return the first ChildContagemPassagem filtered by the ts_dispositivo_ms column
 * @method     ChildContagemPassagem|null findOneByDesvioRelogioMs(string $desvio_relogio_ms) Return the first ChildContagemPassagem filtered by the desvio_relogio_ms column
 * @method     ChildContagemPassagem|null findOneByTsMs(string $ts_ms) Return the first ChildContagemPassagem filtered by the ts_ms column
 * @method     ChildContagemPassagem|null findOneByDigitacaoMs(int $digitacao_ms) Return the first ChildContagemPassagem filtered by the digitacao_ms column
 * @method     ChildContagemPassagem|null findOneByModoEntrada(string $modo_entrada) Return the first ChildContagemPassagem filtered by the modo_entrada column
 * @method     ChildContagemPassagem|null findOneByRecebidoEmMs(string $recebido_em_ms) Return the first ChildContagemPassagem filtered by the recebido_em_ms column
 *
 * @method     ChildContagemPassagem requirePk($key, ?ConnectionInterface $con = null) Return the ChildContagemPassagem by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOne(?ConnectionInterface $con = null) Return the first ChildContagemPassagem matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemPassagem requireOneByPassagemId(string $passagem_id) Return the first ChildContagemPassagem filtered by the passagem_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOneByFonteId(int $fonte_id) Return the first ChildContagemPassagem filtered by the fonte_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOneByDispositivoId(int $dispositivo_id) Return the first ChildContagemPassagem filtered by the dispositivo_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOneByUuidCliente(string $uuid_cliente) Return the first ChildContagemPassagem filtered by the uuid_cliente column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOneByEquipeNumero(int $equipe_numero) Return the first ChildContagemPassagem filtered by the equipe_numero column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOneByTsDispositivoMs(string $ts_dispositivo_ms) Return the first ChildContagemPassagem filtered by the ts_dispositivo_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOneByDesvioRelogioMs(string $desvio_relogio_ms) Return the first ChildContagemPassagem filtered by the desvio_relogio_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOneByTsMs(string $ts_ms) Return the first ChildContagemPassagem filtered by the ts_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOneByDigitacaoMs(int $digitacao_ms) Return the first ChildContagemPassagem filtered by the digitacao_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOneByModoEntrada(string $modo_entrada) Return the first ChildContagemPassagem filtered by the modo_entrada column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemPassagem requireOneByRecebidoEmMs(string $recebido_em_ms) Return the first ChildContagemPassagem filtered by the recebido_em_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemPassagem[]|Collection find(?ConnectionInterface $con = null) Return ChildContagemPassagem objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> find(?ConnectionInterface $con = null) Return ChildContagemPassagem objects based on current ModelCriteria
 *
 * @method     ChildContagemPassagem[]|Collection findByPassagemId(string|array<string> $passagem_id) Return ChildContagemPassagem objects filtered by the passagem_id column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByPassagemId(string|array<string> $passagem_id) Return ChildContagemPassagem objects filtered by the passagem_id column
 * @method     ChildContagemPassagem[]|Collection findByFonteId(int|array<int> $fonte_id) Return ChildContagemPassagem objects filtered by the fonte_id column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByFonteId(int|array<int> $fonte_id) Return ChildContagemPassagem objects filtered by the fonte_id column
 * @method     ChildContagemPassagem[]|Collection findByDispositivoId(int|array<int> $dispositivo_id) Return ChildContagemPassagem objects filtered by the dispositivo_id column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByDispositivoId(int|array<int> $dispositivo_id) Return ChildContagemPassagem objects filtered by the dispositivo_id column
 * @method     ChildContagemPassagem[]|Collection findByUuidCliente(string|array<string> $uuid_cliente) Return ChildContagemPassagem objects filtered by the uuid_cliente column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByUuidCliente(string|array<string> $uuid_cliente) Return ChildContagemPassagem objects filtered by the uuid_cliente column
 * @method     ChildContagemPassagem[]|Collection findByEquipeNumero(int|array<int> $equipe_numero) Return ChildContagemPassagem objects filtered by the equipe_numero column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByEquipeNumero(int|array<int> $equipe_numero) Return ChildContagemPassagem objects filtered by the equipe_numero column
 * @method     ChildContagemPassagem[]|Collection findByTsDispositivoMs(string|array<string> $ts_dispositivo_ms) Return ChildContagemPassagem objects filtered by the ts_dispositivo_ms column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByTsDispositivoMs(string|array<string> $ts_dispositivo_ms) Return ChildContagemPassagem objects filtered by the ts_dispositivo_ms column
 * @method     ChildContagemPassagem[]|Collection findByDesvioRelogioMs(string|array<string> $desvio_relogio_ms) Return ChildContagemPassagem objects filtered by the desvio_relogio_ms column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByDesvioRelogioMs(string|array<string> $desvio_relogio_ms) Return ChildContagemPassagem objects filtered by the desvio_relogio_ms column
 * @method     ChildContagemPassagem[]|Collection findByTsMs(string|array<string> $ts_ms) Return ChildContagemPassagem objects filtered by the ts_ms column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByTsMs(string|array<string> $ts_ms) Return ChildContagemPassagem objects filtered by the ts_ms column
 * @method     ChildContagemPassagem[]|Collection findByDigitacaoMs(int|array<int> $digitacao_ms) Return ChildContagemPassagem objects filtered by the digitacao_ms column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByDigitacaoMs(int|array<int> $digitacao_ms) Return ChildContagemPassagem objects filtered by the digitacao_ms column
 * @method     ChildContagemPassagem[]|Collection findByModoEntrada(string|array<string> $modo_entrada) Return ChildContagemPassagem objects filtered by the modo_entrada column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByModoEntrada(string|array<string> $modo_entrada) Return ChildContagemPassagem objects filtered by the modo_entrada column
 * @method     ChildContagemPassagem[]|Collection findByRecebidoEmMs(string|array<string> $recebido_em_ms) Return ChildContagemPassagem objects filtered by the recebido_em_ms column
 * @psalm-method Collection&\Traversable<ChildContagemPassagem> findByRecebidoEmMs(string|array<string> $recebido_em_ms) Return ChildContagemPassagem objects filtered by the recebido_em_ms column
 *
 * @method     ChildContagemPassagem[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildContagemPassagem> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ContagemPassagemQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ContagemPassagemQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\ContagemPassagem', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildContagemPassagemQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildContagemPassagemQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildContagemPassagemQuery) {
            return $criteria;
        }
        $query = new ChildContagemPassagemQuery();
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
     * @return ChildContagemPassagem|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ContagemPassagemTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ContagemPassagemTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildContagemPassagem A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT passagem_id, fonte_id, dispositivo_id, uuid_cliente, equipe_numero, ts_dispositivo_ms, desvio_relogio_ms, ts_ms, digitacao_ms, modo_entrada, recebido_em_ms FROM contagem_passagem WHERE passagem_id = :p0';
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
            /** @var ChildContagemPassagem $obj */
            $obj = new ChildContagemPassagem();
            $obj->hydrate($row);
            ContagemPassagemTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildContagemPassagem|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(ContagemPassagemTableMap::COL_PASSAGEM_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(ContagemPassagemTableMap::COL_PASSAGEM_ID, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the passagem_id column
     *
     * Example usage:
     * <code>
     * $query->filterByPassagemId(1234); // WHERE passagem_id = 1234
     * $query->filterByPassagemId(array(12, 34)); // WHERE passagem_id IN (12, 34)
     * $query->filterByPassagemId(array('min' => 12)); // WHERE passagem_id > 12
     * </code>
     *
     * @param mixed $passagemId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByPassagemId($passagemId = null, ?string $comparison = null)
    {
        if (is_array($passagemId)) {
            $useMinMax = false;
            if (isset($passagemId['min'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_PASSAGEM_ID, $passagemId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($passagemId['max'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_PASSAGEM_ID, $passagemId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_PASSAGEM_ID, $passagemId, $comparison);

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
                $this->addUsingAlias(ContagemPassagemTableMap::COL_FONTE_ID, $fonteId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($fonteId['max'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_FONTE_ID, $fonteId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_FONTE_ID, $fonteId, $comparison);

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
     * @see       filterByContagemDispositivo()
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
                $this->addUsingAlias(ContagemPassagemTableMap::COL_DISPOSITIVO_ID, $dispositivoId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($dispositivoId['max'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_DISPOSITIVO_ID, $dispositivoId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_DISPOSITIVO_ID, $dispositivoId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the uuid_cliente column
     *
     * Example usage:
     * <code>
     * $query->filterByUuidCliente('fooValue');   // WHERE uuid_cliente = 'fooValue'
     * $query->filterByUuidCliente('%fooValue%', Criteria::LIKE); // WHERE uuid_cliente LIKE '%fooValue%'
     * $query->filterByUuidCliente(['foo', 'bar']); // WHERE uuid_cliente IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $uuidCliente The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByUuidCliente($uuidCliente = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($uuidCliente)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_UUID_CLIENTE, $uuidCliente, $comparison);

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
                $this->addUsingAlias(ContagemPassagemTableMap::COL_EQUIPE_NUMERO, $equipeNumero['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($equipeNumero['max'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_EQUIPE_NUMERO, $equipeNumero['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_EQUIPE_NUMERO, $equipeNumero, $comparison);

        return $this;
    }

    /**
     * Filter the query on the ts_dispositivo_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByTsDispositivoMs(1234); // WHERE ts_dispositivo_ms = 1234
     * $query->filterByTsDispositivoMs(array(12, 34)); // WHERE ts_dispositivo_ms IN (12, 34)
     * $query->filterByTsDispositivoMs(array('min' => 12)); // WHERE ts_dispositivo_ms > 12
     * </code>
     *
     * @param mixed $tsDispositivoMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByTsDispositivoMs($tsDispositivoMs = null, ?string $comparison = null)
    {
        if (is_array($tsDispositivoMs)) {
            $useMinMax = false;
            if (isset($tsDispositivoMs['min'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS, $tsDispositivoMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tsDispositivoMs['max'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS, $tsDispositivoMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_TS_DISPOSITIVO_MS, $tsDispositivoMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the desvio_relogio_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByDesvioRelogioMs(1234); // WHERE desvio_relogio_ms = 1234
     * $query->filterByDesvioRelogioMs(array(12, 34)); // WHERE desvio_relogio_ms IN (12, 34)
     * $query->filterByDesvioRelogioMs(array('min' => 12)); // WHERE desvio_relogio_ms > 12
     * </code>
     *
     * @param mixed $desvioRelogioMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDesvioRelogioMs($desvioRelogioMs = null, ?string $comparison = null)
    {
        if (is_array($desvioRelogioMs)) {
            $useMinMax = false;
            if (isset($desvioRelogioMs['min'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_DESVIO_RELOGIO_MS, $desvioRelogioMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($desvioRelogioMs['max'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_DESVIO_RELOGIO_MS, $desvioRelogioMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_DESVIO_RELOGIO_MS, $desvioRelogioMs, $comparison);

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
                $this->addUsingAlias(ContagemPassagemTableMap::COL_TS_MS, $tsMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tsMs['max'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_TS_MS, $tsMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_TS_MS, $tsMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the digitacao_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByDigitacaoMs(1234); // WHERE digitacao_ms = 1234
     * $query->filterByDigitacaoMs(array(12, 34)); // WHERE digitacao_ms IN (12, 34)
     * $query->filterByDigitacaoMs(array('min' => 12)); // WHERE digitacao_ms > 12
     * </code>
     *
     * @param mixed $digitacaoMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDigitacaoMs($digitacaoMs = null, ?string $comparison = null)
    {
        if (is_array($digitacaoMs)) {
            $useMinMax = false;
            if (isset($digitacaoMs['min'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_DIGITACAO_MS, $digitacaoMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($digitacaoMs['max'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_DIGITACAO_MS, $digitacaoMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_DIGITACAO_MS, $digitacaoMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the modo_entrada column
     *
     * Example usage:
     * <code>
     * $query->filterByModoEntrada('fooValue');   // WHERE modo_entrada = 'fooValue'
     * $query->filterByModoEntrada('%fooValue%', Criteria::LIKE); // WHERE modo_entrada LIKE '%fooValue%'
     * $query->filterByModoEntrada(['foo', 'bar']); // WHERE modo_entrada IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $modoEntrada The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByModoEntrada($modoEntrada = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($modoEntrada)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_MODO_ENTRADA, $modoEntrada, $comparison);

        return $this;
    }

    /**
     * Filter the query on the recebido_em_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByRecebidoEmMs(1234); // WHERE recebido_em_ms = 1234
     * $query->filterByRecebidoEmMs(array(12, 34)); // WHERE recebido_em_ms IN (12, 34)
     * $query->filterByRecebidoEmMs(array('min' => 12)); // WHERE recebido_em_ms > 12
     * </code>
     *
     * @param mixed $recebidoEmMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByRecebidoEmMs($recebidoEmMs = null, ?string $comparison = null)
    {
        if (is_array($recebidoEmMs)) {
            $useMinMax = false;
            if (isset($recebidoEmMs['min'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_RECEBIDO_EM_MS, $recebidoEmMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($recebidoEmMs['max'])) {
                $this->addUsingAlias(ContagemPassagemTableMap::COL_RECEBIDO_EM_MS, $recebidoEmMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemPassagemTableMap::COL_RECEBIDO_EM_MS, $recebidoEmMs, $comparison);

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
                ->addUsingAlias(ContagemPassagemTableMap::COL_FONTE_ID, $contagemFonte->getFonteId(), $comparison);
        } elseif ($contagemFonte instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(ContagemPassagemTableMap::COL_FONTE_ID, $contagemFonte->toKeyValue('PrimaryKey', 'FonteId'), $comparison);

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
     * Filter the query by a related \Baja\Model\ContagemDispositivo object
     *
     * @param \Baja\Model\ContagemDispositivo|ObjectCollection $contagemDispositivo The related object(s) to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByContagemDispositivo($contagemDispositivo, ?string $comparison = null)
    {
        if ($contagemDispositivo instanceof \Baja\Model\ContagemDispositivo) {
            return $this
                ->addUsingAlias(ContagemPassagemTableMap::COL_DISPOSITIVO_ID, $contagemDispositivo->getDispositivoId(), $comparison);
        } elseif ($contagemDispositivo instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            $this
                ->addUsingAlias(ContagemPassagemTableMap::COL_DISPOSITIVO_ID, $contagemDispositivo->toKeyValue('PrimaryKey', 'DispositivoId'), $comparison);

            return $this;
        } else {
            throw new PropelException('filterByContagemDispositivo() only accepts arguments of type \Baja\Model\ContagemDispositivo or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the ContagemDispositivo relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinContagemDispositivo(?string $relationAlias = null, ?string $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('ContagemDispositivo');

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
            $this->addJoinObject($join, 'ContagemDispositivo');
        }

        return $this;
    }

    /**
     * Use the ContagemDispositivo relation ContagemDispositivo object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\ContagemDispositivoQuery A secondary query class using the current class as primary query
     */
    public function useContagemDispositivoQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinContagemDispositivo($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'ContagemDispositivo', '\Baja\Model\ContagemDispositivoQuery');
    }

    /**
     * Use the ContagemDispositivo relation ContagemDispositivo object
     *
     * @param callable(\Baja\Model\ContagemDispositivoQuery):\Baja\Model\ContagemDispositivoQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withContagemDispositivoQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::INNER_JOIN
    ) {
        $relatedQuery = $this->useContagemDispositivoQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to ContagemDispositivo table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\ContagemDispositivoQuery The inner query object of the EXISTS statement
     */
    public function useContagemDispositivoExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\ContagemDispositivoQuery */
        $q = $this->useExistsQuery('ContagemDispositivo', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to ContagemDispositivo table for a NOT EXISTS query.
     *
     * @see useContagemDispositivoExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ContagemDispositivoQuery The inner query object of the NOT EXISTS statement
     */
    public function useContagemDispositivoNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ContagemDispositivoQuery */
        $q = $this->useExistsQuery('ContagemDispositivo', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to ContagemDispositivo table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\ContagemDispositivoQuery The inner query object of the IN statement
     */
    public function useInContagemDispositivoQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\ContagemDispositivoQuery */
        $q = $this->useInQuery('ContagemDispositivo', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to ContagemDispositivo table for a NOT IN query.
     *
     * @see useContagemDispositivoInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ContagemDispositivoQuery The inner query object of the NOT IN statement
     */
    public function useNotInContagemDispositivoQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ContagemDispositivoQuery */
        $q = $this->useInQuery('ContagemDispositivo', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Exclude object from result
     *
     * @param ChildContagemPassagem $contagemPassagem Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($contagemPassagem = null)
    {
        if ($contagemPassagem) {
            $this->addUsingAlias(ContagemPassagemTableMap::COL_PASSAGEM_ID, $contagemPassagem->getPassagemId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the contagem_passagem table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemPassagemTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ContagemPassagemTableMap::clearInstancePool();
            ContagemPassagemTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemPassagemTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ContagemPassagemTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ContagemPassagemTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ContagemPassagemTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
