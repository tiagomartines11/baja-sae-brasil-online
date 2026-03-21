<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Senha as ChildSenha;
use Baja\Model\SenhaQuery as ChildSenhaQuery;
use Baja\Model\Map\SenhaTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the 'senha' table.
 *
 *
 *
 * @method     ChildSenhaQuery orderByEventoId($order = Criteria::ASC) Order by the evento_id column
 * @method     ChildSenhaQuery orderByFilaId($order = Criteria::ASC) Order by the fila_id column
 * @method     ChildSenhaQuery orderBySenha($order = Criteria::ASC) Order by the senha column
 * @method     ChildSenhaQuery orderByEquipeId($order = Criteria::ASC) Order by the equipe_id column
 * @method     ChildSenhaQuery orderByStatus($order = Criteria::ASC) Order by the status column
 * @method     ChildSenhaQuery orderByTsRequisicao($order = Criteria::ASC) Order by the ts_requisicao column
 * @method     ChildSenhaQuery orderByTsStatus($order = Criteria::ASC) Order by the ts_status column
 * @method     ChildSenhaQuery orderByDetalhes($order = Criteria::ASC) Order by the detalhes column
 *
 * @method     ChildSenhaQuery groupByEventoId() Group by the evento_id column
 * @method     ChildSenhaQuery groupByFilaId() Group by the fila_id column
 * @method     ChildSenhaQuery groupBySenha() Group by the senha column
 * @method     ChildSenhaQuery groupByEquipeId() Group by the equipe_id column
 * @method     ChildSenhaQuery groupByStatus() Group by the status column
 * @method     ChildSenhaQuery groupByTsRequisicao() Group by the ts_requisicao column
 * @method     ChildSenhaQuery groupByTsStatus() Group by the ts_status column
 * @method     ChildSenhaQuery groupByDetalhes() Group by the detalhes column
 *
 * @method     ChildSenhaQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildSenhaQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildSenhaQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildSenhaQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildSenhaQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildSenhaQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildSenhaQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildSenhaQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildSenhaQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildSenhaQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildSenhaQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildSenhaQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildSenhaQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     ChildSenhaQuery leftJoinEquipe($relationAlias = null) Adds a LEFT JOIN clause to the query using the Equipe relation
 * @method     ChildSenhaQuery rightJoinEquipe($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Equipe relation
 * @method     ChildSenhaQuery innerJoinEquipe($relationAlias = null) Adds a INNER JOIN clause to the query using the Equipe relation
 *
 * @method     ChildSenhaQuery joinWithEquipe($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Equipe relation
 *
 * @method     ChildSenhaQuery leftJoinWithEquipe() Adds a LEFT JOIN clause and with to the query using the Equipe relation
 * @method     ChildSenhaQuery rightJoinWithEquipe() Adds a RIGHT JOIN clause and with to the query using the Equipe relation
 * @method     ChildSenhaQuery innerJoinWithEquipe() Adds a INNER JOIN clause and with to the query using the Equipe relation
 *
 * @method     \Baja\Model\EventoQuery|\Baja\Model\EquipeQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildSenha findOne(ConnectionInterface $con = null) Return the first ChildSenha matching the query
 * @method     ChildSenha findOneOrCreate(ConnectionInterface $con = null) Return the first ChildSenha matching the query, or a new ChildSenha object populated from the query conditions when no match is found
 *
 * @method     ChildSenha findOneByEventoId(string $evento_id) Return the first ChildSenha filtered by the evento_id column
 * @method     ChildSenha findOneByFilaId(int $fila_id) Return the first ChildSenha filtered by the fila_id column
 * @method     ChildSenha findOneBySenha(int $senha) Return the first ChildSenha filtered by the senha column
 * @method     ChildSenha findOneByEquipeId(int $equipe_id) Return the first ChildSenha filtered by the equipe_id column
 * @method     ChildSenha findOneByStatus(int $status) Return the first ChildSenha filtered by the status column
 * @method     ChildSenha findOneByTsRequisicao(string $ts_requisicao) Return the first ChildSenha filtered by the ts_requisicao column
 * @method     ChildSenha findOneByTsStatus(string $ts_status) Return the first ChildSenha filtered by the ts_status column
 * @method     ChildSenha findOneByDetalhes(string $detalhes) Return the first ChildSenha filtered by the detalhes column *

 * @method     ChildSenha requirePk($key, ConnectionInterface $con = null) Return the ChildSenha by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildSenha requireOne(ConnectionInterface $con = null) Return the first ChildSenha matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildSenha requireOneByEventoId(string $evento_id) Return the first ChildSenha filtered by the evento_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildSenha requireOneByFilaId(int $fila_id) Return the first ChildSenha filtered by the fila_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildSenha requireOneBySenha(int $senha) Return the first ChildSenha filtered by the senha column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildSenha requireOneByEquipeId(int $equipe_id) Return the first ChildSenha filtered by the equipe_id column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildSenha requireOneByStatus(int $status) Return the first ChildSenha filtered by the status column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildSenha requireOneByTsRequisicao(string $ts_requisicao) Return the first ChildSenha filtered by the ts_requisicao column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildSenha requireOneByTsStatus(string $ts_status) Return the first ChildSenha filtered by the ts_status column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildSenha requireOneByDetalhes(string $detalhes) Return the first ChildSenha filtered by the detalhes column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildSenha[]|ObjectCollection find(ConnectionInterface $con = null) Return ChildSenha objects based on current ModelCriteria
 * @method     ChildSenha[]|ObjectCollection findByEventoId(string $evento_id) Return ChildSenha objects filtered by the evento_id column
 * @method     ChildSenha[]|ObjectCollection findByFilaId(int $fila_id) Return ChildSenha objects filtered by the fila_id column
 * @method     ChildSenha[]|ObjectCollection findBySenha(int $senha) Return ChildSenha objects filtered by the senha column
 * @method     ChildSenha[]|ObjectCollection findByEquipeId(int $equipe_id) Return ChildSenha objects filtered by the equipe_id column
 * @method     ChildSenha[]|ObjectCollection findByStatus(int $status) Return ChildSenha objects filtered by the status column
 * @method     ChildSenha[]|ObjectCollection findByTsRequisicao(string $ts_requisicao) Return ChildSenha objects filtered by the ts_requisicao column
 * @method     ChildSenha[]|ObjectCollection findByTsStatus(string $ts_status) Return ChildSenha objects filtered by the ts_status column
 * @method     ChildSenha[]|ObjectCollection findByDetalhes(string $detalhes) Return ChildSenha objects filtered by the detalhes column
 * @method     ChildSenha[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 *
 */
abstract class SenhaQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\SenhaQuery object.
     *
     * @param     string $dbName The database name
     * @param     string $modelName The phpName of a model, e.g. 'Book'
     * @param     string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Senha', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildSenhaQuery object.
     *
     * @param     string $modelAlias The alias of a model in the query
     * @param     Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildSenhaQuery
     */
    public static function create($modelAlias = null, Criteria $criteria = null)
    {
        if ($criteria instanceof ChildSenhaQuery) {
            return $criteria;
        }
        $query = new ChildSenhaQuery();
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
     * @param array[$evento_id, $fila_id, $senha, $equipe_id] $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildSenha|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(SenhaTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = SenhaTableMap::getInstanceFromPool(serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1]), (null === $key[2] || is_scalar($key[2]) || is_callable([$key[2], '__toString']) ? (string) $key[2] : $key[2]), (null === $key[3] || is_scalar($key[3]) || is_callable([$key[3], '__toString']) ? (string) $key[3] : $key[3])]))))) {
            // the object is already in the instance pool
            return $obj;
        }

        return $this->findPkSimple($key, $con);
    }

    /**
     * Find object by primary key using raw SQL to go fast.
     * Bypass doSelect() and the object formatter by using generated code.
     *
     * @param     mixed $key Primary key to use for the query
     * @param     ConnectionInterface $con A connection object
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return ChildSenha A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT evento_id, fila_id, senha, equipe_id, status, ts_requisicao, ts_status, detalhes FROM senha WHERE evento_id = :p0 AND fila_id = :p1 AND senha = :p2 AND equipe_id = :p3';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key[0], PDO::PARAM_STR);
            $stmt->bindValue(':p1', $key[1], PDO::PARAM_INT);
            $stmt->bindValue(':p2', $key[2], PDO::PARAM_INT);
            $stmt->bindValue(':p3', $key[3], PDO::PARAM_INT);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            /** @var ChildSenha $obj */
            $obj = new ChildSenha();
            $obj->hydrate($row);
            SenhaTableMap::addInstanceToPool($obj, serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1]), (null === $key[2] || is_scalar($key[2]) || is_callable([$key[2], '__toString']) ? (string) $key[2] : $key[2]), (null === $key[3] || is_scalar($key[3]) || is_callable([$key[3], '__toString']) ? (string) $key[3] : $key[3])]));
        }
        $stmt->closeCursor();

        return $obj;
    }

    /**
     * Find object by primary key.
     *
     * @param     mixed $key Primary key to use for the query
     * @param     ConnectionInterface $con A connection object
     *
     * @return ChildSenha|array|mixed the result, formatted by the current formatter
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
     * @param     array $keys Primary keys to use for the query
     * @param     ConnectionInterface $con an optional connection object
     *
     * @return ObjectCollection|array|mixed the list of results, formatted by the current formatter
     */
    public function findPks($keys, ConnectionInterface $con = null)
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
     * @param     mixed $key Primary key to use for the query
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByPrimaryKey($key)
    {
        $this->addUsingAlias(SenhaTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
        $this->addUsingAlias(SenhaTableMap::COL_FILA_ID, $key[1], Criteria::EQUAL);
        $this->addUsingAlias(SenhaTableMap::COL_SENHA, $key[2], Criteria::EQUAL);
        $this->addUsingAlias(SenhaTableMap::COL_EQUIPE_ID, $key[3], Criteria::EQUAL);

        return $this;
    }

    /**
     * Filter the query by a list of primary keys
     *
     * @param     array $keys The list of primary key to use for the query
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByPrimaryKeys($keys)
    {
        if (empty($keys)) {
            return $this->add(null, '1<>1', Criteria::CUSTOM);
        }
        foreach ($keys as $key) {
            $cton0 = $this->getNewCriterion(SenhaTableMap::COL_EVENTO_ID, $key[0], Criteria::EQUAL);
            $cton1 = $this->getNewCriterion(SenhaTableMap::COL_FILA_ID, $key[1], Criteria::EQUAL);
            $cton0->addAnd($cton1);
            $cton2 = $this->getNewCriterion(SenhaTableMap::COL_SENHA, $key[2], Criteria::EQUAL);
            $cton0->addAnd($cton2);
            $cton3 = $this->getNewCriterion(SenhaTableMap::COL_EQUIPE_ID, $key[3], Criteria::EQUAL);
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
     * </code>
     *
     * @param     string $eventoId The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByEventoId($eventoId = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($eventoId)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(SenhaTableMap::COL_EVENTO_ID, $eventoId, $comparison);
    }

    /**
     * Filter the query on the fila_id column
     *
     * Example usage:
     * <code>
     * $query->filterByFilaId(1234); // WHERE fila_id = 1234
     * $query->filterByFilaId(array(12, 34)); // WHERE fila_id IN (12, 34)
     * $query->filterByFilaId(array('min' => 12)); // WHERE fila_id > 12
     * </code>
     *
     * @param     mixed $filaId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByFilaId($filaId = null, $comparison = null)
    {
        if (is_array($filaId)) {
            $useMinMax = false;
            if (isset($filaId['min'])) {
                $this->addUsingAlias(SenhaTableMap::COL_FILA_ID, $filaId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($filaId['max'])) {
                $this->addUsingAlias(SenhaTableMap::COL_FILA_ID, $filaId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(SenhaTableMap::COL_FILA_ID, $filaId, $comparison);
    }

    /**
     * Filter the query on the senha column
     *
     * Example usage:
     * <code>
     * $query->filterBySenha(1234); // WHERE senha = 1234
     * $query->filterBySenha(array(12, 34)); // WHERE senha IN (12, 34)
     * $query->filterBySenha(array('min' => 12)); // WHERE senha > 12
     * </code>
     *
     * @param     mixed $senha The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function filterBySenha($senha = null, $comparison = null)
    {
        if (is_array($senha)) {
            $useMinMax = false;
            if (isset($senha['min'])) {
                $this->addUsingAlias(SenhaTableMap::COL_SENHA, $senha['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($senha['max'])) {
                $this->addUsingAlias(SenhaTableMap::COL_SENHA, $senha['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(SenhaTableMap::COL_SENHA, $senha, $comparison);
    }

    /**
     * Filter the query on the equipe_id column
     *
     * Example usage:
     * <code>
     * $query->filterByEquipeId(1234); // WHERE equipe_id = 1234
     * $query->filterByEquipeId(array(12, 34)); // WHERE equipe_id IN (12, 34)
     * $query->filterByEquipeId(array('min' => 12)); // WHERE equipe_id > 12
     * </code>
     *
     * @see       filterByEquipe()
     *
     * @param     mixed $equipeId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByEquipeId($equipeId = null, $comparison = null)
    {
        if (is_array($equipeId)) {
            $useMinMax = false;
            if (isset($equipeId['min'])) {
                $this->addUsingAlias(SenhaTableMap::COL_EQUIPE_ID, $equipeId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($equipeId['max'])) {
                $this->addUsingAlias(SenhaTableMap::COL_EQUIPE_ID, $equipeId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(SenhaTableMap::COL_EQUIPE_ID, $equipeId, $comparison);
    }

    /**
     * Filter the query on the status column
     *
     * Example usage:
     * <code>
     * $query->filterByStatus(1234); // WHERE status = 1234
     * $query->filterByStatus(array(12, 34)); // WHERE status IN (12, 34)
     * $query->filterByStatus(array('min' => 12)); // WHERE status > 12
     * </code>
     *
     * @param     mixed $status The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByStatus($status = null, $comparison = null)
    {
        if (is_array($status)) {
            $useMinMax = false;
            if (isset($status['min'])) {
                $this->addUsingAlias(SenhaTableMap::COL_STATUS, $status['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($status['max'])) {
                $this->addUsingAlias(SenhaTableMap::COL_STATUS, $status['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(SenhaTableMap::COL_STATUS, $status, $comparison);
    }

    /**
     * Filter the query on the ts_requisicao column
     *
     * Example usage:
     * <code>
     * $query->filterByTsRequisicao(1234); // WHERE ts_requisicao = 1234
     * $query->filterByTsRequisicao(array(12, 34)); // WHERE ts_requisicao IN (12, 34)
     * $query->filterByTsRequisicao(array('min' => 12)); // WHERE ts_requisicao > 12
     * </code>
     *
     * @param     mixed $tsRequisicao The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByTsRequisicao($tsRequisicao = null, $comparison = null)
    {
        if (is_array($tsRequisicao)) {
            $useMinMax = false;
            if (isset($tsRequisicao['min'])) {
                $this->addUsingAlias(SenhaTableMap::COL_TS_REQUISICAO, $tsRequisicao['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tsRequisicao['max'])) {
                $this->addUsingAlias(SenhaTableMap::COL_TS_REQUISICAO, $tsRequisicao['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(SenhaTableMap::COL_TS_REQUISICAO, $tsRequisicao, $comparison);
    }

    /**
     * Filter the query on the ts_status column
     *
     * Example usage:
     * <code>
     * $query->filterByTsStatus(1234); // WHERE ts_status = 1234
     * $query->filterByTsStatus(array(12, 34)); // WHERE ts_status IN (12, 34)
     * $query->filterByTsStatus(array('min' => 12)); // WHERE ts_status > 12
     * </code>
     *
     * @param     mixed $tsStatus The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByTsStatus($tsStatus = null, $comparison = null)
    {
        if (is_array($tsStatus)) {
            $useMinMax = false;
            if (isset($tsStatus['min'])) {
                $this->addUsingAlias(SenhaTableMap::COL_TS_STATUS, $tsStatus['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($tsStatus['max'])) {
                $this->addUsingAlias(SenhaTableMap::COL_TS_STATUS, $tsStatus['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(SenhaTableMap::COL_TS_STATUS, $tsStatus, $comparison);
    }

    /**
     * Filter the query on the detalhes column
     *
     * Example usage:
     * <code>
     * $query->filterByDetalhes('fooValue');   // WHERE detalhes = 'fooValue'
     * $query->filterByDetalhes('%fooValue%', Criteria::LIKE); // WHERE detalhes LIKE '%fooValue%'
     * </code>
     *
     * @param     string $detalhes The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByDetalhes($detalhes = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($detalhes)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(SenhaTableMap::COL_DETALHES, $detalhes, $comparison);
    }

    /**
     * Filter the query by a related \Baja\Model\Evento object
     *
     * @param \Baja\Model\Evento|ObjectCollection $evento The related object(s) to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByEvento($evento, $comparison = null)
    {
        if ($evento instanceof \Baja\Model\Evento) {
            return $this
                ->addUsingAlias(SenhaTableMap::COL_EVENTO_ID, $evento->getEventoId(), $comparison);
        } elseif ($evento instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            return $this
                ->addUsingAlias(SenhaTableMap::COL_EVENTO_ID, $evento->toKeyValue('PrimaryKey', 'EventoId'), $comparison);
        } else {
            throw new PropelException('filterByEvento() only accepts arguments of type \Baja\Model\Evento or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Evento relation
     *
     * @param     string $relationAlias optional alias for the relation
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function joinEvento($relationAlias = null, $joinType = Criteria::INNER_JOIN)
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
     * @param     string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
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
     * Filter the query by a related \Baja\Model\Equipe object
     *
     * @param \Baja\Model\Equipe $equipe The related object to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return ChildSenhaQuery The current query, for fluid interface
     */
    public function filterByEquipe($equipe, $comparison = null)
    {
        if ($equipe instanceof \Baja\Model\Equipe) {
            return $this
                ->addUsingAlias(SenhaTableMap::COL_EQUIPE_ID, $equipe->getEquipeId(), $comparison)
                ->addUsingAlias(SenhaTableMap::COL_EVENTO_ID, $equipe->getEventoId(), $comparison);
        } else {
            throw new PropelException('filterByEquipe() only accepts arguments of type \Baja\Model\Equipe');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Equipe relation
     *
     * @param     string $relationAlias optional alias for the relation
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function joinEquipe($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Equipe');

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
            $this->addJoinObject($join, 'Equipe');
        }

        return $this;
    }

    /**
     * Use the Equipe relation Equipe object
     *
     * @see useQuery()
     *
     * @param     string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return \Baja\Model\EquipeQuery A secondary query class using the current class as primary query
     */
    public function useEquipeQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinEquipe($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Equipe', '\Baja\Model\EquipeQuery');
    }

    /**
     * Exclude object from result
     *
     * @param   ChildSenha $senha Object to remove from the list of results
     *
     * @return $this|ChildSenhaQuery The current query, for fluid interface
     */
    public function prune($senha = null)
    {
        if ($senha) {
            $this->addCond('pruneCond0', $this->getAliasedColName(SenhaTableMap::COL_EVENTO_ID), $senha->getEventoId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond1', $this->getAliasedColName(SenhaTableMap::COL_FILA_ID), $senha->getFilaId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond2', $this->getAliasedColName(SenhaTableMap::COL_SENHA), $senha->getSenha(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond3', $this->getAliasedColName(SenhaTableMap::COL_EQUIPE_ID), $senha->getEquipeId(), Criteria::NOT_EQUAL);
            $this->combine(array('pruneCond0', 'pruneCond1', 'pruneCond2', 'pruneCond3'), Criteria::LOGICAL_OR);
        }

        return $this;
    }

    /**
     * Deletes all rows from the senha table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(SenhaTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            SenhaTableMap::clearInstancePool();
            SenhaTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

    /**
     * Performs a DELETE on the database based on the current ModelCriteria
     *
     * @param ConnectionInterface $con the connection to use
     * @return int             The number of affected rows (if supported by underlying database driver).  This includes CASCADE-related rows
     *                         if supported by native driver or if emulated using Propel.
     * @throws PropelException Any exceptions caught during processing will be
     *                         rethrown wrapped into a PropelException.
     */
    public function delete(ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(SenhaTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(SenhaTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            SenhaTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            SenhaTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

} // SenhaQuery
