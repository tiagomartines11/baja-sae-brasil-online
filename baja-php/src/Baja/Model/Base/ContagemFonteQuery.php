<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\ContagemFonte as ChildContagemFonte;
use Baja\Model\ContagemFonteQuery as ChildContagemFonteQuery;
use Baja\Model\Map\ContagemFonteTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the `contagem_fonte` table.
 *
 * @method     ChildContagemFonteQuery orderByFonteId($order = Criteria::ASC) Order by the fonte_id column
 * @method     ChildContagemFonteQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildContagemFonteQuery orderByProvaId($order = Criteria::ASC) Order by the prova_id column
 * @method     ChildContagemFonteQuery orderByNome($order = Criteria::ASC) Order by the nome column
 * @method     ChildContagemFonteQuery orderByTipo($order = Criteria::ASC) Order by the tipo column
 * @method     ChildContagemFonteQuery orderByQualidadeTempo($order = Criteria::ASC) Order by the qualidade_tempo column
 * @method     ChildContagemFonteQuery orderByAtiva($order = Criteria::ASC) Order by the ativa column
 * @method     ChildContagemFonteQuery orderByOrdem($order = Criteria::ASC) Order by the ordem column
 * @method     ChildContagemFonteQuery orderByDesativadaEmMs($order = Criteria::ASC) Order by the desativada_em_ms column
 *
 * @method     ChildContagemFonteQuery groupByFonteId() Group by the fonte_id column
 * @method     ChildContagemFonteQuery groupByEventoId() Group by the evento_id column
 * @method     ChildContagemFonteQuery groupByProvaId() Group by the prova_id column
 * @method     ChildContagemFonteQuery groupByNome() Group by the nome column
 * @method     ChildContagemFonteQuery groupByTipo() Group by the tipo column
 * @method     ChildContagemFonteQuery groupByQualidadeTempo() Group by the qualidade_tempo column
 * @method     ChildContagemFonteQuery groupByAtiva() Group by the ativa column
 * @method     ChildContagemFonteQuery groupByOrdem() Group by the ordem column
 * @method     ChildContagemFonteQuery groupByDesativadaEmMs() Group by the desativada_em_ms column
 *
 * @method     ChildContagemFonteQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildContagemFonteQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildContagemFonteQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildContagemFonteQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildContagemFonteQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildContagemFonteQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildContagemFonteQuery leftJoinProva($relationAlias = null) Adds a LEFT JOIN clause to the query using the Prova relation
 * @method     ChildContagemFonteQuery rightJoinProva($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Prova relation
 * @method     ChildContagemFonteQuery innerJoinProva($relationAlias = null) Adds a INNER JOIN clause to the query using the Prova relation
 *
 * @method     ChildContagemFonteQuery joinWithProva($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Prova relation
 *
 * @method     ChildContagemFonteQuery leftJoinWithProva() Adds a LEFT JOIN clause and with to the query using the Prova relation
 * @method     ChildContagemFonteQuery rightJoinWithProva() Adds a RIGHT JOIN clause and with to the query using the Prova relation
 * @method     ChildContagemFonteQuery innerJoinWithProva() Adds a INNER JOIN clause and with to the query using the Prova relation
 *
 * @method     ChildContagemFonteQuery leftJoinContagemFonteUsuario($relationAlias = null) Adds a LEFT JOIN clause to the query using the ContagemFonteUsuario relation
 * @method     ChildContagemFonteQuery rightJoinContagemFonteUsuario($relationAlias = null) Adds a RIGHT JOIN clause to the query using the ContagemFonteUsuario relation
 * @method     ChildContagemFonteQuery innerJoinContagemFonteUsuario($relationAlias = null) Adds a INNER JOIN clause to the query using the ContagemFonteUsuario relation
 *
 * @method     ChildContagemFonteQuery joinWithContagemFonteUsuario($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the ContagemFonteUsuario relation
 *
 * @method     ChildContagemFonteQuery leftJoinWithContagemFonteUsuario() Adds a LEFT JOIN clause and with to the query using the ContagemFonteUsuario relation
 * @method     ChildContagemFonteQuery rightJoinWithContagemFonteUsuario() Adds a RIGHT JOIN clause and with to the query using the ContagemFonteUsuario relation
 * @method     ChildContagemFonteQuery innerJoinWithContagemFonteUsuario() Adds a INNER JOIN clause and with to the query using the ContagemFonteUsuario relation
 *
 * @method     ChildContagemFonteQuery leftJoinContagemDispositivo($relationAlias = null) Adds a LEFT JOIN clause to the query using the ContagemDispositivo relation
 * @method     ChildContagemFonteQuery rightJoinContagemDispositivo($relationAlias = null) Adds a RIGHT JOIN clause to the query using the ContagemDispositivo relation
 * @method     ChildContagemFonteQuery innerJoinContagemDispositivo($relationAlias = null) Adds a INNER JOIN clause to the query using the ContagemDispositivo relation
 *
 * @method     ChildContagemFonteQuery joinWithContagemDispositivo($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the ContagemDispositivo relation
 *
 * @method     ChildContagemFonteQuery leftJoinWithContagemDispositivo() Adds a LEFT JOIN clause and with to the query using the ContagemDispositivo relation
 * @method     ChildContagemFonteQuery rightJoinWithContagemDispositivo() Adds a RIGHT JOIN clause and with to the query using the ContagemDispositivo relation
 * @method     ChildContagemFonteQuery innerJoinWithContagemDispositivo() Adds a INNER JOIN clause and with to the query using the ContagemDispositivo relation
 *
 * @method     ChildContagemFonteQuery leftJoinContagemPassagem($relationAlias = null) Adds a LEFT JOIN clause to the query using the ContagemPassagem relation
 * @method     ChildContagemFonteQuery rightJoinContagemPassagem($relationAlias = null) Adds a RIGHT JOIN clause to the query using the ContagemPassagem relation
 * @method     ChildContagemFonteQuery innerJoinContagemPassagem($relationAlias = null) Adds a INNER JOIN clause to the query using the ContagemPassagem relation
 *
 * @method     ChildContagemFonteQuery joinWithContagemPassagem($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the ContagemPassagem relation
 *
 * @method     ChildContagemFonteQuery leftJoinWithContagemPassagem() Adds a LEFT JOIN clause and with to the query using the ContagemPassagem relation
 * @method     ChildContagemFonteQuery rightJoinWithContagemPassagem() Adds a RIGHT JOIN clause and with to the query using the ContagemPassagem relation
 * @method     ChildContagemFonteQuery innerJoinWithContagemPassagem() Adds a INNER JOIN clause and with to the query using the ContagemPassagem relation
 *
 * @method     ChildContagemFonteQuery leftJoinContagemTotal($relationAlias = null) Adds a LEFT JOIN clause to the query using the ContagemTotal relation
 * @method     ChildContagemFonteQuery rightJoinContagemTotal($relationAlias = null) Adds a RIGHT JOIN clause to the query using the ContagemTotal relation
 * @method     ChildContagemFonteQuery innerJoinContagemTotal($relationAlias = null) Adds a INNER JOIN clause to the query using the ContagemTotal relation
 *
 * @method     ChildContagemFonteQuery joinWithContagemTotal($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the ContagemTotal relation
 *
 * @method     ChildContagemFonteQuery leftJoinWithContagemTotal() Adds a LEFT JOIN clause and with to the query using the ContagemTotal relation
 * @method     ChildContagemFonteQuery rightJoinWithContagemTotal() Adds a RIGHT JOIN clause and with to the query using the ContagemTotal relation
 * @method     ChildContagemFonteQuery innerJoinWithContagemTotal() Adds a INNER JOIN clause and with to the query using the ContagemTotal relation
 *
 * @method     \Baja\Model\ProvaQuery|\Baja\Model\ContagemFonteUsuarioQuery|\Baja\Model\ContagemDispositivoQuery|\Baja\Model\ContagemPassagemQuery|\Baja\Model\ContagemTotalQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildContagemFonte|null findOne(?ConnectionInterface $con = null) Return the first ChildContagemFonte matching the query
 * @method     ChildContagemFonte findOneOrCreate(?ConnectionInterface $con = null) Return the first ChildContagemFonte matching the query, or a new ChildContagemFonte object populated from the query conditions when no match is found
 *
 * @method     ChildContagemFonte|null findOneByFonteId(int $fonte_id) Return the first ChildContagemFonte filtered by the fonte_id column
 * @method     ChildContagemFonte|null findOneByEventoId(string $evento_id) Return the first ChildContagemFonte filtered by the evento_id column
 * @method     ChildContagemFonte|null findOneByProvaId(string $prova_id) Return the first ChildContagemFonte filtered by the prova_id column
 * @method     ChildContagemFonte|null findOneByNome(string $nome) Return the first ChildContagemFonte filtered by the nome column
 * @method     ChildContagemFonte|null findOneByTipo(string $tipo) Return the first ChildContagemFonte filtered by the tipo column
 * @method     ChildContagemFonte|null findOneByQualidadeTempo(string $qualidade_tempo) Return the first ChildContagemFonte filtered by the qualidade_tempo column
 * @method     ChildContagemFonte|null findOneByAtiva(boolean $ativa) Return the first ChildContagemFonte filtered by the ativa column
 * @method     ChildContagemFonte|null findOneByOrdem(int $ordem) Return the first ChildContagemFonte filtered by the ordem column
 * @method     ChildContagemFonte|null findOneByDesativadaEmMs(string $desativada_em_ms) Return the first ChildContagemFonte filtered by the desativada_em_ms column
 *
 * @method     ChildContagemFonte requirePk($key, ?ConnectionInterface $con = null) Return the ChildContagemFonte by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonte requireOne(?ConnectionInterface $con = null) Return the first ChildContagemFonte matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemFonte requireOneByFonteId(int $fonte_id) Return the first ChildContagemFonte filtered by the fonte_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonte requireOneByEventoId(string $evento_id) Return the first ChildContagemFonte filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonte requireOneByProvaId(string $prova_id) Return the first ChildContagemFonte filtered by the prova_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonte requireOneByNome(string $nome) Return the first ChildContagemFonte filtered by the nome column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonte requireOneByTipo(string $tipo) Return the first ChildContagemFonte filtered by the tipo column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonte requireOneByQualidadeTempo(string $qualidade_tempo) Return the first ChildContagemFonte filtered by the qualidade_tempo column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonte requireOneByAtiva(boolean $ativa) Return the first ChildContagemFonte filtered by the ativa column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonte requireOneByOrdem(int $ordem) Return the first ChildContagemFonte filtered by the ordem column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildContagemFonte requireOneByDesativadaEmMs(string $desativada_em_ms) Return the first ChildContagemFonte filtered by the desativada_em_ms column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildContagemFonte[]|Collection find(?ConnectionInterface $con = null) Return ChildContagemFonte objects based on current ModelCriteria
 * @psalm-method Collection&\Traversable<ChildContagemFonte> find(?ConnectionInterface $con = null) Return ChildContagemFonte objects based on current ModelCriteria
 *
 * @method     ChildContagemFonte[]|Collection findByFonteId(int|array<int> $fonte_id) Return ChildContagemFonte objects filtered by the fonte_id column
 * @psalm-method Collection&\Traversable<ChildContagemFonte> findByFonteId(int|array<int> $fonte_id) Return ChildContagemFonte objects filtered by the fonte_id column
 * @method     ChildContagemFonte[]|Collection findByEventoId(string|array<string> $evento_id) Return ChildContagemFonte objects filtered by the evento_id column
 * @psalm-method Collection&\Traversable<ChildContagemFonte> findByEventoId(string|array<string> $evento_id) Return ChildContagemFonte objects filtered by the evento_id column
 * @method     ChildContagemFonte[]|Collection findByProvaId(string|array<string> $prova_id) Return ChildContagemFonte objects filtered by the prova_id column
 * @psalm-method Collection&\Traversable<ChildContagemFonte> findByProvaId(string|array<string> $prova_id) Return ChildContagemFonte objects filtered by the prova_id column
 * @method     ChildContagemFonte[]|Collection findByNome(string|array<string> $nome) Return ChildContagemFonte objects filtered by the nome column
 * @psalm-method Collection&\Traversable<ChildContagemFonte> findByNome(string|array<string> $nome) Return ChildContagemFonte objects filtered by the nome column
 * @method     ChildContagemFonte[]|Collection findByTipo(string|array<string> $tipo) Return ChildContagemFonte objects filtered by the tipo column
 * @psalm-method Collection&\Traversable<ChildContagemFonte> findByTipo(string|array<string> $tipo) Return ChildContagemFonte objects filtered by the tipo column
 * @method     ChildContagemFonte[]|Collection findByQualidadeTempo(string|array<string> $qualidade_tempo) Return ChildContagemFonte objects filtered by the qualidade_tempo column
 * @psalm-method Collection&\Traversable<ChildContagemFonte> findByQualidadeTempo(string|array<string> $qualidade_tempo) Return ChildContagemFonte objects filtered by the qualidade_tempo column
 * @method     ChildContagemFonte[]|Collection findByAtiva(boolean|array<boolean> $ativa) Return ChildContagemFonte objects filtered by the ativa column
 * @psalm-method Collection&\Traversable<ChildContagemFonte> findByAtiva(boolean|array<boolean> $ativa) Return ChildContagemFonte objects filtered by the ativa column
 * @method     ChildContagemFonte[]|Collection findByOrdem(int|array<int> $ordem) Return ChildContagemFonte objects filtered by the ordem column
 * @psalm-method Collection&\Traversable<ChildContagemFonte> findByOrdem(int|array<int> $ordem) Return ChildContagemFonte objects filtered by the ordem column
 * @method     ChildContagemFonte[]|Collection findByDesativadaEmMs(string|array<string> $desativada_em_ms) Return ChildContagemFonte objects filtered by the desativada_em_ms column
 * @psalm-method Collection&\Traversable<ChildContagemFonte> findByDesativadaEmMs(string|array<string> $desativada_em_ms) Return ChildContagemFonte objects filtered by the desativada_em_ms column
 *
 * @method     ChildContagemFonte[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 * @psalm-method \Propel\Runtime\Util\PropelModelPager&\Traversable<ChildContagemFonte> paginate($page = 1, $maxPerPage = 10, ?ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 */
abstract class ContagemFonteQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ContagemFonteQuery object.
     *
     * @param string $dbName The database name
     * @param string $modelName The phpName of a model, e.g. 'Book'
     * @param string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\ContagemFonte', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildContagemFonteQuery object.
     *
     * @param string $modelAlias The alias of a model in the query
     * @param Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildContagemFonteQuery
     */
    public static function create(?string $modelAlias = null, ?Criteria $criteria = null): Criteria
    {
        if ($criteria instanceof ChildContagemFonteQuery) {
            return $criteria;
        }
        $query = new ChildContagemFonteQuery();
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
     * @return ChildContagemFonte|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ContagemFonteTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ContagemFonteTableMap::getInstanceFromPool(null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key)))) {
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
     * @return ChildContagemFonte A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT fonte_id, evento_id, prova_id, nome, tipo, qualidade_tempo, ativa, ordem, desativada_em_ms FROM contagem_fonte WHERE fonte_id = :p0';
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
            /** @var ChildContagemFonte $obj */
            $obj = new ChildContagemFonte();
            $obj->hydrate($row);
            ContagemFonteTableMap::addInstanceToPool($obj, null === $key || is_scalar($key) || is_callable([$key, '__toString']) ? (string) $key : $key);
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
     * @return ChildContagemFonte|array|mixed the result, formatted by the current formatter
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

        $this->addUsingAlias(ContagemFonteTableMap::COL_FONTE_ID, $key, Criteria::EQUAL);

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

        $this->addUsingAlias(ContagemFonteTableMap::COL_FONTE_ID, $keys, Criteria::IN);

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
                $this->addUsingAlias(ContagemFonteTableMap::COL_FONTE_ID, $fonteId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($fonteId['max'])) {
                $this->addUsingAlias(ContagemFonteTableMap::COL_FONTE_ID, $fonteId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemFonteTableMap::COL_FONTE_ID, $fonteId, $comparison);

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

        $this->addUsingAlias(ContagemFonteTableMap::COL_EVENTO_ID, $eventoId, $comparison);

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

        $this->addUsingAlias(ContagemFonteTableMap::COL_PROVA_ID, $provaId, $comparison);

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

        $this->addUsingAlias(ContagemFonteTableMap::COL_NOME, $nome, $comparison);

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

        $this->addUsingAlias(ContagemFonteTableMap::COL_TIPO, $tipo, $comparison);

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

        $this->addUsingAlias(ContagemFonteTableMap::COL_QUALIDADE_TEMPO, $qualidadeTempo, $comparison);

        return $this;
    }

    /**
     * Filter the query on the ativa column
     *
     * Example usage:
     * <code>
     * $query->filterByAtiva(true); // WHERE ativa = true
     * $query->filterByAtiva('yes'); // WHERE ativa = true
     * </code>
     *
     * @param bool|string $ativa The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByAtiva($ativa = null, ?string $comparison = null)
    {
        if (is_string($ativa)) {
            $ativa = in_array(strtolower($ativa), array('false', 'off', '-', 'no', 'n', '0', ''), true) ? false : true;
        }

        $this->addUsingAlias(ContagemFonteTableMap::COL_ATIVA, $ativa, $comparison);

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
                $this->addUsingAlias(ContagemFonteTableMap::COL_ORDEM, $ordem['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($ordem['max'])) {
                $this->addUsingAlias(ContagemFonteTableMap::COL_ORDEM, $ordem['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemFonteTableMap::COL_ORDEM, $ordem, $comparison);

        return $this;
    }

    /**
     * Filter the query on the desativada_em_ms column
     *
     * Example usage:
     * <code>
     * $query->filterByDesativadaEmMs(1234); // WHERE desativada_em_ms = 1234
     * $query->filterByDesativadaEmMs(array(12, 34)); // WHERE desativada_em_ms IN (12, 34)
     * $query->filterByDesativadaEmMs(array('min' => 12)); // WHERE desativada_em_ms > 12
     * </code>
     *
     * @param mixed $desativadaEmMs The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByDesativadaEmMs($desativadaEmMs = null, ?string $comparison = null)
    {
        if (is_array($desativadaEmMs)) {
            $useMinMax = false;
            if (isset($desativadaEmMs['min'])) {
                $this->addUsingAlias(ContagemFonteTableMap::COL_DESATIVADA_EM_MS, $desativadaEmMs['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($desativadaEmMs['max'])) {
                $this->addUsingAlias(ContagemFonteTableMap::COL_DESATIVADA_EM_MS, $desativadaEmMs['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        $this->addUsingAlias(ContagemFonteTableMap::COL_DESATIVADA_EM_MS, $desativadaEmMs, $comparison);

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
                ->addUsingAlias(ContagemFonteTableMap::COL_EVENTO_ID, $prova->getEventoId(), $comparison)
                ->addUsingAlias(ContagemFonteTableMap::COL_PROVA_ID, $prova->getProvaId(), $comparison);
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
     * Filter the query by a related \Baja\Model\ContagemFonteUsuario object
     *
     * @param \Baja\Model\ContagemFonteUsuario|ObjectCollection $contagemFonteUsuario the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByContagemFonteUsuario($contagemFonteUsuario, ?string $comparison = null)
    {
        if ($contagemFonteUsuario instanceof \Baja\Model\ContagemFonteUsuario) {
            $this
                ->addUsingAlias(ContagemFonteTableMap::COL_FONTE_ID, $contagemFonteUsuario->getFonteId(), $comparison);

            return $this;
        } elseif ($contagemFonteUsuario instanceof ObjectCollection) {
            $this
                ->useContagemFonteUsuarioQuery()
                ->filterByPrimaryKeys($contagemFonteUsuario->getPrimaryKeys())
                ->endUse();

            return $this;
        } else {
            throw new PropelException('filterByContagemFonteUsuario() only accepts arguments of type \Baja\Model\ContagemFonteUsuario or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the ContagemFonteUsuario relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinContagemFonteUsuario(?string $relationAlias = null, ?string $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('ContagemFonteUsuario');

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
            $this->addJoinObject($join, 'ContagemFonteUsuario');
        }

        return $this;
    }

    /**
     * Use the ContagemFonteUsuario relation ContagemFonteUsuario object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\ContagemFonteUsuarioQuery A secondary query class using the current class as primary query
     */
    public function useContagemFonteUsuarioQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinContagemFonteUsuario($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'ContagemFonteUsuario', '\Baja\Model\ContagemFonteUsuarioQuery');
    }

    /**
     * Use the ContagemFonteUsuario relation ContagemFonteUsuario object
     *
     * @param callable(\Baja\Model\ContagemFonteUsuarioQuery):\Baja\Model\ContagemFonteUsuarioQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withContagemFonteUsuarioQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::INNER_JOIN
    ) {
        $relatedQuery = $this->useContagemFonteUsuarioQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to ContagemFonteUsuario table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\ContagemFonteUsuarioQuery The inner query object of the EXISTS statement
     */
    public function useContagemFonteUsuarioExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\ContagemFonteUsuarioQuery */
        $q = $this->useExistsQuery('ContagemFonteUsuario', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to ContagemFonteUsuario table for a NOT EXISTS query.
     *
     * @see useContagemFonteUsuarioExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ContagemFonteUsuarioQuery The inner query object of the NOT EXISTS statement
     */
    public function useContagemFonteUsuarioNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ContagemFonteUsuarioQuery */
        $q = $this->useExistsQuery('ContagemFonteUsuario', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to ContagemFonteUsuario table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\ContagemFonteUsuarioQuery The inner query object of the IN statement
     */
    public function useInContagemFonteUsuarioQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\ContagemFonteUsuarioQuery */
        $q = $this->useInQuery('ContagemFonteUsuario', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to ContagemFonteUsuario table for a NOT IN query.
     *
     * @see useContagemFonteUsuarioInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ContagemFonteUsuarioQuery The inner query object of the NOT IN statement
     */
    public function useNotInContagemFonteUsuarioQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ContagemFonteUsuarioQuery */
        $q = $this->useInQuery('ContagemFonteUsuario', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Filter the query by a related \Baja\Model\ContagemDispositivo object
     *
     * @param \Baja\Model\ContagemDispositivo|ObjectCollection $contagemDispositivo the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByContagemDispositivo($contagemDispositivo, ?string $comparison = null)
    {
        if ($contagemDispositivo instanceof \Baja\Model\ContagemDispositivo) {
            $this
                ->addUsingAlias(ContagemFonteTableMap::COL_FONTE_ID, $contagemDispositivo->getFonteId(), $comparison);

            return $this;
        } elseif ($contagemDispositivo instanceof ObjectCollection) {
            $this
                ->useContagemDispositivoQuery()
                ->filterByPrimaryKeys($contagemDispositivo->getPrimaryKeys())
                ->endUse();

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
                ->addUsingAlias(ContagemFonteTableMap::COL_FONTE_ID, $contagemPassagem->getFonteId(), $comparison);

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
     * Filter the query by a related \Baja\Model\ContagemTotal object
     *
     * @param \Baja\Model\ContagemTotal|ObjectCollection $contagemTotal the related object to use as filter
     * @param string|null $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this The current query, for fluid interface
     */
    public function filterByContagemTotal($contagemTotal, ?string $comparison = null)
    {
        if ($contagemTotal instanceof \Baja\Model\ContagemTotal) {
            $this
                ->addUsingAlias(ContagemFonteTableMap::COL_FONTE_ID, $contagemTotal->getFonteId(), $comparison);

            return $this;
        } elseif ($contagemTotal instanceof ObjectCollection) {
            $this
                ->useContagemTotalQuery()
                ->filterByPrimaryKeys($contagemTotal->getPrimaryKeys())
                ->endUse();

            return $this;
        } else {
            throw new PropelException('filterByContagemTotal() only accepts arguments of type \Baja\Model\ContagemTotal or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the ContagemTotal relation
     *
     * @param string|null $relationAlias Optional alias for the relation
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this The current query, for fluid interface
     */
    public function joinContagemTotal(?string $relationAlias = null, ?string $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('ContagemTotal');

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
            $this->addJoinObject($join, 'ContagemTotal');
        }

        return $this;
    }

    /**
     * Use the ContagemTotal relation ContagemTotal object
     *
     * @see useQuery()
     *
     * @param string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\ContagemTotalQuery A secondary query class using the current class as primary query
     */
    public function useContagemTotalQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinContagemTotal($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'ContagemTotal', '\Baja\Model\ContagemTotalQuery');
    }

    /**
     * Use the ContagemTotal relation ContagemTotal object
     *
     * @param callable(\Baja\Model\ContagemTotalQuery):\Baja\Model\ContagemTotalQuery $callable A function working on the related query
     *
     * @param string|null $relationAlias optional alias for the relation
     *
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function withContagemTotalQuery(
        callable $callable,
        string $relationAlias = null,
        ?string $joinType = Criteria::INNER_JOIN
    ) {
        $relatedQuery = $this->useContagemTotalQuery(
            $relationAlias,
            $joinType
        );
        $callable($relatedQuery);
        $relatedQuery->endUse();

        return $this;
    }

    /**
     * Use the relation to ContagemTotal table for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return \Baja\Model\ContagemTotalQuery The inner query object of the EXISTS statement
     */
    public function useContagemTotalExistsQuery($modelAlias = null, $queryClass = null, $typeOfExists = 'EXISTS')
    {
        /** @var $q \Baja\Model\ContagemTotalQuery */
        $q = $this->useExistsQuery('ContagemTotal', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the relation to ContagemTotal table for a NOT EXISTS query.
     *
     * @see useContagemTotalExistsQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ContagemTotalQuery The inner query object of the NOT EXISTS statement
     */
    public function useContagemTotalNotExistsQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ContagemTotalQuery */
        $q = $this->useExistsQuery('ContagemTotal', $modelAlias, $queryClass, 'NOT EXISTS');
        return $q;
    }

    /**
     * Use the relation to ContagemTotal table for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return \Baja\Model\ContagemTotalQuery The inner query object of the IN statement
     */
    public function useInContagemTotalQuery($modelAlias = null, $queryClass = null, $typeOfIn = 'IN')
    {
        /** @var $q \Baja\Model\ContagemTotalQuery */
        $q = $this->useInQuery('ContagemTotal', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the relation to ContagemTotal table for a NOT IN query.
     *
     * @see useContagemTotalInQuery()
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param string|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return \Baja\Model\ContagemTotalQuery The inner query object of the NOT IN statement
     */
    public function useNotInContagemTotalQuery($modelAlias = null, $queryClass = null)
    {
        /** @var $q \Baja\Model\ContagemTotalQuery */
        $q = $this->useInQuery('ContagemTotal', $modelAlias, $queryClass, 'NOT IN');
        return $q;
    }

    /**
     * Exclude object from result
     *
     * @param ChildContagemFonte $contagemFonte Object to remove from the list of results
     *
     * @return $this The current query, for fluid interface
     */
    public function prune($contagemFonte = null)
    {
        if ($contagemFonte) {
            $this->addUsingAlias(ContagemFonteTableMap::COL_FONTE_ID, $contagemFonte->getFonteId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the contagem_fonte table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(?ConnectionInterface $con = null): int
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemFonteTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ContagemFonteTableMap::clearInstancePool();
            ContagemFonteTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ContagemFonteTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ContagemFonteTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ContagemFonteTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ContagemFonteTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

}
