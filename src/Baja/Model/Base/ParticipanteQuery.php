<?php

namespace Baja\Model\Base;

use \Exception;
use \PDO;
use Baja\Model\Participante as ChildParticipante;
use Baja\Model\ParticipanteQuery as ChildParticipanteQuery;
use Baja\Model\Map\ParticipanteTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;

/**
 * Base class that represents a query for the 'participantes' table.
 *
 *
 *
 * @method     ChildParticipanteQuery orderByParticipanteId($order = Criteria::ASC) Order by the idparticipantes column
 * @method     ChildParticipanteQuery orderByNome($order = Criteria::ASC) Order by the nome column
 * @method     ChildParticipanteQuery orderByFuncao($order = Criteria::ASC) Order by the funcao column
 * @method     ChildParticipanteQuery orderByCpf($order = Criteria::ASC) Order by the cpf column
 * @method     ChildParticipanteQuery orderByEventoId($order = Criteria::ASC) Order by the evento column
 *
 * @method     ChildParticipanteQuery groupByParticipanteId() Group by the idparticipantes column
 * @method     ChildParticipanteQuery groupByNome() Group by the nome column
 * @method     ChildParticipanteQuery groupByFuncao() Group by the funcao column
 * @method     ChildParticipanteQuery groupByCpf() Group by the cpf column
 * @method     ChildParticipanteQuery groupByEventoId() Group by the evento column
 *
 * @method     ChildParticipanteQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildParticipanteQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildParticipanteQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildParticipanteQuery leftJoinWith($relation) Adds a LEFT JOIN clause and with to the query
 * @method     ChildParticipanteQuery rightJoinWith($relation) Adds a RIGHT JOIN clause and with to the query
 * @method     ChildParticipanteQuery innerJoinWith($relation) Adds a INNER JOIN clause and with to the query
 *
 * @method     ChildParticipanteQuery leftJoinEvento($relationAlias = null) Adds a LEFT JOIN clause to the query using the Evento relation
 * @method     ChildParticipanteQuery rightJoinEvento($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Evento relation
 * @method     ChildParticipanteQuery innerJoinEvento($relationAlias = null) Adds a INNER JOIN clause to the query using the Evento relation
 *
 * @method     ChildParticipanteQuery joinWithEvento($joinType = Criteria::INNER_JOIN) Adds a join clause and with to the query using the Evento relation
 *
 * @method     ChildParticipanteQuery leftJoinWithEvento() Adds a LEFT JOIN clause and with to the query using the Evento relation
 * @method     ChildParticipanteQuery rightJoinWithEvento() Adds a RIGHT JOIN clause and with to the query using the Evento relation
 * @method     ChildParticipanteQuery innerJoinWithEvento() Adds a INNER JOIN clause and with to the query using the Evento relation
 *
 * @method     \Baja\Model\EventoQuery endUse() Finalizes a secondary criteria and merges it with its primary Criteria
 *
 * @method     ChildParticipante findOne(ConnectionInterface $con = null) Return the first ChildParticipante matching the query
 * @method     ChildParticipante findOneOrCreate(ConnectionInterface $con = null) Return the first ChildParticipante matching the query, or a new ChildParticipante object populated from the query conditions when no match is found
 *
 * @method     ChildParticipante findOneByParticipanteId(int $idparticipantes) Return the first ChildParticipante filtered by the idparticipantes column
 * @method     ChildParticipante findOneByNome(string $nome) Return the first ChildParticipante filtered by the nome column
 * @method     ChildParticipante findOneByFuncao(string $funcao) Return the first ChildParticipante filtered by the funcao column
 * @method     ChildParticipante findOneByCpf(string $cpf) Return the first ChildParticipante filtered by the cpf column
 * @method     ChildParticipante findOneByEventoId(string $evento) Return the first ChildParticipante filtered by the evento column *

 * @method     ChildParticipante requirePk($key, ConnectionInterface $con = null) Return the ChildParticipante by primary key and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOne(ConnectionInterface $con = null) Return the first ChildParticipante matching the query and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildParticipante requireOneByParticipanteId(int $idparticipantes) Return the first ChildParticipante filtered by the idparticipantes column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByNome(string $nome) Return the first ChildParticipante filtered by the nome column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByFuncao(string $funcao) Return the first ChildParticipante filtered by the funcao column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByCpf(string $cpf) Return the first ChildParticipante filtered by the cpf column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 * @method     ChildParticipante requireOneByEventoId(string $evento) Return the first ChildParticipante filtered by the evento column and throws \Propel\Runtime\Exception\EntityNotFoundException when not found
 *
 * @method     ChildParticipante[]|ObjectCollection find(ConnectionInterface $con = null) Return ChildParticipante objects based on current ModelCriteria
 * @method     ChildParticipante[]|ObjectCollection findByParticipanteId(int $idparticipantes) Return ChildParticipante objects filtered by the idparticipantes column
 * @method     ChildParticipante[]|ObjectCollection findByNome(string $nome) Return ChildParticipante objects filtered by the nome column
 * @method     ChildParticipante[]|ObjectCollection findByFuncao(string $funcao) Return ChildParticipante objects filtered by the funcao column
 * @method     ChildParticipante[]|ObjectCollection findByCpf(string $cpf) Return ChildParticipante objects filtered by the cpf column
 * @method     ChildParticipante[]|ObjectCollection findByEventoId(string $evento) Return ChildParticipante objects filtered by the evento column
 * @method     ChildParticipante[]|\Propel\Runtime\Util\PropelModelPager paginate($page = 1, $maxPerPage = 10, ConnectionInterface $con = null) Issue a SELECT query based on the current ModelCriteria and uses a page and a maximum number of results per page to compute an offset and a limit
 *
 */
abstract class ParticipanteQuery extends ModelCriteria
{
    protected $entityNotFoundExceptionClass = '\\Propel\\Runtime\\Exception\\EntityNotFoundException';

    /**
     * Initializes internal state of \Baja\Model\Base\ParticipanteQuery object.
     *
     * @param     string $dbName The database name
     * @param     string $modelName The phpName of a model, e.g. 'Book'
     * @param     string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'resultados', $modelName = '\\Baja\\Model\\Participante', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildParticipanteQuery object.
     *
     * @param     string $modelAlias The alias of a model in the query
     * @param     Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildParticipanteQuery
     */
    public static function create($modelAlias = null, Criteria $criteria = null)
    {
        if ($criteria instanceof ChildParticipanteQuery) {
            return $criteria;
        }
        $query = new ChildParticipanteQuery();
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
     * @param array[$idparticipantes, $evento] $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildParticipante|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, ConnectionInterface $con = null)
    {
        if ($key === null) {
            return null;
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        $this->basePreSelect($con);

        if (
            $this->formatter || $this->modelAlias || $this->with || $this->select
            || $this->selectColumns || $this->asColumns || $this->selectModifiers
            || $this->map || $this->having || $this->joins
        ) {
            return $this->findPkComplex($key, $con);
        }

        if ((null !== ($obj = ParticipanteTableMap::getInstanceFromPool(serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]))))) {
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
     * @return ChildParticipante A model object, or null if the key is not found
     */
    protected function findPkSimple($key, ConnectionInterface $con)
    {
        $sql = 'SELECT idparticipantes, nome, funcao, cpf, evento FROM participantes WHERE idparticipantes = :p0 AND evento = :p1';
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
            /** @var ChildParticipante $obj */
            $obj = new ChildParticipante();
            $obj->hydrate($row);
            ParticipanteTableMap::addInstanceToPool($obj, serialize([(null === $key[0] || is_scalar($key[0]) || is_callable([$key[0], '__toString']) ? (string) $key[0] : $key[0]), (null === $key[1] || is_scalar($key[1]) || is_callable([$key[1], '__toString']) ? (string) $key[1] : $key[1])]));
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
     * @return ChildParticipante|array|mixed the result, formatted by the current formatter
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
     * @return $this|ChildParticipanteQuery The current query, for fluid interface
     */
    public function filterByPrimaryKey($key)
    {
        $this->addUsingAlias(ParticipanteTableMap::COL_IDPARTICIPANTES, $key[0], Criteria::EQUAL);
        $this->addUsingAlias(ParticipanteTableMap::COL_EVENTO, $key[1], Criteria::EQUAL);

        return $this;
    }

    /**
     * Filter the query by a list of primary keys
     *
     * @param     array $keys The list of primary key to use for the query
     *
     * @return $this|ChildParticipanteQuery The current query, for fluid interface
     */
    public function filterByPrimaryKeys($keys)
    {
        if (empty($keys)) {
            return $this->add(null, '1<>1', Criteria::CUSTOM);
        }
        foreach ($keys as $key) {
            $cton0 = $this->getNewCriterion(ParticipanteTableMap::COL_IDPARTICIPANTES, $key[0], Criteria::EQUAL);
            $cton1 = $this->getNewCriterion(ParticipanteTableMap::COL_EVENTO, $key[1], Criteria::EQUAL);
            $cton0->addAnd($cton1);
            $this->addOr($cton0);
        }

        return $this;
    }

    /**
     * Filter the query on the idparticipantes column
     *
     * Example usage:
     * <code>
     * $query->filterByParticipanteId(1234); // WHERE idparticipantes = 1234
     * $query->filterByParticipanteId(array(12, 34)); // WHERE idparticipantes IN (12, 34)
     * $query->filterByParticipanteId(array('min' => 12)); // WHERE idparticipantes > 12
     * </code>
     *
     * @param     mixed $participanteId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildParticipanteQuery The current query, for fluid interface
     */
    public function filterByParticipanteId($participanteId = null, $comparison = null)
    {
        if (is_array($participanteId)) {
            $useMinMax = false;
            if (isset($participanteId['min'])) {
                $this->addUsingAlias(ParticipanteTableMap::COL_IDPARTICIPANTES, $participanteId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($participanteId['max'])) {
                $this->addUsingAlias(ParticipanteTableMap::COL_IDPARTICIPANTES, $participanteId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ParticipanteTableMap::COL_IDPARTICIPANTES, $participanteId, $comparison);
    }

    /**
     * Filter the query on the nome column
     *
     * Example usage:
     * <code>
     * $query->filterByNome('fooValue');   // WHERE nome = 'fooValue'
     * $query->filterByNome('%fooValue%', Criteria::LIKE); // WHERE nome LIKE '%fooValue%'
     * </code>
     *
     * @param     string $nome The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildParticipanteQuery The current query, for fluid interface
     */
    public function filterByNome($nome = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($nome)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ParticipanteTableMap::COL_NOME, $nome, $comparison);
    }

    /**
     * Filter the query on the funcao column
     *
     * Example usage:
     * <code>
     * $query->filterByFuncao('fooValue');   // WHERE funcao = 'fooValue'
     * $query->filterByFuncao('%fooValue%', Criteria::LIKE); // WHERE funcao LIKE '%fooValue%'
     * </code>
     *
     * @param     string $funcao The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildParticipanteQuery The current query, for fluid interface
     */
    public function filterByFuncao($funcao = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($funcao)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ParticipanteTableMap::COL_FUNCAO, $funcao, $comparison);
    }

    /**
     * Filter the query on the cpf column
     *
     * Example usage:
     * <code>
     * $query->filterByCpf(1234); // WHERE cpf = 1234
     * $query->filterByCpf(array(12, 34)); // WHERE cpf IN (12, 34)
     * $query->filterByCpf(array('min' => 12)); // WHERE cpf > 12
     * </code>
     *
     * @param     mixed $cpf The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildParticipanteQuery The current query, for fluid interface
     */
    public function filterByCpf($cpf = null, $comparison = null)
    {
        if (is_array($cpf)) {
            $useMinMax = false;
            if (isset($cpf['min'])) {
                $this->addUsingAlias(ParticipanteTableMap::COL_CPF, $cpf['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($cpf['max'])) {
                $this->addUsingAlias(ParticipanteTableMap::COL_CPF, $cpf['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ParticipanteTableMap::COL_CPF, $cpf, $comparison);
    }

    /**
     * Filter the query on the evento column
     *
     * Example usage:
     * <code>
     * $query->filterByEventoId('fooValue');   // WHERE evento = 'fooValue'
     * $query->filterByEventoId('%fooValue%', Criteria::LIKE); // WHERE evento LIKE '%fooValue%'
     * </code>
     *
     * @param     string $eventoId The value to use as filter.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ChildParticipanteQuery The current query, for fluid interface
     */
    public function filterByEventoId($eventoId = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($eventoId)) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(ParticipanteTableMap::COL_EVENTO, $eventoId, $comparison);
    }

    /**
     * Filter the query by a related \Baja\Model\Evento object
     *
     * @param \Baja\Model\Evento|ObjectCollection $evento The related object(s) to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return ChildParticipanteQuery The current query, for fluid interface
     */
    public function filterByEvento($evento, $comparison = null)
    {
        if ($evento instanceof \Baja\Model\Evento) {
            return $this
                ->addUsingAlias(ParticipanteTableMap::COL_EVENTO, $evento->getEventoId(), $comparison);
        } elseif ($evento instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            return $this
                ->addUsingAlias(ParticipanteTableMap::COL_EVENTO, $evento->toKeyValue('PrimaryKey', 'EventoId'), $comparison);
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
     * @return $this|ChildParticipanteQuery The current query, for fluid interface
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
     * Exclude object from result
     *
     * @param   ChildParticipante $participante Object to remove from the list of results
     *
     * @return $this|ChildParticipanteQuery The current query, for fluid interface
     */
    public function prune($participante = null)
    {
        if ($participante) {
            $this->addCond('pruneCond0', $this->getAliasedColName(ParticipanteTableMap::COL_IDPARTICIPANTES), $participante->getParticipanteId(), Criteria::NOT_EQUAL);
            $this->addCond('pruneCond1', $this->getAliasedColName(ParticipanteTableMap::COL_EVENTO), $participante->getEventoId(), Criteria::NOT_EQUAL);
            $this->combine(array('pruneCond0', 'pruneCond1'), Criteria::LOGICAL_OR);
        }

        return $this;
    }

    /**
     * Deletes all rows from the participantes table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con) {
            $affectedRows = 0; // initialize var to track total num of affected rows
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            ParticipanteTableMap::clearInstancePool();
            ParticipanteTableMap::clearRelatedInstancePool();

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
            $con = Propel::getServiceContainer()->getWriteConnection(ParticipanteTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(ParticipanteTableMap::DATABASE_NAME);

        // use transaction because $criteria could contain info
        // for more than one table or we could emulating ON DELETE CASCADE, etc.
        return $con->transaction(function () use ($con, $criteria) {
            $affectedRows = 0; // initialize var to track total num of affected rows

            ParticipanteTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            ParticipanteTableMap::clearRelatedInstancePool();

            return $affectedRows;
        });
    }

} // ParticipanteQuery
