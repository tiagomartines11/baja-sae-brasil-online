<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemDecisao as ChildContagemDecisao;
use Baja\Model\ContagemDecisaoQuery as ChildContagemDecisaoQuery;
use Baja\Model\Map\ContagemDecisaoTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `contagem_decisao` table.
 *
 * @method     ChildContagemDecisaoQuery orderByDecisaoId($order = Criteria::ASC) Order by the decisao_id column
 * @method     ChildContagemDecisaoQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildContagemDecisaoQuery orderByProvaId($order = Criteria::ASC) Order by the prova_id column
 * @method     ChildContagemDecisaoQuery orderByTipo($order = Criteria::ASC) Order by the tipo column
 * @method     ChildContagemDecisaoQuery orderByPassagemId($order = Criteria::ASC) Order by the passagem_id column
 * @method     ChildContagemDecisaoQuery orderByFonteId($order = Criteria::ASC) Order by the fonte_id column
 * @method     ChildContagemDecisaoQuery orderByEquipeNumero($order = Criteria::ASC) Order by the equipe_numero column
 * @method     ChildContagemDecisaoQuery orderByTsMs($order = Criteria::ASC) Order by the ts_ms column
 * @method     ChildContagemDecisaoQuery orderByTsFimMs($order = Criteria::ASC) Order by the ts_fim_ms column
 * @method     ChildContagemDecisaoQuery orderByDesfazDecisaoId($order = Criteria::ASC) Order by the desfaz_decisao_id column
 * @method     ChildContagemDecisaoQuery orderByOrigem($order = Criteria::ASC) Order by the origem column
 * @method     ChildContagemDecisaoQuery orderByUsuario($order = Criteria::ASC) Order by the usuario column
 * @method     ChildContagemDecisaoQuery orderByMotivo($order = Criteria::ASC) Order by the motivo column
 * @method     ChildContagemDecisaoQuery orderByCriadoEmMs($order = Criteria::ASC) Order by the criado_em_ms column
 *
 * @method     ChildContagemDecisaoQuery groupByDecisaoId() Group by the decisao_id column
 * @method     ChildContagemDecisaoQuery groupByEventoId() Group by the evento_id column
 * @method     ChildContagemDecisaoQuery groupByProvaId() Group by the prova_id column
 * @method     ChildContagemDecisaoQuery groupByTipo() Group by the tipo column
 * @method     ChildContagemDecisaoQuery groupByPassagemId() Group by the passagem_id column
 * @method     ChildContagemDecisaoQuery groupByFonteId() Group by the fonte_id column
 * @method     ChildContagemDecisaoQuery groupByEquipeNumero() Group by the equipe_numero column
 * @method     ChildContagemDecisaoQuery groupByTsMs() Group by the ts_ms column
 * @method     ChildContagemDecisaoQuery groupByTsFimMs() Group by the ts_fim_ms column
 * @method     ChildContagemDecisaoQuery groupByDesfazDecisaoId() Group by the desfaz_decisao_id column
 * @method     ChildContagemDecisaoQuery groupByOrigem() Group by the origem column
 * @method     ChildContagemDecisaoQuery groupByUsuario() Group by the usuario column
 * @method     ChildContagemDecisaoQuery groupByMotivo() Group by the motivo column
 * @method     ChildContagemDecisaoQuery groupByCriadoEmMs() Group by the criado_em_ms column
 *
 * @method     ChildContagemDecisaoQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildContagemDecisaoQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildContagemDecisaoQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildContagemDecisaoQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildContagemDecisaoQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildContagemDecisaoQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildContagemDecisaoQuery leftJoinProva($relationAlias = null) Adds a LEFT JOIN clause to the query using the Prova relation
 * @method     ChildContagemDecisaoQuery rightJoinProva($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Prova relation
 * @method     ChildContagemDecisaoQuery innerJoinProva($relationAlias = null) Adds a INNER JOIN clause to the query using the Prova relation
 *
 * @method     ChildContagemDecisaoQuery joinWithProva($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Prova relation
 *
 * @method     ChildContagemDecisaoQuery leftJoinWithProva() Adds a LEFT JOIN clause and with to the query using the Prova relation
 * @method     ChildContagemDecisaoQuery rightJoinWithProva() Adds a RIGHT JOIN clause and with to the query using the Prova relation
 * @method     ChildContagemDecisaoQuery innerJoinWithProva() Adds a INNER JOIN clause and with to the query using the Prova relation
 *
 * @method     \Baja\Model\ProvaQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildContagemDecisao|null findOne(?ConnectionInterface $con = null) Return the first ChildContagemDecisao matching the query
 * @method     ChildContagemDecisao findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildContagemDecisao matching the query, or a new ChildContagemDecisao object populated from the query conditions when no match is found
 *
 * @method     ChildContagemDecisao|null findOneByDecisaoId(string $decisao_id) Return the first ChildContagemDecisao filtered by the decisao_id column
 * @method     ChildContagemDecisao|null findOneByEventoId(string $evento_id) Return the first ChildContagemDecisao filtered by the evento_id column
 * @method     ChildContagemDecisao|null findOneByProvaId(string $prova_id) Return the first ChildContagemDecisao filtered by the prova_id column
 * @method     ChildContagemDecisao|null findOneByTipo(string $tipo) Return the first ChildContagemDecisao filtered by the tipo column
 * @method     ChildContagemDecisao|null findOneByPassagemId(string $passagem_id) Return the first ChildContagemDecisao filtered by the passagem_id column
 * @method     ChildContagemDecisao|null findOneByFonteId(int $fonte_id) Return the first ChildContagemDecisao filtered by the fonte_id column
 * @method     ChildContagemDecisao|null findOneByEquipeNumero(int $equipe_numero) Return the first ChildContagemDecisao filtered by the equipe_numero column
 * @method     ChildContagemDecisao|null findOneByTsMs(string $ts_ms) Return the first ChildContagemDecisao filtered by the ts_ms column
 * @method     ChildContagemDecisao|null findOneByTsFimMs(string $ts_fim_ms) Return the first ChildContagemDecisao filtered by the ts_fim_ms column
 * @method     ChildContagemDecisao|null findOneByDesfazDecisaoId(string $desfaz_decisao_id) Return the first ChildContagemDecisao filtered by the desfaz_decisao_id column
 * @method     ChildContagemDecisao|null findOneByOrigem(string $origem) Return the first ChildContagemDecisao filtered by the origem column
 * @method     ChildContagemDecisao|null findOneByUsuario(string $usuario) Return the first ChildContagemDecisao filtered by the usuario column
 * @method     ChildContagemDecisao|null findOneByMotivo(string $motivo) Return the first ChildContagemDecisao filtered by the motivo column
 * @method     ChildContagemDecisao|null findOneByCriadoEmMs(string $criado_em_ms) Return the first ChildContagemDecisao filtered by the criado_em_ms column
 *
 * @method     ChildContagemDecisao requirePk($key, ?ConnectionInterface $con = null) Return the ChildContagemDecisao by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOne(?ConnectionInterface $con = null) Return the first ChildContagemDecisao matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemDecisao requireOneByDecisaoId(string $decisao_id) Return the first ChildContagemDecisao filtered by the decisao_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByEventoId(string $evento_id) Return the first ChildContagemDecisao filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByProvaId(string $prova_id) Return the first ChildContagemDecisao filtered by the prova_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByTipo(string $tipo) Return the first ChildContagemDecisao filtered by the tipo column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByPassagemId(string $passagem_id) Return the first ChildContagemDecisao filtered by the passagem_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByFonteId(int $fonte_id) Return the first ChildContagemDecisao filtered by the fonte_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByEquipeNumero(int $equipe_numero) Return the first ChildContagemDecisao filtered by the equipe_numero column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByTsMs(string $ts_ms) Return the first ChildContagemDecisao filtered by the ts_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByTsFimMs(string $ts_fim_ms) Return the first ChildContagemDecisao filtered by the ts_fim_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByDesfazDecisaoId(string $desfaz_decisao_id) Return the first ChildContagemDecisao filtered by the desfaz_decisao_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByOrigem(string $origem) Return the first ChildContagemDecisao filtered by the origem column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByUsuario(string $usuario) Return the first ChildContagemDecisao filtered by the usuario column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByMotivo(string $motivo) Return the first ChildContagemDecisao filtered by the motivo column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemDecisao requireOneByCriadoEmMs(string $criado_em_ms) Return the first ChildContagemDecisao filtered by the criado_em_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemDecisao[]|Collection find(?ConnectionInterface $con = null) Return ChildContagemDecisao objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> find(?ConnectionInterface $con = null) Return ChildContagemDecisao objects based on current ModelCriteria
 *
 * @method     ChildContagemDecisao[]|Collection findByDecisaoId(string|array<string> $decisao_id) Return ChildContagemDecisao objects filtered by the decisao_id column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByDecisaoId(string|array<string> $decisao_id) Return ChildContagemDecisao objects filtered by the decisao_id column
 * @method     ChildContagemDecisao[]|Collection findByEventoId(string|array<string> $evento_id) Return ChildContagemDecisao objects filtered by the evento_id column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByEventoId(string|array<string> $evento_id) Return ChildContagemDecisao objects filtered by the evento_id column
 * @method     ChildContagemDecisao[]|Collection findByProvaId(string|array<string> $prova_id) Return ChildContagemDecisao objects filtered by the prova_id column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByProvaId(string|array<string> $prova_id) Return ChildContagemDecisao objects filtered by the prova_id column
 * @method     ChildContagemDecisao[]|Collection findByTipo(string|array<string> $tipo) Return ChildContagemDecisao objects filtered by the tipo column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByTipo(string|array<string> $tipo) Return ChildContagemDecisao objects filtered by the tipo column
 * @method     ChildContagemDecisao[]|Collection findByPassagemId(string|array<string> $passagem_id) Return ChildContagemDecisao objects filtered by the passagem_id column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByPassagemId(string|array<string> $passagem_id) Return ChildContagemDecisao objects filtered by the passagem_id column
 * @method     ChildContagemDecisao[]|Collection findByFonteId(int|array<int> $fonte_id) Return ChildContagemDecisao objects filtered by the fonte_id column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByFonteId(int|array<int> $fonte_id) Return ChildContagemDecisao objects filtered by the fonte_id column
 * @method     ChildContagemDecisao[]|Collection findByEquipeNumero(int|array<int> $equipe_numero) Return ChildContagemDecisao objects filtered by the equipe_numero column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByEquipeNumero(int|array<int> $equipe_numero) Return ChildContagemDecisao objects filtered by the equipe_numero column
 * @method     ChildContagemDecisao[]|Collection findByTsMs(string|array<string> $ts_ms) Return ChildContagemDecisao objects filtered by the ts_ms column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByTsMs(string|array<string> $ts_ms) Return ChildContagemDecisao objects filtered by the ts_ms column
 * @method     ChildContagemDecisao[]|Collection findByTsFimMs(string|array<string> $ts_fim_ms) Return ChildContagemDecisao objects filtered by the ts_fim_ms column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByTsFimMs(string|array<string> $ts_fim_ms) Return ChildContagemDecisao objects filtered by the ts_fim_ms column
 * @method     ChildContagemDecisao[]|Collection findByDesfazDecisaoId(string|array<string> $desfaz_decisao_id) Return ChildContagemDecisao objects filtered by the desfaz_decisao_id column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByDesfazDecisaoId(string|array<string> $desfaz_decisao_id) Return ChildContagemDecisao objects filtered by the desfaz_decisao_id column
 * @method     ChildContagemDecisao[]|Collection findByOrigem(string|array<string> $origem) Return ChildContagemDecisao objects filtered by the origem column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByOrigem(string|array<string> $origem) Return ChildContagemDecisao objects filtered by the origem column
 * @method     ChildContagemDecisao[]|Collection findByUsuario(string|array<string> $usuario) Return ChildContagemDecisao objects filtered by the usuario column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByUsuario(string|array<string> $usuario) Return ChildContagemDecisao objects filtered by the usuario column
 * @method     ChildContagemDecisao[]|Collection findByMotivo(string|array<string> $motivo) Return ChildContagemDecisao objects filtered by the motivo column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByMotivo(string|array<string> $motivo) Return ChildContagemDecisao objects filtered by the motivo column
 * @method     ChildContagemDecisao[]|Collection findByCriadoEmMs(string|array<string> $criado_em_ms) Return ChildContagemDecisao objects filtered by the criado_em_ms column
 * @psalm-method Collection&\Traversable<ChildContagemDecisao> findByCriadoEmMs(string|array<string> $criado_em_ms) Return ChildContagemDecisao objects filtered by the criado_em_ms column
 *
 * @method     ChildContagemDecisao[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildContagemDecisao> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ContagemDecisaoQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ContagemDecisaoQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\ContagemDecisao', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildContagemDecisaoQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildContagemDecisaoQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildContagemDecisaoQuery) {
            return $criteria;
        }
        $query = new ChildContagemDecisaoQuery();
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
     * @return ChildContagemDecisao|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ContagemDecisaoTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ContagemDecisaoTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildContagemDecisao A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT decisao_id, evento_id, prova_id, tipo, passagem_id, fonte_id, equipe_numero, ts_ms, ts_fim_ms, desfaz_decisao_id, origem, usuario, motivo, criado_em_ms FROM contagem_decisao WHERE decisao_id = :p0';
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
            /** @var ChildContagemDecisao $obj */
            $obj = new ChildContagemDecisao();
            $obj->hydrate($row);
            ContagemDecisaoTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildContagemDecisao|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_DECISAO_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_DECISAO_ID, $keys, Criteria::IN);

        return $this;
    }

    /**
     * Filter the query on the decisao_id column
     *
     * Example usage:
     * <code>
     * $query->filterByDecisaoId(1234); // WHERE decisao_id = 1234
     * $query->filterByDecisaoId(array(12, 34)); // WHERE decisao_id IN (12, 34)
     * $query->filterByDecisaoId(array('min' => 12)); // WHERE decisao_id > 12
     * </code>
     *
     * @param mixed $decisaoId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDecisaoId($decisaoId = null, ?string $comparison = null)
    {
        if (is_array($decisaoId)) {
            $useMinMax = false;
            if (isset($decisaoId['min'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_DECISAO_ID, $decisaoId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($decisaoId['max'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_DECISAO_ID, $decisaoId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_DECISAO_ID, $decisaoId, $comparison);

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

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_EVENTO_ID, $eventoId, $comparison);

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

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_PROVA_ID, $provaId, $comparison);

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

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_TIPO, $tipo, $comparison);

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
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_PASSAGEM_ID, $passagemId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($passagemId['max'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_PASSAGEM_ID, $passagemId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_PASSAGEM_ID, $passagemId, $comparison);

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
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_FONTE_ID, $fonteId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($fonteId['max'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_FONTE_ID, $fonteId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_FONTE_ID, $fonteId, $comparison);

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
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_EQUIPE_NUMERO, $equipeNumero['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($equipeNumero['max'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_EQUIPE_NUMERO, $equipeNumero['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_EQUIPE_NUMERO, $equipeNumero, $comparison);

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
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_TS_MS, $tsMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tsMs['max'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_TS_MS, $tsMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_TS_MS, $tsMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the ts_fim_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByTsFimMs(1234); // WHERE ts_fim_ms = 1234
     * $query->filterByTsFimMs(array(12, 34)); // WHERE ts_fim_ms IN (12, 34)
     * $query->filterByTsFimMs(array('min' => 12)); // WHERE ts_fim_ms > 12
     * </code>
     *
     * @param mixed $tsFimMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByTsFimMs($tsFimMs = null, ?string $comparison = null)
    {
        if (is_array($tsFimMs)) {
            $useMinMax = false;
            if (isset($tsFimMs['min'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_TS_FIM_MS, $tsFimMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tsFimMs['max'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_TS_FIM_MS, $tsFimMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_TS_FIM_MS, $tsFimMs, $comparison);

        return $this;
    }

    /**
     * Filter the query on the desfaz_decisao_id column
     *
     * Example usage:
     * <code>
     * $query->filterByDesfazDecisaoId(1234); // WHERE desfaz_decisao_id = 1234
     * $query->filterByDesfazDecisaoId(array(12, 34)); // WHERE desfaz_decisao_id IN (12, 34)
     * $query->filterByDesfazDecisaoId(array('min' => 12)); // WHERE desfaz_decisao_id > 12
     * </code>
     *
     * @param mixed $desfazDecisaoId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDesfazDecisaoId($desfazDecisaoId = null, ?string $comparison = null)
    {
        if (is_array($desfazDecisaoId)) {
            $useMinMax = false;
            if (isset($desfazDecisaoId['min'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_DESFAZ_DECISAO_ID, $desfazDecisaoId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($desfazDecisaoId['max'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_DESFAZ_DECISAO_ID, $desfazDecisaoId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_DESFAZ_DECISAO_ID, $desfazDecisaoId, $comparison);

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

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_ORIGEM, $origem, $comparison);

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

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_USUARIO, $usuario, $comparison);

        return $this;
    }

    /**
     * Filter the query on the motivo column
     *
     * Example usage:
     * <code>
     * $query->filterByMotivo('fooValue');   // WHERE motivo = 'fooValue'
     * $query->filterByMotivo('%fooValue%', Criteria::LIKE); // WHERE motivo LIKE '%fooValue%'
     * $query->filterByMotivo(['foo', 'bar']); // WHERE motivo IN ('foo', 'bar')
     * </code>
     *
     * @param string|string[] $motivo The value to use as filter.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByMotivo($motivo = null, ?string $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($motivo)) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_MOTIVO, $motivo, $comparison);

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
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_CRIADO_EM_MS, $criadoEmMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($criadoEmMs['max'])) {
                $this->addUsingAlias(ContagemDecisaoTableMap::COL_CRIADO_EM_MS, $criadoEmMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemDecisaoTableMap::COL_CRIADO_EM_MS, $criadoEmMs, $comparison);

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
                ->addUsingAlias(ContagemDecisaoTableMap::COL_EVENTO_ID, $prova->getEventoId(), $comparison)
                ->addUsingAlias(ContagemDecisaoTableMap::COL_PROVA_ID, $prova->getProvaId(), $comparison);
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
     * @param ChildContagemDecisao $contagemDecisao Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($contagemDecisao = null)
    {
        if ($contagemDecisao) {
            $this->addUsingAlias(ContagemDecisaoTableMap::COL_DECISAO_ID, $contagemDecisao->getDecisaoId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the contagem_decisao table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemDecisaoTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ContagemDecisaoTableMap::clearInstancePool();
            ContagemDecisaoTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemDecisaoTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ContagemDecisaoTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ContagemDecisaoTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ContagemDecisaoTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
