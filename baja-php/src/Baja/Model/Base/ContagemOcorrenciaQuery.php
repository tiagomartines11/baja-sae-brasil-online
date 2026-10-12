<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemOcorrencia as ChildContagemOcorrencia;
use Baja\Model\ContagemOcorrenciaQuery as ChildContagemOcorrenciaQuery;
use Baja\Model\Map\ContagemOcorrenciaTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `contagem_ocorrencia` table.
 *
 * @method     ChildContagemOcorrenciaQuery orderByOcorrenciaId($order = Criteria::ASC) Order by the ocorrencia_id column
 * @method     ChildContagemOcorrenciaQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildContagemOcorrenciaQuery orderByProvaId($order = Criteria::ASC) Order by the prova_id column
 * @method     ChildContagemOcorrenciaQuery orderByTipo($order = Criteria::ASC) Order by the tipo column
 * @method     ChildContagemOcorrenciaQuery orderByEquipeNumero($order = Criteria::ASC) Order by the equipe_numero column
 * @method     ChildContagemOcorrenciaQuery orderByTsMs($order = Criteria::ASC) Order by the ts_ms column
 * @method     ChildContagemOcorrenciaQuery orderByUsuario($order = Criteria::ASC) Order by the usuario column
 * @method     ChildContagemOcorrenciaQuery orderByCriadoEmMs($order = Criteria::ASC) Order by the criado_em_ms column
 * @method     ChildContagemOcorrenciaQuery orderBySubstituiId($order = Criteria::ASC) Order by the substitui_id column
 * @method     ChildContagemOcorrenciaQuery orderByUuidCliente($order = Criteria::ASC) Order by the uuid_cliente column
 * @method     ChildContagemOcorrenciaQuery orderByQualidadeTempo($order = Criteria::ASC) Order by the qualidade_tempo column
 * @method     ChildContagemOcorrenciaQuery orderByTsDispositivoMs($order = Criteria::ASC) Order by the ts_dispositivo_ms column
 * @method     ChildContagemOcorrenciaQuery orderByModoEntrada($order = Criteria::ASC) Order by the modo_entrada column
 * @method     ChildContagemOcorrenciaQuery orderByOrigem($order = Criteria::ASC) Order by the origem column
 *
 * @method     ChildContagemOcorrenciaQuery groupByOcorrenciaId() Group by the ocorrencia_id column
 * @method     ChildContagemOcorrenciaQuery groupByEventoId() Group by the evento_id column
 * @method     ChildContagemOcorrenciaQuery groupByProvaId() Group by the prova_id column
 * @method     ChildContagemOcorrenciaQuery groupByTipo() Group by the tipo column
 * @method     ChildContagemOcorrenciaQuery groupByEquipeNumero() Group by the equipe_numero column
 * @method     ChildContagemOcorrenciaQuery groupByTsMs() Group by the ts_ms column
 * @method     ChildContagemOcorrenciaQuery groupByUsuario() Group by the usuario column
 * @method     ChildContagemOcorrenciaQuery groupByCriadoEmMs() Group by the criado_em_ms column
 * @method     ChildContagemOcorrenciaQuery groupBySubstituiId() Group by the substitui_id column
 * @method     ChildContagemOcorrenciaQuery groupByUuidCliente() Group by the uuid_cliente column
 * @method     ChildContagemOcorrenciaQuery groupByQualidadeTempo() Group by the qualidade_tempo column
 * @method     ChildContagemOcorrenciaQuery groupByTsDispositivoMs() Group by the ts_dispositivo_ms column
 * @method     ChildContagemOcorrenciaQuery groupByModoEntrada() Group by the modo_entrada column
 * @method     ChildContagemOcorrenciaQuery groupByOrigem() Group by the origem column
 *
 * @method     ChildContagemOcorrenciaQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildContagemOcorrenciaQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildContagemOcorrenciaQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildContagemOcorrenciaQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildContagemOcorrenciaQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildContagemOcorrenciaQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildContagemOcorrenciaQuery leftJoinProva($relationAlias = null) Adds a LEFT JOIN clause to the query using the Prova relation
 * @method     ChildContagemOcorrenciaQuery rightJoinProva($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Prova relation
 * @method     ChildContagemOcorrenciaQuery innerJoinProva($relationAlias = null) Adds a INNER JOIN clause to the query using the Prova relation
 *
 * @method     ChildContagemOcorrenciaQuery joinWithProva($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Prova relation
 *
 * @method     ChildContagemOcorrenciaQuery leftJoinWithProva() Adds a LEFT JOIN clause and with to the query using the Prova relation
 * @method     ChildContagemOcorrenciaQuery rightJoinWithProva() Adds a RIGHT JOIN clause and with to the query using the Prova relation
 * @method     ChildContagemOcorrenciaQuery innerJoinWithProva() Adds a INNER JOIN clause and with to the query using the Prova relation
 *
 * @method     \Baja\Model\ProvaQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildContagemOcorrencia|null findOne(?ConnectionInterface $con = null) Return the first ChildContagemOcorrencia matching the query
 * @method     ChildContagemOcorrencia findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildContagemOcorrencia matching the query, or a new ChildContagemOcorrencia object populated from the query conditions when no match is found
 *
 * @method     ChildContagemOcorrencia|null findOneByOcorrenciaId(string $ocorrencia_id) Return the first ChildContagemOcorrencia filtered by the ocorrencia_id column
 * @method     ChildContagemOcorrencia|null findOneByEventoId(string $evento_id) Return the first ChildContagemOcorrencia filtered by the evento_id column
 * @method     ChildContagemOcorrencia|null findOneByProvaId(string $prova_id) Return the first ChildContagemOcorrencia filtered by the prova_id column
 * @method     ChildContagemOcorrencia|null findOneByTipo(string $tipo) Return the first ChildContagemOcorrencia filtered by the tipo column
 * @method     ChildContagemOcorrencia|null findOneByEquipeNumero(int $equipe_numero) Return the first ChildContagemOcorrencia filtered by the equipe_numero column
 * @method     ChildContagemOcorrencia|null findOneByTsMs(string $ts_ms) Return the first ChildContagemOcorrencia filtered by the ts_ms column
 * @method     ChildContagemOcorrencia|null findOneByUsuario(string $usuario) Return the first ChildContagemOcorrencia filtered by the usuario column
 * @method     ChildContagemOcorrencia|null findOneByCriadoEmMs(string $criado_em_ms) Return the first ChildContagemOcorrencia filtered by the criado_em_ms column
 * @method     ChildContagemOcorrencia|null findOneBySubstituiId(string $substitui_id) Return the first ChildContagemOcorrencia filtered by the substitui_id column
 * @method     ChildContagemOcorrencia|null findOneByUuidCliente(string $uuid_cliente) Return the first ChildContagemOcorrencia filtered by the uuid_cliente column
 * @method     ChildContagemOcorrencia|null findOneByQualidadeTempo(string $qualidade_tempo) Return the first ChildContagemOcorrencia filtered by the qualidade_tempo column
 * @method     ChildContagemOcorrencia|null findOneByTsDispositivoMs(string $ts_dispositivo_ms) Return the first ChildContagemOcorrencia filtered by the ts_dispositivo_ms column
 * @method     ChildContagemOcorrencia|null findOneByModoEntrada(string $modo_entrada) Return the first ChildContagemOcorrencia filtered by the modo_entrada column
 * @method     ChildContagemOcorrencia|null findOneByOrigem(string $origem) Return the first ChildContagemOcorrencia filtered by the origem column
 *
 * @method     ChildContagemOcorrencia requirePk($key, ?ConnectionInterface $con = null) Return the ChildContagemOcorrencia by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOne(?ConnectionInterface $con = null) Return the first ChildContagemOcorrencia matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemOcorrencia requireOneByOcorrenciaId(string $ocorrencia_id) Return the first ChildContagemOcorrencia filtered by the ocorrencia_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByEventoId(string $evento_id) Return the first ChildContagemOcorrencia filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByProvaId(string $prova_id) Return the first ChildContagemOcorrencia filtered by the prova_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByTipo(string $tipo) Return the first ChildContagemOcorrencia filtered by the tipo column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByEquipeNumero(int $equipe_numero) Return the first ChildContagemOcorrencia filtered by the equipe_numero column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByTsMs(string $ts_ms) Return the first ChildContagemOcorrencia filtered by the ts_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByUsuario(string $usuario) Return the first ChildContagemOcorrencia filtered by the usuario column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByCriadoEmMs(string $criado_em_ms) Return the first ChildContagemOcorrencia filtered by the criado_em_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneBySubstituiId(string $substitui_id) Return the first ChildContagemOcorrencia filtered by the substitui_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByUuidCliente(string $uuid_cliente) Return the first ChildContagemOcorrencia filtered by the uuid_cliente column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByQualidadeTempo(string $qualidade_tempo) Return the first ChildContagemOcorrencia filtered by the qualidade_tempo column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByTsDispositivoMs(string $ts_dispositivo_ms) Return the first ChildContagemOcorrencia filtered by the ts_dispositivo_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByModoEntrada(string $modo_entrada) Return the first ChildContagemOcorrencia filtered by the modo_entrada column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemOcorrencia requireOneByOrigem(string $origem) Return the first ChildContagemOcorrencia filtered by the origem column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemOcorrencia[]|Collection find(?ConnectionInterface $con = null) Return ChildContagemOcorrencia objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> find(?ConnectionInterface $con = null) Return ChildContagemOcorrencia objects based on current ModelCriteria
 *
 * @method     ChildContagemOcorrencia[]|Collection findByOcorrenciaId(string|array<string> $ocorrencia_id) Return ChildContagemOcorrencia objects filtered by the ocorrencia_id column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByOcorrenciaId(string|array<string> $ocorrencia_id) Return ChildContagemOcorrencia objects filtered by the ocorrencia_id column
 * @method     ChildContagemOcorrencia[]|Collection findByEventoId(string|array<string> $evento_id) Return ChildContagemOcorrencia objects filtered by the evento_id column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByEventoId(string|array<string> $evento_id) Return ChildContagemOcorrencia objects filtered by the evento_id column
 * @method     ChildContagemOcorrencia[]|Collection findByProvaId(string|array<string> $prova_id) Return ChildContagemOcorrencia objects filtered by the prova_id column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByProvaId(string|array<string> $prova_id) Return ChildContagemOcorrencia objects filtered by the prova_id column
 * @method     ChildContagemOcorrencia[]|Collection findByTipo(string|array<string> $tipo) Return ChildContagemOcorrencia objects filtered by the tipo column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByTipo(string|array<string> $tipo) Return ChildContagemOcorrencia objects filtered by the tipo column
 * @method     ChildContagemOcorrencia[]|Collection findByEquipeNumero(int|array<int> $equipe_numero) Return ChildContagemOcorrencia objects filtered by the equipe_numero column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByEquipeNumero(int|array<int> $equipe_numero) Return ChildContagemOcorrencia objects filtered by the equipe_numero column
 * @method     ChildContagemOcorrencia[]|Collection findByTsMs(string|array<string> $ts_ms) Return ChildContagemOcorrencia objects filtered by the ts_ms column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByTsMs(string|array<string> $ts_ms) Return ChildContagemOcorrencia objects filtered by the ts_ms column
 * @method     ChildContagemOcorrencia[]|Collection findByUsuario(string|array<string> $usuario) Return ChildContagemOcorrencia objects filtered by the usuario column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByUsuario(string|array<string> $usuario) Return ChildContagemOcorrencia objects filtered by the usuario column
 * @method     ChildContagemOcorrencia[]|Collection findByCriadoEmMs(string|array<string> $criado_em_ms) Return ChildContagemOcorrencia objects filtered by the criado_em_ms column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByCriadoEmMs(string|array<string> $criado_em_ms) Return ChildContagemOcorrencia objects filtered by the criado_em_ms column
 * @method     ChildContagemOcorrencia[]|Collection findBySubstituiId(string|array<string> $substitui_id) Return ChildContagemOcorrencia objects filtered by the substitui_id column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findBySubstituiId(string|array<string> $substitui_id) Return ChildContagemOcorrencia objects filtered by the substitui_id column
 * @method     ChildContagemOcorrencia[]|Collection findByUuidCliente(string|array<string> $uuid_cliente) Return ChildContagemOcorrencia objects filtered by the uuid_cliente column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByUuidCliente(string|array<string> $uuid_cliente) Return ChildContagemOcorrencia objects filtered by the uuid_cliente column
 * @method     ChildContagemOcorrencia[]|Collection findByQualidadeTempo(string|array<string> $qualidade_tempo) Return ChildContagemOcorrencia objects filtered by the qualidade_tempo column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByQualidadeTempo(string|array<string> $qualidade_tempo) Return ChildContagemOcorrencia objects filtered by the qualidade_tempo column
 * @method     ChildContagemOcorrencia[]|Collection findByTsDispositivoMs(string|array<string> $ts_dispositivo_ms) Return ChildContagemOcorrencia objects filtered by the ts_dispositivo_ms column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByTsDispositivoMs(string|array<string> $ts_dispositivo_ms) Return ChildContagemOcorrencia objects filtered by the ts_dispositivo_ms column
 * @method     ChildContagemOcorrencia[]|Collection findByModoEntrada(string|array<string> $modo_entrada) Return ChildContagemOcorrencia objects filtered by the modo_entrada column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByModoEntrada(string|array<string> $modo_entrada) Return ChildContagemOcorrencia objects filtered by the modo_entrada column
 * @method     ChildContagemOcorrencia[]|Collection findByOrigem(string|array<string> $origem) Return ChildContagemOcorrencia objects filtered by the origem column
 * @psalm-method Collection&\Traversable<ChildContagemOcorrencia> findByOrigem(string|array<string> $origem) Return ChildContagemOcorrencia objects filtered by the origem column
 *
 * @method     ChildContagemOcorrencia[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildContagemOcorrencia> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ContagemOcorrenciaQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ContagemOcorrenciaQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\ContagemOcorrencia', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildContagemOcorrenciaQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildContagemOcorrenciaQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildContagemOcorrenciaQuery) {
            return $criteria;
        }
        $query = new ChildContagemOcorrenciaQuery();
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
     * @return ChildContagemOcorrencia|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ContagemOcorrenciaTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ContagemOcorrenciaTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildContagemOcorrencia A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT ocorrencia_id, evento_id, prova_id, tipo, equipe_numero, ts_ms, usuario, criado_em_ms, substitui_id, uuid_cliente, qualidade_tempo, ts_dispositivo_ms, modo_entrada, origem FROM contagem_ocorrencia WHERE ocorrencia_id = :p0';
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
            /** @var ChildContagemOcorrencia $obj */
            $obj = new ChildContagemOcorrencia();
            $obj->hydrate($row);
            ContagemOcorrenciaTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildContagemOcorrencia|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the ocorrencia_id column
     *
     * Example usage:
     * <code>
     * $query->filterByOcorrenciaId(1234); // WHERE ocorrencia_id = 1234
     * $query->filterByOcorrenciaId(array(12, 34)); // WHERE ocorrencia_id IN (12, 34)
     * $query->filterByOcorrenciaId(array('min' => 12)); // WHERE ocorrencia_id > 12
     * </code>
     *
     * @param mixed $ocorrenciaId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByOcorrenciaId($ocorrenciaId = null, ?string $comparison = null)
    {
        if (is_array($ocorrenciaId)) {
            $useMinMax = false;
            if (isset($ocorrenciaId['min'])) {
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID, $ocorrenciaId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($ocorrenciaId['max'])) {
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID, $ocorrenciaId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID, $ocorrenciaId, $comparison);

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

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_EVENTO_ID, $eventoId, $comparison);

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

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_PROVA_ID, $provaId, $comparison);

        return $this;
    }

    /**
     * Filter the query on the tipo column
     *
     * Example usage:
     * <code>
     * $query->filterByTipo('fooValue');   // WHERE tipo = 'fooValue'
     * $query->filterByTipo('%fooValue%', Criteria::LIKE); // WHERE tipo LIKE '%fooValue%'
     * $query->filterByTipo(['foo', 'bar']); // WHERE tipo IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $tipo The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByTipo($tipo = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($tipo)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_TIPO, $tipo, $comparison);

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
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_EQUIPE_NUMERO, $equipeNumero['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($equipeNumero['max'])) {
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_EQUIPE_NUMERO, $equipeNumero['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_EQUIPE_NUMERO, $equipeNumero, $comparison);

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
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_TS_MS, $tsMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tsMs['max'])) {
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_TS_MS, $tsMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_TS_MS, $tsMs, $comparison);

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

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_USUARIO, $usuario, $comparison);

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
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_CRIADO_EM_MS, $criadoEmMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($criadoEmMs['max'])) {
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_CRIADO_EM_MS, $criadoEmMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_CRIADO_EM_MS, $criadoEmMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the substitui_id column
     *
     * Example usage:
     * <code>
     * $query->filterBySubstituiId(1234); // WHERE substitui_id = 1234
     * $query->filterBySubstituiId(array(12, 34)); // WHERE substitui_id IN (12, 34)
     * $query->filterBySubstituiId(array('min' => 12)); // WHERE substitui_id > 12
     * </code>
     *
     * @param mixed $substituiId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterBySubstituiId($substituiId = null, ?string $comparison = null)
    {
        if (is_array($substituiId)) {
            $useMinMax = false;
            if (isset($substituiId['min'])) {
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_SUBSTITUI_ID, $substituiId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($substituiId['max'])) {
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_SUBSTITUI_ID, $substituiId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_SUBSTITUI_ID, $substituiId, $comparison);

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

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_UUID_CLIENTE, $uuidCliente, $comparison);

        return $this;
    }

    /**
     * Filter the query on the qualidade_tempo column
     *
     * Example usage:
     * <code>
     * $query->filterByQualidadeTempo('fooValue');   // WHERE qualidade_tempo = 'fooValue'
     * $query->filterByQualidadeTempo('%fooValue%', Criteria::LIKE); // WHERE qualidade_tempo LIKE '%fooValue%'
     * $query->filterByQualidadeTempo(['foo', 'bar']); // WHERE qualidade_tempo IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $qualidadeTempo The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByQualidadeTempo($qualidadeTempo = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($qualidadeTempo)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_QUALIDADE_TEMPO, $qualidadeTempo, $comparison);

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
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_TS_DISPOSITIVO_MS, $tsDispositivoMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tsDispositivoMs['max'])) {
                $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_TS_DISPOSITIVO_MS, $tsDispositivoMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_TS_DISPOSITIVO_MS, $tsDispositivoMs, $comparison);

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

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_MODO_ENTRADA, $modoEntrada, $comparison);

        return $this;
    }

    /**
     * Filter the query on the origem column
     *
     * Example usage:
     * <code>
     * $query->filterByOrigem('fooValue');   // WHERE origem = 'fooValue'
     * $query->filterByOrigem('%fooValue%', Criteria::LIKE); // WHERE origem LIKE '%fooValue%'
     * $query->filterByOrigem(['foo', 'bar']); // WHERE origem IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $origem The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByOrigem($origem = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($origem)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_ORIGEM, $origem, $comparison);

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
                ->addUsingAlias(ContagemOcorrenciaTableMap::COL_EVENTO_ID, $prova->getEventoId(), $comparison)
                ->addUsingAlias(ContagemOcorrenciaTableMap::COL_PROVA_ID, $prova->getProvaId(), $comparison);
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
     * @param ChildContagemOcorrencia $contagemOcorrencia Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($contagemOcorrencia = null)
    {
        if ($contagemOcorrencia) {
            $this->addUsingAlias(ContagemOcorrenciaTableMap::COL_OCORRENCIA_ID, $contagemOcorrencia->getOcorrenciaId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the contagem_ocorrencia table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemOcorrenciaTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ContagemOcorrenciaTableMap::clearInstancePool();
            ContagemOcorrenciaTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemOcorrenciaTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ContagemOcorrenciaTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ContagemOcorrenciaTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ContagemOcorrenciaTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
